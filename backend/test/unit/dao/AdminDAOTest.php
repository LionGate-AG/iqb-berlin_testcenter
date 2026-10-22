<?php
/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once "test/unit/test-helper/AssertsArraysIgnoringOrder.php";
require_once "test/unit/test-helper/UsesTestStateCache.php";

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AdminDAOTest extends TestCase {
  use AssertsArraysIgnoringOrder;
  use UsesTestStateCache;

  private AdminDAO $dbc;

  function setUp(): void {
    require_once "test/unit/TestDB.class.php";
    TestDB::setUp();

    $this->dbc = new AdminDAO();
    $this->dbc->runFile(ROOT_DIR . '/backend/test/unit/testdata.sql');
  }

  function tearDown(): void {
    unset($this->dbc);
  }

  public function test_login() {
    $token = $this->dbc->createAdminToken('super', 'user123');
    $this->assertNotNull($token);

    $rejection = $this->dbc->createAdminToken('peter', 'peterspassword');
    $this->assertEquals(FailedLogin::usernameNotFound, $rejection);

    $rejection = $this->dbc->createAdminToken('super', 'peterspassword');
    $this->assertEquals(FailedLogin::wrongPassword, $rejection);
  }

  public function test_validateToken() {
    $token = $this->dbc->createAdminToken('super', 'user123');
    $result = $this->dbc->getAdmin($token);
    $this->assertEquals(1, $result->getId());
    $this->assertEquals('super', $result->getName());
    $this->assertTrue($result->isSuperadmin());
  }

  public function test_getWorkspaces() {
    $token = $this->dbc->createAdminToken('super', 'user123');
    $result = $this->dbc->getWorkspaces($token);
    $expect = [new WorkspaceData(1, 'example_workspace', 'RW')];
    $this->assertEquals($result, $expect);

    $token = $this->dbc->createAdminToken('i_exist_but_am_not_allowed_anything', 'user123');
    $result = $this->dbc->getWorkspaces($token);
    $this->assertEquals(array(), $result);
  }

  public function test_hasAdminAccessToWorkspace() {
    $token = $this->dbc->createAdminToken('super', 'user123');
    $result = $this->dbc->hasAdminAccessToWorkspace($token, 1);
    $this->assertEquals(true, $result);

    $token = $this->dbc->createAdminToken('i_exist_but_am_not_allowed_anything', 'user123');
    $result = $this->dbc->hasAdminAccessToWorkspace($token, 1);
    $this->assertEquals(false, $result);
  }

  public function test_getWorkspaceRole() {
    $token = $this->dbc->createAdminToken('super', 'user123');
    $result = $this->dbc->getWorkspaceRole($token, 1);
    $this->assertEquals("RW", $result);

    $token = $this->dbc->createAdminToken('i_exist_but_am_not_allowed_anything', 'user123');
    $result = $this->dbc->getWorkspaceRole($token, 1);
    $this->assertEquals("", $result);
  }

  public function testGetResponseReportData(): void {
    // Arrange
    $workspaceId = 1;
    $groups = ['sample_group'];

    // Act
    $actualResponseReportData = $this->dbc->getResponseReportData($workspaceId, $groups);

    // Assert
    $expectedResponseReportData = [
      [
        'groupname' => 'sample_group',
        'loginname' => 'sample_user',
        'code' => 'xxx',
        'bookletname' => 'first sample test',
        'unitname' => 'UNIT_1',
        'laststate' => '{"SOME_STATE": "WHATEVER"}',
        'originalUnitId' => 'UNIT_1',
        'responses' => [
          [
            'id' => "all",
            'content' => "{\"name\":\"Sam Sample\",\"age\":34}",
            'ts' => 1597903000000,
            'responseType' => 'the-response-type'
          ]
        ]
      ],
      [
        'groupname' => 'sample_group',
        'loginname' => 'sample_user',
        'code' => 'xxx',
        'bookletname' => 'first sample test',
        'unitname' => 'UNIT.SAMPLE',
        'laststate' => '{"PRESENTATIONCOMPLETE": "yes"}',
        'originalUnitId' => 'UNIT.SAMPLE',
        'responses' => [
          [
            'id' => "all",
            'content' => "{\"name\":\"Elias Example\",\"age\":35}",
            'ts' => 1597903000000,
            'responseType' => 'the-response-type'
          ],
          [
            'id' => "other",
            'content' => "{\"other\":\"stuff\"}",
            'ts' => 1597903000000,
            'responseType' => 'the-response-type'
          ]
        ]
      ]
    ];

    parent::assertSame($expectedResponseReportData, $actualResponseReportData);
  }

  function testGetLogReportData(): void {
    // Arrange
    $workspaceId = 1;
    $groups = ['sample_group'];

    // Act
    $actualLogReportData = $this->dbc->getLogReportData($workspaceId, $groups);

    // Assert
    $expectedLogReportData = [
      [
        'groupname' => 'sample_group',
        'loginname' => 'sample_user',
        'code' => 'xxx',
        'bookletname' => 'first sample test',
        'unitname' => 'UNIT.SAMPLE',
        'originalUnitId' => 'UNIT.SAMPLE',
        'timestamp' => 1597903000000,
        'logentry' => 'sample unit log'
      ],
      [
        'groupname' => 'sample_group',
        'loginname' => 'sample_user',
        'code' => 'xxx',
        'bookletname' => 'first sample test',
        'unitname' => '',
        'originalUnitId' => '',
        'timestamp' => 1597903000000,
        'logentry' => 'sample log entry'
      ]
    ];

    parent::assertSame($expectedLogReportData, $actualLogReportData);
  }

  function testGetReviewReportData(): void {
    // Arrange
    $workspaceId = 1;
    $groups = ['review_group'];

    // Act
    $actualReviewReportData = $this->dbc->getReviewReportData($workspaceId, $groups);

    // Assert
    $expectedReviewReportData = [
      [
        'groupname' => 'review_group',
        'loginname' => 'test-review',
        'code' => '',
        'bookletname' => 'BOOKLET.SAMPLE-1#bookletstate=isset',
        'unitname' => 'UNIT_1',
        'priority' => 1,
        'categories' => '',
        'reviewtime' => '2030-01-01 12:00:00',
        'entry' => 'this is a sample unit review',
        'page' => null,
        'pagelabel' => null,
        'originalUnitId' => 'UNIT_1',
        'userAgent' => '',
        'reviewer' => null
      ],
      [
        'groupname' => 'review_group',
        'loginname' => 'test-review',
        'code' => '',
        'bookletname' =>  'BOOKLET.SAMPLE-1#bookletstate=isset',
        'unitname' => '',
        'priority' => 1,
        'categories' => '',
        'reviewtime' => '2030-01-01 12:00:00',
        'entry' => 'sample booklet review',
        'page' => null,
        'pagelabel' => null,
        'originalUnitId' => '',
        'userAgent' => '',
        'reviewer' => null
      ]
    ];

    parent::assertSame($expectedReviewReportData, $actualReviewReportData);
  }

  /**
   * @dataProvider reportDataMethods
   */
  function testGetReportDataWithoutGroupFilterCoversAllGroups(string $method): void {
    $allGroups = $this->dbc->$method(1, null);

    $perGroup = array_merge(
      $this->dbc->$method(1, ['sample_group']),
      $this->dbc->$method(1, ['review_group'])
    );
    parent::assertNotEmpty($allGroups);
    $this->assertSameIgnoringOrder($perGroup, $allGroups);
  }

  /**
   * @dataProvider reportDataMethods
   */
  function testGetReportDataWithEmptyGroupFilterReturnsNothing(string $method): void {
    parent::assertSame([], $this->dbc->$method(1, []));
  }

  /**
   * @return array<string, string[]>
   */
  static function reportDataMethods(): array {
    return [
      'responses' => ['getResponseReportData'],
      'logs' => ['getLogReportData'],
      'reviews' => ['getReviewReportData']
    ];
  }

  function test_addCommand() {
    $command = new Command(-1, 'a_keyword', 1597905000, 'first_argument', 'second_argument');
    $commandId = $this->dbc->storeCommand(1, [1], $command);
    $this->assertEquals(5, $commandId);
    $expectation = [
      "id" => 5,
      "test_id" => 1,
      "keyword" => 'a_keyword',
      "parameter" => '["first_argument", "second_argument"]',
      "commander_id" => 1,
      // 1597905000 is 06:30 UTC, stored as the instant it is rather than as local wall time
      'timestamp' => '2020-08-20 06:30:00+00',
      'executed' => false
    ];
    $result = $this->dbc->_("select * from test_commands where keyword='a_keyword'");
    $this->assertEquals($expectation, $result);
  }

  function test_addCommandForSeveralTests() {
    $command = new Command(-1, 'shared_keyword', 1597905000);
    $commandId = $this->dbc->storeCommand(1, [1, 2], $command);

    $expectation = [
      ["id" => $commandId, "test_id" => 1],
      ["id" => $commandId, "test_id" => 2]
    ];
    $result = $this->dbc->_(
      "select id, test_id from test_commands where keyword='shared_keyword' order by test_id",
      [],
      true
    );
    $this->assertEquals($expectation, $result);
  }

  function test_addCommandDrawsFreshIds() {
    $first = $this->dbc->storeCommand(1, [1], new Command(-1, 'first_keyword', 1597905000));
    $second = $this->dbc->storeCommand(1, [1], new Command(-1, 'second_keyword', 1597905000));

    $this->assertNotEquals($first, $second);
  }

  function test_getTest() {
    $expectation = [
      'locked' => false,
      'id' => '1',
      'laststate' => '{"CURRENT_UNIT_ID": "UNIT_1"}',
      'label' => 'first test label'
    ];
    $result = $this->dbc->getTest(1);
    $this->assertEquals($expectation, $result);
  }

  function test_deleteResultData() {
    $this->dbc->deleteResultData(1, 'not_existing');
    $this->assertGreaterThan(0, $this->countTableRows('login_sessions'));
    $this->assertGreaterThan(0, $this->countTableRows('person_sessions'));
    $this->assertGreaterThan(0, $this->countTableRows('tests'));
    $this->assertGreaterThan(0, $this->countTableRows('test_logs'));
    $this->assertGreaterThan(0, $this->countTableRows('units'));
    $this->assertGreaterThan(0, $this->countTableRows('unit_data'));
    $this->assertGreaterThan(0, $this->countTableRows('unit_logs'));
    $this->assertGreaterThan(0, $this->countTableRows('unit_reviews'));
    $this->assertGreaterThan(0, $this->countTableRows('test_reviews'));

    $this->dbc->deleteResultData(1, 'sample_group');
    $this->dbc->deleteResultData(1, 'review_group');
    $this->assertEquals(0, $this->countTableRows('login_sessions'));
    $this->assertEquals(0, $this->countTableRows('person_sessions'));
    $this->assertEquals(0, $this->countTableRows('tests'));
    $this->assertEquals(0, $this->countTableRows('test_logs'));
    $this->assertEquals(0, $this->countTableRows('units'));
    $this->assertEquals(0, $this->countTableRows('unit_data'));
    $this->assertEquals(0, $this->countTableRows('unit_logs'));
    $this->assertEquals(0, $this->countTableRows('unit_reviews'));
    $this->assertEquals(0, $this->countTableRows('test_reviews'));
  }

  function test_deleteResultData_dropsTheCachedStatesOfTheGroup(): void {
    $this->enableTestStateCache(true);
    TestStateBuffer::push(1, 1, '{"A":"1"}');
    TestStateBuffer::store(2, 1, '{}');
    TestStateBuffer::push(3, 5, '{"B":"1"}');

    $this->dbc->deleteResultData(1, 'sample_group');

    $this->assertNull(TestStateBuffer::get(1));
    $this->assertNull(TestStateBuffer::get(2));
    $this->assertNotNull(TestStateBuffer::get(3), 'a test of another group keeps its state');
    $this->assertSame([3], array_keys(TestStateBuffer::pending(10)));
  }

  function test_deleteResultDataByPersonAndBooklet_dropsTheCachedStatesOfTheDeletedTests(): void {
    $this->enableTestStateCache(true);
    TestStateBuffer::push(1, 1, '{"A":"1"}');
    TestStateBuffer::push(2, 1, '{"A":"2"}');

    $this->dbc->deleteResultDataByPersonAndBooklet(1, [
      ['loginName' => 'sample_user', 'code' => 'xxx', 'nameSuffix' => 'xxx', 'bookletName' => 'first sample test']
    ]);

    $this->assertSame(0, (int) ($this->dbc->_('select count(*) as c from tests where id = 1')['c'] ?? -1));
    $this->assertNull(TestStateBuffer::get(1));
    $this->assertSame([2], array_keys(TestStateBuffer::pending(10)));
  }

  public function test_getTestSessions_showsTheBufferedState(): void {
    $this->enableTestStateCache(true);
    TestStateBuffer::push(1, 1, '{"CURRENT_UNIT_ID":"UNIT.SAMPLE"}');

    /** @var list<SessionChangeMessage> $sessions */
    $sessions = $this->dbc->getTestSessions(1, ['sample_group'])->asArray();
    /** @var array<int, array{testState: array<string, mixed>, unitState: array<string, mixed>}> $sessionsByTest */
    $sessionsByTest = [];
    foreach ($sessions as $session) {
      /** @var array{testId: int, testState: array<string, mixed>, unitState: array<string, mixed>} $asArray */
      $asArray = $session->jsonSerialize();
      $sessionsByTest[$asArray['testId']] = $asArray;
    }

    $this->assertSame(['CURRENT_UNIT_ID' => 'UNIT.SAMPLE', 'status' => 'running'], $sessionsByTest[1]['testState']);
    $this->assertSame(['PRESENTATIONCOMPLETE' => 'yes'], $sessionsByTest[1]['unitState']);
  }

  public function test_getResultStats() {
    $expectation = [
      [
        'groupName' => 'sample_group',
        'groupLabel' => 'Sample Group',
        'bookletsStarted' => 2,
        'numUnitsMin' => 0,
        'numUnitsMax' => 2,
        'numUnitsTotal' => 2,
        'numUnitsAvg' => 1.0,
        'lastChange' => 1643011260
      ],
      [
        'groupName' => 'review_group',
        'groupLabel' => 'Review Group',
        'bookletsStarted' => 1,
        'numUnitsMin' => 1,
        'numUnitsMax' => 1,
        'numUnitsTotal' => 1,
        'numUnitsAvg' => 1.0,
        'lastChange' => 1643011260
      ]
    ];
    $result = $this->dbc->getResultStats(1);
    $this->assertSameIgnoringOrder($expectation, $result);

    $someTestState = '{"CONTROLLER":"TERMINATED","CONNECTION":"LOST","CURRENT_UNIT_ID":"UNIT.SAMPLE","FOCUS":"HAS","TESTLETS_TIMELEFT":"{\"a_testlet_with_restrictions\":0}"}';
    // PostgreSQL has a strict boolean type and does not implicitly convert MySQL-style 0/1 values.
    $this->dbc->_("insert into tests (name, file_id, person_id, locked, running, timestamp_server, laststate) values ('BOOKLET.SAMPLE-2', 'BOOKLET.SAMPLE-2', 1, false, true, '2023-11-14 11:13:20+00:00', '$someTestState')");
    $this->dbc->_("insert into units (name, test_id) values ('UNIT_1', 4)");

    $expectation = [
      [
        'groupName' => 'sample_group',
        'groupLabel' => 'Sample Group',
        'bookletsStarted' => 3,
        'numUnitsMin' => 0,
        'numUnitsMax' => 2,
        'numUnitsTotal' => 3,
        'numUnitsAvg' => 1.0,
        'lastChange' => 1699960400
      ],
      [
        'groupName' => 'review_group',
        'groupLabel' => 'Review Group',
        'bookletsStarted' => 1,
        'numUnitsMin' => 1,
        'numUnitsMax' => 1,
        'numUnitsTotal' => 1,
        'numUnitsAvg' => 1.0,
        'lastChange' => 1643011260
      ]
    ];
    $result = $this->dbc->getResultStats(1);
    $this->assertSameIgnoringOrder($expectation, $result);

    $this->dbc->_('delete from test_reviews');
    $result = $this->dbc->getResultStats(1);
    $this->assertSameIgnoringOrder($expectation, $result);

    $this->dbc->_('delete from unit_reviews');
    $result = $this->dbc->getResultStats(1);
    $this->assertSameIgnoringOrder([$expectation[0]], $result);

    $this->dbc->_(
      "insert into test_reviews (booklet_id, reviewtime, priority, categories, entry)
      values (3, '2030-01-01 12:00:00', 1, '', 'new booklet review')"
    );
    $result = $this->dbc->getResultStats(1);
    $this->assertSameIgnoringOrder($expectation, $result);
  }

  public function test_getTestSessions() {
    $expectation = [
      [
        'personId' => 1,
        'timestamp' => 1643011260,
        'testId' => 1,
        'groupName' => 'sample_group',
        'groupLabel' => 'Sample Group',
        'personLabel' => 'sample_user/xxx',
        'mode' => 'run-hot-return',
        'testState' => [
          'CURRENT_UNIT_ID' => 'UNIT_1',
          'status' => 'running'
        ],
        'bookletName' => 'first sample test',
        'unitName' => 'UNIT_1',
        'unitState' => [
          'SOME_STATE' => 'WHATEVER'
        ]
      ],
      [
        'personId' => 1,
        'timestamp' => 1643011260,
        'testId' => 2,
        'groupName' => 'sample_group',
        'groupLabel' => 'Sample Group',
        'personLabel' => 'sample_user/xxx',
        'mode' => 'run-hot-return',
        'testState' => [
          'status' => 'running',
        ],
        'bookletName' => 'BOOKLET.SAMPLE-1',
        'unitState' => []
      ]
    ];

    $sessionChangeMessageArray = function(SessionChangeMessage $s): array {
      $asArray = $s->jsonSerialize();
      foreach ($asArray as $key => $value) {
        if ((($key == "testState") or ($key == "unitState")) and (empty((array) $value))) {
          $asArray[$key] = [];
        }
      }
      return $asArray;
    };

    $result = $this->dbc->getTestSessions(1, ['sample_group']);
    $resultAsArray = array_map($sessionChangeMessageArray, $result->asArray());
    usort($resultAsArray, function ($first, $second) {
      return $first['testId'] <=> $second['testId'];
    });
    $this->assertSame($expectation, $resultAsArray);

    $result = $this->dbc->getTestSessions(1, []); // all groups
    $resultAsArray = array_map($sessionChangeMessageArray, $result->asArray());
    usort($resultAsArray, function ($first, $second) {
      return $first['testId'] <=> $second['testId'];
    });
    $this->assertSame($expectation, $resultAsArray);

    $result = $this->dbc->getTestSessions(1, ['unknown_group']);
    $resultAsArray = array_map($sessionChangeMessageArray, $result->asArray());
    usort($resultAsArray, function ($first, $second) {
      return $first['testId'] <=> $second['testId'];
    });
    $this->assertSame([], $resultAsArray);
  }

  public function test_getGroup(): void {
    $result = $this->dbc->getGroup('sample_group');
    $expectation = new Group('sample_group', 'Sample Group');
    $this->assertEquals($expectation, $result);

    $result = $this->dbc->getGroup('gibberish');
    $this->assertNull($result);
  }

  private function countTableRows(string $tableName): int {
    return (int) $this->dbc->_("select count(*) as c from $tableName")["c"];
  }

  public function test_getBookletFiles_readsUnitFilesFromTheActiveStorage(): void {
    require_once "test/unit/mock-classes/StorageDriverFake.php";
    $storage = new StorageDriverFake([
      'ws_1/Resource/unit-a.voud' => '{"def":"a"}',
      'ws_1/Resource/unit-a.vocs' => '{"scheme":"a"}',
      'ws_1/Resource/Unit-a.vomd' => '{"meta":"a"}',
    ]);
    SystemConfig::$storage_driver = 's3';
    Storage::setDriver($storage);
    $this->dbc->_(
      "insert into files(workspace_id, name, id, label, type, modification_ts, is_valid, size) values
        (1, 'Unit-a.xml', 'UNIT.A', 'Unit A', 'Unit', '2023-01-16 09:00:00+01:00', true, 1),
        (1, 'Unit-b.xml', 'UNIT.B', 'Unit B', 'Unit', '2023-01-16 09:00:00+01:00', true, 1)"
    );
    $this->dbc->_(
      "insert into file_relations(workspace_id, subject_name, subject_type, relationship_type, object_type, object_name) values
        (1, 'Booklet.xml', 'Booklet', 'containsUnit', 'Unit', 'Unit-a.xml'),
        (1, 'Booklet.xml', 'Booklet', 'containsUnit', 'Unit', 'Unit-b.xml'),
        (1, 'Unit-a.xml', 'Unit', 'usesPlayer', 'Resource', 'verona-player-simple-6.0.html'),
        (1, 'Unit-a.xml', 'Unit', 'isDefinedBy', 'Resource', 'unit-a.voud'),
        (1, 'Unit-a.xml', 'Unit', 'usesScheme', 'Resource', 'unit-a.vocs'),
        (1, 'Unit-b.xml', 'Unit', 'isDefinedBy', 'Resource', 'unit-b.voud')"
    );

    ini_set('error_log', '/dev/null'); // the missing unit-b.voud is logged; PHPUnit would take that output as an error

    $result = $this->dbc->getBookletFiles(1, ['BOOKLET.SAMPLE-1']);

    $this->assertEquals(
      [
        'name' => 'Unit-a.xml',
        'label' => 'Unit A',
        'workspaceId' => 1,
        'playerVersion' => ['major' => 4, 'minor' => 0, 'patch' => 0],
        'playerPath' => 'ws_1/verona-player-simple-6.0.html',
        'unitDefinition' => ['def' => 'a'],
        'codingScheme' => ['scheme' => 'a'],
        'metaData' => ['meta' => 'a'],
      ],
      $result['UNIT.A']
    );
    // a missing definition stays null, as before; no metadata file, no key
    $this->assertEquals(
      ['name' => 'Unit-b.xml', 'label' => 'Unit B', 'workspaceId' => 1, 'unitDefinition' => null],
      $result['UNIT.B']
    );
  }
}
