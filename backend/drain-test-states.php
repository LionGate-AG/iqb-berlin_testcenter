#!/usr/bin/env php
<?php
/**
 * Drainer of the test-state buffer: writes the states the backend buffered in the cache server (TestStateBuffer) to the
 * database, all pending ones every STATE_DRAIN_INTERVAL_MS. See TestStateBuffer for the guarantees and
 * TestStateDrainer for the batch handling.
 *
 * Runs as one long-lived process next to the backend, from the same image and with the same environment, including
 * TESTCENTER_AUTH_TOKEN_TTL, the expiry a written entry gets. Run exactly one: settling is atomic, so more would be
 * safe, but they would only compete for the same rows.
 *
 * It drains whatever the setting of TESTCENTER_BUFFER_TEST_STATE, so that states buffered before the switch was turned
 * off still reach the database.
 *
 * Settings (see SystemConfig): STATE_DRAIN_INTERVAL_MS, STATE_DRAIN_BATCH rows per UPDATE, STATE_DRAIN_REPORT_SEC
 * seconds between two status lines.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
  header('HTTP/1.0 403 Forbidden');
  echo "This is only for usage from command line.";
  exit(1);
}

define('ROOT_DIR', realpath(__DIR__ . '/..'));

require_once __DIR__ . '/vendor/autoload.php';

$drainLog = function(string $message): void {
  fwrite(STDERR, sprintf("[%s] drain-test-states: %s\n", gmdate('Y-m-d\TH:i:s\Z'), $message));
};

SystemConfig::readEnvironment();
date_default_timezone_set(SystemConfig::$system_timezone);

$intervalMilliseconds = SystemConfig::$testState_drainIntervalMs;
$reportSeconds = SystemConfig::$testState_drainReportSeconds;
if ($reportSeconds < 1) {
  $drainLog('STATE_DRAIN_REPORT_SEC must be at least 1');
  exit(1);
}
try {
  $drainer = new TestStateDrainer(SystemConfig::$testState_drainBatchSize, $intervalMilliseconds, $drainLog);
} catch (InvalidArgumentException $exception) {
  $drainLog('STATE_DRAIN_BATCH or STATE_DRAIN_INTERVAL_MS: ' . $exception->getMessage());
  exit(1);
}

$drainLog(sprintf(
  'starting: interval=%dms batch=%d report=%ds',
  $intervalMilliseconds,
  SystemConfig::$testState_drainBatchSize,
  $reportSeconds
));

$testDAO = null;
$databaseRetrySeconds = 0;
$lastReport = time();

while (true) {
  $roundStart = (int) hrtime(true);

  if (time() - $lastReport >= $reportSeconds) {
    $drainLog($drainer->report());
    $lastReport = time();
  }

  if (!$testDAO) {
    try {
      DB::connect();
      $testDAO = new TestDAO();
      $databaseRetrySeconds = 0;
      $drainLog('database connected');
    } catch (Throwable $exception) {
      $databaseRetrySeconds = min(30, max(1, $databaseRetrySeconds * 2));
      $drainLog("database unavailable ({$exception->getMessage()}), retrying in {$databaseRetrySeconds}s");
      sleep($databaseRetrySeconds);
      continue;
    }
  }

  try {
    $taken = $drainer->drainRound($testDAO);
  } catch (RuntimeException $exception) {
    $drainLog($exception->getMessage() . '; reconnecting to the database');
    $testDAO = null;
    continue;
  }

  if ($taken === 0) {
    // An empty result can also mean a dead connection to the cache server. Checking it costs nothing while there is
    // no work, and without it the process would keep using a dead connection forever.
    CacheService::connection(true);
  }

  $remainingMicroseconds = $intervalMilliseconds * 1000 - intdiv((int) hrtime(true) - $roundStart, 1000);
  if ($remainingMicroseconds > 0) {
    usleep($remainingMicroseconds);
  }
}
