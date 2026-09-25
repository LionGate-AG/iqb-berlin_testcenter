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

  // ===========================================================================
  // PRESIGNED RESOURCE URLS
  // ===========================================================================

  /** <workspaceId>:<path> -> presigned GET url. */
  private const PRESIGNED_URL_PREFIX = 'presigned-url:';

  /**
   * Margin between how long we keep a signed URL and how long the signature is
   * actually valid for. An entry handed out in its final second still has this
   * much life left by the time the client follows the redirect.
   */
  private const PRESIGNED_URL_MARGIN = 300;

  /**
   * Why this cache exists: TestController::getFile() served every resource with
   * a blocking Storage::driver()->exists() call before signing. Measured on
   * tc-dev 2026-09-22, that round trip costs ~50ms -- ~45ms of which is a fresh
   * TLS handshake, because the SDK does not reuse the connection -- and it holds
   * a PHP-FPM worker for the whole of it.
   *
   * Every test taker fetches the same handful of files (the unit definitions and
   * the ~3.1MB player HTML), so a 99,227-user run made ~700,000 identical S3
   * round trips. During the spawn burst that exhausted pm.max_children (300) and
   * nginx shed the overflow as limit_conn 503s: ~10,300 of them, which was
   * essentially every failure in that run.
   *
   * Caching the signed URL collapses that to one S3 round trip per file per TTL.
   *
   * Replacing a file at the same key needs no invalidation -- the signature
   * covers the key, not the object -- so a cached URL serves the NEW content.
   * Only deletion leaves a stale entry, which is why deleteFiles() flushes.
   */
  public static function getPresignedUrl(int $workspaceId, string $path): ?string {
    if (!self::authTokenCacheEnabled() or self::presignedUrlTtl() <= 0) {
      return null;
    }
    $redis = self::connection();
    if ($redis === null) {
      return null;
    }
    try {
      $url = $redis->get(self::PRESIGNED_URL_PREFIX . $workspaceId . ':' . $path);
    } catch (Throwable $throwable) {
      return null;
    }
    return (is_string($url) and $url !== '') ? $url : null;
  }

  public static function storePresignedUrl(int $workspaceId, string $path, string $url): void {
    $ttl = self::presignedUrlTtl();
    if (!self::authTokenCacheEnabled() or $ttl <= 0 or $url === '') {
      return;
    }
    $redis = self::connection();
    if ($redis === null) {
      return;
    }
    try {
      $redis->set(self::PRESIGNED_URL_PREFIX . $workspaceId . ':' . $path, $url, $ttl);
    } catch (Throwable $throwable) {
      // Caching is an optimisation; never let it fail a request.
    }
  }

  /** Drop every cached signed URL. For file deletion. */
  public static function flushPresignedUrls(): void {
    self::flushByPrefix(self::PRESIGNED_URL_PREFIX);
  }

  /**
   * Deliberately SHORTER than the signature's own lifetime by
   * PRESIGNED_URL_MARGIN, so a cached URL can never outlive the signature it
   * carries. Returns 0 -- caching off -- when the configured TTL is too short
   * for the margin to leave anything useful.
   */
  private static function presignedUrlTtl(): int {
    return max(0, SystemConfig::$storage_presignTtl - self::PRESIGNED_URL_MARGIN);
  }

  // ===========================================================================
  // Test ownership + laststate
  //
  // WHY: every request through IsTestWritable -- above all the periodic
  // PATCH /test/{id}/state that each test taker repeats for the whole session --
  // ran `SELECT laststate FROM tests WHERE id = ? AND person_id = ?`. Measured on
  // the fresh-onboarding run of 2026-09-23 (97,886 users):
  //
  //     2,192 executions/s x 297ms = ~650 connections held continuously
  //     44% of all database time -- the single largest statement
  //
  // The statement is fast in isolation (p50 0.18ms); the cost was that it held a
  // pooled connection while queueing behind everything else, and the pool was
  // exhausted: 178,762 "Max connect timeout" 500s, ~70% of them on this route.
  //
  // WHAT IS CACHED: the same two facts the SELECT established -- which person owns
  // the test, and its current laststate JSON -- so a hit feeds the identical
  // authorisation and read-modify-write code a miss would.
  //
  // WHY IT IS SAFE TO CACHE:
  //   - Ownership never changes while the row exists: nothing updates
  //     tests.person_id.
  //   - laststate has exactly ONE writer, TestDAO::updateTestState, which writes
  //     through here after every UPDATE. TestDAO::createTest seeds the entry,
  //     which also overwrites any stale entry left at a reused id.
  //   - Rows only disappear by cascade from the admin deletes in AdminDAO and
  //     SuperAdminDAO, which call dropTestStates() for the deleted ids.
  //   - A cached owner that does not match the caller returns null, NOT a denial:
  //     the caller then asks the database, which stays authoritative.
  //   - Without write-behind (below) the database is still written on every
  //     PATCH and only the read moves here.
  //
  // Positive entries only, as with group tokens. Gated by the same switch and TTL
  // as the auth-token cache; each write refreshes the TTL, so an active session
  // stays warm and an idle one falls back to one SELECT.
  //
  // WRITE-BEHIND (2026-09-25, TestStateBuffer): with TESTCENTER_BUFFER_TEST_STATE
  // the PATCH no longer UPDATEs the table at all. The new state is written HERE,
  // without a TTL, and flushed to MySQL by backend/drain-test-states.php every
  // ~2s. While an entry is pending, this cache -- not the table -- holds the
  // current state, so every reader of laststate must ask the cache first
  // (getCachedTestState) and only fall back to the table on a miss. A miss is
  // always safe: an entry is only ever absent once it has been written back.
  // ===========================================================================

  /**
   * <testId> -> {"p": person_sessions.id, "s": tests.laststate (JSON string or null)}.
   * Public so TestStateBuffer writes and settles the very same keys.
   */
  public const TEST_STATE_PREFIX = 'test-state:';

  /**
   * The cached tests row -- ['laststate' => ?string, 'person_id' => int] -- for
   * $testId regardless of who owns it; null on a miss, on a malformed entry, or
   * when Redis is off/unreachable. Callers MUST fall back to the database on null.
   *
   * @return array{laststate: ?string, person_id: int}|null
   */
  public static function getCachedTestState(int $testId): ?array {
    if (!self::authTokenCacheEnabled()) {
      return null;
    }
    $redis = self::connection();
    if ($redis === null) {
      return null;
    }
    try {
      $raw = $redis->get(self::TEST_STATE_PREFIX . $testId);
    } catch (Throwable $throwable) {
      return null;
    }
    return self::decodeTestStateEntry($raw);
  }

  /**
   * The cached laststate of each of $testIds that has an entry, as one MGET.
   * Ids without an entry are absent from the result; the caller keeps the
   * table's value for those.
   *
   * @param int[] $testIds
   * @return array<int, ?string> testId => laststate
   */
  public static function getCachedLaststates(array $testIds): array {
    if (!self::authTokenCacheEnabled() or empty($testIds)) {
      return [];
    }
    $redis = self::connection();
    if ($redis === null) {
      return [];
    }
    $testIds = array_values(array_unique(array_map('intval', $testIds)));
    try {
      $raws = $redis->mGet(array_map(fn(int $id) => self::TEST_STATE_PREFIX . $id, $testIds));
    } catch (Throwable $throwable) {
      return [];
    }
    $laststates = [];
    foreach ($testIds as $index => $testId) {
      $entry = self::decodeTestStateEntry($raws[$index] ?? false);
      if ($entry !== null) {
        $laststates[$testId] = $entry['laststate'];
      }
    }
    return $laststates;
  }

  /**
   * @return array{laststate: ?string, person_id: int}|null
   */
  public static function decodeTestStateEntry(mixed $raw): ?array {
    if (!is_string($raw) or $raw === '') {
      return null;
    }
    $entry = json_decode($raw, true);
    if (
      !is_array($entry)
      or !isset($entry['p'])
      or !array_key_exists('s', $entry)
      or !(is_string($entry['s']) or $entry['s'] === null)
    ) {
      return null;
    }
    return ['laststate' => $entry['s'], 'person_id' => (int) $entry['p']];
  }

  /** TTL an entry gets once it is no longer pending (TestStateBuffer::settle). */
  public static function testStateTtl(): int {
    return self::authTokenTtl();
  }

  public static function encodeTestStateEntry(int $personId, ?string $laststate): string {
    return json_encode(['p' => $personId, 's' => $laststate]);
  }

  /**
   * The row getOwnedTest() would return -- ['laststate' => ?string, 'person_id' =>
   * int] -- when the cache knows $personId owns $testId; null on a miss, on an
   * owner mismatch, or when Redis is off/unreachable. Callers MUST fall back to the
   * database on null.
   *
   * @return array{laststate: ?string, person_id: int}|null
   */
  public static function getOwnedTestRow(int $testId, int $personId): ?array {
    $entry = self::getCachedTestState($testId);
    if ($entry === null or $entry['person_id'] !== $personId) {
      return null;
    }
    return $entry;
  }

  /**
   * Read-through fill after a cache miss: SET NX, never overwriting.
   *
   * A miss followed by a table read races with a PATCH that buffers a newer state
   * for the same test in between. A plain SET would then replace that pending
   * entry with the older table value, and the drainer would write the older value
   * back -- losing the update. NX lets the pending entry win.
   */
  public static function populateTestState(int $testId, int $personId, ?string $laststate): void {
    if (!self::authTokenCacheEnabled()) {
      return;
    }
    $redis = self::connection();
    if ($redis === null) {
      return;
    }
    try {
      $redis->set(
        self::TEST_STATE_PREFIX . $testId,
        self::encodeTestStateEntry($personId, $laststate),
        ['nx', 'ex' => self::authTokenTtl()]
      );
    } catch (Throwable $throwable) {
      // Only a missed fill; the next read asks the table again.
    }
  }

  public static function storeTestState(int $testId, int $personId, ?string $laststate): void {
    if (!self::authTokenCacheEnabled()) {
      return;
    }
    $redis = self::connection();
    if ($redis === null) {
      return;
    }
    $key = self::TEST_STATE_PREFIX . $testId;
    try {
      $redis->set($key, self::encodeTestStateEntry($personId, $laststate), self::authTokenTtl());
    } catch (Throwable $throwable) {
      // A failed write must not leave the PREVIOUS state behind: the next PATCH
      // would merge into it and write the older state back to the database. Try
      // to drop the key instead; if Redis is unreachable for that too, the next
      // read most likely fails as well and falls back to the table.
      self::invalidateTestState($testId);
    }
  }

  public static function invalidateTestState(int $testId): void {
    if (!self::authTokenCacheEnabled()) {
      return;
    }
    $redis = self::connection();
    if ($redis === null) {
      return;
    }
    try {
      $redis->unlink(self::TEST_STATE_PREFIX . $testId);
    } catch (Throwable $throwable) {
      // Bounded by the TTL.
    }
  }

  /**
   * Drop the entries of tests that were just deleted, pending or not, for the
   * admin deletes that cascade to `tests`.
   *
   * By id rather than by prefix: with write-behind, entries of OTHER tests may
   * still be pending, and a wholesale flush would silently discard their
   * not-yet-written state. Dropping a deleted test's pending state is correct --
   * its row is gone, and the drainer's UPDATE would match nothing anyway.
   *
   * @param int[] $testIds
   */
  public static function dropTestStates(array $testIds): void {
    if (!self::authTokenCacheEnabled() or empty($testIds)) {
      return;
    }
    $redis = self::connection();
    if ($redis === null) {
      return;
    }
    $testIds = array_values(array_unique(array_map('intval', $testIds)));
    try {
      foreach (array_chunk($testIds, 1000) as $chunk) {
        $redis->unlink(array_map(fn(int $id) => self::TEST_STATE_PREFIX . $id, $chunk));
        $redis->sRem(TestStateBuffer::KEY_PENDING, ...$chunk);
      }
    } catch (Throwable $throwable) {
      // Leftover entries only authorise PATCHes that then write to no row, and
      // expire with the TTL once the drainer has settled them.
    }
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
