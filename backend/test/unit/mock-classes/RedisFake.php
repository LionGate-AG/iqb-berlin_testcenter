<?php
declare(strict_types=1);

/**
 * In-memory stand-in for the Redis client, covering the commands CacheService uses for the auth-token cache,
 * LogBuffer for the test-log queue and TestStateBuffer for the test states. Expiry is recorded but not enforced. With
 * $failing set, every command throws like a dropped connection.
 *
 * Lua scripts cannot run here: eval() knows the scripts of LogBuffer and TestStateBuffer and reproduces what they do.
 */
class RedisFake extends Redis {
  /** @var array<string, string> */
  public array $store = [];
  /** @var array<string, int|null> key => ttl in seconds as passed to set() */
  public array $ttl = [];
  /** @var array<string, list<string>> */
  public array $lists = [];
  /** @var array<string, array<array-key, true>> */
  public array $sets = [];
  public bool $failing = false;
  public int $calls = 0;

  public function __construct() {
    // deliberately no parent::__construct(): nothing to connect to
  }

  private function call(): void {
    $this->calls++;
    if ($this->failing) {
      throw new RedisException('Connection lost');
    }
  }

  public function get(string $key): mixed {
    $this->call();
    return $this->store[$key] ?? false;
  }

  /** Options: an int (the expiry) or an array with `nx` and `ex`, like phpredis. */
  public function set(string $key, mixed $value, mixed $options = null): Redis|string|bool {
    $this->call();
    if (is_array($options) and in_array('nx', $options, true) and array_key_exists($key, $this->store)) {
      return false;
    }
    $this->store[$key] = (string) $value;
    $expiry = is_array($options) ? ($options['ex'] ?? null) : $options;
    $this->ttl[$key] = is_int($expiry) ? $expiry : null;
    return true;
  }

  public function mget(array $keys): Redis|array {
    $this->call();
    return array_map(fn(string $key): string|false => $this->store[$key] ?? false, array_values($keys));
  }

  public function expire(string $key, int $timeout, ?string $mode = null): Redis|bool {
    $this->call();
    if (!array_key_exists($key, $this->store)) {
      return false;
    }
    $this->ttl[$key] = $timeout;
    return true;
  }

  public function incr(string $key, int $by = 1): Redis|int|false {
    $this->call();
    $this->store[$key] = (string) ((int) ($this->store[$key] ?? 0) + $by);
    return (int) $this->store[$key];
  }

  public function sAdd(string $key, mixed $value, mixed ...$other_values): Redis|int|false {
    $this->call();
    $added = 0;
    foreach ([$value, ...$other_values] as $member) {
      if (!isset($this->sets[$key][(string) $member])) {
        $this->sets[$key][(string) $member] = true;
        $added++;
      }
    }
    return $added;
  }

  public function srem(string $key, mixed $value, mixed ...$other_values): Redis|int|false {
    $this->call();
    $removed = 0;
    foreach ([$value, ...$other_values] as $member) {
      if (isset($this->sets[$key][(string) $member])) {
        unset($this->sets[$key][(string) $member]);
        $removed++;
      }
    }
    return $removed;
  }

  /** Not random here: the first $count members in the order they were added. */
  public function sRandMember(string $key, int $count = 0): Redis|array|false|string {
    $this->call();
    return array_map('strval', array_slice(array_keys($this->sets[$key] ?? []), 0, $count));
  }

  public function scard(string $key): Redis|int|false {
    $this->call();
    return count($this->sets[$key] ?? []);
  }

  public function del(array|string $key, string ...$other_keys): Redis|int|false {
    $this->call();
    $keys = array_merge(is_array($key) ? $key : [$key], $other_keys);
    $deleted = 0;
    foreach ($keys as $k) {
      if (is_string($k) and array_key_exists($k, $this->store)) {
        unset($this->store[$k], $this->ttl[$k]);
        $deleted++;
      }
    }
    return $deleted;
  }

