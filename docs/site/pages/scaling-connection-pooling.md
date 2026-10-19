# Scaling: Connection Pooling

By default, every backend request opens its own connection to PostgreSQL and closes it at the end of the request.
That costs a TCP and authentication handshake per request. Under many concurrent test takers this turns into a
constant stream of connects and disconnects. This page describes the two settings that avoid it:

- **persistent connections:** each backend worker process keeps its database connection open across requests;
- **PgBouncer:** a connection pooler between the backend and PostgreSQL, so that many backend connections share a
  small number of PostgreSQL connections.

Both are optional and off by default.

## Persistent connections

With `DB_PERSISTENT_CONNECTIONS=true`, a backend worker process (an Apache or PHP-FPM worker) opens its connection
once and reuses it for all requests it serves. A connection that is still in a transaction at the end of a request
is rolled back before it is reused. The backend keeps no other state in a database session.

The price is that every worker holds a connection for as long as it exists, also while it is idle. The number of
open connections is then no longer the number of requests in progress, but the number of worker processes:

```
backend instances × worker processes per instance  ≤  connections the database accepts
```

PostgreSQL in the compose setup and in the Helm chart accepts 500 connections (`max_connections`). Workers per
instance are Apache's `MaxRequestWorkers` (150 by default) or PHP-FPM's `pm.max_children`, depending on the backend
image. Leave room for the start-up and maintenance connections as well.

- A single backend instance stays well below the limit, so persistent connections can be switched on directly.
- With several backend instances (e.g. Helm with `deployment.backend.replicas` > 1 or autoscaling), the product can
  exceed `max_connections`. New workers then fail with `too many clients already`. Put PgBouncer in front of
  PostgreSQL in that case.

### Configuration

| Deployment | Setting |
|---|---|
| docker compose | `DB_PERSISTENT_CONNECTIONS=true` in `.env.prod` |
| Helm | `config.db.persistentConnections: true` |

Only the request path uses persistent connections. Start-up and maintenance scripts (`initialize.php`,
`check-db-compatibility.php`) always open a connection of their own.

## Prepared statements

The backend does not use named prepared statements on any connection. PHP's PDO frees a named statement with the
SQL command `DEALLOCATE pdo_stmt_…`. Behind a pooler in transaction mode, that command can reach a different
PostgreSQL connection than the one that prepared the statement. It then fails with `prepared statement … does not
exist`, and inside a transaction the failure aborts the whole transaction.

Instead, PDO sends every query together with its parameters as an unnamed statement
(`PDO::PGSQL_ATTR_DISABLE_PREPARES`). Parameters are still bound by the server, not interpolated into the SQL text,
so this does not weaken protection against SQL injection. This applies with and without a pooler, so the same
backend image works in both setups.

For the same reason, the backend reads ids of inserted rows with `INSERT … RETURNING id` in the same statement
instead of a second query (`lastval()`), which a pooler could send to another connection.

## PgBouncer

