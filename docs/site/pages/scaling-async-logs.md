# Scaling: Async Test Logs

While a test runs, the browser of every test-taker reports events - the test starts, a unit is entered, the focus
leaves the window, the connection is lost - and the backend writes each of them to the table `test_logs`. Normally
this is one database insert per request. With many concurrent test-takers, these many small writes from many backend
processes at once become a large share of the database load.

With asynchronous test logs, the backend puts the rows into a queue in the cache service (Redis) instead and answers
the request. A separate process, the **log drainer**, takes the rows off the queue and writes them with one insert
per batch of up to several thousand rows. The database then sees few large writes from one writer instead of many
small writes from many.

The feature is switched off by default. Small and medium installations do not need it.

## When to enable it

Enable it when the database is the bottleneck under many simultaneous test-takers, e.g. several thousand at once, and
the inserts into `test_logs` are among its most frequent or most expensive statements.

Leave it off otherwise. It adds a process to operate, and it weakens the delivery guarantee of the test logs (see
below).

## What changes for the logs

Logs written through the queue reach the database with a short delay, normally below a second. Their content is the
same; their order in the table may differ, which does not matter because the log report sorts by the time the
browser reported.

### Delivery guarantee: at most once

The drainer takes a batch off the queue *before* it writes it. A row is therefore written at most once, but it can be
lost:

| Event | What is lost |
|---|---|
| The cache service restarts | **every row still in the queue.** The cache service runs without persistence. |
| The drainer is stopped, crashes or is restarted | the batch it holds at that moment (at most `LOG_DRAIN_BATCH` rows). Everything still queued stays and is written after the restart. |
| The database is unreachable while the drainer writes | the batch it holds at that moment. The drainer reconnects and continues; rows queued meanwhile wait. |
| The database rejects single rows (e.g. the test was deleted after the row was queued) | those rows only. The drainer retries a rejected batch row by row. |

A guarantee of at least once (write, then remove from the queue) was not chosen: `test_logs` has no unique key, so a
batch written twice after a failure would put duplicate rows into the log report, and they cannot be told apart
from real events afterwards.

If the logs of an exam must be complete under all circumstances, leave the feature off.

### Fallback to direct writes

The backend writes the rows directly to the database, as without the feature, when

- the cache service is not configured or not reachable,
- the queue holds 250,000 rows or more (see [Monitoring](#monitoring)).

So a failure of the cache service or of the drainer costs database load, but no logs. In rare cases (the connection
to the cache service breaks while the rows are being sent) a row can be written twice.

The cap protects the cache service: the queue has no expiry and is never evicted, so without a cap a stopped drainer
would let it grow until the cache service evicts the cached logins and tokens instead.

## Configuration

| docker compose (`.env.prod`) | Helm value | Default | Meaning |
|---|---|---|---|
| `TESTCENTER_ASYNC_TEST_LOGS` | `config.backend.asyncTestLogs` | `false` | switches the queue on (`true`, `on`, `yes` or `1`) |
| `LOG_DRAIN_BATCH` | `deployment.logDrainer.batchSize` | `5000` | rows per insert, between 1 and 21,845 (PostgreSQL accepts at most 65,535 parameters per statement, three per row) |
| `LOG_DRAIN_IDLE_MS` | `deployment.logDrainer.idleMs` | `200` | pause in milliseconds when the queue is empty |
| `LOG_DRAIN_REPORT_SEC` | `deployment.logDrainer.reportSeconds` | `30` | seconds between two status lines of the drainer |
| - | `deployment.logDrainer.enabled` | `false` | deploy the drainer although the switch is off, see below |
| - | `deployment.logDrainer.resources` | 50m / 64Mi | resources of the drainer |

An invalid value of one of the `LOG_DRAIN_*` variables stops the drainer at start with a message in its log.

The queue uses the existing cache service (`REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`). Without a configured cache
service the switch has no effect.

### The drainer

The drainer runs the backend image with the command `php /var/www/testcenter/backend/drain-logs.php` and the same
environment as the backend. Run **exactly one**: more would only compete for the same queue.

- **docker compose:** the service `log-drainer` is always started. While the switch is off it only checks the empty
  queue a few times per second.
- **Helm:** the Deployment `<release>-log-drainer` is created when `config.backend.asyncTestLogs` is on, or when
  `deployment.logDrainer.enabled` is true.

The drainer writes whatever the switch says. That is what makes switching it off safe: rows queued before stay in the
queue and are still written. With Helm, set `deployment.logDrainer.enabled: true` in the same step as switching
`asyncTestLogs` off, and remove it once the drainer reports `depth=0`.

The drainer survives an unreachable database or cache service: it retries the database with increasing pauses (up to
30 seconds) and re-establishes the connection to the cache service when the queue appears empty.

## Monitoring

The drainer logs one status line every `LOG_DRAIN_REPORT_SEC` seconds, to `stderr`
(`docker compose logs log-drainer`, `kubectl logs deployment/<release>-log-drainer`):

```
[2026-10-19T10:00:00Z] drain-logs: depth=12 inserted=184233 batches=301 rows_per_batch=612.1 discarded=0 refused=0
```

| Field | Meaning |
|---|---|
| `depth` | rows waiting in the queue right now; `cache-server-unavailable` if the drainer cannot reach the cache service |
| `inserted`, `batches`, `rows_per_batch` | rows written and inserts issued since the drainer started |
| `discarded` | rows the database rejected, since the drainer started |
| `refused` | rows the backend wrote directly because the queue was full, since the previous status line |

What to watch:

- A `depth` that stays near zero is the normal state. A `depth` that keeps growing means the drainer does not keep
  up or is stuck; once it reaches 250,000, `refused` rises and the backend writes directly again.
- `discarded` should stay at 0. Rows are discarded when they refer to a test that no longer exists, or when the
  database could not be reached during a write.
- The queue can also be read directly: `redis-cli -a "$REDIS_PASSWORD" llen test-logs:queue`.

## Sizing

- **Memory of the cache service:** a queued row takes roughly 100 to 200 bytes. The cap of 250,000 rows therefore
  needs up to about 50 MB of `REDIS_MEMORY_MAX`, and only while the drainer falls behind.
- **Drainer:** one PHP process holding at most one batch. A small fraction of a CPU core and 64 MB of memory are
  enough.
- **Batch size:** larger batches mean fewer statements, but more rows lost if the drainer dies while writing. The
  default of 5,000 is far above what one drain interval collects even with many thousand test-takers, so a batch
  is usually only as large as the queue.
