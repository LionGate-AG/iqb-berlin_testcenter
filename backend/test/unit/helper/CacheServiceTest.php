<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CacheServiceTest extends TestCase {
  private RedisFake $redis;

  /** @var array{token: string, id: int, type: string, workspaceId: int, mode: string, validTo: string, group: string} */
  private array $row = [
    'token' => 'person-token',
    'id' => 1,
    'type' => 'person',
    'workspaceId' => 1,
    'mode' => 'run-hot-return',
    'validTo' => '2030-01-02 10:00:00+01',
    'group' => 'sample_group'
  ];

  static function setUpBeforeClass(): void {
    require_once "test/unit/mock-classes/RedisFake.php";
  }

  function setUp(): void {
    SystemConfig::$cacheServer_host = 'cache-server';
    SystemConfig::$cacheServer_port = 6379;
    SystemConfig::$cacheServer_password = 'secret';
    SystemConfig::$cache_authToken = true;
    SystemConfig::$cache_authTokenTtl = 300;
    $this->redis = new RedisFake();
    self::useRedis($this->redis);
  }

  private static function useRedis(?Redis $redis): void {
    (new ReflectionProperty(CacheService::class, 'redis'))->setValue(null, $redis);
  }

  function test_authTokenCache_isOffByDefault(): void {
    putenv('TESTCENTER_CACHE_AUTH_TOKEN');
    putenv('TESTCENTER_AUTH_TOKEN_TTL');
    SystemConfig::readEnvironment();

    $this->assertFalse(SystemConfig::$cache_authToken);
    $this->assertSame(300, SystemConfig::$cache_authTokenTtl);
    $this->assertFalse(CacheService::authTokenCacheEnabled());
  }

  function test_authTokenCache_readFromEnvironment(): void {
    putenv('TESTCENTER_CACHE_AUTH_TOKEN=1');
    putenv('TESTCENTER_AUTH_TOKEN_TTL=60');
    SystemConfig::readEnvironment();

    $this->assertTrue(SystemConfig::$cache_authToken);
    $this->assertSame(60, SystemConfig::$cache_authTokenTtl);
    $this->assertTrue(CacheService::authTokenCacheEnabled());
  }

  function test_authTokenCache_malformedTtlIsRejected(): void {
    putenv('TESTCENTER_AUTH_TOKEN_TTL=5min');

    $this->expectExceptionMessage('TESTCENTER_AUTH_TOKEN_TTL');
    SystemConfig::readEnvironment();
  }

  function test_authTokenCache_switchedOff_doesNotTouchRedis(): void {
    SystemConfig::$cache_authToken = false;

    CacheService::storeAuthTokenRow('person-token', $this->row, 0);
    $this->assertNull(CacheService::getAuthTokenRow('person-token'));
    CacheService::invalidateAuthToken('person-token');
    CacheService::storeGroupToken(1, 'sample_group', 'group-token');
    $this->assertNull(CacheService::getGroupToken(1, 'sample_group'));
    $this->assertNull(CacheService::isGroupTokenValid(1, 'group-token'));
    CacheService::flushAuthTokens();

    $this->assertSame(0, $this->redis->calls);
  }

  function test_authTokenCache_ttlZero_disablesCache(): void {
    SystemConfig::$cache_authTokenTtl = 0;

    CacheService::storeAuthTokenRow('person-token', $this->row, 0);

    $this->assertFalse(CacheService::authTokenCacheEnabled());
    $this->assertSame(0, $this->redis->calls);
  }

  function test_storeAuthTokenRow_and_getAuthTokenRow(): void {
    CacheService::storeAuthTokenRow('person-token', $this->row + ['unrelated' => 'column'], 0);

    $this->assertSame($this->row, CacheService::getAuthTokenRow('person-token'));
    $this->assertSame(300, $this->redis->ttl['auth-token:person:person-token']);
    $this->assertNull(CacheService::getAuthTokenRow('other-token'));
  }

  function test_storeAuthTokenRow_ttlIsCappedBySessionExpiry(): void {
    CacheService::storeAuthTokenRow('person-token', $this->row, TimeStamp::now() + 60);

    $ttl = $this->redis->ttl['auth-token:person:person-token'];
    $this->assertGreaterThan(0, $ttl);
    $this->assertLessThanOrEqual(60, $ttl);
  }

  function test_storeAuthTokenRow_expiredSessionIsNotStored(): void {
    CacheService::storeAuthTokenRow('person-token', $this->row, TimeStamp::now() - 1);

    $this->assertEmpty($this->redis->store);
  }

  function test_getAuthTokenRow_incompleteEntryIsAMiss(): void {
    $this->redis->store['auth-token:person:person-token'] = (string) json_encode(['token' => 'person-token']);
    $this->redis->store['auth-token:person:garbage'] = 'not json';

    $this->assertNull(CacheService::getAuthTokenRow('person-token'));
    $this->assertNull(CacheService::getAuthTokenRow('garbage'));
  }

  function test_invalidateAuthToken(): void {
    CacheService::storeAuthTokenRow('person-token', $this->row, 0);

    CacheService::invalidateAuthToken('person-token');
    CacheService::invalidateAuthToken(null);

    $this->assertNull(CacheService::getAuthTokenRow('person-token'));
  }

  function test_groupToken(): void {
    $this->assertNull(CacheService::getGroupToken(1, 'sample_group'));
    $this->assertNull(CacheService::isGroupTokenValid(1, 'group-token'));

    CacheService::storeGroupToken(1, 'sample_group', 'group-token');

    $this->assertSame('group-token', CacheService::getGroupToken(1, 'sample_group'));
    $this->assertNull(CacheService::getGroupToken(2, 'sample_group'));
    $this->assertTrue(CacheService::isGroupTokenValid(1, 'group-token'));
    $this->assertNull(CacheService::isGroupTokenValid(2, 'group-token'));

    CacheService::storeGroupTokenValid(1, 'other-group-token');
    $this->assertTrue(CacheService::isGroupTokenValid(1, 'other-group-token'));
  }

  function test_flushAuthTokens_dropsTokensButNothingElse(): void {
    CacheService::storeAuthTokenRow('person-token', $this->row, 0);
    CacheService::storeAuthTokenRow('other-person-token', $this->row, 0);
    CacheService::storeGroupToken(1, 'sample_group', 'group-token');
    $this->redis->store['group-token:group-token'] = '1';
    $this->redis->store['login-failed:monitor:'] = '3';

    CacheService::flushAuthTokens();

    $this->assertSame(['group-token:group-token', 'login-failed:monitor:'], array_keys($this->redis->store));
  }

  function test_failingRedis_behavesLikeAMiss(): void {
    CacheService::storeAuthTokenRow('person-token', $this->row, 0);
    CacheService::storeGroupToken(1, 'sample_group', 'group-token');
    $this->redis->failing = true;

    $this->assertNull(CacheService::getAuthTokenRow('person-token'));
    $this->assertNull(CacheService::getGroupToken(1, 'sample_group'));
    $this->assertNull(CacheService::isGroupTokenValid(1, 'group-token'));
    CacheService::storeAuthTokenRow('person-token', $this->row, 0);
    CacheService::storeGroupToken(1, 'sample_group', 'group-token');
    CacheService::storeGroupTokenValid(1, 'group-token');
    CacheService::invalidateAuthToken('person-token');
    CacheService::flushAuthTokens();
  }

  function test_unreachableRedis_behavesLikeAMiss(): void {
    self::useRedis(null);
    SystemConfig::$cacheServer_host = '127.0.0.1';
    SystemConfig::$cacheServer_port = 1;

    $this->assertNull(CacheService::getAuthTokenRow('person-token'));
    CacheService::storeAuthTokenRow('person-token', $this->row, 0);
    CacheService::invalidateAuthToken('person-token');
    $this->assertNull(CacheService::getGroupToken(1, 'sample_group'));
  }
}
