# Scaling: Auth-Token Cache

Every request of a test-taker carries a session token, and the backend resolves it before it does anything else. With
many concurrent test-takers this lookup becomes one of the most frequent database queries. The auth-token cache
answers it from the cache service (Redis) instead.

The cache is switched off by default. Small and medium installations do not need it.

## What is cached

- **Person tokens** - the session tokens of test-takers and monitors after login. The cache keeps the raw result row
  of the token lookup (workspace, mode, group, expiry), not a ready-made decision. A token served from the cache is
  therefore checked exactly like one read from the database: a removed login is rejected with `410`, an expired session
  with `410`. The cache is only consulted on routes that accept person tokens; on all other routes a person token is
  rejected with `403` as before.
- **Group tokens** - the token of each login group, used at login and to authorize downloads of test resources.
- **Owner and state of tests** - checked and read by every request on a running test. See
  [Scaling: Test-State Buffer](./scaling-test-state-buffer.md), which also describes how these entries are kept
  current and when they are dropped.

Not cached:

- Admin tokens. An admin logout or a deleted admin account takes effect immediately.
- Login tokens (the step between entering a name and choosing a code). They are used rarely.
- Tokens the database rejects. Only rows that passed every check are stored.

## Invalidation

The application removes cached entries itself whenever a token stops being valid:

| Event | Effect on the cache |
|---|---|
| Login of a person who already has a session (the token is renewed) | the old token is removed |
| Logout | the token is removed |
| Uploading, changing or deleting a Testtakers file | all cached person and group tokens are removed |
| Deleting the result data of a group | all cached person and group tokens are removed |
| Deleting a workspace | all cached person and group tokens are removed |

Removing all entries is cheap for the cache service, but afterwards each active test-taker causes one database lookup
again. Uploading a Testtakers file during a large exam therefore briefly raises the database load.

Changes made directly in the database, outside the application, are not noticed. For those the TTL is the upper
bound: an entry is served for at most `TESTCENTER_AUTH_TOKEN_TTL` seconds and never beyond the expiry of its session.
After a manual change in the database, either wait that long or restart the cache service.

## Configuration

| docker compose (`.env.prod`) | Helm value | Default | Meaning |
|---|---|---|---|
| `TESTCENTER_CACHE_AUTH_TOKEN` | `config.backend.cacheAuthToken` | `false` | switches the cache on (`true`, `on`, `yes` or `1`) |
| `TESTCENTER_AUTH_TOKEN_TTL` | `config.backend.authTokenTtl` | `300` | maximum age of an entry in seconds; `0` switches the cache off |

`TESTCENTER_AUTH_TOKEN_TTL` must be a non-negative whole number; any other value stops the backend with a
configuration error.

The cache uses the existing cache service (`REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`). Without a configured cache
service the switch has no effect.

## When to enable it

Enable it when the database is the bottleneck under many simultaneous test-takers, e.g. several thousand at once. The
token lookup runs on every request of every test-taker, so it scales with the number of people and their activity,
not with the size of the tests.

Leave it off when the database is not under pressure. Then the cache only adds a component whose state has to be
kept in mind when changing data by hand.

## Failure behaviour

The cache is an optimisation; it never decides whether a request may proceed.

- If the cache service cannot be reached, or a cache command fails, the backend reads the token from the database as
  if the cache were switched off. Logins and requests keep working, only with the database load of an installation
  without the cache.
- If the cache service is restarted, it starts empty and fills again with the next requests.
- When the cache service runs out of memory, it evicts entries (all of them carry an expiry). An evicted entry only
  costs one database lookup.

## Sizing

- **Memory:** one entry takes a few hundred bytes. 100,000 active test-takers need about 50 MB in addition to what the
  cache service holds otherwise (`REDIS_MEMORY_MAX`).
- **Connections:** the backend keeps its connection to the cache service open between requests, one per backend
  worker process. Redis accepts 10,000 clients by default; with many backend instances, the sum of their worker
  processes has to stay below that.
