#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Drain worker for buffered test states (write-behind of `tests.laststate`).
 *
 * Every interval it writes all pending states (TestStateBuffer) to the table as
 * multi-row UPDATEs, so ~2,300 single-row UPDATEs per second from ~1,000 pooled
 * connections become a handful of statements from this one connection. See
 * TestStateBuffer for the measurements and the consistency model.
 *
 * # Deliberately runs regardless of TESTCENTER_BUFFER_TEST_STATE
 *
 * The flag governs whether REQUESTS buffer. This worker always drains, so that
 * turning buffering off leaves no state stranded in Redis. Start it BEFORE
 * enabling the flag. Run exactly ONE replica: settling is atomic so more would be
 * safe, but they would only contend for the same rows.
 *
 * # Failure handling
 *
 * At-least-once: an id leaves the pending set only after its UPDATE succeeded and
 * only if its entry did not change meanwhile. A crash, a database outage or a
 * Redis error just leaves entries pending for the next round; nothing is lost
 * while Redis keeps them. A batch the database rejects is retried row by row, and
 * only rows that fail on their own are discarded (and counted), so one bad row
 * cannot wedge the buffer.
 *
 * # Environment
 *   STATE_DRAIN_INTERVAL_MS  how often pending states are flushed   (default 2000)
 *   STATE_DRAIN_BATCH        rows per UPDATE                         (default 500)
 *   STATE_DRAIN_REPORT_SEC   seconds between stats log lines         (default 30)
 * plus the same MYSQL_* / REDIS_* / TESTCENTER_AUTH_TOKEN_TTL variables the backend
 * receives.
 */

if (php_sapi_name() !== 'cli') {
  header('HTTP/1.0 403 Forbidden');
  echo "This is only for usage from command line.";
  exit(1);
}

define('ROOT_DIR', realpath(__DIR__ . '/..'));
const DATA_DIR = ROOT_DIR . '/data';

require_once __DIR__ . '/vendor/autoload.php';

function drainLog(string $message): void {
  fwrite(STDERR, sprintf("[%s] drain-test-states: %s\n", gmdate('Y-m-d\TH:i:s\Z'), $message));
}

SystemConfig::readEnvironment();
date_default_timezone_set(SystemConfig::$system_timezone);

$intervalMicros = max(100, (int) (getenv('STATE_DRAIN_INTERVAL_MS') ?: 2000)) * 1000;
$batchSize = max(1, (int) (getenv('STATE_DRAIN_BATCH') ?: 500));
$reportEvery = max(5, (int) (getenv('STATE_DRAIN_REPORT_SEC') ?: 30));

drainLog(sprintf('starting: interval=%dms batch=%d report=%ds', $intervalMicros / 1000, $batchSize, $reportEvery));

$testDAO = null;
$stats = ['flushed' => 0, 'changed' => 0, 'batches' => 0, 'discarded' => 0, 'maxCycleMs' => 0];
$lastReport = time();
$dbBackoff = 0;

while (true) {
  $cycleStart = microtime(true);

  // Connect lazily and reconnect on failure, so the worker survives a database
  // restart instead of crash-looping. Pending states wait in Redis meanwhile.
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

  // Flush everything pending now, one batch at a time. A short batch means the
  // set is drained; the interval bounds a round in which ids keep changing.
  $batchesThisCycle = 0;
  do {
    $pending = TestStateBuffer::pending($batchSize);
    if (empty($pending)) {
      break;
    }
    $batchesThisCycle++;

    /** @var array<int, string> $laststates testId => laststate */
    $laststates = [];
    foreach ($pending as $testId => $raw) {
      $entry = CacheService::decodeTestStateEntry($raw);
      // A gone or malformed entry has nothing to write; settle() clears the id.
      if ($entry !== null and $entry['laststate'] !== null) {
        $laststates[$testId] = $entry['laststate'];
      }
    }
    // Ascending ids: neighbouring rows share pages, so the batch touches each
    // page of the clustered index in one pass.
    ksort($laststates);

    try {
      $stats['changed'] += $testDAO->writeTestStatesNow($laststates);
      $stats['flushed'] += count($laststates);
      $stats['batches']++;
    } catch (Throwable $throwable) {
      drainLog('batch of ' . count($laststates) . ' failed (' . $throwable->getMessage() . '); retrying row-by-row');

      $rowFailures = 0;
      foreach ($laststates as $testId => $laststate) {
        try {
          $stats['changed'] += $testDAO->writeTestStatesNow([$testId => $laststate]);
          $stats['flushed']++;
        } catch (Throwable $rowThrowable) {
          $rowFailures++;
          drainLog("discarding state of test $testId (" . $rowThrowable->getMessage() . ')');
        }
      }

      // Every row failed: the database is gone rather than the rows bad. Keep
      // everything pending and reconnect.
      if (count($laststates) > 0 and $rowFailures === count($laststates)) {
        $testDAO = null;
        drainLog('entire batch unwritable -- keeping it pending and reconnecting');
        break;
      }
      $stats['discarded'] += $rowFailures;
    }

    // Entries that changed during the write stay pending and go out next round.
    TestStateBuffer::settle($pending);
  } while (count($pending) === $batchSize and (microtime(true) - $cycleStart) * 1e6 < $intervalMicros);

  if ($batchesThisCycle === 0) {
    // Nothing pending -- or Redis unreachable, which pending() cannot tell apart.
    // Verify (and if needed re-establish) the connection while idle; see the same
    // step in drain-logs.php for the 28-hour outage it prevents.
    CacheService::connection(true);
  }

  $cycleMicros = (int) ((microtime(true) - $cycleStart) * 1e6);
  $stats['maxCycleMs'] = max($stats['maxCycleMs'], intdiv($cycleMicros, 1000));

  if (time() - $lastReport >= $reportEvery) {
    reportStats($stats);
    $stats['maxCycleMs'] = 0;
    $lastReport = time();
  }

  if ($cycleMicros < $intervalMicros) {
    usleep($intervalMicros - $cycleMicros);
  }
}

/**
 * @param array{flushed: int, changed: int, batches: int, discarded: int, maxCycleMs: int} $stats
 */
function reportStats(array $stats): void {
  $depth = TestStateBuffer::depth();
  drainLog(sprintf(
    'pending=%s flushed=%d changed=%d batches=%d rows_per_batch=%.1f discarded=%d fallbacks=%d max_cycle_ms=%d',
    $depth < 0 ? 'redis-unavailable' : (string) $depth,
    $stats['flushed'],
    $stats['changed'],
    $stats['batches'],
    $stats['batches'] > 0 ? $stats['flushed'] / $stats['batches'] : 0.0,
    $stats['discarded'],
    TestStateBuffer::takeFallbacks(),
    $stats['maxCycleMs']
  ));
}
