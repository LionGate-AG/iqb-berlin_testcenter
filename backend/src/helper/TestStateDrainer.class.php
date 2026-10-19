<?php
declare(strict_types=1);

/**
 * Writes the states buffered in TestStateBuffer to `tests`, one UPDATE per batch. Run by backend/drain-test-states.php.
 *
 * Nothing leaves the pending set before it is written, so a failure at any point - the database or the cache server
 * is gone, the process dies - only means the states are written in a later round. A batch the database rejects is
 * retried row by row: if the database is reachable, the rows that still fail are discarded, so that one bad state
 * cannot block the others for good.
 */
class TestStateDrainer {
  /** PostgreSQL accepts at most 65,535 bound parameters in one statement; a row takes two, the statement one more. */
  public const int MAX_BATCH_SIZE = 32767;
  /** below this, the drainer would mostly ask an empty set */
  public const int MIN_INTERVAL_MS = 100;

  private int $written = 0;
  private int $missing = 0;
  private int $discarded = 0;
  private int $batches = 0;
  private int $longestRoundMs = 0;

  /**
   * @param Closure(string): void $log
   * @throws InvalidArgumentException if $batchSize or $intervalMs is out of range
   */
  public function __construct(
    private readonly int $batchSize,
    private readonly int $intervalMs,
    private readonly Closure $log
  ) {
    if (($batchSize < 1) or ($batchSize > self::MAX_BATCH_SIZE)) {
      throw new InvalidArgumentException(
        "The batch size must be between 1 and " . self::MAX_BATCH_SIZE . ", got $batchSize."
      );
    }
    if ($intervalMs < self::MIN_INTERVAL_MS) {
      throw new InvalidArgumentException(
        "The interval must be at least " . self::MIN_INTERVAL_MS . " ms, got $intervalMs."
      );
    }
  }

  /**
   * Writes what is pending, batch by batch, until a batch is not full - everything pending at the start is written
   * then - or the interval is used up, which bounds a round in which states keep changing.
   *
   * @return int the number of states taken from the buffer; 0 when nothing is pending or the cache server is not
   *   reachable
   * @throws RuntimeException if the database cannot be reached; the batch stays pending
   */
  public function drainRound(TestDAO $testDAO): int {
    $start = (int) hrtime(true);
    $taken = 0;
    do {
      $pending = TestStateBuffer::pending($this->batchSize);
      if (!$pending) {
        break;
      }
      $taken += count($pending);
      $this->writeBatch($testDAO, $pending);
      TestStateBuffer::settle($pending);
      $elapsedMs = intdiv((int) hrtime(true) - $start, 1_000_000);
    } while ((count($pending) === $this->batchSize) and ($elapsedMs < $this->intervalMs));

    $this->longestRoundMs = max($this->longestRoundMs, intdiv((int) hrtime(true) - $start, 1_000_000));
    return $taken;
  }

  /**
   * @param array<int, string|false> $pending test id => entry, as TestStateBuffer::pending() returned it
   * @throws RuntimeException if the database cannot be reached
   */
  private function writeBatch(TestDAO $testDAO, array $pending): void {
    $laststates = [];
    foreach ($pending as $testId => $raw) {
      $entry = TestStateBuffer::decode($raw);
      // an entry that is gone or unreadable has nothing to write; settling takes the test off the pending set
      if (($entry !== null) and ($entry['laststate'] !== null)) {
        $laststates[$testId] = $entry['laststate'];
      }
    }
    if (!$laststates) {
      return;
    }

    $this->batches++;
    try {
      $this->countWritten(count($laststates), $testDAO->writeTestStates($laststates));
      return;
    } catch (Throwable $exception) {
      ($this->log)('batch of ' . count($laststates) . ' states failed (' . $exception->getMessage() . '), retrying row by row');
    }

    $failed = [];
    foreach ($laststates as $testId => $laststate) {
      try {
        $this->countWritten(1, $testDAO->writeTestStates([$testId => $laststate]));
      } catch (Throwable $exception) {
        $failed[$testId] = $exception->getMessage();
      }
    }
    if (!$failed) {
      return;
    }

    if (!$this->databaseIsReachable($testDAO)) {
      // settling would take the failed states off the pending set; they have to stay until the database is back
      throw new RuntimeException('the database is not reachable, ' . count($failed) . ' states stay pending');
    }
    foreach ($failed as $testId => $message) {
      ($this->log)("discarded the state of test $testId, the database rejected it ($message)");
    }
    $this->discarded += count($failed);
  }

  private function countWritten(int $sent, int $updated): void {
    $this->written += $updated;
    $this->missing += $sent - $updated;
  }

  private function databaseIsReachable(TestDAO $testDAO): bool {
    try {
      $testDAO->_('SELECT 1');
      return true;
    } catch (Throwable) {
      return false;
    }
  }

  /**
   * One line with the number of pending tests and the counts since start, plus the state updates the backend wrote
   * directly since the last report. A pending count that keeps growing means the drainer does not keep up or is
   * stuck; fallbacks mean the cache server refused writes.
   */
  public function report(): string {
    $depth = TestStateBuffer::depth();
    $line = sprintf(
      'pending=%s written=%d batches=%d rows_per_batch=%.1f missing=%d discarded=%d fallbacks=%d longest_round_ms=%d',
      $depth ?? 'cache-server-unavailable',
      $this->written,
      $this->batches,
      $this->batches ? ($this->written + $this->missing) / $this->batches : 0.0,
      $this->missing,
      $this->discarded,
      TestStateBuffer::takeFallbackCount(),
      $this->longestRoundMs
    );
    $this->longestRoundMs = 0;
    return $line;
  }
}
