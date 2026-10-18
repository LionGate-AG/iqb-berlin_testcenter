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

### Running PgBouncer

The compose setup and the Helm chart connect the backend to the database service directly and do not contain
PgBouncer yet. When PgBouncer runs in front of PostgreSQL, size it with the same rule as above:

- **client side:** `max_client_conn` per PgBouncer instance ≥ backend replicas × worker processes ÷ PgBouncer
  replicas, plus headroom;
- **server side:** PgBouncer replicas × (`default_pool_size` + `reserve_pool_size`), capped by `max_db_connections`,
  stays below PostgreSQL's `max_connections`;
- raise the open-files limit of the PgBouncer container to at least `max_client_conn` plus the server connections.

PgBouncer is single-threaded; scale it with replicas rather than with CPU per instance.

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

Set `DB_PERSISTENT_CONNECTIONS=false` (Helm: `config.db.persistentConnections: false`) and restart.
