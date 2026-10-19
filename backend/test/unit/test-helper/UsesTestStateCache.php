<?php
declare(strict_types=1);

/**
 * Switches on the test-state cache (and with $buffer the write-behind) against a RedisFake.
 */
trait UsesTestStateCache {
  private function enableTestStateCache(bool $buffer = false): RedisFake {
    require_once "test/unit/mock-classes/RedisFake.php";
    SystemConfig::$cacheServer_host = 'cache-server';
    SystemConfig::$cacheServer_port = 6379;
    SystemConfig::$cacheServer_password = 'secret';
    SystemConfig::$cache_authToken = true;
    SystemConfig::$cache_authTokenTtl = 300;
    SystemConfig::$testState_buffer = $buffer;
    $redis = new RedisFake();
    (new ReflectionProperty(CacheService::class, 'redis'))->setValue(null, $redis);
    return $redis;
  }
}
