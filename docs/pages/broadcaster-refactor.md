---
layout: default
---

# Broadcaster Refactor — Horizontal Scalability via Redis

## Goal

Convert the broadcaster (NestJS + `ws`, Node) from a **single-pod, in-memory** WebSocket server
into a **multi-pod, horizontally-scalable** one. All shared state and all cross-pod message fan-out
go through a **dedicated, standalone Redis** (Redis pub/sub + Redis data structures). A client may
connect its WebSocket to **any** pod — no sticky sessions / `ip_hash`.

### Why this was needed

Previously every piece of state lived in plain in-memory JS objects inside a single process:
the live socket map, the monitor/testee registrations, and the cached session state. That made the
broadcaster impossible to scale horizontally:

- a WebSocket is pinned to the one pod that terminates it, and only that pod's memory knew the
  `token → socket` mapping;
- the backend pushes a change to the broadcaster `Service`, which would load-balance to *one*
  arbitrary pod — and that pod could only reach the sockets it personally held, so every client on
  a different pod silently missed the update;
- there was no cross-pod messaging to fan a push out to all pods.

The deployment reinforced this: `replicas: 1`, no shared store, no autoscaling.

## Core architectural principle

Two independent layers, kept deliberately separate:

1. **Registration / group membership = GLOBAL**, lives in Redis. When a monitor/testee logs in, the
   PHP backend POSTs a registration (token + groups / testId) to the broadcaster → written to Redis,
   visible to every pod.
2. **The live WebSocket socket = LOCAL**, lives only in the pod that terminates it. Each pod keeps
   an in-memory `Map<token, WebSocket>` of the sockets it personally holds. Never shared.

**Broadcasting = "fan-out then local-intersect":** resolve target tokens from Redis group membership
→ publish `{message, tokens}` on a Redis pub/sub channel → **every** pod receives it → each pod
intersects the token list with its local `Map` and sends only to sockets it holds. Pods holding none
do nothing.

### Redis data model (keys)

| Key | Type | Contents |
|-----|------|----------|
| `testees` | HASH | `token → {token, testId, disconnectNotificationUri}` |
| `testee-testid:<id>` | SET | testee tokens for a testId |
| `monitors` | HASH | `token → {token, groups[]}` |
| `monitor-groups:<g>` | SET | monitor tokens watching group `g` |
| `testSessions:<g>` | HASH | `testId → TestSessionChange` JSON (live session state per group) |
| `activeGroups` | SET | group names that have session state |
| `client-alive:<token>` | STRING | `"1"` with `EX ~90s` — per-connected-token liveness marker |
| `websocket-connections` | LIST | connected tokens (ops/debug only, not read by code) |

### Pub/sub channels

| Channel | Payload |
|---------|---------|
| `broadcaster:session-change` | `{groupName, sessions: TestSessionChange[], tokens: string[]}` |
| `broadcaster:command` | `{command: Command, tokens: string[]}` |
| `broadcaster:system-clean` | `{}` (every pod disconnects local clients) |

### Liveness & lazy cleanup

Each pod refreshes `client-alive:<token>` (`EX ~90s`) on its 30s heartbeat for its local sockets.
Before every broadcast, `partitionByAlive(tokens)` (pipelined `EXISTS`) splits alive/dead; dead
tokens are removed from Redis sets/hashes and only alive tokens are published. This self-heals stale
registrations left behind by pods that crashed without running `handleDisconnect`.

### Atomicity

`testSessions:<group>` is a HASH; an incoming `TestSessionChange` is merged via a single Lua `EVAL`
(`KEYS=[hashKey]`, `ARGV=[testId, incoming JSON]`): read → deep-merge `unitState`/`testState`/scalars
(reset `unitState` if `unitName` changed) → write. Concurrent updates to the same `testId` (possibly
from different pods) cannot interleave or lose data.

---

## Commit 1 — Broadcaster application changes

Move registration/group membership and session state to a shared Redis, and fan out cross-pod via
Redis pub/sub. Each pod keeps only a local `Map` of the sockets it terminates.

### New files

- **`src/redis/redis.service.ts`** — `ioredis` wrapper with **two** connections: `pub` (commands +
  publish) and `sub` (subscriber-only; a connection in subscriber mode cannot issue normal commands).
  A single `message` dispatcher on `sub` routes to a `Map<channel, handler>`. Exposes
  `publish/subscribe`, `hset/hget/hdel/hgetall`, `sadd/srem/smembers`, `del`,
  `pushConnection/removeConnection`, `setClientAlive/deleteClientAlive`, `partitionByAlive`, and the
  atomic `mergeSessionHash` (Lua). Config from `REDIS_HOST/REDIS_PORT/REDIS_PASSWORD`.
