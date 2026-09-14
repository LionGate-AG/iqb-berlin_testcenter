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


  // ===========================================================================
  // Auth-token cache
  //
  // WHY: SessionDAO::getToken() runs on EVERY authenticated request (see
  // RequireToken::__invoke) and resolves the token with a 3-table join
  // (person_sessions + login_sessions + logins). Measured 2026-09-10 with
  // test_logs already moved off the database, that statement was
  //
  //     1,334,548 executions, 581 ms average, 775,481 s total
  //
  // -- the single largest consumer of database time in the system, and
  // historically the top source of connect timeouts (338 of 1,406 in one
  // 20-minute window, see the comment on getToken()).
  //
  // The row it returns changes only when a session is created, its token is
  // rotated, or it is logged out -- all of which are events this application
  // performs itself and can therefore invalidate precisely. Between those
  // events the query re-derives an identical answer thousands of times per
  // second per test-taker.
  //
  // WHAT IS CACHED: the raw result row, not the constructed AuthToken. That is
  // deliberate -- a cache hit and a cache miss then feed the SAME validation
  // code (workspaceId null -> 410, type check, TimeStamp::checkExpiration), so
  // a cached entry can never bypass a check that a fresh one would fail.
  //
  // PERSON TOKENS ONLY. Admin and login tokens are deliberately excluded:
  // they are low volume (so there is nothing to win) and admin sessions are
  // security-sensitive, where a logout must take effect immediately rather
  // than within a TTL.
  // ===========================================================================

  /** Key namespace. Prod tokens are Random::string(24) with no type prefix, so the type must be in the key. */
  private const AUTH_TOKEN_PREFIX = 'auth-token:person:';

  /**
   * Upper bound on how long a cached row may be served, in seconds.
   *
   * Every invalidation this code CAN do precisely, it does (see
   * invalidateAuthToken() and its call sites in SessionDAO). This ceiling
   * exists for the mutations it cannot see individually:
   *
   *   * `WorkspaceDAO::deleteLoginSource()` / `addLoginSource()` rewrite whole
   *     login sets on XML import -- these DO call flushAuthTokens(), so they
   *     are covered, but only for the workspace-import path;
   *   * anything that edits `logins` (mode, group_name, workspace_id) or
   *     `person_sessions.valid_until` directly in the database, outside the
   *     application.
   *
   * So the worst case is: a login removed by hand keeps working for up to this
   * long. Five minutes against a session lifetime of ~23 hours. Tunable with
   * TESTCENTER_AUTH_TOKEN_TTL; set it to 0 to disable the cache entirely.
   */
  private const AUTH_TOKEN_DEFAULT_TTL = 300;

  private static ?bool $authTokenCacheEnabled = null;
  private static ?int $authTokenTtl = null;

  public static function authTokenCacheEnabled(): bool {
    if (self::$authTokenCacheEnabled === null) {
      self::$authTokenCacheEnabled = (getenv('TESTCENTER_CACHE_AUTH_TOKEN') === '1') && (self::authTokenTtl() > 0);
    }
    return self::$authTokenCacheEnabled;
  }

  private static function authTokenTtl(): int {
    if (self::$authTokenTtl === null) {
      $configured = getenv('TESTCENTER_AUTH_TOKEN_TTL');
      self::$authTokenTtl = ($configured === false or $configured === '')
        ? self::AUTH_TOKEN_DEFAULT_TTL
        : max(0, (int) $configured);
    }
    return self::$authTokenTtl;
  }

  /**
   * The cached token row, or null on a miss / when the cache is off or Redis is
   * unreachable. Callers MUST fall back to the database on null -- a Redis
   * outage has to degrade to today's behaviour, never to a failed login.
   *
   * @return array{token: string, id: string, type: string, workspaceId: string, mode: string, validTo: ?string, group: string}|null
   */
  public static function getAuthTokenRow(string $tokenString): ?array {
    if (!self::authTokenCacheEnabled()) {
      return null;
    }
    $redis = self::connection();
    if ($redis === null) {
      return null;
    }
    try {
      $raw = $redis->get(self::AUTH_TOKEN_PREFIX . $tokenString);
    } catch (Throwable $throwable) {
      return null;
    }
    if (!is_string($raw) or $raw === '') {
      return null;
    }
    $row = @igbinary_unserialize($raw);
    // An entry written by an older pod with a different field set would fail
    // the validation below in confusing ways; treat it as a miss instead.
    if (!is_array($row) or !isset($row['token'], $row['type'], $row['workspaceId'])) {
      return null;
    }
    return $row;
  }

  /**
   * Cache a resolved token row.
   *
   * $validToTimestamp is the session's own expiry (0 when it has none). The TTL
   * is the smaller of that and the configured ceiling, so an entry can never
   * outlive the session it describes even if every explicit invalidation were
   * to be missed.
   */
  public static function storeAuthTokenRow(string $tokenString, array $row, int $validToTimestamp): void {
    if (!self::authTokenCacheEnabled()) {
      return;
    }
    $redis = self::connection();
    if ($redis === null) {
      return;
    }

    $ttl = self::authTokenTtl();
    if ($validToTimestamp > 0) {
      $ttl = min($ttl, $validToTimestamp - TimeStamp::now());
    }
    if ($ttl <= 0) {
      return;
    }

    try {
      $redis->set(self::AUTH_TOKEN_PREFIX . $tokenString, igbinary_serialize($row), $ttl);
    } catch (Throwable $throwable) {
      // Caching is an optimisation; never let it fail a request.
    }
  }

  /**
   * Drop one cached token. Called wherever `person_sessions.token` stops being
   * valid: rotation in SessionDAO::createOrUpdatePersonSession() (which happens
   * on EVERY login -- forceUpdateToken defaults to true) and logout in
   * SessionDAO::deletePersonToken().
   *
   * Tolerates null/empty so call sites do not have to guard: a session whose
   * token was null had nothing cached under it.
   */
  public static function invalidateAuthToken(?string $tokenString): void {
    if (!self::authTokenCacheEnabled() or !$tokenString) {
      return;
    }
    $redis = self::connection();
    if ($redis === null) {
      return;
    }
    try {
      $redis->del(self::AUTH_TOKEN_PREFIX . $tokenString);
    } catch (Throwable $throwable) {
      // Worst case the stale entry is served until its TTL expires.
    }
  }

  /**
   * Drop every cached token. For BULK changes to `logins`, where the individual
   * affected tokens are not known: workspace login import replaces whole login
   * sets and rewrites `person_sessions.valid_until` wholesale.
   *
   * SCAN + UNLINK rather than KEYS + DEL: KEYS blocks the server for the whole
   * keyspace scan, and this shares a Redis instance with the test_logs queue
   * (LogBuffer) on the hot request path. UNLINK frees in a background thread.
   * The cost is paid only on an admin import, never on the request path.
   */
  public static function flushAuthTokens(): void {
    // Group tokens go too. A login import rewrites `logins`, which is what both
    // caches are ultimately derived from, so flushing one and not the other would
    // leave the two disagreeing.
    self::flushByPrefix(self::AUTH_TOKEN_PREFIX, self::GROUP_TOKEN_PREFIX, self::GROUP_TOKEN_VALID_PREFIX);
  }

  /**
   * Unlink every key under the given prefixes.
   *
   * SCAN + UNLINK rather than KEYS + DEL: KEYS blocks the server for the whole
   * keyspace scan, and this shares a Redis instance with the test_logs queue
   * (LogBuffer) on the hot request path. UNLINK frees in a background thread.
   * The cost is paid only on an admin action, never on the request path.
   */
  private static function flushByPrefix(string ...$prefixes): void {
    if (!self::authTokenCacheEnabled()) {
      return;
    }
    $redis = self::connection();
    if ($redis === null) {
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
    } catch (Throwable $throwable) {
      // Entries expire on their own within TESTCENTER_AUTH_TOKEN_TTL.
    }
  }


  // ===========================================================================
  // Group-token cache
  //
  // WHY: `login_session_groups` held FOUR rows and still absorbed 363,299 row
  // operations and 1,027 s of database time in the 2026-09-11 run, because two
  // hot paths resolve it from SQL on every call:
  //
  //   getOrCreateGroupToken()  93,682 blind `insert ignore` x 4.6 ms = 430 s
  //                            (+ the follow-up SELECT on every duplicate)
  //   groupTokenExists()      175,805 lookups x 5.7 ms = 1,011 s, on the
  //                            file-serving route GET /file/{group_token}/...
  //
  // A group token is stable for the life of the group row, and there are a
  // handful of groups per workspace. This is about as cacheable as data gets.
  //
  // INVALIDATION: the row changes in exactly two ways --
  //   * AdminDAO::deleteResultData() deletes it (admin "delete result data");
  //   * WorkspaceDAO login import rewrites login sets.
  // Both flush. AdminDAO's other write only touches `last_modified`, which is
  // not cached here, so it deliberately does not flush.
  //
  // Governed by the same TESTCENTER_CACHE_AUTH_TOKEN / TESTCENTER_AUTH_TOKEN_TTL
  // switch as the auth-token cache: one operational control for session caching,
  // so there is one thing to check when something looks stale -- and one thing
  // to turn off to revert to pure SQL.
  // ===========================================================================

  /** <workspaceId>:<groupName> -> group token. */
  private const GROUP_TOKEN_PREFIX = 'group-session-token:';

  /** <workspaceId>:<token> -> "1". POSITIVE results only; see isGroupTokenValid(). */
  private const GROUP_TOKEN_VALID_PREFIX = 'group-session-valid:';

  /** The cached group token for a workspace+group, or null on a miss. */
  public static function getGroupToken(int $workspaceId, string $groupName): ?string {
    if (!self::authTokenCacheEnabled()) {
      return null;
    }
    $redis = self::connection();
    if ($redis === null) {
      return null;
    }
    try {
      $token = $redis->get(self::GROUP_TOKEN_PREFIX . $workspaceId . ':' . $groupName);
    } catch (Throwable $throwable) {
      return null;
    }
    return (is_string($token) and $token !== '') ? $token : null;
  }

  public static function storeGroupToken(int $workspaceId, string $groupName, string $token): void {
    if (!self::authTokenCacheEnabled() or $token === '') {
      return;
    }
    $redis = self::connection();
    if ($redis === null) {
      return;
    }
    try {
      $redis->set(self::GROUP_TOKEN_PREFIX . $workspaceId . ':' . $groupName, $token, self::authTokenTtl());
      // Populate the reverse lookup at the same time: the code that needs the
      // token and the code that validates it are different routes, and warming
      // both here means the file route does not have to pay its own first miss.
      $redis->set(self::GROUP_TOKEN_VALID_PREFIX . $workspaceId . ':' . $token, '1', self::authTokenTtl());
    } catch (Throwable $throwable) {
      // Caching is an optimisation; never let it fail a request.
    }
  }

  /**
   * true when this token is KNOWN valid for the workspace, null when unknown.
   *
   * Never returns false. Only positive results are cached, deliberately: a
   * negative would have to be invalidated the moment the group is created, and
   * getting that wrong would reject a legitimate token -- a visible failure,
   * where a missing positive costs only one SQL lookup. Callers must treat null
   * as "ask the database".
   */
  public static function isGroupTokenValid(int $workspaceId, string $token): ?bool {
    if (!self::authTokenCacheEnabled() or $token === '') {
      return null;
    }
    $redis = self::connection();
    if ($redis === null) {
      return null;
    }
    try {
      return $redis->get(self::GROUP_TOKEN_VALID_PREFIX . $workspaceId . ':' . $token) === '1' ? true : null;
    } catch (Throwable $throwable) {
      return null;
    }
  }

  public static function storeGroupTokenValid(int $workspaceId, string $token): void {
    if (!self::authTokenCacheEnabled() or $token === '') {
      return;
    }
    $redis = self::connection();
    if ($redis === null) {
      return;
    }
    try {
      $redis->set(self::GROUP_TOKEN_VALID_PREFIX . $workspaceId . ':' . $token, '1', self::authTokenTtl());
    } catch (Throwable $throwable) {
      // Ignored -- see storeGroupToken().
    }
  }

  /** Drop every cached group token. For deletes and bulk login imports. */
  public static function flushGroupTokens(): void {
    self::flushByPrefix(self::GROUP_TOKEN_PREFIX, self::GROUP_TOKEN_VALID_PREFIX);
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
    // GROUP token, matching what storeAuthentication() writes. This used to
    // delete `group-token:` . getPerson()->getToken() -- a different key from
    // the one ever written, so the delete could never hit anything and every
    // `group-token:*` entry lived out its full ~23h TTL. Inert until now
    // (nothing reads these keys), but it wasted Redis memory and would have
    // become a stale-auth bug the moment a reader was added.
    self::$redis->del('group-token:' . $personSession->getLoginSession()->getGroupToken());
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
