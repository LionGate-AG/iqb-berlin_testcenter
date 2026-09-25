<?php
declare(strict_types=1);

/**
 * Write-behind buffer for `tests.laststate`, backed by the test-state cache.
 *
 * # Why this exists
 *
 * Every PATCH /test/{id}/state ended in `UPDATE tests SET laststate = ? WHERE id = ?`,
 * sent synchronously by whichever of ~18,000 PHP-FPM workers served the request.
 * Measured in the fresh-onboarding runs of 2026-09-23 (98k users, 120 users/s):
 *
 *   * ~2,300 of these UPDATEs per second at 300-390ms each -- ~880 pooled
 *     connections busy with this statement alone;
 *   * SHOW ENGINE INNODB STATUS mid-storm: 1,406 threads queued on ONE page latch
 *     of the `tests` clustered index, running this UPDATE;
 *   * database throughput flat at ~4,000 statements/s whether ProxySQL admitted
 *     1,800 or 1,200 connections -- fewer connections only made more requests time
 *     out ("9001 Max connect timeout": 102,859 and 174,245 500s respectively, 63%
 *     of them on PATCH /state).
 *
 * The cost is concurrency on the same pages, not the row write -- the same finding
 * as for `test_logs` (see LogBuffer), where moving to one writer cut total database
 * time by 91.8%.
 *
 * # What this changes
 *
 * The request writes the merged state into the test-state cache entry (see
 * CacheService, `test-state:<id>`) WITHOUT a TTL and adds the id to the pending
 * set, atomically, and returns without touching MySQL. backend/drain-test-states.php
 * flushes pending entries every ~2s as multi-row UPDATEs from ONE connection:
 *
 *   ~2,300 single-row UPDATEs/s from ~1,000 connections
 *     -> ~5 multi-row UPDATEs/s from one connection
 *
 * # Semantics -- read before relying on this
 *
 * The cache entry is the CURRENT state while pending; the table lags by up to one
 * drain interval. Every reader of laststate therefore asks the cache first and
 * falls back to the table on a miss (CacheService::getCachedTestState and its
 * callers). An entry is only ever absent once written back, so a miss is safe.
 *
 * AT-LEAST-ONCE with last-write-wins, unlike LogBuffer: rewriting the same state is
 * harmless, so the drainer removes an id from the pending set only AFTER the
 * UPDATE, and only if the entry is unchanged since it was read (SETTLE_SCRIPT). A
 * PATCH that lands mid-flush keeps its id pending for the next round.
 *
 * Pending entries have no TTL, so the cache server's `volatile-lru` policy never
 * evicts them. After a successful flush they get the normal TTL again.
 *
 * What is lost if Redis itself restarts: the pending states, i.e. at most about
 * one drain interval of navigation state (current unit, timers, locks). Responses
 * are stored elsewhere and not affected. The cache server runs without
 * persistence; enabling AOF there would close even that window.
 */
class TestStateBuffer {
  /**
   * Set of test ids whose cached state is not yet in the table. Deliberately under
   * the test-state prefix, so a sweep of `test-state:*` (reset_onboarding.sh)
   * clears it together with the entries it refers to.
   */
  public const KEY_PENDING = 'test-state:pending';
  /** Counter: pushes that fell back to a synchronous UPDATE. */
  private const KEY_FALLBACKS = 'test-state:fallbacks';

  /**
   * Compare-and-settle, atomically per call.
   *
   * ARGV: pending-set key, key prefix, TTL, then (id, entry-as-read) pairs; an
   * empty entry means the key was already gone when read. An id is settled -- off
   * the pending set, TTL restored -- only if its entry still equals what was
   * flushed. Anything written meanwhile stays pending and is flushed next round.
   */
  private const SETTLE_SCRIPT = <<<'LUA'
local settled = 0
for i = 4, #ARGV, 2 do
  local key = ARGV[2] .. ARGV[i]
  local current = redis.call('GET', key)
  if (current == false and ARGV[i + 1] == '') or current == ARGV[i + 1] then
    redis.call('SREM', ARGV[1], ARGV[i])
    if current ~= false then
      redis.call('EXPIRE', key, ARGV[3])
    end
    settled = settled + 1
  end
end
return settled
LUA;

