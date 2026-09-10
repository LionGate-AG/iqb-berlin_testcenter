<?php
/** @noinspection PhpUnhandledExceptionInspection */

class CacheService {
  private static Redis|null $redis = null;

  private static function connect(): bool {
    if (
      !SystemConfig::$cacheServer_host or
      !SystemConfig::$cacheServer_port or
      !SystemConfig::$cacheServer_password
    ) {
      return false;
    }
    if (!isset(self::$redis)) {
      try {
        self::$redis = new Redis([
          'host' => SystemConfig::$cacheServer_host,
          'port' => SystemConfig::$cacheServer_port,
          'auth' => SystemConfig::$cacheServer_password,
          // PERSISTENT (2026-09-10). Without this every request opened a brand-new
          // TCP connection to Redis: PHP resets class statics at the end of each
          // FPM request, so `self::$redis` was always null on entry and this
          // constructor always reconnected.
          //
          // Measured consequence: Redis reported 8,967,518 total_connections_received
          // against an op rate of single digits per second -- roughly one connection
          // per request. Under a login burst that is a connect storm from every
          // backend pod, and it produced 71 "Redis server ... went away" 500s on
          // PUT /session/login in 20 minutes while Redis itself was completely
          // healthy (28.8h uptime, 0 restarts, 0 rejected_connections, 6Mi of 1Gi,
          // 75m of 500m CPU). The failure was client-side connection churn, not the
          // server.
          //
          // The problem got materially worse when test_logs writes moved onto Redis
          // (see LogBuffer), because PATCH /state is the dominant request and it now
          // touches Redis too -- multiplying the connection rate by the PATCH:login
          // ratio.
          //
          // With `persistent` the socket is pooled by PHP and survives the request,
          // so the constructor hands back an existing connection instead of dialling
          // a new one: ~1 connection per FPM worker rather than ~1 per request.
          'persistent' => true
        ]);
      } catch (RedisException $exception) {
        throw new Exception("Could not reach Cache-Service: " . $exception->getMessage());
      }
    }
    return true;
  }

  /**
   * Drop the cached handle so the next connect() dials again.
   *
   * Needed because the handle is cached in a static. Under PHP-FPM that static is
   * discarded at the end of every request, so a connection that died between
   * requests is never reused. In a LONG-RUNNING CLI process (backend/drain-logs.php)
   * there is no such reset: once `self::$redis` held a dead socket, `isset()` stayed
   * true, connect() kept returning true, and every operation on the corpse threw --
   * permanently.
   *
   * That is not hypothetical. The log drainer ran for 28 HOURS reporting
   * `depth=redis-unavailable inserted=0 batches=0` while Redis was healthy and
   * reachable from inside its own pod, because its cached handle had died once and
   * could never be replaced. Rows accumulated in the queue the whole time.
   */
  public static function reset(): void {
    if (isset(self::$redis)) {
      try {
        self::$redis->close();
      } catch (Throwable $throwable) {
        // Already dead -- that is exactly why we are dropping it.
      }
    }
    self::$redis = null;
  }

  /**
   * The shared Redis handle, or null when the cache server is not configured or
   * unreachable. Exposed so LogBuffer can use the SAME connection singleton and
   * the same credentials as everything else here, rather than opening a second
   * connection with duplicated config.
   *
   * Callers MUST treat null as "Redis is unavailable" and degrade gracefully --
   * this returns null instead of throwing precisely so the test_logs write path
   * can fall back to a synchronous INSERT rather than failing the request.
   */
  public static function connection(bool $verify = false): ?Redis {
    try {
      if (!self::connect()) {
        return null;
      }
      // $verify is for LONG-RUNNING processes only. A cached handle whose socket
      // has died still passes isset(), so without an explicit liveness check the
      // caller would keep using a corpse forever (see reset()). One PING per call
      // is far too expensive for the request path -- which does not need it, since
      // the static is discarded every request anyway -- so it is opt-in. The drain
      // worker sets it once per loop iteration, a few times a second at most.
      if ($verify) {
        try {
          self::$redis->ping();
        } catch (Throwable $throwable) {
          self::reset();
          if (!self::connect()) {
            return null;
          }
        }
      }
    } catch (Throwable $throwable) {
      return null;
    }
    return self::$redis;
  }

  static function storeAuthentication(PersonSession $personSession): void {
    if (!self::connect()) {
      return;
    }
    self::$redis->set(
      'group-token:' . $personSession->getLoginSession()->getGroupToken(),
      $personSession->getLoginSession()->getLogin()->getWorkspaceId(),
      $personSession->getLoginSession()->getLogin()->getValidTo()
        ? $personSession->getLoginSession()->getLogin()->getValidTo() - TimeStamp::now()
        : 24 * 60 * 60
    );
  }

  public static function removeAuthentication(PersonSession $personSession): void {
    if (!self::connect()) {
      return;
    }
    self::$redis->del('group-token:' . $personSession->getPerson()->getToken());
  }

  public static function storeFile(string $filePath): void {
    // On the object-store path the file-server redirects to a presigned URL and
    // never reads from this cache, so warming it would only waste Redis memory.
    if (Storage::isObjectStore()) {
      return;
    }
    if (!SystemConfig::$cacheServer_includeFiles) {
      return;
    }
    if (!self::connect()) {
      return;
    }
    if (self::$redis->exists("file:$filePath")) {
      self::$redis->expire("file:$filePath", 24 * 60 * 60);
    } else {
      try {
        self::$redis->set("file:$filePath", file_get_contents(DATA_DIR . $filePath), 24 * 60 * 60);
      } catch (RedisException $e) {
        error_log('Cache exhausted: ' . $filePath);
      }
    }
  }

  static function getStatusFilesCache(): string {
    if (
      !SystemConfig::$cacheServer_host or
      !SystemConfig::$cacheServer_port or
      !SystemConfig::$cacheServer_password or
      !SystemConfig::$cacheServer_includeFiles
    ) {
      return 'off';
    }
    try {
      self::connect();
    } catch (RedisException $exception) {
      return 'unreachable';
    }
    return 'on';
  }

  public static function getFailedLogins(string $name): int {
    if (!self::connect()) return 0;
    $loginsFailed = self::$redis->get("login-failed:$name:");
    return (int) $loginsFailed;
  }

  public static function addFailedLogin(string $name): void {
    if (!self::connect()) return;
    $loginsFailed = self::getFailedLogins($name);
    $loginsFailed++;
    $expiration = SystemConfig::$debug_fastLoginReuse ? 5 : 30 * 60;
    self::$redis->set("login-failed:$name:", $loginsFailed, $expiration);
  }
}
