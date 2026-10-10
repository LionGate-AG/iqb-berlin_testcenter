<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PortalControllerTest extends TestCase {
  use MockeryPHPUnitIntegration;

  private Mockery\MockInterface $portalDaoMock;
  private ?string $dataDir = null;

  function setUp(): void {
    require_once "test/unit/test-helper/RequestCreator.class.php";
    require_once "test/unit/test-helper/ResponseCreator.class.php";
    $this->portalDaoMock = Mockery::mock('overload:' . PortalDAO::class);
  }

  function tearDown(): void {
    if ($this->dataDir !== null) {
      self::removeTree($this->dataDir);
    }
  }

  private static function body(Slim\Http\Response $response): string {
    $response->getBody()->rewind();
    return $response->getBody()->getContents();
  }

  /**
   * DATA_DIR as a real temp tree: Folder::getContainedRealPath needs realpath, which vfsStream does not support. Every
   * test runs in its own process (class annotation), so the constant can be defined per test.
   *   ws_1/Resource/verona-player-simple-6.0.html, SAMPLE_UNITCONTENTS.HTM, GeoGebra/js/deployggb.js,
   *   ws_1/Resource/GeoGebra/escape.js -> <DATA_DIR>/secret.txt (symlink out of the workspace)
   */
  private function makeDataDir(): void {
    $this->dataDir = sys_get_temp_dir() . '/tc_portal_resource_' . uniqid('', true);
    define('DATA_DIR', $this->dataDir);
    mkdir("$this->dataDir/ws_1/Resource/GeoGebra/js", 0777, true);
    file_put_contents("$this->dataDir/ws_1/Resource/verona-player-simple-6.0.html", '<html>player</html>');
    file_put_contents("$this->dataDir/ws_1/Resource/SAMPLE_UNITCONTENTS.HTM", '<html>unit</html>');
    file_put_contents("$this->dataDir/ws_1/Resource/GeoGebra/js/deployggb.js", 'ggb();');
    file_put_contents("$this->dataDir/secret.txt", 'secret');
    symlink("$this->dataDir/secret.txt", "$this->dataDir/ws_1/Resource/GeoGebra/escape.js");
  }

  private static function removeTree(string $path): void {
    if (is_link($path)) {
      unlink($path);
    } else if (is_dir($path)) {
      foreach (scandir($path) as $entry) {
        if ($entry !== '.' and $entry !== '..') {
          self::removeTree("$path/$entry");
        }
      }
      rmdir($path);
    } else if (file_exists($path)) {
      unlink($path);
    }
  }

  private static function getPlayerResource(int $workspaceId, string $path): Slim\Http\Response {
    return PortalController::getPlayerResource(
      RequestCreator::create('GET', "/workspace/$workspaceId/player-resource")->withAttribute('ws_id', (string) $workspaceId),
      ResponseCreator::createEmpty(),
      ['ws_id' => (string) $workspaceId, 'path' => $path]
    );
  }

  function test_getWorkspaces(): void {
    $overview = (object) ['1' => ['name' => 'ws', 'booklets' => (object) []]];
    $this->portalDaoMock->expects('getWorkspaceOverview')->andReturn($overview)->once();

    $response = PortalController::getWorkspaces(RequestCreator::create('GET', '/portal/workspaces'), ResponseCreator::createEmpty());

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('{"1":{"name":"ws","booklets":{}}}', self::body($response));
  }

  function test_getTestBookletIds(): void {
    $this->portalDaoMock->expects('getTestBookletIds')->with(7)->andReturn((object) ['3' => 'B1'])->once();

    $response = PortalController::getTestBookletIds(
      RequestCreator::create('GET', '/workspace/7/test-booklet-ids')->withAttribute('ws_id', '7'),
      ResponseCreator::createEmpty()
    );

    $this->assertEquals('{"3":"B1"}', self::body($response));
  }

  function test_getBookletUnits(): void {
    $this->portalDaoMock->expects('getBookletUnits')->with(7)->andReturn((object) ['B1' => ['U1']])->once();

    $response = PortalController::getBookletUnits(
      RequestCreator::create('GET', '/workspace/7/booklet-units')->withAttribute('ws_id', '7'),
      ResponseCreator::createEmpty()
    );

    $this->assertEquals('{"B1":["U1"]}', self::body($response));
  }

  function test_getTesteeTokens(): void {
    $rows = [['code' => 'c1', 'bookletFileId' => 'B1', 'nameSuffix' => 'c1']];
    $this->portalDaoMock->expects('getTesteeTokens')->with(7, ['c1'])->andReturn($rows)->once();

    $response = PortalController::getTesteeTokens(
      RequestCreator::create('POST', '/workspace/7/testee-tokens', json_encode(['codes' => ['c1']]))->withAttribute('ws_id', '7'),
      ResponseCreator::createEmpty()
    );

    $this->assertEquals(json_encode($rows), self::body($response));
  }

  function test_getTesteeTokens_rejectsNonArrayCodes(): void {
    $this->expectException(HttpBadRequestException::class);
    PortalController::getTesteeTokens(
      RequestCreator::create('POST', '/workspace/7/testee-tokens', json_encode(['codes' => 'c1']))->withAttribute('ws_id', '7'),
      ResponseCreator::createEmpty()
    );
  }

  function test_getPlayerResource_streamsAPlayerOfTheWorkspace(): void {
    $this->makeDataDir();
    $this->portalDaoMock->expects('isPlayer')->with(1, 'verona-player-simple-6.0.html')->andReturn(true)->once();

    $response = self::getPlayerResource(1, 'verona-player-simple-6.0.html');

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('<html>player</html>', self::body($response));
    $this->assertEquals('text/html', $response->getHeaderLine('Content-Type'));
    $this->assertEquals('19', $response->getHeaderLine('Content-Length'));
    $this->assertEquals('private, max-age=3600', $response->getHeaderLine('Cache-Control'));
  }

  function test_getPlayerResource_streamsGeoGebraFilesWithoutPlayerLookup(): void {
    $this->makeDataDir();
    $this->portalDaoMock->shouldNotReceive('isPlayer');

    $response = self::getPlayerResource(1, 'GeoGebra/js/deployggb.js');

    $this->assertEquals('ggb();', self::body($response));
    $this->assertEquals('text/javascript', $response->getHeaderLine('Content-Type'));
  }

  function test_getPlayerResource_404ForAResourceThatIsNoPlayer(): void {
    $this->makeDataDir();
    $this->portalDaoMock->expects('isPlayer')->with(1, 'SAMPLE_UNITCONTENTS.HTM')->andReturn(false)->once();

    $this->expectException(HttpNotFoundException::class);
    self::getPlayerResource(1, 'SAMPLE_UNITCONTENTS.HTM');
  }

  function test_getPlayerResource_404WhenThePlayerFileIsMissing(): void {
    $this->makeDataDir();
    $this->portalDaoMock->expects('isPlayer')->with(1, 'missing-player.html')->andReturn(true)->once();

    $this->expectException(HttpNotFoundException::class);
    self::getPlayerResource(1, 'missing-player.html');
  }

  function test_getPlayerResource_404ForEverythingOutsidePlayersAndGeoGebra(): void {
    $this->makeDataDir();
    $this->portalDaoMock->shouldNotReceive('isPlayer');
    $rejected = [
      [1, ''],
      [1, '../secret.txt'],
      [1, '../../secret.txt'],
      [1, 'GeoGebra/../verona-player-simple-6.0.html'],
      [1, 'GeoGebra/../../../secret.txt'],
      [1, 'GeoGebra/escape.js'],
      [1, '/etc/passwd'],
      [1, 'GeoGebra/'],
      [1, 'GeoGebra/js'],
      [1, 'GeoGebra//js/deployggb.js'],
      [1, 'GeoGebra/./js/deployggb.js'],
      [1, 'GeoGebra\\js\\deployggb.js'],
      [1, 'sub/verona-player-simple-6.0.html'],
      [1, "verona-player-simple-6.0.html\0"],
      [1, "GeoGebra/js/deployggb.js\0.png"],
      [0, 'GeoGebra/js/deployggb.js'],
      [2, 'GeoGebra/js/deployggb.js'],
    ];
    foreach ($rejected as [$workspaceId, $path]) {
      try {
        self::getPlayerResource($workspaceId, $path);
        $this->fail("ws $workspaceId `$path` must be rejected");
      } catch (HttpNotFoundException) {
        $this->addToAssertionCount(1);
      }
    }
  }
}
