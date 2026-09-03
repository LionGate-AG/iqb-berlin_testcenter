<?php
declare(strict_types=1);

/**
 * Cross-request buffer for `test_logs` rows, backed by a Redis list.
 *
 * # Why this exists
 *
 * `TestDAO::addTestLogs()` already builds a single multi-row INSERT, but it can
 * only ever batch what arrived in ONE HTTP request -- PHP-FPM is request-scoped,
 * so there is nowhere for it to keep a buffer that survives to the next request.
 * Measured `rows_per_stmt = 1.00` under load test and 1-3 in production. That is
 * why `INSERT INTO test_logs` became one statement per state change.
 *
 * Measured 2026-09-03 at ~88k users, that statement was 947,740s of 1,157,890s
 * of total database time (81.8%) over 3.2M executions, avg 293.6ms, 7.42% over
 * one second -- while every other statement on the same request path sat at
 * <=0.44% over one second.
 *
 * The cost is NOT the row write. The same digest reported a `min_timer_wait` of
 * 0.050ms, so an uncontended insert costs ~50us; the average was ~5,900x that
 * floor. Every write-path counter stayed at zero all run (`Innodb_log_waits`,
 * `Innodb_buffer_pool_wait_free`, `Innodb_data_pending_fsyncs`,
 * `Innodb_row_lock_current_waits`), so the time was contention on write-
 * transaction registration, not I/O, locking or CPU.
 *
 * Phase-0 proved it causally: skipping ONLY this insert (TESTCENTER_SKIP_TEST_LOGS)
 * cut total database time 91.8% at comparable volume AND made every surviving
 * statement 2-3x faster -- `UPDATE tests` 46.9ms -> 18.3ms, `UPDATE person_sessions`
 * 38.3ms -> 12.4ms, `SELECT person_sessions.token` 11.3ms -> 5.2ms -- with refused
 * connections going from +1,423 to +3 and throughput rising ~43% at the same user
 * count. The surviving writes were slow because 3.2M insert transactions were
 * competing with them, not because of their own cost.
 *
 * # What this changes
 *
 * Requests `rPush` their rows and return without touching MySQL. A single drain
 * worker (backend/drain-logs.php) pops them in blocks and issues one multi-row
 * INSERT per block:
 *
 *   4.49M single-row transactions  ->  ~4,500 transactions of 1,000 rows
 *
 * Two things shrink, and the second matters more: the transaction COUNT falls
 * ~1000x, and the number of CONCURRENT writers collapses from ~12,000 PHP-FPM
 * workers to one. Latch contention responds to concurrency, which is why Phase-0
 * sped up unrelated statements.
 *
 * # Semantics -- read before relying on this
 *
 * AT-MOST-ONCE. Rows are popped and then inserted; a drain-worker crash, or a
 * Redis restart, loses whatever was buffered (bounded by the batch size and the
 * queue depth). This was chosen deliberately over at-least-once: `test_logs` has
 * no unique key, so a replay would put DUPLICATE rows into the customer-facing
 * workspace log CSV (AdminDAO::getLogReportData -> LogReportOutput), and a
 * duplicated audit row is worse than a missing sub-second window. `pcntl` is not
 * installed in this image, so the worker cannot trap SIGTERM either -- pod
 * termination also drops at most one in-flight batch.
 *
 * ORDERING is not preserved across batches and does not need to be:
 * `test_logs.timestamp` is client-supplied and the report sorts by it.
 *
 * FAILURE means FALLBACK, never data loss: `push()` returns false whenever Redis
 * is unconfigured, unreachable, or over the depth cap, and the caller then does
 * today's synchronous INSERT. A Redis outage is therefore a performance
 * regression, not a hole in the audit trail. It regresses exactly at peak load,
 * which is the accepted trade.
 */
class LogBuffer {
  /** Redis list holding igbinary-serialised [testId, logKey, timestamp, logContent]. */
  private const KEY = 'test-logs:queue';

  /** Counter key: pushes that fell back to a synchronous INSERT. */
  private const KEY_FALLBACKS = 'test-logs:fallbacks';

  /** Counter key: rows discarded by the drain worker (FK violations after a reseed). */
  public const KEY_DISCARDED = 'test-logs:discarded';

  /**
   * Depth beyond which callers stop buffering and write synchronously instead.
   *
   * This cap is NOT belt-and-braces -- it is load-bearing, because of how the
   * cache server is configured (templates/cache-server/deployment.yaml):
   *
   *     --maxmemory 1G  --maxmemory-policy volatile-lru
   *     --save ""       --appendonly no
   *
   * `volatile-lru` evicts only keys that have an expire set. Every auth key does
   * (`group-token:*`, TTL ~80,000s, written by CacheService::storeAuthentication).
   * This queue does NOT. So if the queue were ever allowed to fill Redis, the
   * eviction would fall entirely on the AUTHENTICATION TOKENS while the queue
   * itself survived -- i.e. a stalled drain worker would log people out rather
   * than lose log rows. maxmemory also equals the container's 1Gi limit, so the
   * pod would likely be OOMKilled before eviction even completed.
   *
   * 250,000 rows is ~50MB at ~200 bytes/row: ~5% of maxmemory, against an
   * observed steady state of 832KB and an all-time peak of 7.74MB. Beyond it,
   * callers write synchronously -- slower, but it cannot touch auth.
   *
   * The queue is deliberately left WITHOUT a TTL. Giving it one would make it
   * evictable and would silently discard log rows under memory pressure; an
   * explicit depth cap that degrades to synchronous writes is preferable to
   * invisible data loss.
   *
   * Note `--save ""` / `--appendonly no`: there is no persistence, so a Redis
   * restart drops the queue. That is consistent with the at-most-once semantics
   * documented above, not an additional exposure.
   */
  private const MAX_DEPTH = 250000;

