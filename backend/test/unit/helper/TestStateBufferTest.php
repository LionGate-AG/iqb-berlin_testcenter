<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once "test/unit/test-helper/UsesTestStateCache.php";

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TestStateBufferTest extends TestCase {
  use UsesTestStateCache;

  private RedisFake $redis;

  function setUp(): void {
    $this->redis = $this->enableTestStateCache(true);
  }

  private static function useRedis(?Redis $redis): void {
    (new ReflectionProperty(CacheService::class, 'redis'))->setValue(null, $redis);
  }

  function test_switch_isOffByDefault(): void {
    putenv('TESTCENTER_BUFFER_TEST_STATE');
    SystemConfig::readEnvironment();

    $this->assertFalse(SystemConfig::$testState_buffer);
  }

  function test_switch_readFromEnvironment(): void {
    putenv('TESTCENTER_BUFFER_TEST_STATE=1');
    putenv('TESTCENTER_CACHE_AUTH_TOKEN=1');
    SystemConfig::readEnvironment();

    $this->assertTrue(TestStateBuffer::bufferEnabled());
  }

  function test_switch_requiresTheCache(): void {
    SystemConfig::$cache_authToken = false;

    $this->assertFalse(TestStateBuffer::cacheEnabled());
    $this->assertFalse(TestStateBuffer::bufferEnabled());
    $this->assertFalse(TestStateBuffer::push(1, 1, '{}'));
    $this->assertNull(TestStateBuffer::get(1));
    $this->assertSame(0, $this->redis->calls);
  }

  function test_drainerSettings_readFromEnvironment(): void {
    putenv('STATE_DRAIN_INTERVAL_MS');
    putenv('STATE_DRAIN_BATCH=100');
    putenv('STATE_DRAIN_REPORT_SEC=10');
    SystemConfig::readEnvironment();

    $this->assertSame(2000, SystemConfig::$testState_drainIntervalMs);
    $this->assertSame(100, SystemConfig::$testState_drainBatchSize);
    $this->assertSame(10, SystemConfig::$testState_drainReportSeconds);
  }

  function test_drainerSettings_mustBeNumbers(): void {
    putenv('STATE_DRAIN_BATCH=many');

    $this->expectException(Exception::class);
    SystemConfig::readEnvironment();
  }

  function test_push_switchedOff_doesNotTouchRedis(): void {
    SystemConfig::$testState_buffer = false;

    $this->assertFalse(TestStateBuffer::push(1, 1, '{}'));
    $this->assertSame(0, $this->redis->calls);
  }

  function test_push_writesThePendingEntryWithoutExpiry(): void {
    TestStateBuffer::store(5, 7, '{"A":"old"}');

    $this->assertTrue(TestStateBuffer::push(5, 7, '{"A":"new"}'));

    $this->assertNull($this->redis->ttl['test-state:5'], 'a pending entry must not expire');
    $this->assertSame(['laststate' => '{"A":"new"}', 'person_id' => 7], TestStateBuffer::get(5));
    $this->assertSame([5 => '{"p":7,"s":"{\"A\":\"new\"}"}'], TestStateBuffer::pending(10));
    $this->assertSame(1, TestStateBuffer::depth());
  }

  function test_push_refused_fallsBackAndIsCounted(): void {
    // like a cache server whose memory is full: it refuses writes, but still answers
    $redis = new class extends RedisFake {
      public function eval(string $script, array $args = [], int $num_keys = 0): mixed {
        throw new RedisException('OOM command not allowed when used memory > \'maxmemory\'.');
      }
    };
    self::useRedis($redis);

    $this->assertFalse(TestStateBuffer::push(5, 7, '{}'));
    $this->assertFalse(TestStateBuffer::push(6, 7, '{}'));

    $this->assertSame(0, TestStateBuffer::depth());
    $this->assertSame(2, TestStateBuffer::takeFallbackCount());
    $this->assertSame(0, TestStateBuffer::takeFallbackCount());
  }

  function test_failingRedis_fallsBack(): void {
    $this->redis->failing = true;

    $this->assertFalse(TestStateBuffer::push(5, 7, '{}'));
    $this->assertNull(TestStateBuffer::get(5));
    $this->assertSame([], TestStateBuffer::getLaststates([5]));
    $this->assertSame([], TestStateBuffer::pending(10));
    $this->assertSame(-1, TestStateBuffer::settle([5 => false]));
    $this->assertNull(TestStateBuffer::depth());
  }

  function test_unreachableRedis_fallsBack(): void {
    self::useRedis(null);
    SystemConfig::$cacheServer_host = '127.0.0.1';
    SystemConfig::$cacheServer_port = 1;

    $this->assertFalse(TestStateBuffer::push(5, 7, '{}'));
    $this->assertNull(TestStateBuffer::get(5));
    $this->assertNull(TestStateBuffer::depth());
  }

  function test_settle_takesUnchangedEntriesOffThePendingSet(): void {
    TestStateBuffer::push(5, 7, '{"A":"1"}');
    TestStateBuffer::push(6, 7, '{"B":"1"}');
    $pending = TestStateBuffer::pending(10);

    $this->assertSame(2, TestStateBuffer::settle($pending));

    $this->assertSame(0, TestStateBuffer::depth());
    $this->assertSame(300, $this->redis->ttl['test-state:5'], 'a settled entry expires like any other');
    $this->assertSame('{"A":"1"}', TestStateBuffer::get(5)['laststate'] ?? null);
  }

  function test_settle_keepsEntriesPendingThatChangedSinceTheyWereRead(): void {
    TestStateBuffer::push(5, 7, '{"A":"1"}');
    TestStateBuffer::push(6, 7, '{"B":"1"}');
    $pending = TestStateBuffer::pending(10);

    // a state update arrives while the drainer writes
    TestStateBuffer::push(5, 7, '{"A":"2"}');

    $this->assertSame(1, TestStateBuffer::settle($pending));

    $this->assertSame([5 => '{"p":7,"s":"{\"A\":\"2\"}"}'], TestStateBuffer::pending(10));
    $this->assertNull($this->redis->ttl['test-state:5']);
  }

  function test_settle_entryGone(): void {
    $this->redis->sets[TestStateBuffer::KEY_PENDING] = ['5' => true];
    $pending = TestStateBuffer::pending(10);
    $this->assertSame([5 => false], $pending);

    $this->assertSame(1, TestStateBuffer::settle($pending));
    $this->assertSame(0, TestStateBuffer::depth());
  }

  function test_settle_withoutTtl_deletesTheEntry(): void {
    TestStateBuffer::push(5, 7, '{}');
    SystemConfig::$cache_authTokenTtl = 0;

    TestStateBuffer::settle(TestStateBuffer::pending(10));

    $this->assertArrayNotHasKey('test-state:5', $this->redis->store);
  }

  function test_populate_neverReplacesAnEntry(): void {
    TestStateBuffer::push(5, 7, '{"A":"buffered"}');

    TestStateBuffer::populate(5, 7, '{"A":"from the table"}');
    TestStateBuffer::populate(6, 7, '{"B":"from the table"}');

    $this->assertSame('{"A":"buffered"}', TestStateBuffer::get(5)['laststate'] ?? null);
    $this->assertSame('{"B":"from the table"}', TestStateBuffer::get(6)['laststate'] ?? null);
    $this->assertSame(300, $this->redis->ttl['test-state:6']);
  }

  function test_store_failing_dropsTheEntry(): void {
    $redis = new class extends RedisFake {
      public function set(string $key, mixed $value, mixed $options = null): Redis|string|bool {
        throw new RedisException('read error on connection');
      }
    };
    $redis->store['test-state:5'] = '{"p":7,"s":"{\"A\":\"old\"}"}';
    self::useRedis($redis);

    TestStateBuffer::store(5, 7, '{"A":"new"}');

    $this->assertNull(TestStateBuffer::get(5), 'the next update must not merge into the old state');
  }

  function test_getLaststates_returnsTheEntriesThatExist(): void {
    TestStateBuffer::push(5, 7, '{"A":"1"}');
    TestStateBuffer::store(6, 8, null);

    $this->assertSame([5 => '{"A":"1"}', 6 => null], TestStateBuffer::getLaststates([5, 6, 9, 5]));
    $this->assertSame([], TestStateBuffer::getLaststates([]));
  }

  function test_drop_removesEntriesAndPendingMarks(): void {
    TestStateBuffer::push(5, 7, '{}');
    TestStateBuffer::push(6, 7, '{}');
    TestStateBuffer::store(9, 7, '{}');

    TestStateBuffer::drop([5, 9]);

    $this->assertNull(TestStateBuffer::get(5));
    $this->assertNull(TestStateBuffer::get(9));
    $this->assertSame([6], array_keys(TestStateBuffer::pending(10)));
  }

  function test_decode_rejectsWhatIsNoEntry(): void {
    $this->assertNull(TestStateBuffer::decode(false));
    $this->assertNull(TestStateBuffer::decode(''));
    $this->assertNull(TestStateBuffer::decode('not json'));
    $this->assertNull(TestStateBuffer::decode('{"p":"7","s":"{}"}'));
    $this->assertNull(TestStateBuffer::decode('{"p":7}'));
    $this->assertNull(TestStateBuffer::decode('{"p":7,"s":{}}'));
    $this->assertSame(['laststate' => null, 'person_id' => 7], TestStateBuffer::decode('{"p":7,"s":null}'));
  }
}