[PgBouncer](https://www.pgbouncer.org/) accepts many client connections and multiplexes them onto a small pool of
PostgreSQL connections. In **transaction mode**, a PostgreSQL connection is assigned to a client only for the
duration of one transaction. Thousands of persistent backend connections then need only a few dozen PostgreSQL
connections. Transaction mode requires that clients keep no session state (no `SET`, temporary tables, advisory
locks or named prepared statements across transactions), which the backend fulfils.

### docker compose

The compose setup contains an optional PgBouncer service in the profile `pgbouncer`. To use it, set in `.env.prod`:

```
COMPOSE_PROFILES=pgbouncer
DB_HOST=pgbouncer
DB_PERSISTENT_CONNECTIONS=true
```

and restart with `make testcenter-up`. `COMPOSE_PROFILES` starts the service, `DB_HOST` points the backend at it.
PgBouncer listens on port 5432 like PostgreSQL itself, so `DB_PORT` stays unchanged. Without `DB_HOST=pgbouncer`,
the service runs but is not used; without the profile, the backend cannot reach `pgbouncer` and does not start.

The service is configured for one backend instance:

| PgBouncer setting | Value | Meaning |
|---|---|---|
| `pool_mode` | `transaction` | a PostgreSQL connection per transaction, not per client |
| `max_client_conn` | 1000 | backend connections PgBouncer accepts (one per worker process) |
| `default_pool_size` | 50 | PostgreSQL connections per database and user |
| `max_db_connections` | 80 | upper limit of PostgreSQL connections, well below `max_connections` |
| `query_wait_timeout` | 10 s | a request waiting longer for a free PostgreSQL connection fails instead of blocking its worker |
| open files (`ulimit -n`) | 65536 | every client connection is a file descriptor; the usual default of 1024 would cap PgBouncer at about 1000 clients |

They are set as environment variables of the `pgbouncer` service in `docker-compose.yml`, from which the image
(`edoburu/pgbouncer`) generates its configuration.

Database maintenance commands (`make testcenter-connect-db`, backup and restore) connect to PostgreSQL directly and
are not affected.

### Kubernetes (Helm)

The Helm chart contains an optional PgBouncer deployment. Enable it with:

```yaml
config:
  db:
    persistentConnections: true
  pgbouncer:
    enabled: true
```

The chart then deploys PgBouncer (`<release>-pgbouncer`: Deployment, Service, ConfigMap with `pgbouncer.ini`, and a
Secret with the auth file built from `secret.db`) and points the backend's `DB_HOST` at it. PgBouncer listens on
port 5432, so `DB_PORT` stays unchanged. The seed Job keeps connecting to PostgreSQL directly, because it applies
schema changes, which do not belong in a transaction-pooled connection. The configuration matches the compose
service (same image, transaction mode, SCRAM authentication, `query_wait_timeout`, `ignore_startup_parameters`).
PgBouncer raises its open-files soft limit to the hard limit at start, for the reason given above.

| Value | Default | `pgbouncer.ini` | Meaning |
|---|---|---|---|
| `config.pgbouncer.enabled` | `false` | | deploy PgBouncer and route the backend through it |
| `deployment.pgbouncer.replicas` | 2 | | PgBouncer pods behind one service |
| `config.pgbouncer.maxClientConn` | 1000 | `max_client_conn` | client connections per pod |
| `config.pgbouncer.defaultPoolSize` | 20 | `default_pool_size` | PostgreSQL connections per pod for normal traffic |
| `config.pgbouncer.minPoolSize` | 0 | `min_pool_size` | PostgreSQL connections per pod kept open when idle |
| `config.pgbouncer.reservePoolSize` | 5 | `reserve_pool_size` | additional connections per pod once clients have waited 3 s |
| `config.pgbouncer.maxDbConnections` | 40 | `max_db_connections` | upper limit of PostgreSQL connections per pod |
| `config.pgbouncer.maxPreparedStatements` | 200 | `max_prepared_statements` | protocol-level prepared statements tracked per server connection, for clients that use them |
| `config.pgbouncer.queryWaitTimeout` | 10 | `query_wait_timeout` | seconds a request waits for a free PostgreSQL connection |
| `image.pgbouncer.*` | `edoburu/pgbouncer:v1.25.2-p0` | | image |
| `deployment.pgbouncer.resources` | 100m / 64Mi requested, 1 CPU / 256Mi limit | | |

The defaults suit a few backend replicas. For larger installations, size the pool with these rules:

- **client side:** `maxClientConn` ≥ backend replicas × worker processes per replica ÷ PgBouncer replicas, plus
  headroom. With persistent connections, every worker holds one client connection. The Service spreads
  connections across the PgBouncer pods only roughly evenly, so leave at least 20 % headroom.
- **server side:** PgBouncer replicas × `maxDbConnections` stays below PostgreSQL's `max_connections` (500 in the
  chart), with room for the seed Job and maintenance connections. Example: 2 × 40 = 80.
- A pool of a few dozen PostgreSQL connections per pod is usually enough: in transaction mode a connection is busy
  only while a query runs. More server connections than PostgreSQL has CPU cores to work on them rarely helps.

PgBouncer is single-threaded and uses at most one CPU core; scale it with replicas rather than with CPU per pod.

### Failure behaviour

- **PgBouncer down:** the backend cannot connect, and requests fail with a database error until PgBouncer is back.
  After a restart of PgBouncer, persistent connections reconnect on their own.
- **Pool exhausted:** a request waits up to `query_wait_timeout` for a free PostgreSQL connection, then fails with
  an error. A high number of waiting clients (`SHOW POOLS` on PgBouncer's admin console) means the pool is too
  small or PostgreSQL is too slow.
- **PostgreSQL restarted:** PgBouncer reconnects; a request in flight at that moment fails.
- **PostgreSQL restarted, persistent connections without PgBouncer:** every backend worker notices its broken
  connection only when it uses it. Its first request after the restart fails with `server closed the connection
  unexpectedly`, the next one reconnects.

## Switching back

Set `DB_PERSISTENT_CONNECTIONS=false` (Helm: `config.db.persistentConnections: false`), remove `DB_HOST` or set it
to `db`, remove the `pgbouncer` profile, and restart. No data is stored in PgBouncer.
In Helm, set `config.pgbouncer.enabled: false`; the backend then connects to PostgreSQL directly again.
