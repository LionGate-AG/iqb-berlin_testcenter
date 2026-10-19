# Scaling: Broadcaster

The broadcaster is the WebSocket service that pushes live updates: test-session changes to group monitors and
commands (pause, resume, goto, ...) to running tests. It can run as several instances (pods) side by side. All
shared state lives in a dedicated Redis, `broadcaster-redis`, and the instances exchange messages through Redis
pub/sub. A client may connect its WebSocket to any instance; no sticky sessions are needed.

## Architecture

Two kinds of state are kept apart:

- **Registrations are global.** When a monitor or a test starts, the backend registers its token with the
  broadcaster over HTTP. The request can land on any instance, so the registration is written to Redis, where every
  instance sees it.
- **WebSockets are local.** A socket lives in the instance that accepted it. Each instance keeps a map of the
  sockets it holds and never shares it.

A push works as **fan-out, then local delivery**:

1. The instance that receives the change from the backend resolves the target tokens from Redis (the monitors of a
   group, the tests of a command).
2. It publishes `{payload, tokens}` on a Redis channel.
3. Every instance receives the message, keeps the tokens whose socket it holds and sends to those. Instances that
   hold none do nothing.

### What lives in Redis

| Key | Type | Contents |
|---|---|---|
| `testees` | hash | token → testee (`token`, `testId`, `disconnectNotificationUri`) |
| `testee-testid:<testId>` | set | testee tokens of a test |
| `monitors` | hash | token → monitor (`token`, `groups`) |
| `monitor-groups:<group>` | set | monitor tokens watching a group |
| `testSessions:<group>` | hash | testId → latest session state of the group |
| `activeGroups` | set | groups that have session state |
| `ws-registrations` | sorted set | tokens registered but not yet connected, scored by registration time |
| `client-alive:<token>` | string | liveness marker of a connected token, expires after 90 s |
| `connected-tokens` | set | connected tokens, for operators only; the application does not read it |

| Channel | Payload |
|---|---|
| `broadcaster:session-change` | `{groupName, sessions, tokens}` |
| `broadcaster:command` | `{command, tokens}` |
| `broadcaster:system-clean` | `{}`: every instance closes all its sockets |

Session changes are merged into `testSessions:<group>` by a Lua script, so concurrent changes of the same test,
even from different instances, cannot overwrite each other.

### Registration, liveness and clean-up

- **Only registered tokens may connect.** A WebSocket with an unknown token is closed with code 1008. Taking a
  registration is atomic, so of two sockets using the same fresh token only one is admitted, even on different
  instances.
- **Unused registrations expire.** A registration whose socket has not connected after 30 seconds (for example
  behind a proxy that blocks WebSockets) is dropped with the next heartbeat, together with its testee or monitor
  entry.
- **Liveness.** Every 30 seconds each instance pings its sockets, terminates the ones that did not answer and
  refreshes `client-alive:<token>` for the others. Before a push, the target tokens are checked against these
  markers; tokens without a live socket anywhere are removed from Redis. This also cleans up after an instance that
  crashed without closing its sockets.
- **Connection lost.** When a test's socket closes, the broadcaster reports the lost connection to the backend,
  unless the same test is already connected again with another token on any instance. A failed report is tried
  up to three times with exponential backoff.

## Configuration

### Docker Compose

`docker-compose.yml` starts the `broadcaster-redis` service next to the broadcaster. It uses the same
`REDIS_PASSWORD` from `.env` as the cache service; there is nothing else to configure. With Docker Compose the
broadcaster runs as one instance, which is enough for most installations.

`broadcaster-redis` is separate from `cache-server` on purpose: the cache service evicts keys when it is full,
while the broadcaster's data is state and must never be evicted (`--maxmemory-policy noeviction`). It is not
persisted to disk.

The broadcaster reads these environment variables:

| Variable | Default | Meaning |
|---|---|---|
| `REDIS_HOST` | `localhost` | host of the broadcaster's Redis |
| `REDIS_PORT` | `6379` | its port |
| `REDIS_PASSWORD` | empty | its password; empty means no authentication |

## Sizing

- **Instances.** One instance accepts at most 10,000 WebSockets. In a load test with 100,000 concurrent test takers,
  30 instances (about 3,300 sockets each) handled the load with a CPU request of 150m and about 200 MiB of memory
  per instance. As a rule of thumb, plan one instance per 3,000 to 3,500 concurrent clients and at least two for
  availability.
- **Redis.** One `broadcaster-redis` instance is enough. At 100,000 test takers it used about 95m CPU and stayed
  within 128 MiB of memory. Memory grows with the number of registered tests and of monitored test sessions.
- **Autoscaling.** Scale on CPU. The memory of an instance is not released after a load peak, so a memory target
  would scale up but never down again.

## Failure behaviour

- **An instance fails.** Its clients lose their socket and reconnect to another instance. Their liveness markers
  expire after 90 seconds, and their stale entries are removed on the next push that addresses them.
- **Redis is unreachable.** The instances keep retrying the connection; commands wait until it is back. `/health`
  fails, so an orchestrator using it as readiness check takes the instances out of service. Calls from the backend time out after 2 seconds, and
  the backend carries on without the broadcaster.
- **Redis restarts.** Redis keeps no data on disk, so all registrations and session states are lost. Sockets that
  stay open receive no pushes until their clients register again. Avoid restarting `broadcaster-redis` during an
  exam.
- **Known limitation: mass disconnect.** When nearly all clients disconnect at the same moment, e.g. at the end of a
  large exam, the clean-up after the disconnects can block an instance's event loop for some time. Its readiness
  probe may then fail and the instance may be restarted. This does not happen while an exam is running.
