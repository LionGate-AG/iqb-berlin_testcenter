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
          // Keeps the socket open across requests. With the auth-token cache switched on, every
          // authenticated request talks to Redis, and a new connection per request would make
          // connection set-up the dominant cost.
          'persistent' => true
        ]);
      } catch (RedisException $exception) {
        throw new Exception("Could not reach Cache-Service: " . $exception->getMessage());
      }
    }
    return true;
  }

  /**
   * The shared Redis handle, or null when the cache server is not configured or not reachable.
   *
   * Unlike connect() this never throws: the callers are optimisations which fall back to the database when it
   * returns null.
   *
   * @param bool $verify for long-running processes only: PING the handle and reconnect if its socket has died. A web
   *   request does not need this, because the handle does not outlive the request; a worker that keeps it for days
   *   would otherwise go on using a dead socket forever.
   */
  public static function connection(bool $verify = false): ?Redis {
    try {
      if (!self::connect()) {
        return null;
      }
      if ($verify and !self::isAlive()) {
        // the client connects lazily, so only a command on the new handle shows whether the server is back
        self::reset();
        if (!self::connect() or !self::isAlive()) {
          self::reset();
          return null;
        }
      }
      return self::$redis;
    } catch (Throwable) {
      return null;
    }
  }

  private static function isAlive(): bool {
    try {
      self::$redis?->ping();
      return isset(self::$redis);
    } catch (Throwable) {
      return false;
    }
  }

  /**
   * Drops the shared handle, so the next call dials again.
   */
  public static function reset(): void {
    if (isset(self::$redis)) {
      try {
        self::$redis->close();
      } catch (Throwable) {
        // the connection is dropped because it is broken; closing it may fail for the same reason
      }
    }
    self::$redis = null;
  }

  // Auth-token cache
  //
  // SessionDAO::getToken() runs on every authenticated request and resolves the token with a join over
  // person_sessions, login_sessions and logins. The row it returns only changes when the application creates,
  // rotates or deletes a session, or when a workspace's logins are re-imported - all places that invalidate the
  // cache explicitly. The TTL only bounds changes made outside the application, e.g. directly in the database.
  //
  // The raw result row is cached, not the AuthToken built from it, so a hit runs through the same validation as a
  // miss (removed login, expiry). Only person tokens are cached, and the cache is only consulted on routes that accept
  // them: admin and login tokens are rare, and an admin logout must take effect immediately.
  //
  // The group tokens of login_session_groups are cached under the same switch, because they are derived from the
  // same login data and are invalidated together with the person tokens.

  private const string AUTH_TOKEN_PREFIX = 'auth-token:person:';
  /** <workspaceId>:<groupName> -> group token */
  private const string GROUP_TOKEN_PREFIX = 'group-session-token:';
  /** <workspaceId>:<groupToken> -> '1', positive results only */
  private const string GROUP_TOKEN_VALID_PREFIX = 'group-session-valid:';
  /** @var list<string> the fields of the token row SessionDAO::getToken() needs */
  private const array AUTH_TOKEN_ROW_FIELDS = ['token', 'id', 'type', 'workspaceId', 'mode', 'validTo', 'group'];

  public static function authTokenCacheEnabled(): bool {
    return SystemConfig::$cache_authToken and (SystemConfig::$cache_authTokenTtl > 0);
  }

  private static function authTokenCache(): ?Redis {
    return self::authTokenCacheEnabled() ? self::connection() : null;
  }

  /**
   * The cached token row, or null on a miss, when the cache is off, or when Redis is not reachable. Callers have to
   * query the database on null.
   *
   * @return array<array-key, mixed>|null the row with the fields of AUTH_TOKEN_ROW_FIELDS
   */
  public static function getAuthTokenRow(string $tokenString): ?array {
    $redis = self::authTokenCache();
    if (!$redis) {
      return null;
    }
    try {
      $raw = $redis->get(self::AUTH_TOKEN_PREFIX . $tokenString);
    } catch (Throwable) {
      return null;
    }
    if (!is_string($raw)) {
      return null;
    }
    $row = json_decode($raw, true);
    if (!is_array($row)) {
      return null;
    }
    foreach (self::AUTH_TOKEN_ROW_FIELDS as $field) {
      if (!array_key_exists($field, $row)) {
        return null;
      }
    }
    return $row;
  }

  /**
   * Caches a token row. The entry never outlives the session it describes: its TTL is the configured TTL or the time
   * until $validToTimestamp, whichever is shorter. A $validToTimestamp of 0 means the session does not expire.
   *
   * @param array<array-key, mixed> $row the result row of the token query; fields beyond AUTH_TOKEN_ROW_FIELDS are dropped
   */
  public static function storeAuthTokenRow(string $tokenString, array $row, int $validToTimestamp): void {
    $redis = self::authTokenCache();
    if (!$redis) {
      return;
    }
    $ttl = SystemConfig::$cache_authTokenTtl;
    if ($validToTimestamp > 0) {
      $ttl = min($ttl, $validToTimestamp - TimeStamp::now());
    }
    if ($ttl <= 0) {
      return;
    }
    $entry = array_intersect_key($row, array_flip(self::AUTH_TOKEN_ROW_FIELDS));
    try {
      $redis->set(self::AUTH_TOKEN_PREFIX . $tokenString, json_encode($entry), $ttl);
    } catch (Throwable) {
      // a failed write only costs the next request a database query
    }
  }

  /**
   * Drops one cached token. To be called wherever a person token stops being valid.
   */
  public static function invalidateAuthToken(?string $tokenString): void {
    if (!$tokenString) {
      return;
    }
    $redis = self::authTokenCache();
    if (!$redis) {
      return;
    }
    try {
      $redis->del(self::AUTH_TOKEN_PREFIX . $tokenString);
    } catch (Throwable) {
      // the entry expires with its TTL
    }
  }

  /**
   * Drops all cached person tokens and group tokens. For changes to logins or sessions where the affected tokens are
   * not known one by one, e.g. a login import or deleting a group's or a workspace's data.
   */
  public static function flushAuthTokens(): void {
    self::flushByPrefix(
      self::authTokenCache(),
      self::AUTH_TOKEN_PREFIX,
      self::GROUP_TOKEN_PREFIX,
      self::GROUP_TOKEN_VALID_PREFIX
    );
  }

  /**
   * SCAN and UNLINK instead of KEYS and DEL: KEYS blocks Redis for the whole keyspace, and UNLINK frees the memory in
   * the background. This runs on admin actions only, never on the request path of test-takers.
   */
  private static function flushByPrefix(?Redis $redis, string ...$prefixes): void {
    if (!$redis) {
      return;
    }
    try {
      $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
      foreach ($prefixes as $prefix) {
        $iterator = null;
        while ($keys = $redis->scan($iterator, $prefix . '*', 1000)) {
          $redis->unlink($keys);
        }
      }
    } catch (Throwable) {
      // the entries expire with their TTL
    }
  }

  /** The cached group token of a group, or null on a miss. */
  public static function getGroupToken(int $workspaceId, string $groupName): ?string {
    $redis = self::authTokenCache();
    if (!$redis) {
      return null;
    }
    try {
      $token = $redis->get(self::GROUP_TOKEN_PREFIX . "$workspaceId:$groupName");
    } catch (Throwable) {
      return null;
    }
    return (is_string($token) and ($token !== '')) ? $token : null;
  }

  /**
   * Caches a group token in both directions: by group for the login, and as known-valid for the file route.
   */
  public static function storeGroupToken(int $workspaceId, string $groupName, string $token): void {
    if ($token === '') {
      return;
    }
    $redis = self::authTokenCache();
    if (!$redis) {
      return;
    }
    try {
      $redis->set(self::GROUP_TOKEN_PREFIX . "$workspaceId:$groupName", $token, SystemConfig::$cache_authTokenTtl);
      $redis->set(self::GROUP_TOKEN_VALID_PREFIX . "$workspaceId:$token", '1', SystemConfig::$cache_authTokenTtl);
    } catch (Throwable) {
      // a failed write only costs the next request a database query
    }
  }

  /**
   * True when the group token is known to be valid for the workspace, null when that is unknown. Never false: only
   * positive results are cached, because a cached negative would reject a group created after it was cached.
   */
  public static function isGroupTokenValid(int $workspaceId, string $token): ?bool {
    if ($token === '') {
      return null;
    }
    $redis = self::authTokenCache();
    if (!$redis) {
      return null;
    }
    try {
      return ($redis->get(self::GROUP_TOKEN_VALID_PREFIX . "$workspaceId:$token") === '1') ? true : null;
    } catch (Throwable) {
      return null;
    }
  }

  public static function storeGroupTokenValid(int $workspaceId, string $token): void {
    if ($token === '') {
      return;
    }
    $redis = self::authTokenCache();
    if (!$redis) {
      return;
    }
    try {
      $redis->set(self::GROUP_TOKEN_VALID_PREFIX . "$workspaceId:$token", '1', SystemConfig::$cache_authTokenTtl);
    } catch (Throwable) {
      // a failed write only costs the next request a database query
    }
  }

  // Presigned-URL cache
  //
  // On the object store, TestController::getFile() answers every resource request of a test-taker with a redirect to
  // a presigned URL. Signing is local, but the existence check before it is a blocking round trip to the object store
  // that holds a PHP worker. All test-takers of a workspace load the same few files (player, unit definitions), so
  // the signed URL is cached per object key and the round trip happens once per file and URL lifetime.
  //
  // The signature covers the key, not the content: a file replaced under the same name is served in its new version
  // through a cached URL. A cached URL of a deleted file leads to a 404 of the object store instead of the backend;
  // Workspace::deleteFiles() drops all entries so that the backend answers again.

  /** <object key> -> presigned GET url */
  private const string PRESIGNED_URL_PREFIX = 'presigned-url:';

  /**
   * Seconds an entry expires before the signature does, so a URL handed out in the last second of its entry is still
   * valid when the client follows the redirect.
   */
  private const int PRESIGNED_URL_MARGIN = 300;

  public static function presignedUrlCacheEnabled(): bool {
    return SystemConfig::$cache_presignedUrls and (self::presignedUrlTtl() > 0);
  }

  /** The configured URL lifetime minus the margin; 0 (cache off) when the lifetime is not longer than the margin. */
  private static function presignedUrlTtl(): int {
    return max(0, SystemConfig::$storage_presignTtl - self::PRESIGNED_URL_MARGIN);
  }

  private static function presignedUrlCache(): ?Redis {
    return self::presignedUrlCacheEnabled() ? self::connection() : null;
  }

  /**
   * The cached presigned URL of an object, or null on a miss, when the cache is off, or when Redis is not reachable.
   * $key must already be validated (Workspace::getFileKey()).
   */
  public static function getPresignedUrl(string $key): ?string {
    $redis = self::presignedUrlCache();
    if (!$redis) {
      return null;
    }
    try {
      $url = $redis->get(self::PRESIGNED_URL_PREFIX . $key);
    } catch (Throwable) {
      return null;
    }
    return (is_string($url) and ($url !== '')) ? $url : null;
  }

  /** Caches a presigned URL that was just signed for SystemConfig::$storage_presignTtl seconds. */
  public static function storePresignedUrl(string $key, string $url): void {
    if ($url === '') {
      return;
    }
    $redis = self::presignedUrlCache();
    if (!$redis) {
      return;
    }
    try {
      $redis->set(self::PRESIGNED_URL_PREFIX . $key, $url, self::presignedUrlTtl());
    } catch (Throwable) {
      // a failed write only costs the next request a round trip to the object store
    }
  }

  /** Drops all cached presigned URLs. */
  public static function flushPresignedUrls(): void {
    self::flushByPrefix(self::presignedUrlCache(), self::PRESIGNED_URL_PREFIX);
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

  public static function resetFailedLogins(string $name): void {
    if (!self::connect()) return;

    self::$redis->del("login-failed:$name:");
  }
}
