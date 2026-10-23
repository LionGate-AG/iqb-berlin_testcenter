<?php
declare(strict_types=1);

/**
 * Queue for `test_logs` rows in the cache server (Redis), so that requests do not insert them one by one.
 *
 * With TESTCENTER_ASYNC_TEST_LOGS switched on, TestDAO::addTestLogs() appends the rows of a request to a Redis list
 * and returns. A single drainer process (backend/drain-logs.php, see LogDrainer) takes them off the list and writes
 * them with one multi-row INSERT per batch. Under many concurrent test-takers this replaces a large number of
 * single-row transactions from many concurrent writers by few large ones from one writer.
 *
 * Delivery is at most once: the drainer takes rows off the list before it inserts them. `test_logs` has no unique
 * key, so retrying a batch that may have been written would duplicate rows in the workspace log export; losing rows
 * in a failure was chosen over that. Rows are lost when
 * - the cache server restarts: it runs without persistence, everything still queued is gone;
 * - the drainer dies or loses the database while it writes: the batch it holds is gone.
 *
 * Whenever the rows cannot be queued - the switch is off, the cache server is not configured or not reachable, or
 * the queue is over MAX_DEPTH - push() returns false and the caller inserts them synchronously, as without the
 * switch.
 *
 * The order of rows is not kept across batches. It does not have to be: the log export sorts by the timestamp the
 * client sent.
 */
class LogBuffer {
  private const string KEY = 'test-logs:queue';
  /** rows refused because the queue was full, since the drainer last read the counter */
  private const string KEY_REFUSED = 'test-logs:refused';

  /**
   * Beyond this many queued rows, push() refuses and the caller inserts synchronously.
   *
   * The queue has no expiry and must not get one: the cache server evicts only keys with an expiry when it is full
   * (`volatile-lru`), so an expiring queue could be dropped silently. The other side of that is that a queue which is
   * not drained (the drainer is stopped or too slow) grows until the eviction hits the keys that do expire - the
   * cached auth tokens and logins. The cap stops it well before that: 250,000 rows are about 50 MB.
   */
  public const int MAX_DEPTH = 250000;

  /**
   * Appends the rows only if the queue is below the cap, in one round trip and atomically, so concurrent requests
   * cannot push it beyond the cap. Returns the new length, or -1 if the queue is full; then the refused rows are
   * counted instead.
   *
   * KEYS[1] the queue, KEYS[2] the counter of refused rows, ARGV[1] the cap, ARGV[2..] the rows
   */
  public const string PUSH_SCRIPT = <<<'LUA'
    if redis.call('LLEN', KEYS[1]) >= tonumber(ARGV[1]) then
      redis.call('INCRBY', KEYS[2], #ARGV - 1)
      return -1
    end
    return redis.call('RPUSH', KEYS[1], unpack(ARGV, 2))
    LUA;

  public static function enabled(): bool {
    return SystemConfig::$testLogs_async;
  }

  /**
   * Queues rows for the drainer.
   *
   * @param TestLog[] $testLogs
   * @return bool true when the rows are queued and must not be inserted by the caller; false when the caller has to
   *   insert them itself
   */
  public static function push(array $testLogs): bool {
    if (!self::enabled() or empty($testLogs)) {
      return false;
    }

    $redis = CacheService::connection();
    if (!$redis) {
      return false;
    }

    $entries = [];
    foreach ($testLogs as $testLog) {
      $entry = self::encode($testLog);
      if ($entry === null) {
        return false;
      }
      $entries[] = $entry;
    }

    try {
      $length = $redis->eval(
        self::PUSH_SCRIPT,
        [self::KEY, self::KEY_REFUSED, (string) self::MAX_DEPTH, ...$entries],
        2
      );
    } catch (Throwable) {
      // A failure in transit leaves open whether the rows were queued. Inserting them as well may duplicate them;
      // that is accepted here because it is rare, and losing them is the alternative.
      return false;
    }
    return is_int($length) and ($length > 0);
  }

  /**
   * Takes up to $count rows off the queue. For the drainer only: rows taken are gone from the queue, whatever
   * happens to them afterwards.
   *
   * @return TestLog[] empty when the queue is empty or the cache server is not reachable
   */
  public static function take(int $count): array {
    $redis = CacheService::connection();
    if (!$redis) {
      return [];
    }

    try {
      $entries = $redis->lPop(self::KEY, $count);
    } catch (Throwable) {
      return [];
    }
    if (!is_array($entries)) {
      return [];
    }

    $testLogs = [];
    foreach ($entries as $entry) {
      $testLog = is_string($entry) ? self::decode($entry) : null;
      // an entry that cannot be read is dropped, it would block the queue otherwise
      if ($testLog !== null) {
        $testLogs[] = $testLog;
      }
    }
    return $testLogs;
  }

  /**
   * The number of queued rows, or null when the cache server is not reachable.
   */
  public static function depth(): ?int {
    $redis = CacheService::connection();
    if (!$redis) {
      return null;
    }
    try {
      $depth = $redis->lLen(self::KEY);
    } catch (Throwable) {
      return null;
    }
    return is_int($depth) ? $depth : null;
  }

  /**
   * Reads and resets the number of rows refused because the queue was full. 0 when the cache server is not reachable.
   */
  public static function takeRefusedCount(): int {
    $redis = CacheService::connection();
    if (!$redis) {
      return 0;
    }
    try {
      $count = $redis->getDel(self::KEY_REFUSED);
    } catch (Throwable) {
      return 0;
    }
    return is_string($count) ? (int) $count : 0;
  }

  /**
   * A positional JSON array: it does not depend on the class definition, so rows queued by an older backend version
   * can still be read after an update, and it can be inspected with redis-cli.
   */
  private static function encode(TestLog $testLog): ?string {
    try {
      return json_encode(
        [$testLog->testId, $testLog->logKey, $testLog->timestamp, $testLog->logContent],
        JSON_THROW_ON_ERROR
      );
    } catch (JsonException) {
      // e.g. invalid UTF-8; the caller inserts the rows synchronously and gets the database's verdict on them
      return null;
    }
  }

  private static function decode(string $entry): ?TestLog {
    $fields = json_decode($entry, true);
    if (
      !is_array($fields) or
      (count($fields) !== 4) or
      !is_int($fields[0] ?? null) or
      !is_string($fields[1] ?? null) or
      !is_int($fields[2] ?? null) or
      !is_string($fields[3] ?? null)
    ) {
      return null;
    }
    return new TestLog($fields[0], $fields[1], $fields[2], $fields[3]);
  }
}
