# Scaling: Test-State Buffer

While a test runs, the browser of every test-taker reports changes of the test's state - the current unit, the
remaining time, the focus, the connection - with `PATCH /test/{id}/state`. Normally every such request reads the
test's row from the table `tests` and writes it back with the new state (`tests.laststate`). With many concurrent
test-takers, these single-row reads and updates from many backend processes at once become a large share of the
database load, and they compete for the same pages of the table.

This page describes two steps that take this traffic off the database:

1. **The test-state cache.** It comes with the [auth-token cache](./scaling-auth-cache.md)
   (`TESTCENTER_CACHE_AUTH_TOKEN`). The backend keeps the owner and the state of each test in the cache service
   (Redis). The ownership check that every request on a test makes, and the read of the current state, are answered
   from there. The table is still updated on every change.
2. **The test-state buffer** (`TESTCENTER_BUFFER_TEST_STATE`, on top of the cache). A state change is written to the
   cache only, and the test is marked as *pending*. A separate process, the **state drainer**, writes all pending
   states to the table every two seconds, with one update per batch of up to 500 tests. The database then sees a few
   large writes per second from one writer instead of one write per request from many.

Both are switched off by default. Small and medium installations do not need them.

## When to enable it

Enable the buffer when the database is the bottleneck under many simultaneous test-takers, e.g. several thousand at
once, and the updates of `tests` (`update tests set laststate ...`) are among its most frequent or most expensive
statements. Enable the auth-token cache first and check its effect; the buffer requires it.

Leave the buffer off otherwise. It adds a process to operate, and it accepts a small risk for the navigation state
(see below).

## What changes

### Reading

While a test is pending, its state in the cache is newer than the row in the table. Every part of the backend that
reads the state of a running test therefore asks the cache first and falls back to the table only if the cache has no
entry:

- resuming a test after a reload or a new login (`PUT /test`, `GET /test/{id}`),
- the ownership check of every request on a test, and the merge of every state change,
- the group monitor and its lock and unlock commands,
- the commands a test polls (`GET /test/{id}/commands`) and the report of a lost connection.

A test-taker therefore always sees the state of their own last change, immediately.

The table lags by up to one drain interval (`STATE_DRAIN_INTERVAL_MS`, 2 seconds by default). This is visible only
where the table is read directly: the result overview and the list of started tests of a workspace, the reports, and
queries made directly in the database. The time of the last change (`tests.timestamp_server`) is the time the drainer
wrote the state.

### Delivery guarantee: at least once

A test leaves the pending set only after the drainer has written its state, and only if its entry has not changed
since the drainer read it. Both are checked in one atomic step in the cache service. A change that arrives while the
drainer writes keeps the test pending, and the newer state is written in the next round. The same state may be
written twice, which is harmless: the later write only repeats the earlier one.

This is the opposite choice from the [asynchronous test logs](./scaling-async-logs.md), which are delivered at most
once: a test log written twice would be a duplicate row, a state written twice is not.

| Event | What happens |
|---|---|
| The drainer is stopped, crashes or is restarted | nothing is lost. The states stay pending and are written after the restart. |
| The database is unreachable | nothing is lost. The drainer keeps the states pending and reconnects. |
| The database rejects the state of a single test | that state is discarded and logged; all others are written. A batch the database rejects is retried test by test. |
| A test is deleted while its state is pending | its entry is dropped together with the test. |
| **The cache service restarts** | **the pending states are lost**: at most about one drain interval of navigation state per test (current unit, timers, focus). The test resumes from the state the table had. Responses are stored in other tables and are not affected. |

The cache service runs without persistence. That last risk is the price of the buffer; with
`STATE_DRAIN_INTERVAL_MS=2000` it concerns at most the changes of the last two seconds.

### Fallback to direct writes

The backend updates the table directly, as without the buffer, when

- the cache service is not configured or not reachable,
- the cache service refuses the write, e.g. because its memory is full.