  private static ?bool $enabled = null;

  public static function enabled(): bool {
    if (self::$enabled === null) {
      self::$enabled = (getenv('TESTCENTER_BUFFER_TEST_STATE') === '1') && CacheService::authTokenCacheEnabled();
    }
    return self::$enabled;
  }

  /**
   * Buffer a test's new state. True when it is safely pending; false when
   * buffering is off or Redis refused -- the caller then UPDATEs synchronously.
   */
  public static function push(int $testId, int $personId, string $laststate): bool {
    if (!self::enabled()) {
      return false;
    }
    $redis = CacheService::connection();
    if ($redis === null) {
      return false;
    }
    try {
      // SET without options also clears any TTL, which is what keeps a pending
      // entry from expiring or being evicted before it is written back. Entry and
      // pending flag go together in MULTI: the drainer must never see the id
      // pending with an entry that is not the one to be written.
      $result = $redis->multi()
        ->set(CacheService::TEST_STATE_PREFIX . $testId, CacheService::encodeTestStateEntry($personId, $laststate))
        ->sAdd(self::KEY_PENDING, $testId)
        ->exec();
      if (is_array($result) and ($result[0] ?? false) === true) {
        return true;
      }
    } catch (Throwable $throwable) {
      // Fall through to the synchronous path.
    }
    self::countFallback($redis);
    return false;
  }

  /**
   * Up to $max pending ids with their entries as currently stored (false when the
   * key is gone). For the drainer only.
   *
   * @return array<int, string|false> testId => raw entry
   */
  public static function pending(int $max): array {
    $redis = CacheService::connection();
    if ($redis === null) {
      return [];
    }
    try {
      $ids = $redis->sRandMember(self::KEY_PENDING, $max);
      if (!is_array($ids) or empty($ids)) {
        return [];
      }
      $ids = array_map('intval', $ids);
      $raws = $redis->mGet(array_map(fn(int $id) => CacheService::TEST_STATE_PREFIX . $id, $ids));
    } catch (Throwable $throwable) {
      return [];
    }
    $pending = [];
    foreach ($ids as $index => $testId) {
      $pending[$testId] = $raws[$index] ?? false;
    }
    return $pending;
  }

  /**
   * Take flushed entries off the pending set, unless they changed meanwhile.
   * Returns how many were settled, or -1 when Redis failed (they stay pending and
   * are simply rewritten next round).
   *
   * @param array<int, string|false> $flushed testId => raw entry, as pending() returned it
   */
  public static function settle(array $flushed): int {
    if (empty($flushed)) {
      return 0;
    }
    $redis = CacheService::connection();
    if ($redis === null) {
      return -1;
    }
    $args = [self::KEY_PENDING, CacheService::TEST_STATE_PREFIX, (string) CacheService::testStateTtl()];
    foreach ($flushed as $testId => $raw) {
      $args[] = (string) $testId;
      $args[] = ($raw === false) ? '' : $raw;
    }
    try {
      return (int) $redis->eval(self::SETTLE_SCRIPT, $args, 0);
    } catch (Throwable $throwable) {
      return -1;
    }
  }

  /** Number of pending ids, or -1 when Redis is unavailable. For monitoring. */
  public static function depth(): int {
    $redis = CacheService::connection();
    if ($redis === null) {
      return -1;
    }
    try {
      return (int) $redis->sCard(self::KEY_PENDING);
    } catch (Throwable $throwable) {
      return -1;
    }
  }

  /** Read and reset the fallback counter. 0 when Redis is unavailable. */
  public static function takeFallbacks(): int {
    $redis = CacheService::connection();
    if ($redis === null) {
      return 0;
    }
    try {
      $value = $redis->getDel(self::KEY_FALLBACKS);
      return $value === false ? 0 : (int) $value;
    } catch (Throwable $throwable) {
      return 0;
    }
  }

  private static function countFallback(?Redis $redis): void {
    if ($redis === null) {
      return;
    }
    try {
      $redis->incr(self::KEY_FALLBACKS);
    } catch (Throwable $throwable) {
    }
  }
}
