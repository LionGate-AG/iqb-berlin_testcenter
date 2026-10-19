<?php
declare(strict_types=1);

/**
 * The state of tests (`tests.laststate`) in the cache server (Redis), and the write-behind of that state to the
 * database.
 *
 * Cache (switched on together with the auth-token cache, TESTCENTER_CACHE_AUTH_TOKEN)
 *
 * Every request on a test checks that the test belongs to the person of the token (IsTestOwner), and every state
 * update merges its patch into the current state. Both need the same two facts of the test's row, its owner and its
 * laststate, which are kept here as one entry per test. Readers of laststate ask this cache first and read the table
 * on a miss. This is safe to cache because
 * - the owner of a test never changes while its row exists;
 * - laststate has one writer, TestDAO::updateTestState(), which keeps the entry current;
 * - TestDAO::getOrCreateTest() writes the entry of every new test, which also replaces an entry left behind at the
 *   same id. PostgreSQL hands out an id only once, unless the identity of `tests` is reset (TRUNCATE ... RESTART
 *   IDENTITY, restoring a dump); the cache server has to be flushed after that;
 * - the admin actions that delete tests drop their entries (drop());
 * - an entry naming another owner is no denial: the caller asks the table, which decides.
 * Entries that are not pending expire after TESTCENTER_AUTH_TOKEN_TTL seconds.
 *
 * Write-behind (TESTCENTER_BUFFER_TEST_STATE, requires the cache)
 *
 * A state update writes the merged state into the entry only, marks the test as pending and does not touch the table.
 * The drainer (backend/drain-test-states.php, see TestStateDrainer) writes all pending states every few seconds, with
 * one UPDATE per batch. Until then the entry, not the row, holds the current state, which is why every reader of
 * laststate has to ask the cache first.
 * - At least once, last write wins: a test leaves the pending set only after the drainer has written its state, and
 *   only if the entry has not changed since the drainer read it (SETTLE_SCRIPT). A state written twice is harmless.
 * - Pending entries have no expiry. The cache server evicts only keys with an expiry (`volatile-lru`), so they are
 *   never evicted. When its memory is full nevertheless, push() fails and the caller writes to the table.
 * - Whenever the cache server is not reachable or refuses the write, push() returns false and the caller updates the
 *   row itself, as without the switch.
 * - A restart of the cache server, which runs without persistence, loses the pending states: about one drain interval
 *   of navigation state (current unit, timers, focus). Responses are stored in other tables and are not affected.
 */
class TestStateBuffer {
  /** <test id> -> {"p": <person_sessions.id of the owner>, "s": <laststate as JSON, or null>} */
  private const string PREFIX = 'test-state:';
  /** the ids of the tests whose entry holds a state the table does not have yet */
  public const string KEY_PENDING = 'test-state:pending';
  /** pushes that fell back to a direct UPDATE, since the drainer last read the counter */
  private const string KEY_FALLBACKS = 'test-state:fallbacks';

  /**
   * Writes the entry without expiry and marks the test as pending, atomically: the drainer must never find a test
   * pending whose entry is not the state to be written. SET without options also removes an expiry the entry had.
   *
   * KEYS[1] the entry, KEYS[2] the pending set, ARGV[1] the entry's value, ARGV[2] the test id
   */
  public const string PUSH_SCRIPT = <<<'LUA'
    redis.call('SET', KEYS[1], ARGV[1])
    redis.call('SADD', KEYS[2], ARGV[2])
    return 1
    LUA;

  /**
   * Takes written tests off the pending set, but only those whose entry still is what the drainer wrote; an entry
   * changed meanwhile stays pending and is written in the next round. A settled entry expires like any other, or is
   * deleted when the cache has no TTL configured. Returns the number of settled tests.
   *
   * KEYS[1] the pending set, ARGV[1] the key prefix, ARGV[2] the TTL, then pairs of test id and the entry as the
   * drainer read it ('' if there was none)
   */
  public const string SETTLE_SCRIPT = <<<'LUA'
    local settled = 0
    for i = 3, #ARGV, 2 do
      local key = ARGV[1] .. ARGV[i]
      local current = redis.call('GET', key)
      if (current == false and ARGV[i + 1] == '') or current == ARGV[i + 1] then
        redis.call('SREM', KEYS[1], ARGV[i])
        if current ~= false then
          if tonumber(ARGV[2]) > 0 then
            redis.call('EXPIRE', key, ARGV[2])
          else
            redis.call('DEL', key)
          end
        end
        settled = settled + 1
      end
    end
    return settled
    LUA;

