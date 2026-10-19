<?php
declare(strict_types=1);

/**
 * In-memory stand-in for the Redis client, covering the commands CacheService uses for the auth-token cache and
 * LogBuffer uses for the test-log queue. Expiry is recorded but not enforced. With $failing set, every command throws
 * like a dropped connection.
 *
 * Lua scripts cannot run here: eval() knows LogBuffer::PUSH_SCRIPT only and reproduces what it does.
 */
class RedisFake extends Redis {
  /** @var array<string, string> */
  public array $store = [];
  /** @var array<string, int|null> key => ttl in seconds as passed to set() */
  public array $ttl = [];
  /** @var array<string, list<string>> */
  public array $lists = [];
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

  public function set(string $key, mixed $value, mixed $options = null): Redis|string|bool {
    $this->call();
    $this->store[$key] = (string) $value;
    $this->ttl[$key] = is_int($options) ? $options : null;
    return true;
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