- **`src/redis/redis.constants.ts`** — Redis key builders, channel names, and pub/sub payload types.

### Refactored files

- **`src/common/websocket.gateway.ts`** — keeps the local `Map<token, WebSocket>` and a `WeakSet`
  for ping/pong liveness, but now: writes `client-alive` + `pushConnection` on connect; deletes them
  on disconnect; refreshes the `client-alive` TTL on each heartbeat; adds `filterLocalTokens(tokens)`
  (intersect a cluster-wide token list with locally-held sockets); and implements `onModuleDestroy`
  for **graceful shutdown** (clear heartbeat → `disconnectAll` → wait ~5s so clients detect the close
  and reconnect to a surviving pod).
- **`src/test-session/test-session.service.ts`** — `applySessionChange` does an atomic Lua merge into
  `testSessions:<group>`, adds the group to `activeGroups`, resolves `monitor-groups:<group>`, evicts
  dead monitors via `partitionByAlive`, and publishes a `session-change` with the alive tokens.
  Subscribes to `session-change` (intersect + send locally) and `system-clean` (`disconnectAll`).
  `addMonitor`/`removeMonitor` read/write Redis; `removeMonitor` also disconnects the local socket.
- **`src/testee/testee.service.ts`** — `addTestee`/`removeTestee` use Redis;
  `broadcastCommandToTestees` unions testee tokens across testIds, evicts dead, publishes a `command`
  with alive tokens. `notifyDisconnection` reads the `disconnectNotificationUri` from Redis **before**
  deletion and POSTs it (fire-and-forget, with retries + exponential backoff). Subscribes to `command`
  (intersect + send locally).
- **`src/*/*.controller.ts`** (monitor, testee, command, test-session, system) — handlers became
  `async` because the services now do Redis I/O. `system.controller` publishes `system-clean` so
  **all** pods drop their local sockets, then clears Redis state.
- **`src/app.module.ts`** — registers `RedisService` as the first provider.
- **`src/main.ts`** — `app.enableShutdownHooks()` so `onModuleDestroy` runs on `SIGTERM`/`SIGINT`.

### Dependency

- **`package.json` / `package-lock.json`** — adds `ioredis@^5`.

### Why these changes

This is the heart of the refactor: it removes the single-process assumption. State that must be seen
by every pod (who is registered, what the live session state is) moves to Redis; the only thing that
stays local is the OS-level socket, which inherently can't be shared. Pub/sub is what lets a push that
arrives on one pod reach a socket held by another.

---

## Commit 2 — Adapt unit tests to the Redis-backed broadcaster

### New file

- **`src/redis/redis.fake.ts`** — an in-memory stand-in for `RedisService` implementing the same
  public surface and delivering published messages synchronously to subscribers, so a single instance
  models one pod. Used by the service specs to assert end-state and cross-pod delivery without a real
  Redis.

### Rewritten specs

- `src/common/websocket.gateway.spec.ts`
- `src/test-session/test-session.service.spec.ts`
- `src/test-session/test-session.controller.spec.ts`
- `src/testee/testee.service.spec.ts`
- `src/testee/testee.controller.spec.ts`
- `src/monitor/monitor.controller.spec.ts`
- `src/command/command.controller.spec.ts`

### Why these changes

The old specs asserted against in-memory objects (`testSessions`, `monitors`) and synchronous
methods, which no longer exist. Two adaptations were required:

1. Services now take `RedisService`, so each `TestingModule` provides the `FakeRedisService` double.
2. Controllers became `async`, so a synchronous validation `throw` is now a **rejected promise** —
   the throw-assertions moved from `expect(() => …).toThrow()` to `await expect(…).rejects.toThrow()`,
   and happy paths to `await expect(…).resolves.toBeUndefined()`.

Result: **64 tests pass**, keeping `npm run unit-test` green after the refactor. (Note: because app
and tests are split into two commits, the test suite is red between commit 1 and commit 2 — that is
inherent to separating the two concerns.)

---

## Commit 3 — Add dedicated Redis service to docker-compose

### Changed file

- **`docker-compose.yml`** — adds a standalone `broadcaster-redis` service (Redis 8, `noeviction`,
  no persistence) and wires `REDIS_HOST=broadcaster-redis` / `REDIS_PORT` / `REDIS_PASSWORD` into the
  broadcaster, plus `depends_on: broadcaster-redis`.

### Why these changes

The new broadcaster cannot run without a Redis to connect to. A **dedicated, standalone** instance
(separate from the existing `cache-server`, which serves the backend/file-server auth + file cache)
keeps the two concerns isolated: the broadcaster's data is a **state store** that must never be
evicted, whereas `cache-server` is a cache with LRU eviction. `noeviction` is chosen for exactly that
reason — registration/session keys must not be dropped under memory pressure.