  public static function cacheEnabled(): bool {
    return CacheService::authTokenCacheEnabled();
  }

  public static function bufferEnabled(): bool {
    return SystemConfig::$testState_buffer and self::cacheEnabled();
  }

  private static function cache(): ?Redis {
    return self::cacheEnabled() ? CacheService::connection() : null;
  }

  /**
   * The cached row of a test, whoever owns it. Null on a miss, when the cache is off or the cache server is not
   * reachable; callers have to read the table then.
   *
   * @return array{laststate: ?string, person_id: int}|null
   */
  public static function get(int $testId): ?array {
    $redis = self::cache();
    if (!$redis) {
      return null;
    }
    try {
      $raw = $redis->get(self::PREFIX . $testId);
    } catch (Throwable) {
      return null;
    }
    return self::decode($raw);
  }

  /**
   * The cached laststate of those of the tests which have an entry, read with one round trip. The tests without an
   * entry are missing from the result; for them the table's value applies.
   *
   * @param int[] $testIds
   * @return array<int, ?string> test id => laststate
   */
  public static function getLaststates(array $testIds): array {
    $redis = self::cache();
    if (!$redis or !$testIds) {
      return [];
    }
    $testIds = array_values(array_unique($testIds));
    try {
      $raws = $redis->mGet(array_map(fn(int $testId): string => self::PREFIX . $testId, $testIds));
    } catch (Throwable) {
      return [];
    }
    if (!is_array($raws)) {
      return [];
    }
    $laststates = [];
    foreach ($testIds as $index => $testId) {
      $entry = self::decode($raws[$index] ?? false);
      if ($entry !== null) {
        $laststates[$testId] = $entry['laststate'];
      }
    }
    return $laststates;
  }

  /**
   * Fills the cache after a miss, with the row just read from the table. Never replaces an entry: between the miss
   * and this call, a state update may have buffered a newer state, which must win over the table's.
   */
  public static function populate(int $testId, int $personId, ?string $laststate): void {
    $redis = self::cache();
    if (!$redis) {
      return;
    }
    try {
      $redis->set(
        self::PREFIX . $testId,
        self::encode($personId, $laststate),
        ['nx', 'ex' => SystemConfig::$cache_authTokenTtl]
      );
    } catch (Throwable) {
      // the next read asks the table again
    }
  }

  /**
   * Replaces the entry with a row the table has: after the row was written directly, or when the test was created.
   */
  public static function store(int $testId, int $personId, ?string $laststate): void {
    $redis = self::cache();
    if (!$redis) {
      return;
    }
    try {
      $redis->set(self::PREFIX . $testId, self::encode($personId, $laststate), SystemConfig::$cache_authTokenTtl);
    } catch (Throwable) {
      // the old entry must not stay: the next state update would merge into it and write the older state back
      self::invalidate($testId);
    }
  }

  public static function invalidate(int $testId): void {
    $redis = self::cache();
    if (!$redis) {
      return;
    }
    try {
      $redis->unlink(self::PREFIX . $testId);
    } catch (Throwable) {
      // the entry expires with its TTL, if it is not pending
    }
  }

  /**
   * Drops the entries of deleted tests, pending or not. By id, not by prefix: other tests' entries may be pending, and
   * dropping them would lose their state. A deleted test's pending state has no row to go to anyway.
   *
   * @param int[] $testIds
   */
  public static function drop(array $testIds): void {
    $redis = self::cache();
    if (!$redis or !$testIds) {
      return;
    }
    try {
      foreach (array_chunk(array_values(array_unique($testIds)), 1000) as $chunk) {
        $redis->unlink(array_map(fn(int $testId): string => self::PREFIX . $testId, $chunk));
        $redis->sRem(self::KEY_PENDING, $chunk[0], ...array_slice($chunk, 1));
      }
    } catch (Throwable) {
      // A leftover entry belongs to no row: the drainer writes it to nothing, and it expires once settled.
    }
  }