  /**
   * How often the depth cap is actually checked, in calls per LLEN.
   *
   * The cap has to be evaluated BEFORE pushing: `rPush` returns the resulting
   * length, but by then the rows are already queued, and returning false at that
   * point would make the caller insert them a second time -- duplicating rows.
   * So the check is a separate LLEN, amortised over 100 calls to keep it at ~1%
   * overhead on a path that runs millions of times per run.
   */
  private const DEPTH_CHECK_INTERVAL = 100;

  private static ?bool $enabled = null;
  private static int $callsSinceDepthCheck = 0;
  private static bool $overCap = false;

  public static function enabled(): bool {
    if (self::$enabled === null) {
      self::$enabled = getenv('TESTCENTER_ASYNC_TEST_LOGS') === '1';
    }
    return self::$enabled;
  }

  /**
   * Queue rows for the drain worker.
   *
   * @param TestLog[] $testLogs
   * @return bool true when the rows are queued and the caller must NOT insert
   *              them; false when the caller must insert them synchronously.
   */
  public static function push(array $testLogs): bool {
    if (!self::enabled() || empty($testLogs)) {
      return false;
    }

    $redis = CacheService::connection();
    if ($redis === null) {
      self::countFallback($redis);
      return false;
    }

    try {
      // Amortised, and deliberately BEFORE the push -- see DEPTH_CHECK_INTERVAL.
      if (self::$callsSinceDepthCheck++ % self::DEPTH_CHECK_INTERVAL === 0) {
        self::$overCap = ((int) $redis->lLen(self::KEY)) > self::MAX_DEPTH;
      }
      if (self::$overCap) {
        self::countFallback($redis);
        return false;
      }

      $payload = [];
      foreach ($testLogs as $log) {
        // Positional array rather than the object: igbinary on a plain array is
        // smaller and does not tie the queue format to the class definition, so
        // rows queued by an old pod still decode after a rollout.
        $payload[] = igbinary_serialize([
          $log->testId,
          $log->logKey,
          $log->timestamp,
          $log->logContent
        ]);
      }

      // Variadic rPush: one round trip for the whole request's rows.
      return $redis->rPush(self::KEY, ...$payload) !== false;
    } catch (Throwable $throwable) {
      // Redis died mid-push. The rows may or may not have landed; we return
      // false so the caller writes them synchronously. A partial rPush can
      // therefore duplicate a row in the rare case Redis failed *after*
      // accepting it -- accepted as strictly better than losing it.
      self::countFallback($redis);
      return false;
    }
  }

  /**
   * Pop up to $count rows for insertion. Used by the drain worker only.
   *
   * @return TestLog[]
   */
  public static function drain(int $count): array {
    $redis = CacheService::connection();
    if ($redis === null) {
      return [];
    }

    try {
      $raw = $redis->lPop(self::KEY, $count);
    } catch (Throwable $throwable) {
      return [];
    }
    if (!is_array($raw) || empty($raw)) {
      return [];
    }

    $logs = [];
    foreach ($raw as $item) {
      $fields = @igbinary_unserialize($item);
      if (!is_array($fields) || count($fields) !== 4) {
        // Undecodable entry -- drop it rather than stalling the queue forever.
        continue;
      }
      $logs[] = new TestLog(
        (int) $fields[0],
        (string) $fields[1],
        (int) $fields[2],
        (string) $fields[3]
      );
    }

    return $logs;
  }

  /** Current queue depth, or -1 when Redis is unavailable. For monitoring. */
  public static function depth(): int {
    $redis = CacheService::connection();
    if ($redis === null) {
      return -1;
    }
    try {
      return (int) $redis->lLen(self::KEY);
    } catch (Throwable $throwable) {
      return -1;
    }
  }

  /** Read and reset a counter. Returns 0 when Redis is unavailable. */
  public static function takeCounter(string $key): int {
    $redis = CacheService::connection();
    if ($redis === null) {
      return 0;
    }
    try {
      $value = $redis->getDel($key);
      return $value === false ? 0 : (int) $value;
    } catch (Throwable $throwable) {
      return 0;
    }
  }

  /** Increment a counter, best-effort. */
  public static function bumpCounter(string $key, int $by = 1): void {
    $redis = CacheService::connection();
    if ($redis === null) {
      return;
    }
    try {
      $redis->incrBy($key, $by);
    } catch (Throwable $throwable) {
      // Monitoring must never break the write path.
    }
  }

  private static function countFallback(?Redis $redis): void {
    if ($redis === null) {
      return;
    }
    try {
      $redis->incr(self::KEY_FALLBACKS);
    } catch (Throwable $throwable) {
      // Ignored: this counter exists to make a Redis outage visible, and it is
      // itself the first thing that stops working during one.
    }
  }
}
