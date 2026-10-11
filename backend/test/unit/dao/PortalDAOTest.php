<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PortalDAOTest extends TestCase {
  private PortalDAO $dbc;

  function setUp(): void {
    require_once "test/unit/TestDB.class.php";
    TestDB::setUp();
    $this->dbc = new PortalDAO();
    $this->dbc->runFile(ROOT_DIR . '/backend/test/unit/testdata.sql');
  }

  function tearDown(): void {
    unset($this->dbc);
  }

  function test_getWorkspaceOverview(): void {
    $this->assertEquals(
      [
        '1' => [
          'name' => 'example_workspace',
          'booklets' => [
            'BOOKLET.NO.TEST' => ['label' => 'Booklet without test', 'description' => 'No test yet'],
            'BOOKLET.SAMPLE-1' => ['label' => 'Sample Booklet Label', 'description' => 'Desc'],
          ],
        ],
      ],
      json_decode(json_encode($this->dbc->getWorkspaceOverview()), true)
    );
  }

  function test_getTestBookletIds(): void {
    $this->assertEquals(
      ['1' => 'first sample test', '2' => 'BOOKLET.SAMPLE-1', '3' => 'BOOKLET.SAMPLE-1'],
      json_decode(json_encode($this->dbc->getTestBookletIds(1)), true)
    );
    $this->assertEquals([], json_decode(json_encode($this->dbc->getTestBookletIds(99)), true));
  }

  function test_getBookletUnits_onlyUnitsWithCodingScheme(): void {
    $this->dbc->_(
      "insert into files(workspace_id, name, id, type, modification_ts, is_valid, size) values
        (1, 'Unit-coded.xml', 'UNIT.CODED', 'Unit', '2023-01-16 09:00:00+01:00', true, 1),
        (1, 'Unit-plain.xml', 'UNIT.PLAIN', 'Unit', '2023-01-16 09:00:00+01:00', true, 1)"
    );
    $this->dbc->_(
      "insert into file_relations(workspace_id, subject_name, subject_type, relationship_type, object_type, object_name) values
        (1, 'Booklet.xml', 'Booklet', 'containsUnit', 'Unit', 'Unit-coded.xml'),
        (1, 'Booklet.xml', 'Booklet', 'containsUnit', 'Unit', 'Unit-plain.xml'),
        (1, 'Unit-coded.xml', 'Unit', 'usesScheme', 'Resource', 'scheme.vocs.json')"
    );

    $this->assertEquals(
      ['BOOKLET.SAMPLE-1' => ['UNIT.CODED']],
      json_decode(json_encode($this->dbc->getBookletUnits(1)), true)
    );
  }

  function test_getTesteeTokens(): void {
    $rows = $this->dbc->getTesteeTokens(1, ['xxx', 'unknown']);
    usort($rows, fn($a, $b) => strcmp($a['bookletFileId'], $b['bookletFileId']));
    $this->assertEquals(
      [
        ['code' => 'xxx', 'bookletFileId' => 'BOOKLET.SAMPLE-1', 'nameSuffix' => 'xxx'],
        ['code' => 'xxx', 'bookletFileId' => 'first sample test', 'nameSuffix' => 'xxx'],
      ],
      $rows
    );
    $this->assertEquals([], $this->dbc->getTesteeTokens(1, []));
  }

  function test_isPlayer(): void {
    $this->assertTrue($this->dbc->isPlayer(1, 'verona-player-simple-6.0.html'));
    $this->assertTrue($this->dbc->isPlayer(1, 'missnamed-player-simple-4.1.5.html'));
    $this->assertFalse($this->dbc->isPlayer(2, 'verona-player-simple-6.0.html'));
    $this->assertFalse($this->dbc->isPlayer(1, 'Booklet.xml'));
    $this->assertFalse($this->dbc->isPlayer(1, 'does-not-exist.html'));
  }
}