  /**
   * Buffers the new state of a test for the drainer.
   *
   * @return bool true when the state is pending and the caller must not write it; false when the caller has to update
   *   the row itself: the switch is off, or the cache server is not reachable or refused the write
   */
  public static function push(int $testId, int $personId, string $laststate): bool {
    if (!self::bufferEnabled()) {
      return false;
    }
    $redis = CacheService::connection();
    if (!$redis) {
      return false;
    }
    try {
      $redis->eval(
        self::PUSH_SCRIPT,
        [self::PREFIX . $testId, self::KEY_PENDING, self::encode($personId, $laststate), (string) $testId],
        2
      );
      return true;
    } catch (Throwable) {
      self::countFallback($redis);
      return false;
    }
  }

  /**
   * Up to $count pending tests with their entries as they are now; false where the entry is gone. For the drainer.
   *
   * @return array<int, string|false> test id => entry
   */
  public static function pending(int $count): array {
    $redis = CacheService::connection();
    if (!$redis) {
      return [];
    }
    try {
      $members = $redis->sRandMember(self::KEY_PENDING, $count);
      if (!is_array($members) or !$members) {
        return [];
      }
      $testIds = array_map('intval', array_values($members));
      $raws = $redis->mGet(array_map(fn(int $testId): string => self::PREFIX . $testId, $testIds));
    } catch (Throwable) {
      return [];
    }
    if (!is_array($raws)) {
      return [];
    }
    $pending = [];
    foreach ($testIds as $index => $testId) {
      $raw = $raws[$index] ?? false;
      $pending[$testId] = is_string($raw) ? $raw : false;
    }
    return $pending;
  }

  /**
   * Takes written tests off the pending set unless their entry changed since pending() returned it. For the drainer.
   *
   * @param array<int, string|false> $written test id => entry, as pending() returned it
   * @return int the number of settled tests; -1 when the cache server failed, then all of them stay pending and are
   *   written again
   */
  public static function settle(array $written): int {
    if (!$written) {
      return 0;
    }
    $redis = CacheService::connection();
    if (!$redis) {
      return -1;
    }
    $args = [self::KEY_PENDING, self::PREFIX, (string) SystemConfig::$cache_authTokenTtl];
    foreach ($written as $testId => $raw) {
      $args[] = (string) $testId;
      $args[] = ($raw === false) ? '' : $raw;
    }
    try {
      $settled = $redis->eval(self::SETTLE_SCRIPT, $args, 1);
    } catch (Throwable) {
      return -1;
    }
    return is_int($settled) ? $settled : -1;
  }

  /**
   * The number of pending tests, or null when the cache server is not reachable.
   */
  public static function depth(): ?int {
    $redis = CacheService::connection();
    if (!$redis) {
      return null;
    }
    try {
      $depth = $redis->sCard(self::KEY_PENDING);
    } catch (Throwable) {
      return null;
    }
    return is_int($depth) ? $depth : null;
  }

  /**
   * Reads and resets the number of state updates written directly because the cache server refused them. 0 when the
   * cache server is not reachable.
   */
  public static function takeFallbackCount(): int {
    $redis = CacheService::connection();
    if (!$redis) {
      return 0;
    }
    try {
      $count = $redis->getDel(self::KEY_FALLBACKS);
    } catch (Throwable) {
      return 0;
    }
    return is_string($count) ? (int) $count : 0;
  }

  /**
   * @return array{laststate: ?string, person_id: int}|null null for anything that is not a valid entry
   */
  public static function decode(mixed $raw): ?array {
    if (!is_string($raw) or ($raw === '')) {
      return null;
    }
    $entry = json_decode($raw, true);
    if (
      !is_array($entry) or
      !is_int($entry['p'] ?? null) or
      !array_key_exists('s', $entry) or
      !(is_string($entry['s']) or ($entry['s'] === null))
    ) {
      return null;
    }
    return ['laststate' => $entry['s'], 'person_id' => $entry['p']];
  }

  private static function encode(int $personId, ?string $laststate): string {
    return (string) json_encode(['p' => $personId, 's' => $laststate]);
  }

  private static function countFallback(Redis $redis): void {
    try {
      $redis->incr(self::KEY_FALLBACKS);
    } catch (Throwable) {
      // a cache server that refused the state most likely refuses this as well
    }
  }
}