So a failure of the cache service costs database load, but no state. The drainer reports these direct writes as
`fallbacks` (see [Monitoring](#monitoring)); while the cache service is unreachable they cannot be counted.

### Requirements for the cache service

Pending entries carry **no expiry**. The cache service is started with the eviction policy `volatile-lru` (in
`docker-compose.yml` and in the Helm chart), which evicts only keys that have an expiry, so pending states are never
evicted. Do not change the policy to an `allkeys-*` policy: the cache service could then drop states that are not in
the database yet. When the memory is full and nothing can be evicted, the cache service refuses new states and the
backend writes them directly.

Entries that are not pending (written, or read from the table) expire after `TESTCENTER_AUTH_TOKEN_TTL` seconds, like
the cached tokens.

### Test ids

A cached entry names the owner of its test, and the ownership check trusts it only for that person; for anyone else
the table decides. The backend writes the entry of every new test when it creates the row, and drops the entries of
deleted tests when the result data of a group or a workspace is deleted. PostgreSQL never hands out a test id twice,
unless the identity of the table `tests` is reset, e.g. by `TRUNCATE ... RESTART IDENTITY` or by restoring a dump.
**After such a change in the database, restart the cache service** (or delete its keys `test-state:*`), so that no
entry of a former test remains at a reused id.

## Configuration

| docker compose (`.env.prod`) | Helm value | Default | Meaning |
|---|---|---|---|
| `TESTCENTER_BUFFER_TEST_STATE` | `config.backend.bufferTestState` | `false` | switches the buffer on (`true`, `on`, `yes` or `1`); has no effect unless the auth-token cache is on |
| `TESTCENTER_CACHE_AUTH_TOKEN` | `config.backend.cacheAuthToken` | `false` | the auth-token cache, which includes the test-state cache |
| `TESTCENTER_AUTH_TOKEN_TTL` | `config.backend.authTokenTtl` | `300` | seconds until an entry that is not pending expires |
| `STATE_DRAIN_INTERVAL_MS` | `deployment.stateDrainer.intervalMs` | `2000` | milliseconds between two rounds of the drainer, at least 100; the maximum lag of the table |
| `STATE_DRAIN_BATCH` | `deployment.stateDrainer.batchSize` | `500` | tests per update, between 1 and 32,767 (PostgreSQL accepts at most 65,535 parameters per statement, two per test) |
| `STATE_DRAIN_REPORT_SEC` | `deployment.stateDrainer.reportSeconds` | `30` | seconds between two status lines of the drainer |
| - | `deployment.stateDrainer.enabled` | `false` | deploy the drainer although the switch is off, see below |
| - | `deployment.stateDrainer.resources` | 50m / 64Mi | resources of the drainer |

An invalid value of one of the `STATE_DRAIN_*` variables stops the drainer at start with a message in its log.

### The drainer

The drainer runs the backend image with the command `php /var/www/testcenter/backend/drain-test-states.php` and the
same environment as the backend. Run **exactly one**. More would be safe, but they would only compete for the same
rows.

- **docker compose:** the service `state-drainer` is always started. While the switch is off it only finds the
  pending set empty, once per interval.
- **Helm:** the Deployment `<release>-state-drainer` is created when `config.backend.bufferTestState` is on, or when
  `deployment.stateDrainer.enabled` is true.

The drainer writes whatever the switch says. That is what makes switching it off safe: states buffered before are
still written. With Helm, set `deployment.stateDrainer.enabled: true` in the same step as switching `bufferTestState`
off, and remove it once the drainer reports `pending=0`.

### Switching on and off

Switching the buffer on or off is safe while tests are running, as long as the auth-token cache stays on: every
backend process reads the cache first either way, so it makes no difference to readers whether a process buffers or
writes directly.

Switching the **auth-token cache** off while states are pending is not: backend processes without the cache would read
the older state from the table. To switch both off, switch the buffer off first, wait until the drainer reports
`pending=0`, then switch the auth-token cache off. To switch both on, do it in the opposite order, or at a time
without running tests.

## Monitoring

The drainer logs one status line every `STATE_DRAIN_REPORT_SEC` seconds, to `stderr`
(`docker compose logs state-drainer`, `kubectl logs deployment/<release>-state-drainer`):

```
[2026-10-19T10:00:00Z] drain-test-states: pending=312 written=1790412 batches=3763 rows_per_batch=475.8 missing=0 discarded=0 fallbacks=0 longest_round_ms=740
```

| Field | Meaning |
|---|---|
| `pending` | tests whose state is not in the table yet; `cache-server-unavailable` if the drainer cannot reach the cache service |
| `written`, `batches`, `rows_per_batch` | states written and updates issued since the drainer started |
| `missing` | states of tests that were deleted before the drainer wrote them, since start |
| `discarded` | states the database rejected, since start; each one is logged |
| `fallbacks` | state changes the backend wrote directly because the cache service refused them, since the previous status line |
| `longest_round_ms` | the longest round since the previous status line; a round writes everything pending at its start |

What to watch:

- `pending` follows the number of active test-takers and drops back after each round. A `pending` that keeps growing
  means the drainer does not keep up or is stuck.
- A `longest_round_ms` near or above `STATE_DRAIN_INTERVAL_MS` means a round takes as long as the interval; the table
  then lags by more than one interval. Increase `STATE_DRAIN_BATCH`, or look at the database.
- `fallbacks` and `discarded` should stay at 0.
- The pending set can also be read directly: `redis-cli -a "$REDIS_PASSWORD" scard test-state:pending`.

## Sizing

- **Memory of the cache service:** an entry takes a few hundred bytes up to a few kilobytes, depending on the state
  the booklet keeps. With the auth-token cache on, every active test has one, pending or not; 100,000 active tests
  need roughly 100 MB of `REDIS_MEMORY_MAX`.
- **Drainer:** one PHP process holding at most one batch. A small fraction of a CPU core and 64 MB of memory are
  enough.
- **Interval and batch size:** a round writes every pending test, in as many batches as needed. With 2 seconds and
  several thousand state changes per second, a round writes a few thousand rows. A shorter interval means a smaller
  lag of the table and less to lose when the cache service restarts, but more, smaller updates.
