#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Drain worker for the `test_logs` queue.
 *
 * Pops rows that requests buffered via LogBuffer and inserts them in large
 * multi-row batches, so millions of single-row transactions per load test become
 * a few thousand large ones -- and, more importantly, so the number of CONCURRENT
 * writers collapses from ~12,000 PHP-FPM workers to this one process. See
 * LogBuffer for the measurements behind that and for the at-most-once semantics
 * this accepts.
 *
 * This session runs with foreign_key_checks=0 (see the connect block below); the
 * FK was measured to be the dominant cost of a batch insert and the source of a
 * lock convoy against `UPDATE tests`.
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
 *   LOG_DRAIN_BATCH      rows per INSERT                    (default 5000)
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

$batchSize = max(1, (int) (getenv('LOG_DRAIN_BATCH') ?: 5000));
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

      // Skip foreign-key validation FOR THIS SESSION ONLY.
      //
      // test_logs.booklet_id references tests.id (fk_log_booklet). Validating it
      // costs an index lookup AND a shared lock on the referenced `tests` row --
      // per row. In a 1,000-row batch that is 1,000 lookups and 1,000 S-locks held
      // until the transaction commits, which measured on 2026-09-03 as:
      //
      //   * batch INSERT avg 853ms for 1,000 rows = 0.85ms/row, against a measured
      //     uncontended floor of 0.05ms/row -- 17x the floor, nearly all of it FK work;
      //   * a lock convoy: `UPDATE tests SET laststate` needs an X-lock on those same
      //     rows and blocked behind the batch. Caught directly in data_lock_waits with
      //     up to 12 concurrent waiters against a batch holding 991 row locks
      //     (Innodb_row_lock_waits 11,301, avg 210ms, max 8,760ms).
      //
      // Together those capped drain throughput at ~1,200 rows/s, below the arrival
      // rate, so the queue climbed to its 250,000 cap in ~5 minutes. Past the cap
      // LogBuffer::push() returns false and callers revert to synchronous inserts --
      // 65,704 of them -- which put Threads_running from 74 to 1,468, exhausted the
      // ProxySQL pool ("Max connect timeout reached ... after 10089ms"), and produced
      // 51,828 shed 503s. Every failure in that run traced back to this FK cost.
      //
      // WHY SESSION-SCOPED RATHER THAN DROPPING THE CONSTRAINT: fk_log_booklet is
      // `ON DELETE CASCADE`, and AdminDAO::deleteResultData() /
      // deleteResultDataByPersonAndBooklet() (WorkspaceController, admin "delete
      // result data") rely on that cascade to remove a test's log rows. test_logs has
      // no PRIMARY KEY and only index_fk_log_booklet, so orphans left behind would be
      // awkward to sweep -- and this is test-taker data with a deletion feature, so
      // silently breaking the cascade is not acceptable. Disabling validation per
      // session keeps the constraint, and therefore keeps the cascade, while removing
      // its cost from the one writer that cannot afford it. The request path continues
      // to validate normally.
      //
      // WHAT THIS GIVES UP: rows whose parent test was deleted between the request and
      // the drain will now insert as orphans instead of being rejected. The window is
      // seconds, and AdminDAO::getLogReportData() inner-joins tests, so orphans are
      // invisible in the report rather than harmful. The row-by-row fallback below is
      // retained for every other error class.
      $testDAO->_('set session foreign_key_checks = 0');

      $dbBackoff = 0;
      drainLog('database connected (foreign_key_checks=0 for this session)');
    } catch (Throwable $throwable) {
      $dbBackoff = min(30, $dbBackoff === 0 ? 1 : $dbBackoff * 2);
      drainLog('database unavailable (' . $throwable->getMessage() . '), retrying in ' . $dbBackoff . 's');
      sleep($dbBackoff);
      continue;
    }
  }

  $logs = LogBuffer::drain($batchSize);

  if (empty($logs)) {
    // LogBuffer::drain() returns [] both when the queue is genuinely empty AND
    // when Redis is unreachable -- the two are indistinguishable to the caller.
    // That ambiguity is what let this worker sit for 28 HOURS reporting
    // `depth=redis-unavailable inserted=0 batches=0` while Redis was healthy and
    // reachable from inside this very pod: its cached handle had died once, and
    // because the handle lives in a static that only FPM ever resets, nothing
    // could ever replace it.
    //
    // So verify (and if necessary re-establish) the connection here, in the IDLE
    // branch specifically: a PING costs nothing when there is no work, and under
    // load this branch is never taken. See CacheService::connection($verify).
    CacheService::connection(true);

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
    // A whole batch failed. Foreign-key violations -- previously the expected
    // cause, since this database is reseeded between runs -- can no longer occur
    // here: this session sets foreign_key_checks=0, so a row whose parent test was
    // deleted inserts as an orphan rather than failing. What remains are packet or
    // parameter limits, a lost connection mid-statement, and genuine data errors.
    //
    // Retrying the whole batch could loop forever on the same bad row, so fall back
    // to row-by-row and discard only the offenders; everything still-valid in the
    // batch is preserved. Kept deliberately even though its original trigger is
    // gone -- a poisoned batch must never be able to wedge the queue.
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
  // depth() returns -1 when Redis is unreachable. Rendered as an explicit
  // "redis-unavailable" so a dead connection is obvious in `kubectl logs`
  // instead of hiding as a plausible-looking number.
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
