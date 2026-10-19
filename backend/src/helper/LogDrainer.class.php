<?php
declare(strict_types=1);

/**
 * Writes the rows queued in LogBuffer to `test_logs`, one multi-row INSERT per batch. Run by backend/drain-logs.php.
 *
 * A batch the database rejects is retried row by row, and only the rows that fail on their own are discarded, so
 * one bad row (e.g. of a test deleted after the row was queued) cannot block the queue or take the rest of its batch
 * with it. Nothing is ever put back into the queue: delivery is at most once, see LogBuffer.
 */
class LogDrainer {
  /** PostgreSQL accepts at most 65,535 bound parameters in one statement, and a row takes three. */
  public const int MAX_BATCH_SIZE = 21845;

  private int $inserted = 0;
  private int $discarded = 0;
  private int $batches = 0;

  /**
   * @param Closure(string): void $log
   * @throws InvalidArgumentException if $batchSize is out of range
   */
  public function __construct(
    private readonly int $batchSize,
    private readonly Closure $log
  ) {
    if (($batchSize < 1) or ($batchSize > self::MAX_BATCH_SIZE)) {
      throw new InvalidArgumentException(
        "The batch size must be between 1 and " . self::MAX_BATCH_SIZE . ", got $batchSize."
      );
    }
  }

  /**
   * Takes one batch off the queue and inserts it.
   *
   * @return int the number of rows taken off the queue; 0 when it is empty or the cache server is not reachable
   * @throws RuntimeException if not a single row of the batch could be inserted, which points to a lost database
   *   connection rather than to bad rows. The batch is lost.
   */
  public function drainBatch(TestDAO $testDAO): int {
    $testLogs = LogBuffer::take($this->batchSize);
    if (empty($testLogs)) {
      return 0;
    }

    try {
      $testDAO->insertTestLogs($testLogs);
      $this->inserted += count($testLogs);
      $this->batches++;
      return count($testLogs);
    } catch (Throwable $exception) {
      ($this->log)('batch of ' . count($testLogs) . ' rows failed (' . $exception->getMessage() . '), retrying row by row');
    }

    $failed = 0;
    foreach ($testLogs as $testLog) {
      try {
        $testDAO->insertTestLogs([$testLog]);
        $this->inserted++;
      } catch (Throwable) {
        $failed++;
      }
    }
    $this->discarded += $failed;
    $this->batches++;

    if ($failed === count($testLogs)) {
      throw new RuntimeException("none of the $failed rows of the batch could be inserted, they are discarded");
    }
    if ($failed > 0) {
      ($this->log)("discarded $failed rows the database rejected");
    }
    return count($testLogs);
  }

  /**
   * One line with the queue depth and the counts since start, plus the rows refused since the last report because
   * the queue was full. A depth that keeps growing means the drainer does not keep up or is stuck; refused rows mean
   * the backend wrote them synchronously.
   */
  public function report(): string {
    $depth = LogBuffer::depth();
    return sprintf(
      'depth=%s inserted=%d batches=%d rows_per_batch=%.1f discarded=%d refused=%d',
      $depth ?? 'cache-server-unavailable',
      $this->inserted,
      $this->batches,
      $this->batches ? $this->inserted / $this->batches : 0.0,
      $this->discarded,
      LogBuffer::takeRefusedCount()
    );
  }
}
