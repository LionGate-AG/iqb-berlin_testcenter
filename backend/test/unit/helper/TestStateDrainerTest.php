<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once "test/unit/test-helper/UsesTestStateCache.php";

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TestStateDrainerTest extends TestCase {
  use UsesTestStateCache;

  private RedisFake $redis;
  private TestDAO $testDAO;
  /** @var list<string> */
  private array $logged = [];

  function setUp(): void {
    require_once "test/unit/TestDB.class.php";

    TestDB::setUp();
    $this->testDAO = new TestDAO();
    $this->testDAO->runFile(__DIR__ . '/../testdata.sql');
    $this->redis = $this->enableTestStateCache(true);
  }

  private function drainer(int $batchSize = 10, int $intervalMs = 2000): TestStateDrainer {
    return new TestStateDrainer($batchSize, $intervalMs, function(string $message): void {
      $this->logged[] = $message;
    });
  }

  function test_drainRound_writesAllPendingStates(): void {
    TestStateBuffer::push(1, 1, '{"CURRENT_UNIT_ID":"UNIT_2"}');
    TestStateBuffer::push(2, 1, '{"CURRENT_UNIT_ID":"UNIT_3"}');
    TestStateBuffer::push(3, 5, '{"FOCUS":"HAS"}');
    $this->assertSame(['CURRENT_UNIT_ID' => 'UNIT_1'], $this->tableState(1), 'the table lags');

    $drainer = $this->drainer(2);
    $this->assertSame(3, $drainer->drainRound($this->testDAO), 'a full batch is followed by the next one');

    $this->assertSame(['CURRENT_UNIT_ID' => 'UNIT_2'], $this->tableState(1));
    $this->assertSame(['CURRENT_UNIT_ID' => 'UNIT_3'], $this->tableState(2));
    $this->assertSame(['FOCUS' => 'HAS'], $this->tableState(3));
    $this->assertSame(0, TestStateBuffer::depth());
    $this->assertSame(300, $this->redis->ttl['test-state:1'], 'written entries expire');
    $this->assertSame(0, $drainer->drainRound($this->testDAO));
    $this->assertStringStartsWith(
      'pending=0 written=3 batches=2 rows_per_batch=1.5 missing=0 discarded=0 fallbacks=0',
      $drainer->report()
    );
  }

  function test_drainRound_stateChangedDuringTheWrite_staysPending(): void {
    TestStateBuffer::push(1, 1, '{"CURRENT_UNIT_ID":"UNIT_2"}');
    $testDAO = new class extends TestDAO {
      public bool $patchArrived = false;

      public function writeTestStates(array $laststates): int {
        $written = parent::writeTestStates($laststates);
        if (!$this->patchArrived) {
          $this->patchArrived = true;
          TestStateBuffer::push(1, 1, '{"CURRENT_UNIT_ID":"UNIT_3"}');
        }
        return $written;
      }
    };

    // a batch that is not full ends the round
    $this->drainer(2)->drainRound($testDAO);

    $this->assertSame(['CURRENT_UNIT_ID' => 'UNIT_2'], $this->tableState(1));
    $this->assertSame(1, TestStateBuffer::depth(), 'the newer state must not be lost');
    $this->assertSame('{"CURRENT_UNIT_ID":"UNIT_3"}', TestStateBuffer::get(1)['laststate'] ?? null);

    $this->drainer(2)->drainRound($testDAO);

    $this->assertSame(['CURRENT_UNIT_ID' => 'UNIT_3'], $this->tableState(1));
    $this->assertSame(0, TestStateBuffer::depth());
  }

  function test_drainRound_deletedTest_isCountedAsMissingAndSettled(): void {
    TestStateBuffer::push(1, 1, '{"A":"1"}');
    TestStateBuffer::push(999999, 1, '{"A":"2"}');
    $drainer = $this->drainer();

    $this->assertSame(2, $drainer->drainRound($this->testDAO));

    $this->assertSame(0, TestStateBuffer::depth());
    $this->assertStringContainsString('written=1 batches=1 rows_per_batch=2.0 missing=1', $drainer->report());
  }

  function test_drainRound_rejectedState_isDiscardedAlone(): void {
    TestStateBuffer::push(1, 1, '{"A":"1"}');
    // valid JSON, but jsonb does not accept the NUL character
    TestStateBuffer::push(2, 1, '{"A":"\u0000"}');
    $drainer = $this->drainer();

    $drainer->drainRound($this->testDAO);

    $this->assertSame(['A' => '1'], $this->tableState(1));
    $this->assertSame([], $this->tableState(2));
    $this->assertSame(0, TestStateBuffer::depth(), 'a rejected state must not block the buffer');
    $this->assertStringContainsString('discarded=1', $drainer->report());
    $this->assertStringContainsString('discarded the state of test 2', implode("\n", $this->logged));
  }

  function test_drainRound_databaseGone_keepsEverythingPending(): void {
    TestStateBuffer::push(1, 1, '{"A":"1"}');
    $testDAO = new class extends TestDAO {
      public function _(string $sql, array $replacements = [], $multiRow = false): ?array {
        throw new Exception('server closed the connection unexpectedly');
      }
    };

    try {
      $this->drainer()->drainRound($testDAO);
      $this->fail('expected RuntimeException');
    } catch (RuntimeException) {
    }

    $this->assertSame(1, TestStateBuffer::depth());
    $this->drainer()->drainRound($this->testDAO);
    $this->assertSame(['A' => '1'], $this->tableState(1));
  }

  function test_drainRound_redisUnavailable(): void {
    TestStateBuffer::push(1, 1, '{"A":"1"}');
    $this->redis->failing = true;

    $this->assertSame(0, $this->drainer()->drainRound($this->testDAO));
    $this->assertStringStartsWith('pending=cache-server-unavailable', $this->drainer()->report());
  }

  function test_report_includesFallbacks(): void {
    $this->redis->store['test-state:fallbacks'] = '4';
    $drainer = $this->drainer();

    $this->assertStringContainsString('fallbacks=4', $drainer->report());
    $this->assertStringContainsString('fallbacks=0', $drainer->report());
  }

  function test_batchSize_mustFitIntoOneStatement(): void {
    $this->drainer(TestStateDrainer::MAX_BATCH_SIZE);

    $this->expectException(InvalidArgumentException::class);
    $this->drainer(TestStateDrainer::MAX_BATCH_SIZE + 1);
  }

  function test_batchSize_mustBePositive(): void {
    $this->expectException(InvalidArgumentException::class);
    $this->drainer(0);
  }

  function test_interval_hasAMinimum(): void {
    $this->drainer(10, TestStateDrainer::MIN_INTERVAL_MS);

    $this->expectException(InvalidArgumentException::class);
    $this->drainer(10, TestStateDrainer::MIN_INTERVAL_MS - 1);
  }

  function test_maxBatchSize_isAcceptedByTheDatabase(): void {
    $laststates = array_fill_keys(range(1, TestStateDrainer::MAX_BATCH_SIZE), '{"A":"1"}');

    $this->assertSame(3, $this->testDAO->writeTestStates($laststates));
  }

  /** @return array<string, mixed> */
  private function tableState(int $testId): array {
    $row = $this->testDAO->_('select laststate from tests where id = :id', [':id' => $testId]);
    $laststate = $row['laststate'] ?? null;
    /** @var array<string, mixed>|null $state */
    $state = JSON::decode(is_string($laststate) ? $laststate : '{}', true);
    return $state ?? [];
  }
}