---

## Commit 4 — Make the Helm chart multi-pod

### New files

- **`scripts/helm/testcenter/templates/broadcaster-redis/deployment.yaml`**
- **`scripts/helm/testcenter/templates/broadcaster-redis/service.yaml`**
- **`scripts/helm/testcenter/templates/broadcaster-redis/secret.yaml`** — the dedicated standalone
  Redis (one replica, `noeviction`, `requirepass` from its own secret), mirroring the `cache-server`
  template style.
- **`scripts/helm/testcenter/templates/broadcaster/hpa.yaml`** — `HorizontalPodAutoscaler` v2,
  **CPU only**, gated on `autoscaling.enabled`.
- **`scripts/helm/testcenter/templates/broadcaster/poddisruptionbudget.yaml`** — `minAvailable: 1`,
  created only when the effective replica count is `> 1`.

### Changed files

- **`scripts/helm/testcenter/templates/broadcaster/deployment.yaml`** — adds `REDIS_HOST/PORT/PASSWORD`
  env (host = `<release>-broadcaster-redis`, password from the new secret); makes `replicas` conditional
  (omitted when the HPA is enabled, so the two don't fight); adds `terminationGracePeriodSeconds` and a
  `preStop: sleep 5` lifecycle hook; adds a `wait-for-broadcaster-redis` init container; adds a
  `checksum/secret` annotation so password rotation rolls the pods.
- **`scripts/helm/testcenter/values.yaml`** — adds `image.broadcasterRedis`, `secret.broadcasterRedis`
  (reuses the `redisPassword` anchor), `deployment.broadcasterRedis` (resources + probes), and a
  `deployment.broadcaster` block with `replicas`, `terminationGracePeriodSeconds`, and an `autoscaling`
  section (`enabled`, `minReplicas`, `maxReplicas`, `targetCPUUtilizationPercentage`).

### Why these changes

Without this, deploying the new code to Kubernetes would be **broken** — the chart passed no Redis
env (the app would retry-loop against `localhost:6379`) and still ran a single replica. The chart now:

- provisions the dedicated Redis and points the broadcaster at it;
- can run 2+ pods, optionally driven by a CPU-based HPA;
- drains gracefully on scale-down / rollout. Two complementary mechanisms:
  - **`preStop: sleep 5`** delays `SIGTERM` so kube-proxy removes the pod from the Service endpoints
    first — no *new* connections land on a pod that is about to die. It then dovetails with
    `onModuleDestroy`, which closes existing sockets so clients reconnect to a survivor.
  - **PodDisruptionBudget (`minAvailable: 1`)** ensures *voluntary* disruptions (node drains,
    autoscaler) evict pods one at a time, never all at once. It is gated on `> 1` replica because a
    PDB of `minAvailable: 1` with a single pod would block all node drains.
- **CPU-only HPA**: memory scales with the number of held WebSocket connections (roughly static per
  pod), so it is a poor autoscaling signal; CPU reflects actual broadcast/serialization work.

The Traefik `IngressRoute` already round-robins a ClusterIP with no stickiness and handles WebSocket
upgrades natively, so no sticky-session change was needed there.

---

## Acceptance criteria — all verified

1. **2+ pods behind a non-sticky LB; a monitor on pod A sees changes for a testee on pod B.** ✅
2. **Kill a pod: clients reconnect elsewhere and resume; stale registrations evicted lazily.** ✅
   (registration survives in Redis through a `SIGKILL`; lazy `client-alive` eviction self-heals)
3. **A command to testIds reaches exactly the matching testees across all pods, once each.** ✅
4. **Concurrent session-changes for the same testId don't lose state.** ✅ (atomic Lua merge)
5. **`POST /system/clean` disconnects all clients on all pods and clears all Redis state.** ✅
6. **Scale-down doesn't hard-drop clients (graceful `preStop` + `onModuleDestroy`).** ✅

Verified three ways: **64 unit tests** (against the in-memory fake), a **local 2-pod docker-compose
demo** (dedicated Redis + two pods + a non-sticky nginx LB, with deterministic per-pod cross-pod
checks), and a **k3d run** (2 pods + HPA + PDB, with the HPA observed scaling 2 → 5 under CPU load).

## Note on the build

This refactor depends on a `broadcaster/tsconfig.json` fix (`ignoreDeprecations: "6.0"` +
explicit `rootDir`) required by the TypeScript 6.0 bump; without it the `nest build` step (local,
Docker, and CI) fails. That fix is committed separately (already at `HEAD`) and is a prerequisite for
all four commits above.
