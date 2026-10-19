<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Exception\HttpNotFoundException;

class TestControllerInjector extends TestController {
  public static function injectSessionDAO(SessionDAO $sessionDao): void {
    TestController::$_sessionDAO = $sessionDao;
  }
}

/**
 * TestController::getFile() on the object store: the route test-takers' players and units are loaded from.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TestControllerTest extends TestCase {
  use MockeryPHPUnitIntegration;

  private StorageDriverFake $storage;

  static function setUpBeforeClass(): void {
    require_once "test/unit/VfsForTest.class.php";
    VfsForTest::setUpBeforeClass();
  }

  function setUp(): void {
    require_once "test/unit/test-helper/RequestCreator.class.php";
    require_once "test/unit/test-helper/ResponseCreator.class.php";
    require_once "test/unit/mock-classes/StorageDriverFake.php";
    require_once "test/unit/mock-classes/RedisFake.php";

    VfsForTest::setUp();
    Mockery::mock('overload:' . WorkspaceDAO::class);

    $sessionDao = $this->createMock(SessionDAO::class);
    $sessionDao->method('groupTokenExists')->willReturnMap([[1, 'group-token', true]]);
    TestControllerInjector::injectSessionDAO($sessionDao);

    $this->storage = new StorageDriverFake(['ws_1/Resource/player.html' => '<html lang="en"></html>']);
    SystemConfig::$storage_driver = 's3';
    SystemConfig::$storage_presignTtl = 3600;
    Storage::setDriver($this->storage);
  }

  private function getFile(string $path): ResponseInterface {
    $request = RequestCreator::create('GET', "/file/group-token/ws_1/$path")
      ->withAttribute('group_token', 'group-token')
      ->withAttribute('ws_id', 1);

    return TestController::getFile($request, ResponseCreator::createEmpty(), ['path' => $path]);
  }

  function test_getFile_objectStore_redirectsToAPresignedUrl(): void {
    $response = $this->getFile('Resource/player.html');

    $this->assertSame(302, $response->getStatusCode());
    $this->assertSame(
      'https://s3.example.org/tc-files/ws_1/Resource/player.html?X-Amz-Expires=3600',
      $response->getHeaderLine('Location')
    );
    $this->assertSame('private', $response->getHeaderLine('Cache-Control'));
    $this->assertSame(['exists ws_1/Resource/player.html', 'presignGet ws_1/Resource/player.html'], $this->storage->calls);
  }

  function test_getFile_objectStore_missingObject(): void {
    try {
      $this->getFile('Resource/other.html');
      $this->fail('expected a 404');
    } catch (HttpNotFoundException) {
      $this->assertSame(['exists ws_1/Resource/other.html'], $this->storage->calls);
    }
  }

  function test_getFile_objectStore_rejectsTraversalWithoutAskingTheStore(): void {
    $this->storage->objects['ws_1/Testtakers/SAMPLE_TESTTAKERS.XML'] = '<Testtakers/>';

    try {
      $this->getFile('Resource/../Testtakers/SAMPLE_TESTTAKERS.XML');
      $this->fail('expected a 404');
    } catch (HttpNotFoundException) {
      $this->assertSame([], $this->storage->calls);
    }
  }

  private function usePresignedUrlCache(): RedisFake {
    SystemConfig::$cacheServer_host = 'cache-server';
    SystemConfig::$cacheServer_port = 6379;
    SystemConfig::$cacheServer_password = 'secret';
    SystemConfig::$cache_presignedUrls = true;
    $redis = new RedisFake();
    (new ReflectionProperty(CacheService::class, 'redis'))->setValue(null, $redis);

    return $redis;
  }

  function test_getFile_objectStore_cacheMiss_signsAndStoresTheUrl(): void {
    $redis = $this->usePresignedUrlCache();

    $response = $this->getFile('Resource/player.html');

    $url = 'https://s3.example.org/tc-files/ws_1/Resource/player.html?X-Amz-Expires=3600';
    $this->assertSame($url, $response->getHeaderLine('Location'));
    $this->assertSame(['presigned-url:ws_1/Resource/player.html' => $url], $redis->store);
  }

  function test_getFile_objectStore_cacheHit_skipsTheObjectStore(): void {
    $redis = $this->usePresignedUrlCache();
    $redis->store['presigned-url:ws_1/Resource/player.html'] = 'https://s3.example.org/cached';

    $response = $this->getFile('Resource/player.html');

    $this->assertSame(302, $response->getStatusCode());
    $this->assertSame('https://s3.example.org/cached', $response->getHeaderLine('Location'));
    $this->assertSame('private', $response->getHeaderLine('Cache-Control'));
    $this->assertSame([], $this->storage->calls);
  }

  function test_getFile_objectStore_rejectsTraversalBeforeTheCache(): void {
    $redis = $this->usePresignedUrlCache();
    $redis->store['presigned-url:ws_1/Resource/../Testtakers/SAMPLE_TESTTAKERS.XML'] = 'https://s3.example.org/cached';

    try {
      $this->getFile('Resource/../Testtakers/SAMPLE_TESTTAKERS.XML');
      $this->fail('expected a 404');
    } catch (HttpNotFoundException) {
      $this->assertSame(0, $redis->calls);
      $this->assertSame([], $this->storage->calls);
    }
  }
}
