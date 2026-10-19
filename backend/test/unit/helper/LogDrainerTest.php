<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class LogDrainerTest extends TestCase {
  private RedisFake $redis;
  private TestDAO $testDAO;
  /** @var list<string> */
  private array $logged = [];

  function setUp(): void {
    require_once "test/unit/TestDB.class.php";
    require_once "test/unit/mock-classes/RedisFake.php";

    TestDB::setUp();
    $this->testDAO = new TestDAO();
    $this->testDAO->runFile(__DIR__ . '/../testdata.sql');

    SystemConfig::$cacheServer_host = 'cache-server';
    SystemConfig::$cacheServer_port = 6379;
    SystemConfig::$cacheServer_password = 'secret';
    SystemConfig::$testLogs_async = true;
    $this->redis = new RedisFake();
    (new ReflectionProperty(CacheService::class, 'redis'))->setValue(null, $this->redis);
  }

  private function drainer(int $batchSize): LogDrainer {
    return new LogDrainer($batchSize, function(string $message): void {
      $this->logged[] = $message;
    });
  }

  function test_drainBatch_insertsOneBatch(): void {
    LogBuffer::push([new TestLog(1, 'DRAINED_A', 11), new TestLog(1, 'DRAINED_B', 12, 'content')]);
    LogBuffer::push([new TestLog(1, 'DRAINED_C', 13)]);
    $drainer = $this->drainer(2);

    $this->assertSame(2, $drainer->drainBatch($this->testDAO));
    $this->assertSame(['DRAINED_A', 'DRAINED_B : content'], $this->drainedEntries());
    $this->assertSame(1, LogBuffer::depth());

    $this->assertSame(1, $drainer->drainBatch($this->testDAO));
    $this->assertSame(0, $drainer->drainBatch($this->testDAO));
    $this->assertSame(['DRAINED_A', 'DRAINED_B : content', 'DRAINED_C'], $this->drainedEntries());
    $this->assertStringStartsWith('depth=0 inserted=3 batches=2 rows_per_batch=1.5 discarded=0', $drainer->report());
  }

  function test_drainBatch_discardsOnlyRejectedRows(): void {
    // the test of the second row does not exist (any more), so the foreign key rejects it
    LogBuffer::push([new TestLog(1, 'DRAINED_A', 11), new TestLog(999999, 'DRAINED_X', 12), new TestLog(1, 'DRAINED_B', 13)]);
    $drainer = $this->drainer(10);

    $this->assertSame(3, $drainer->drainBatch($this->testDAO));

    $this->assertSame(['DRAINED_A', 'DRAINED_B'], $this->drainedEntries());
    $this->assertStringContainsString('discarded=1', $drainer->report());
    $this->assertStringContainsString('discarded 1 rows', implode("\n", $this->logged));
  }

  function test_drainBatch_throwsWhenNoRowCanBeInserted(): void {
    LogBuffer::push([new TestLog(999999, 'DRAINED_X', 12), new TestLog(999998, 'DRAINED_Y', 13)]);
    $drainer = $this->drainer(10);

    try {
      $drainer->drainBatch($this->testDAO);
      $this->fail('expected RuntimeException');
    } catch (RuntimeException) {
    }

    $this->assertSame(0, LogBuffer::depth(), 'the batch must not be queued again (at most once)');
    $this->assertStringContainsString('discarded=2', $drainer->report());
  }

  function test_drainBatch_redisUnavailable(): void {
    $this->redis->failing = true;

    $this->assertSame(0, $this->drainer(10)->drainBatch($this->testDAO));
    $this->assertStringStartsWith('depth=cache-server-unavailable', $this->drainer(10)->report());
  }

  function test_report_includesRefusedRows(): void {
    $this->redis->lists['test-logs:queue'] = array_fill(0, LogBuffer::MAX_DEPTH, '[1,"key",1,""]');
    LogBuffer::push([new TestLog(1, 'REFUSED', 1)]);
    $drainer = $this->drainer(10);

    $this->assertStringEndsWith('refused=1', $drainer->report());
    $this->assertStringEndsWith('refused=0', $drainer->report());
  }

  function test_batchSize_mustFitIntoOneStatement(): void {
    $this->drainer(LogDrainer::MAX_BATCH_SIZE);

    $this->expectException(InvalidArgumentException::class);
    $this->drainer(LogDrainer::MAX_BATCH_SIZE + 1);
  }

  function test_batchSize_mustBePositive(): void {
    $this->expectException(InvalidArgumentException::class);
    $this->drainer(0);
  }

  function test_maxBatchSize_isAcceptedByTheDatabase(): void {
    $testLogs = array_fill(0, LogDrainer::MAX_BATCH_SIZE, new TestLog(1, 'DRAINED_MAX', 1));

    $this->testDAO->insertTestLogs($testLogs);

    $count = $this->testDAO->_("select count(*) as c from test_logs where logentry = 'DRAINED_MAX'");
    $this->assertSame(LogDrainer::MAX_BATCH_SIZE, (int) ($count['c'] ?? 0));
  }

  /** @return list<string> */
  private function drainedEntries(): array {
    $rows = $this->testDAO->_(
      "select logentry from test_logs where logentry like 'DRAINED_%' order by timestamp",
      [],
      true
    ) ?? [];
    return array_values(array_map(fn(array $row): string => (string) $row['logentry'], $rows));
  }
}
