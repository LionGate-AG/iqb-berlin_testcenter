<?php

/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

class TestDAO extends DAO {
  // TODO unit test
  public function getTestByPerson(int $personId, string $testName): TestData | null {
    $test = $this->_(
      'select tests.locked, tests.name, tests.id, tests.file_id, tests.laststate, tests.label, tests.running from tests
            where tests.person_id=:personId and tests.name=:testname',
      [
        ':personId' => $personId,
        ':testname' => $testName
      ]
    );

    if (!$test) {
      return null;
    }
    return new TestData(
      $test['id'],
      $test['name'],
      $test['file_id'],
      $test['label'],
      '',
      (bool) $test['locked'],
      (bool) $test['running'],
      JSON::decode(self::currentLaststate((int) $test['id'], $test['laststate']))
    );
  }

  // TODO unit test
  public function createTest(int $personId, TestName $testName, string $bookletLabel): TestData {
    $state = (object) [];
    $this->_(
      'insert into tests (person_id, name, label, laststate, file_id) values (:person_id, :name, :label, :state, :file_id)',
      [
        ':person_id' => $personId,
        ':name' => $testName->name,
        ':label' => $bookletLabel,
        ':state' => json_encode($state),
        ':file_id' => $testName->bookletFileId
      ]
    );
    $testId = (int) $this->pdoDBhandle->lastInsertId();

    // Seed the test-state cache so the first PATCH is already a hit. This also
    // overwrites any stale entry at this id: after a TRUNCATE resets
    // AUTO_INCREMENT, test ids are reused, and a leftover entry could otherwise
    // hand one user the previous run's state for the same id.
    CacheService::storeTestState($testId, $personId, json_encode($state));

    return new TestData(
      $testId,
      $testName->name,
      $testName->bookletFileId,
      $bookletLabel,
      '',
      false,
      false,
      $state
    );
  }

  // TODO unit test
  public function getTestById(int $testId): TestData|null {
    $test = $this->_(
      'select tests.locked, tests.name, tests.id, tests.file_id, tests.laststate, tests.label, tests.running from tests where id = :id',
      [
        ':id' => $testId
      ]
    );

    if (!$test) {
      return null;
    }

    return new TestData(
      $test['id'],
      $test['name'],
      $test['file_id'],
      $test['label'],
      '',
      (bool) $test['locked'],
      (bool) $test['running'],
      JSON::decode(self::currentLaststate((int) $test['id'], $test['laststate']))
    );
  }

  // TODO unit test
  public function addTestReview(
    int $testId,
    int $priority,
    string $categories,
    string $entry,
    string $userAgent,
    int $personId,
    ?string $reviewer = null
  ): void {
    $this->_(
      'insert into test_reviews (booklet_id, person_id, reviewtime, priority, categories, entry, reviewer, user_agent) values(:b, :person, :t, :p, :c, :e, :s, :u)',
      [
        ':b' => $testId,
        ':person' => $personId,
        ':t' => TimeStamp::toSQLFormat(TimeStamp::now()),
        ':p' => $priority,
        ':c' => $categories,
        ':e' => $entry,
        ':s' => $reviewer,
        ':u' => $userAgent
      ]
    );
  }