  public function unlink(array|string $key, string ...$other_keys): Redis|int|false {
    return $this->del($key, ...$other_keys);
  }

  public function setOption(int $option, mixed $value): bool {
    $this->call();
    return true;
  }

  /** Returns all matches in one batch, then signals the end of the iteration like phpredis does. */
  public function scan(null|int|string &$iterator, ?string $pattern = null, int $count = 0, ?string $type = null): array|false {
    $this->call();
    if ($iterator === 0) {
      return false;
    }
    $iterator = 0;
    $matches = array_values(array_filter(
      array_keys($this->store),
      fn(string $key): bool => ($pattern === null) or fnmatch($pattern, $key)
    ));
    return $matches ?: false;
  }

  public function eval(string $script, array $args = [], int $num_keys = 0): mixed {
    $this->call();
    /** @var list<string> $args */
    if ($script === TestStateBuffer::PUSH_SCRIPT) {
      return $this->evalTestStatePush($args);
    }
    if ($script === TestStateBuffer::SETTLE_SCRIPT) {
      return $this->evalTestStateSettle($args);
    }
    if ($script !== LogBuffer::PUSH_SCRIPT) {
      throw new LogicException('RedisFake does not know this script.');
    }
    /** @var list<string> $args KEYS[1] the queue, KEYS[2] the counter, ARGV[1] the cap, ARGV[2..] the rows */
    $queue = $args[0];
    $refusedCounter = $args[1];
    $cap = (int) $args[2];
    $rows = array_slice($args, 3);
    if (count($this->lists[$queue] ?? []) >= $cap) {
      $this->store[$refusedCounter] = (string) ((int) ($this->store[$refusedCounter] ?? 0) + count($rows));
      return -1;
    }
    $this->lists[$queue] = array_merge($this->lists[$queue] ?? [], $rows);
    return count($this->lists[$queue]);
  }

  /** @param list<string> $args KEYS[1] the entry, KEYS[2] the pending set, ARGV[1] the entry's value, ARGV[2] the id */
  private function evalTestStatePush(array $args): int {
    [$key, $pendingSet, $value, $testId] = $args;
    $this->store[$key] = $value;
    $this->ttl[$key] = null;
    $this->sets[$pendingSet][$testId] = true;
    return 1;
  }

  /** @param list<string> $args KEYS[1] the pending set, ARGV[1] the prefix, ARGV[2] the TTL, then id and entry pairs */
  private function evalTestStateSettle(array $args): int {
    [$pendingSet, $prefix, $ttl] = $args;
    $settled = 0;
    for ($i = 3; $i < count($args); $i += 2) {
      $key = $prefix . $args[$i];
      $current = $this->store[$key] ?? false;
      if ((($current === false) and ($args[$i + 1] === '')) or ($current === $args[$i + 1])) {
        unset($this->sets[$pendingSet][$args[$i]]);
        if ($current !== false) {
          if ((int) $ttl > 0) {
            $this->ttl[$key] = (int) $ttl;
          } else {
            unset($this->store[$key], $this->ttl[$key]);
          }
        }
        $settled++;
      }
    }
    return $settled;
  }

  public function lPop(string $key, int $count = 0): Redis|array|bool|string {
    $this->call();
    if (empty($this->lists[$key])) {
      return false;
    }
    if ($count === 0) {
      return array_shift($this->lists[$key]);
    }
    return array_splice($this->lists[$key], 0, $count);
  }

  public function lLen(string $key): Redis|int|false {
    $this->call();
    return count($this->lists[$key] ?? []);
  }

  public function getDel(string $key): Redis|string|bool {
    $this->call();
    $value = $this->store[$key] ?? false;
    unset($this->store[$key], $this->ttl[$key]);
    return $value;
  }

  public function ping(?string $message = null): Redis|string|bool {
    $this->call();
    return true;
  }

  public function close(): bool {
    return true;
  }
}
