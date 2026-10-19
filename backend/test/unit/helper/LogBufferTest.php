<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class LogBufferTest extends TestCase {
  private RedisFake $redis;

  static function setUpBeforeClass(): void {
    require_once "test/unit/mock-classes/RedisFake.php";
  }

  function setUp(): void {
    SystemConfig::$cacheServer_host = 'cache-server';
    SystemConfig::$cacheServer_port = 6379;
    SystemConfig::$cacheServer_password = 'secret';
    SystemConfig::$testLogs_async = true;
    $this->redis = new RedisFake();
    self::useRedis($this->redis);
  }

  private static function useRedis(?Redis $redis): void {
    (new ReflectionProperty(CacheService::class, 'redis'))->setValue(null, $redis);
  }

  function test_switch_isOffByDefault(): void {
    putenv('TESTCENTER_ASYNC_TEST_LOGS');
    SystemConfig::readEnvironment();

    $this->assertFalse(LogBuffer::enabled());
  }

  function test_switch_readFromEnvironment(): void {
    putenv('TESTCENTER_ASYNC_TEST_LOGS=1');
    SystemConfig::readEnvironment();

    $this->assertTrue(LogBuffer::enabled());
  }

  function test_drainerSettings_readFromEnvironment(): void {
    putenv('LOG_DRAIN_BATCH');
    putenv('LOG_DRAIN_IDLE_MS=50');
    putenv('LOG_DRAIN_REPORT_SEC=10');
    SystemConfig::readEnvironment();

    $this->assertSame(5000, SystemConfig::$testLogs_drainBatchSize);
    $this->assertSame(50, SystemConfig::$testLogs_drainIdleMs);
    $this->assertSame(10, SystemConfig::$testLogs_drainReportSeconds);
  }

  function test_push_switchedOff_doesNotTouchRedis(): void {
    SystemConfig::$testLogs_async = false;

    $this->assertFalse(LogBuffer::push([new TestLog(1, 'key', 1, 'content')]));
    $this->assertSame(0, $this->redis->calls);
  }

  function test_push_and_take(): void {
    $first = new TestLog(1, 'first', 1700000000000, '"some content"');
    $second = new TestLog(2, 'second', 1700000000001);

    $this->assertTrue(LogBuffer::push([$first, $second]));
    $this->assertTrue(LogBuffer::push([new TestLog(3, 'third', 3)]));
    $this->assertSame(3, LogBuffer::depth());

    $this->assertEquals([$first, $second], LogBuffer::take(2));
    $this->assertEquals([new TestLog(3, 'third', 3)], LogBuffer::take(2));
    $this->assertSame([], LogBuffer::take(2));
    $this->assertSame(0, LogBuffer::depth());
  }

  function test_push_nothing(): void {
    $this->assertFalse(LogBuffer::push([]));
    $this->assertSame(0, $this->redis->calls);
  }

  function test_push_fullQueue_isRefusedAndCounted(): void {
    $this->redis->lists['test-logs:queue'] = array_fill(0, LogBuffer::MAX_DEPTH, '[1,"key",1,""]');

    $this->assertFalse(LogBuffer::push([new TestLog(1, 'a', 1), new TestLog(1, 'b', 2)]));
    $this->assertFalse(LogBuffer::push([new TestLog(1, 'c', 3)]));

    $this->assertSame(LogBuffer::MAX_DEPTH, LogBuffer::depth());
    $this->assertSame(3, LogBuffer::takeRefusedCount());
    $this->assertSame(0, LogBuffer::takeRefusedCount());
  }

  function test_push_rowThatCannotBeEncoded_isRefused(): void {
    $this->assertFalse(LogBuffer::push([new TestLog(1, 'fine', 1), new TestLog(1, "invalid \xB1\x31", 2)]));

    $this->assertSame(0, LogBuffer::depth());
  }

  function test_take_dropsEntriesThatCannotBeRead(): void {
    $this->redis->lists['test-logs:queue'] = [
      'not json',
      '[1,"too few fields",1]',
      '["1","wrong type",1,""]',
      '[4,"fine",5,"content"]'
    ];

    $this->assertEquals([new TestLog(4, 'fine', 5, 'content')], LogBuffer::take(10));
    $this->assertSame(0, LogBuffer::depth());
  }

  function test_failingRedis_fallsBack(): void {
    $this->redis->failing = true;

    $this->assertFalse(LogBuffer::push([new TestLog(1, 'key', 1)]));
    $this->assertSame([], LogBuffer::take(10));
    $this->assertNull(LogBuffer::depth());
    $this->assertSame(0, LogBuffer::takeRefusedCount());
  }

  function test_unreachableRedis_fallsBack(): void {
    self::useRedis(null);
    SystemConfig::$cacheServer_host = '127.0.0.1';
    SystemConfig::$cacheServer_port = 1;

    $this->assertFalse(LogBuffer::push([new TestLog(1, 'key', 1)]));
    $this->assertSame([], LogBuffer::take(10));
    $this->assertNull(LogBuffer::depth());
  }

  function test_noCacheServerConfigured_fallsBack(): void {
    self::useRedis(null);
    SystemConfig::$cacheServer_host = '';

    $this->assertFalse(LogBuffer::push([new TestLog(1, 'key', 1)]));
  }
}
