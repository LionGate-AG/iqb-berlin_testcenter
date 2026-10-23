#!/usr/bin/env php
<?php
/**
 * Drainer of the test-log queue: writes the rows the backend queued in the cache server (LogBuffer) to the database
 * in batches. See LogBuffer for the delivery guarantees and LogDrainer for the batch handling.
 *
 * Runs as one long-lived process next to the backend, from the same image and with the same environment. Run exactly
 * one: more would only compete for the same queue.
 *
 * It drains whatever the setting of TESTCENTER_ASYNC_TEST_LOGS, so that rows queued before the switch was turned off
 * still reach the database.
 *
 * Settings (see SystemConfig): LOG_DRAIN_BATCH rows per INSERT, LOG_DRAIN_IDLE_MS pause when the queue is empty,
 * LOG_DRAIN_REPORT_SEC seconds between two status lines.
 *
 * The process cannot finish a batch on shutdown: the batch it holds at that moment is lost.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
  header('HTTP/1.0 403 Forbidden');
  echo "This is only for usage from command line.";
  exit(1);
}

define('ROOT_DIR', realpath(__DIR__ . '/..'));

require_once __DIR__ . '/vendor/autoload.php';

function drainLog(string $message): void {
  fwrite(STDERR, sprintf("[%s] drain-logs: %s\n", gmdate('Y-m-d\TH:i:s\Z'), $message));
}

SystemConfig::readEnvironment();
date_default_timezone_set(SystemConfig::$system_timezone);

$idleMilliseconds = SystemConfig::$testLogs_drainIdleMs;
$reportSeconds = SystemConfig::$testLogs_drainReportSeconds;
if (($idleMilliseconds < 1) or ($reportSeconds < 1)) {
  drainLog('LOG_DRAIN_IDLE_MS and LOG_DRAIN_REPORT_SEC must be at least 1');
  exit(1);
}
try {
  $drainer = new LogDrainer(SystemConfig::$testLogs_drainBatchSize, drainLog(...));
} catch (InvalidArgumentException $exception) {
  drainLog('LOG_DRAIN_BATCH: ' . $exception->getMessage());
  exit(1);
}

drainLog(sprintf(
  'starting: batch=%d idle=%dms report=%ds',
  SystemConfig::$testLogs_drainBatchSize,
  $idleMilliseconds,
  $reportSeconds
));

$testDAO = null;
$databaseRetrySeconds = 0;
$lastReport = time();

while (true) {
  if (time() - $lastReport >= $reportSeconds) {
    drainLog($drainer->report());
    $lastReport = time();
  }

  // Connect before taking rows off the queue, so they wait there while the database is away.
  if (!$testDAO) {
    try {
      DB::connect();
      $testDAO = new TestDAO();
      $databaseRetrySeconds = 0;
      drainLog('database connected');
    } catch (Throwable $exception) {
      $databaseRetrySeconds = min(30, max(1, $databaseRetrySeconds * 2));
      drainLog("database unavailable ({$exception->getMessage()}), retrying in {$databaseRetrySeconds}s");
      sleep($databaseRetrySeconds);
      continue;
    }
  }

  try {
    $taken = $drainer->drainBatch($testDAO);
  } catch (RuntimeException $exception) {
    drainLog($exception->getMessage() . '; reconnecting to the database');
    $testDAO = null;
    continue;
  }

  if ($taken === 0) {
    // An empty result can also mean a dead connection to the cache server. Checking it costs nothing while there is
    // no work, and without it the process would keep using a dead connection forever.
    CacheService::connection(true);
    usleep($idleMilliseconds * 1000);
  }
}