  // TODO unit test
  public function addUnitReview(
    int $testId,
    string $unitName,
    int $priority,
    string $categories,
    string $entry,
    string $userAgent,
    string $originalUnitId,
    int $personId,
    ?int $page = null,
    ?string $pageLabel = null,
    ?string $reviewer = null,
  ): void {
    $this->_(
      'insert ignore into units (name, test_id, original_unit_id) values(:u, :t, :o)',
      [
        ':u' => $unitName,
        ':t' => $testId,
        ':o' => $originalUnitId
      ]
    );
    $this->_(
      'insert into unit_reviews (
            unit_name,
            person_id,
            test_id,
            reviewtime,
            priority,
            categories,
            entry,
            reviewer,
            page,
            pagelabel,
            user_agent
        ) values (:unit_name, :person_id, :test_id, :t, :p, :c, :e, :s, :pa, :pl, :ua)
          ',
      [
        ':unit_name' => $unitName,
        ':person_id' => $personId,
        ':test_id' => $testId,
        ':t' => TimeStamp::toSQLFormat(TimeStamp::now()),
        ':p' => $priority,
        ':c' => $categories,
        ':e' => $entry,
        ':s' => $reviewer,
        ':pa' => $page,
        ':pl' => $pageLabel,
        ':ua' => $userAgent,
      ]
    );
  }

  public function getUnitReviews(int $testId, string $unitName, int $personId): array {
    return $this->_(
      'select
            unit_reviews.id,
            unit_reviews.unit_name,
            unit_reviews.test_id,
            unit_reviews.person_id,
            unit_reviews.reviewtime,
            unit_reviews.priority,
            unit_reviews.categories,
            unit_reviews.entry,
            unit_reviews.reviewer,
            unit_reviews.page,
            unit_reviews.pagelabel,
            unit_reviews.user_agent as userAgent,
            units.original_unit_id as originalUnitId
          from unit_reviews
          left join units on units.test_id = unit_reviews.test_id
              and units.name = unit_reviews.unit_name
          where unit_reviews.test_id = :test_id
            and unit_reviews.unit_name = :unit_name
            and unit_reviews.person_id = :person_id
          order by unit_reviews.reviewtime desc',
      [
        ':test_id' => $testId,
        ':unit_name' => $unitName,
        ':person_id' => $personId
      ],
      true
    );
  }

  public function getTestReviews(int $testId, int $personId): array {
    return $this->_(
        'select
          id,
          booklet_id,
          person_id,
          reviewtime,
          priority,
          categories,
          entry,
          reviewer,
          user_agent as userAgent
        from test_reviews
        where booklet_id = :test_id
          and person_id = :person_id
        order by reviewtime desc',
        [
          ':test_id' => $testId,
          ':person_id' => $personId
        ],
        true
      );
  }

  public function deleteUnitReview(int $reviewId, int $personId): void {
    $this->_(
      'delete from unit_reviews 
        where id = :id 
            and person_id = :person_id',
      [
        ':id' => $reviewId,
        ':person_id' => $personId
      ]
    );
  }
    public function deleteTestReview(int $reviewId, int $personId): void {
      $this->_(
        'delete from test_reviews 
        where id = :id 
          and person_id = :person_id',
        [
          ':id' => $reviewId,
          ':person_id' => $personId
        ]
      );
  }

  public function updateUnitReview(
    int $reviewId,
    int $priority,
    string $categories,
    string $entry,
    ?string $reviewer,
    int $personId,
    ?string $pagelabel
  ): void {
    $this->_(
      'update unit_reviews
      set
        priority = :p,
        categories = :c,
        entry = :e,
        reviewer = :s,
        person_id = :person_id,
        pagelabel = :pagelabel,
        reviewtime = :t
      where id = :id',
      [
        ':id' => $reviewId,
        ':p' => $priority,
        ':c' => $categories,
        ':e' => $entry,
        ':s' => $reviewer,
        ':person_id' => $personId,
        ':pagelabel' => $pagelabel,
        ':t' => TimeStamp::toSQLFormat(TimeStamp::now())
      ]
    );
  }

  public function updateTestReview(
    int $reviewId,
    int $priority,
    string $categories,
    string $entry,
    ?string $reviewer,
    int $personId
  ): void {
    $this->_(
      'update test_reviews
      set
        priority = :p,
        categories = :c,
        entry = :e,
        reviewer = :s,
        person_id = :person_id,
        reviewtime = :t
      where id = :id',
      [
        ':id' => $reviewId,
        ':p' => $priority,
        ':c' => $categories,
        ':e' => $entry,
        ':s' => $reviewer,
        ':person_id' => $personId,
        ':t' => TimeStamp::toSQLFormat(TimeStamp::now())
      ]
    );
  }

  public function unitReviewExists(int $reviewId, int $personId): bool {
    $result = $this->_(
      'select count(*) as count 
     from unit_reviews 
     where id = :id 
       and person_id = :person_id',
      [
        ':id' => $reviewId,
        ':person_id' => $personId
      ],
      true
    );
    return $result && $result[0]['count'] > 0;
  }

  public function testReviewExists(int $reviewId, int $personId): bool {
    $result = $this->_(
      'select count(*) as count 
     from test_reviews 
     where id = :id 
       and person_id = :person_id',
      [
        ':id' => $reviewId,
        ':person_id' => $personId
      ],
      true
    );
    return $result && $result[0]['count'] > 0;
  }

  public function getTestState(int $testId): array {
    $test = $this->_(
      'select tests.laststate from tests where tests.id=:testId',
      [
        ':testId' => $testId
      ]
    );

    return ($test) ? JSON::decode(self::currentLaststate($testId, $test['laststate']), true) : [];
  }

  // TODO use data-collection class
  public function getTestSession(int $testId): array {
    $testSession = $this->_(
      'select
        login_sessions.id as login_id,
        logins.mode,
        login_sessions.workspace_id,
        logins.group_name as group_name,
        login_sessions.token as login_token,
        person_sessions.code,
        person_sessions.token as person_token,
        tests.person_id, 
        tests.laststate as testState,
        tests.id,
        tests.locked,
        tests.running,
        tests.label
      from 
        tests 
        left join person_sessions on person_sessions.id = tests.person_id
        left join login_sessions on person_sessions.login_sessions_id = login_sessions.id
        left join logins on logins.name = login_sessions.name
      where 
        tests.id=:testId',
      [
        ':testId' => $testId
      ]
    );

    if ($testSession == null) {
      throw new HttpError("Test not found", 404);
    }

    $testSession['testState'] = self::currentLaststate($testId, $testSession['testState']);
    $testSession['laststate'] = $this->getTestFullState($testSession);

    return $testSession;
  }

  // TODO use data-collection class for $statePatch (key-vale pairs)
  /**
   * @param array|null $preloadedTestRow The tests row (needing only `laststate`)
   *   when the caller has ALREADY read it and can prove the test exists -- lets
   *   this method skip its own SELECT. Optional on purpose: callers without that
   *   guarantee (InitDAO's seeding, the connection-lost helper) pass nothing and
   *   keep the read plus the 404 below exactly as before.
   *
   *   Used by TestController::patchState, where IsTestWritable has just fetched
   *   this identical row to authorise the request (see SessionDAO::getOwnedTest).
   *   Re-reading it there cost a round trip on the single most frequent endpoint
   *   in the system. Note the 404 is unreachable on that path anyway: the
   *   middleware already 403s a test that does not exist, since its lookup keys
   *   on (id, person_id).
   */
  public function updateTestState(int $testId, array $statePatch, ?array $preloadedTestRow = null): array {
    // Cache before table: with write-behind the cached state may be newer than
    // the row, and merging onto the row would write an older state back.
    $testData = $preloadedTestRow ?? CacheService::getCachedTestState($testId) ?? $this->_(
      'SELECT tests.laststate, tests.person_id FROM tests WHERE tests.id = :testId',
      [
        ':testId' => $testId
      ]
    );

    if ($testData == null) {
      throw new HttpError("Test not found", 404);
    }

    $oldState = $testData['laststate'] ? JSON::decode($testData['laststate'], true) : [];
    // TODO add column laststate_update_ts analogous to unit_state to avoid race conditions
    $newState = State::applyPatch($statePatch, $oldState);
    $newStateJson = json_encode((object)$newState['newState']);

    // Write-behind (TestStateBuffer): the state goes to the cache and is written to
    // the table by backend/drain-test-states.php within one drain interval. Falls
    // through to the synchronous UPDATE below when buffering is off or Redis
    // refuses, so an outage costs database load, never a lost state.
    if (isset($testData['person_id']) and TestStateBuffer::push($testId, (int) $testData['person_id'], $newStateJson)) {
      return $newState['newState'];
    }

    $this->_(
      'update tests set laststate = :laststate, timestamp_server = :timestamp where id = :id',
      [
        ':laststate' => $newStateJson,
        ':id' => $testId,
        ':timestamp' => TimeStamp::toSQLFormat(TimeStamp::now())
      ]
    );

    // This is the only statement that writes laststate, so this is the one place
    // the test-state cache has to be kept current (see CacheService::getOwnedTestRow).
    // rowCount() is CHANGED rows here, not matched rows: an identical state written
    // within the same second reports 0 although the row exists. 0 therefore only
    // drops the entry -- the next read re-populates it from the table -- rather than
    // being treated as "test gone".
    if (isset($testData['person_id']) and $this->lastAffectedRows > 0) {
      CacheService::storeTestState($testId, (int) $testData['person_id'], $newStateJson);
    } else {
      CacheService::invalidateTestState($testId);
    }

    return $newState['newState'];
  }

  /**
   * Write buffered states to the table as ONE multi-row UPDATE. For the drainer
   * (backend/drain-test-states.php) only. Rows of tests deleted meanwhile simply
   * match nothing -- the join only touches rows that still exist.
   *
   * Ids are inlined as integer literals rather than bound: bound values arrive as
   * strings, which would type the CTE column as a string and turn the join on
   * tests.id into a comparison that cannot use the primary key.
   *
   * @param array<int, string> $laststates testId => laststate JSON
   * @return int rows changed
   */
  public function writeTestStatesNow(array $laststates): int {
    if (empty($laststates)) {
      return 0;
    }
    $rows = [];
    $params = [':timestamp' => TimeStamp::toSQLFormat(TimeStamp::now())];
    $index = 0;
    foreach ($laststates as $testId => $laststate) {
      $rows[] = 'ROW(' . (int) $testId . ", :s$index)";
      $params[":s$index"] = $laststate;
      $index++;
    }
    $this->_(
      'WITH pending (id, laststate) AS (VALUES ' . implode(', ', $rows) . ')
      UPDATE tests INNER JOIN pending ON tests.id = pending.id
      SET tests.laststate = pending.laststate, tests.timestamp_server = :timestamp',
      $params
    );
    return (int) $this->lastAffectedRows;
  }

  /**
   * The current laststate of a test: the cached value when there is one -- it may
   * not have been written back yet (TestStateBuffer) -- otherwise the table's.
   */
  private static function currentLaststate(int $testId, ?string $tableLaststate): ?string {
    $cached = CacheService::getCachedTestState($testId);
    return ($cached === null) ? $tableLaststate : $cached['laststate'];
  }

  public function getUnitState(int $testId, string $unitName): array {
    $unitData = $this->_(
      'select units.laststate from units where units.name = :unitname and units.test_id = :testId',
      [
        ':unitname' => $unitName,
        ':testId' => $testId
      ]
    );

    return $unitData ? JSON::decode($unitData['laststate'], true) : [];
  }

  public function updateUnitState(
    int $testId,
    string $unitName,
    array $statePatch,
    string $originalUnitId = ''
  ): array {
    $unitData = $this->_(
      'select laststate, laststate_update_ts from units where test_id = :testId and name = :unitName',
      [
        ':testId' => $testId,
        ':unitName' => $unitName
      ]
    );
    $oldState = $unitData['laststate'] ? JSON::decode($unitData['laststate'], true) : [];
    $oldStateUpdateTs = $unitData['laststate_update_ts'] ? JSON::decode($unitData['laststate_update_ts'], true) : [];
    $newState = State::applyPatch($statePatch, $oldState, $oldStateUpdateTs);

    // todo save states in separate key-value table instead of JSON blob
    $this->_(
      'insert into units (test_id, name, laststate, laststate_update_ts, original_unit_id)
      values (:testId, :unitName, :laststate, :laststate_update_ts, :originalUnitId)
      on duplicate key update laststate = :laststate, laststate_update_ts = :laststate_update_ts;',
      [
        ':laststate' => json_encode((object)$newState['newState']),
        ':laststate_update_ts' => json_encode($newState['updateTs']),
        ':testId' => $testId,
        ':unitName' => $unitName,
        ':originalUnitId' => $originalUnitId
      ]
    );

    return $newState['newState'];
  }

  // TODO unit test
  public function lockTest(int $testId): void {
    $this->changeTestLockStatus($testId, unlock: false);
  }

  // TODO unit test
  public function unlockTest(int $testId): void {
    $this->changeTestLockStatus($testId, unlock: true);
  }

  // TODO unit test
  private function changeTestLockStatus(int $testId, bool $unlock): void {
    $this->_(
      'update tests set locked = :locked , timestamp_server = :timestamp where id = :id',
      [
        ':locked' => $unlock ? '0' : '1',
        ':id' => $testId,
        ':timestamp' => TimeStamp::toSQLFormat(TimeStamp::now())
      ]
    );
  }

  /* TODO decide on what to do with the different dataTypes per player/unit: the database makes it possible, but the
  application code does treat it like its not possible, because the verona interface does only work with one responseType
  per player/unit */
  public function getDataParts(int $testId, string $unitName): array {
    $result = $this->_(
      'select
          unit_data.part_id,
          unit_data.content,
          unit_data.response_type
        from
          unit_data
        where
          unit_data.unit_name = :unitname
          and unit_data.test_id = :testId
        ',
      [
        ':unitname' => $unitName,
        ':testId' => $testId
      ],
      true
    );

    $unitData = [];
    foreach ($result as $row) {
      $unitData[$row['part_id']] = $row['content'];
    }

    return [
      "dataParts" => $unitData,
      "dataType" => $row['response_type'] ?? '' // TODO see function head
    ];
  }

  public function updateDataParts(
    int $testId,
    string $unitName,
    array $dataParts,
    string $type,
    int $timestamp
  ): void {
    if (empty($this->_(
      'SELECT 1 FROM units WHERE test_id = :test_id AND name = :name;',
      [':test_id' => $testId, ':name' => $unitName]
    ))) {
      throw new HttpError(
        'Unit response could not be saved because the unit does not exist (yet). Please try again.',
        404,
        'Unit not found'
      );
    }
    foreach ($dataParts as $partId => $content) {
      $this->_(
      'insert into unit_data(unit_name, test_id, part_id, content, ts, response_type)
            values (:unit_name, :test_id, :part_id, :content, :ts, :response_type)
            on duplicate key update
              content = if (ts < :ts, :content, content),
              ts = if (ts < :ts, :ts, ts),
              response_type = if (ts < :ts, :response_type, response_type);',
        [
          ':unit_name' => $unitName,
          ':test_id' => $testId,
          ':part_id' => $partId,
          ':content' => $content,
          ':ts' => $timestamp,
          ':response_type' => $type
        ]
      );
    }
  }

  // TODO unit test
  public function deleteAttachmentDataPart(string $partId): void {
    // unitId is not necessary for identification, because partId contains unitName and TestId in case of attachments
    $this->_(
      'delete from unit_data where part_id = :partId',
      [':partId' => $partId]
    );
  }

  /**
   * @param UnitLog[] $unitLogs
   */
  public function addUnitLogs(array $unitLogs): void {
    if (empty($unitLogs)) {
      return;
    }

    foreach ($unitLogs as $unitLog) {
      if (!$unitLog instanceof UnitLog) {
        throw new \http\Exception\InvalidArgumentException('All array elements must be UnitLog instances');
      }
    }

    $placeholders = [];
    $params = [];

    /** @var UnitLog $log */
    foreach ($unitLogs as $index => $log) {
      $placeholders[] = "(:unitName{$index}, :testId{$index}, :logentry{$index}, :timestamp{$index})";

      $params[":unitName{$index}"] = $log->unitName;
      $params[":testId{$index}"] = $log->testId;
      $params[":logentry{$index}"] = $log->logKey . ($log->logContent ? ' = ' . $log->logContent : '');
      $params[":timestamp{$index}"] = $log->timestamp;
    }

    $sql = 'insert into unit_logs (unit_name, test_id, logentry, timestamp) values ' . implode(', ', $placeholders);
    $this->_($sql, $params);
  }

  /**
   * Record test-log rows.
   *
   * Routing, in order:
   *   1. TESTCENTER_SKIP_TEST_LOGS=1  -> drop them (Phase-0 diagnostic, see below)
   *   2. TESTCENTER_ASYNC_TEST_LOGS=1 -> queue them for backend/drain-logs.php,
   *      which inserts them ~1,000 at a time (see LogBuffer for the measurements
   *      and the at-most-once semantics this accepts)
   *   3. otherwise                    -> insert them synchronously, as always
   *
   * Step 2 falls through to step 3 whenever Redis is unavailable or the queue is
   * over its depth cap, so a cache-server outage costs latency, never audit rows.
   *
   * @param TestLog[] $testLogs
   */
  public function addTestLogs(array $testLogs): void {
    if (empty($testLogs)) {
      return;
    }

    foreach ($testLogs as $testLog) {
      if (!$testLog instanceof TestLog) {
        throw new \http\Exception\InvalidArgumentException('All array elements must be TestLog instances');
      }
    }

    // PHASE-0 DIAGNOSTIC -- default OFF, never true in production.
    //
    // Drops test_logs entirely. It exists to measure the ceiling: on 2026-09-03
    // at ~88k users this one statement was 947,740s of 1,157,890s of total
    // database time (81.8%), and turning it off cut total database time 91.8%
    // while making every surviving statement 2-3x faster. That measurement is
    // what justified the async path; keeping the switch means it can be re-run
    // to re-establish the ceiling after future changes.
    //
    // NEVER true in production: test_logs backs the customer-facing workspace
    // log CSV (AdminDAO::getLogReportData -> LogReportOutput).
    //
    // Checked before LogBuffer deliberately -- "skip" must mean no rows at all,
    // not rows queued into Redis that the drain worker then silently discards.
    //
    // Static because this runs millions of times per run and getenv() is not free.
    static $skip = null;
    if ($skip === null) {
      $skip = getenv('TESTCENTER_SKIP_TEST_LOGS') === '1';
    }
    if ($skip) {
      return;
    }

    if (LogBuffer::push($testLogs)) {
      return;
    }

    $this->insertTestLogsNow($testLogs);
  }

  /**
   * Insert test-log rows immediately, as one multi-row INSERT.
   *
   * Split out of addTestLogs() so that exactly one place builds this statement.
   * It has two callers with different batch sizes, and they must not diverge:
   *   * addTestLogs()          -- the synchronous fallback, 1-3 rows per call
   *   * backend/drain-logs.php -- the drain worker, up to 1,000 rows per call
   *
   * @param TestLog[] $testLogs
   */
  public function insertTestLogsNow(array $testLogs): void {
    if (empty($testLogs)) {
      return;
    }

    $placeholders = [];
    $params = [];

    /** @var TestLog $log */
    foreach ($testLogs as $index => $log) {
      $placeholders[] = "(:bookletId{$index}, :logentry{$index}, :timestamp{$index})";

      $params[":bookletId{$index}"] = $log->testId;
      $params[":logentry{$index}"] = $log->logKey . ($log->logContent ? ' : ' . $log->logContent : '');
      $params[":timestamp{$index}"] = $log->timestamp;
    }

    $sql = 'insert into test_logs (booklet_id, logentry, timestamp) values ' . implode(', ', $placeholders);

    $this->_($sql, $params);
  }

  // TODO unit test
  public function setTestRunning(int $testId): void {
    $this->_(
      'update tests set running = :running , timestamp_server = :timestamp where id = :id',
      [
        ':running' => '1',
        ':id' => $testId,
        ':timestamp' => TimeStamp::toSQLFormat(TimeStamp::now())
      ]
    );
  }

  public function getCommands(int $testId, ?int $lastCommandId = null): array {
    $sql = "select * from test_commands where test_id = :test_id and executed = 0 order by timestamp";
    $replacements = [':test_id' => $testId];
    if ($lastCommandId) {
      $replacements[':last_id'] = $lastCommandId;
      $sql = str_replace(
        'where',
        'where timestamp > (select timestamp from test_commands where id = :last_id) and ',
        $sql
      );
    }

    $commands = [];
    foreach ($this->_($sql, $replacements, true) as $line) {
      $commands[] = new Command(
        (int) $line['id'],
        $line['keyword'],
        TimeStamp::fromSQLFormat($line['timestamp']),
        ...JSON::decode($line['parameter'], true)
      );
    }
    return $commands;
  }

  public function setCommandExecuted(int $testId, int $commandId): bool {
    $command = $this->_(
      'select executed from test_commands where test_id = :testId and id = :commandId',
      [':testId' => $testId, ':commandId' => $commandId]
    );

    if (!$command) {
      throw new HttpError("Command `$commandId` not found on test `$testId`", 404);
    }

    if ($command['executed']) {
      return false;
    }

    $this->_(
      'update test_commands set executed = 1 where test_id = :testId and id = :commandId',
      [':testId' => $testId, ':commandId' => $commandId]
    );

    return true;
  }

}
