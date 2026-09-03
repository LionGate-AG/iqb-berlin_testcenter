#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Drain worker for the `test_logs` queue.
 *
 * Pops rows that requests buffered via LogBuffer and inserts them in large
 * multi-row batches, so ~4.5M single-row transactions per load test become
 * ~4,500 transactions of 1,000 rows -- and, more importantly, so the number of
 * CONCURRENT writers collapses from ~12,000 PHP-FPM workers to this one process.
 * See LogBuffer for the measurements behind that and for the at-most-once
 * semantics this accepts.
 *
 * # Deliberately runs regardless of TESTCENTER_ASYNC_TEST_LOGS
 *
 * The flag governs whether REQUESTS buffer. This worker always drains, so that
 * turning async off leaves no rows stranded in Redis. Run exactly ONE replica:
 * `lPop` is atomic so more would be safe, but they would only contend.
 *
 * # Shutdown is lossy, by design
 *
 * `pcntl` is not built into this image, so SIGTERM cannot be trapped. On pod
 * termination PHP exits and an in-flight batch is lost -- bounded by
 * LOG_DRAIN_BATCH rows. That is the same at-most-once trade documented in
 * LogBuffer, chosen because `test_logs` has no unique key and a replay would put
 * duplicate rows into the customer-facing log CSV.
 *
 * # Environment
 *   LOG_DRAIN_BATCH      rows per INSERT                    (default 1000)
 *   LOG_DRAIN_IDLE_MS    sleep when the queue is empty      (default 200)
 *   LOG_DRAIN_REPORT_SEC seconds between stats log lines    (default 30)
 * plus the same MYSQL_* / REDIS_* variables the backend receives.
 */

if (php_sapi_name() !== 'cli') {
  header('HTTP/1.0 403 Forbidden');
  echo "This is only for usage from command line.";
  exit(1);
}

define('ROOT_DIR', realpath(__DIR__ . '/..'));
const DATA_DIR = ROOT_DIR . '/data';

// Absolute, not the relative path initialize.php uses: that only resolves
// because PHP-FPM's cwd happens to be .../backend. A container command has no
// such guarantee.
require_once __DIR__ . '/vendor/autoload.php';

function drainLog(string $message): void {
  // stderr so it is never confused with data, and is picked up by kubectl logs.
  fwrite(STDERR, sprintf("[%s] drain-logs: %s\n", gmdate('Y-m-d\TH:i:s\Z'), $message));
}

SystemConfig::readEnvironment();
date_default_timezone_set(SystemConfig::$system_timezone);

$batchSize = max(1, (int) (getenv('LOG_DRAIN_BATCH') ?: 1000));
$idleMicros = max(10, (int) (getenv('LOG_DRAIN_IDLE_MS') ?: 200)) * 1000;
$reportEvery = max(5, (int) (getenv('LOG_DRAIN_REPORT_SEC') ?: 30));

drainLog(sprintf('starting: batch=%d idle=%dms report=%ds', $batchSize, $idleMicros / 1000, $reportEvery));

$testDAO = null;
$inserted = 0;
$discarded = 0;
$batches = 0;
$lastReport = time();
$dbBackoff = 0;

while (true) {
  // Connect lazily and reconnect on failure, so the worker survives a database
  // restart (this deployment uses strategy Recreate) instead of crash-looping.
  if ($testDAO === null) {
    try {
      DB::connect();
      $testDAO = new TestDAO();
      $dbBackoff = 0;
      drainLog('database connected');
    } catch (Throwable $throwable) {
      $dbBackoff = min(30, $dbBackoff === 0 ? 1 : $dbBackoff * 2);
      drainLog('database unavailable (' . $throwable->getMessage() . '), retrying in ' . $dbBackoff . 's');
      sleep($dbBackoff);
      continue;
    }
  }

  $logs = LogBuffer::drain($batchSize);

  if (empty($logs)) {
    if (time() - $lastReport >= $reportEvery) {
      reportStats($inserted, $discarded, $batches);
      $lastReport = time();
    }
    usleep($idleMicros);
    continue;
  }

  try {
    $testDAO->insertTestLogsNow($logs);
    $inserted += count($logs);
    $batches++;
  } catch (Throwable $throwable) {
    // A whole batch failed. The expected cause is a foreign-key violation:
    // test_logs.booklet_id references tests.id, and this database is reseeded
    // between load-test runs -- so rows buffered before a truncate reference
    // parents that no longer exist. Retrying the batch would loop forever on the
    // same poisoned rows, so fall back to row-by-row and discard only the
    // offenders. Everything still-valid in the batch is preserved.
    drainLog('batch of ' . count($logs) . ' failed (' . $throwable->getMessage() . '); retrying row-by-row');

    $rowFailures = 0;
    foreach ($logs as $log) {
      try {
        $testDAO->insertTestLogsNow([$log]);
        $inserted++;
      } catch (Throwable $rowThrowable) {
        $rowFailures++;
      }
    }

    if ($rowFailures > 0) {
      $discarded += $rowFailures;
      LogBuffer::bumpCounter(LogBuffer::KEY_DISCARDED, $rowFailures);
      drainLog('discarded ' . $rowFailures . ' uninsertable row(s)');
    }

    // If every row failed, the database itself is probably gone rather than the
    // rows being bad. Drop the handle so the next iteration reconnects.
    if ($rowFailures === count($logs)) {
      $testDAO = null;
      drainLog('entire batch uninsertable -- reconnecting');
    }
  }

  if (time() - $lastReport >= $reportEvery) {
    reportStats($inserted, $discarded, $batches);
    $lastReport = time();
  }
}

function reportStats(int $inserted, int $discarded, int $batches): void {
  $depth = LogBuffer::depth();
  drainLog(sprintf(
    'depth=%s inserted=%d batches=%d rows_per_batch=%.1f discarded=%d',
    $depth < 0 ? 'redis-unavailable' : (string) $depth,
    $inserted,
    $batches,
    $batches > 0 ? $inserted / $batches : 0.0,
    $discarded
  ));
}
