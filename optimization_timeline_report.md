# Testcenter Scalability & Optimisation Programme — Consolidated Report

**Engineer:** Hedi Ben Fradj
**Programme period:** 23 June 2026 – 31 August 2026 (**10 weeks**)
**Platform:** IQB Testcenter on STACKIT SKE (Gardener), ArgoCD-managed
**Objective:** take the platform from a single-instance deployment to a horizontally scalable system
capable of 40,000 and ultimately 100,000+ concurrent test takers

**Repositories covered**

| Repository | Role | Branch |
|---|---|---|
| `iqb-berlin_testcenter` | Application code (backend PHP, broadcaster, frontend) | `loadtest` |
| `fwu-infrastruktur` | Helm chart, Kubernetes deployment, ArgoCD | `opensource-testcenter-latest` |
| `lg-loadtest-util` | Locust load-test harness (AWS) | — |

---

## 1. Commit accounting

Commit history of both repositories was filtered to this author's commits (three git identities:
`benh`, `hedi-ben-fradj`, `Hedi Ben Fradj`). Where a commit message was terse, the diff was read to
establish what was actually solved.

| | Commits authored | **Substantial commits after consolidation** | Consolidation ratio |
|---|---|---|---|
| `iqb-berlin_testcenter` (application) | **47** | **18** | 2.6 : 1 |
| `fwu-infrastruktur` (infrastructure) | **76** | **33** | 2.3 : 1 |
| **Total** | **123** | **51** | **2.4 : 1** |

Of these, **18 commits fall after 18 August** (2 application, 16 infrastructure) and cover Phase 10
below, plus 7 further commits in the load-test harness repository.

Of the 60 infrastructure commits, **57 are on `opensource-testcenter-latest`**; the remaining 3 are the
23 June groundwork on an earlier branch.

### What "consolidation" means here

The 105 commits are the real working record. The **47** are the commits that each carry a distinct
engineering change — and **those 47 resolve 35 distinct issues.** The 58 that were folded away were not
wasted work; they were the iteration around each fix:

| Folded away | Count | Nature |
|---|---|---|
| Replica-count and HPA-threshold tuning | 26 | 1–3 line numeric changes between load-test runs. Their findings are preserved in the tuning record (Appendix A) rather than as 26 separate entries. |
| Merge commits | 8 | No content of their own. |
| Iteration on an already-identified fix | ~15 | Where 3–4 commits converged on one solution, the commit carrying the surviving fix is the one cited. |
| Documentation moves, build-config bumps, stash artifacts | ~7 | No change in capability. |
| Reverted or self-corrected same-day changes | 2 | Net zero effect. |

**Two commits were promoted rather than folded**, because reading their diffs showed they were
substantially more than their messages claimed — a full second ingress implementation behind
*"fixed url for app"*, and a cross-service failure-loop diagnosis behind a replica bump. Both appear as
full items below.

---

## 2. Executive summary of outcomes

| Metric | Start of programme | 31 August 2026 | Change |
|---|---|---|---|
| **Peak concurrent users reached** | ~27,000 | **~80,000 at ~1% failures** (20 Aug) | **~3×** |
| Request failure rate at peak | ~20% | **1%** (20 Aug) · 5% at 45k under sustained load (31 Aug, §Phase 10) | **−95%** |
| Dominant failure mode | HTTP 500 — resource exhaustion | connection-pool exhaustion, now root-caused (§Phase 10) | qualitative |
| Broadcaster | **1 pod** (state in process memory) | 8–40 pods (Redis-backed) | unbounded |
| Backend concurrency per pod | **5** PHP requests | 300 | **60×** |
| Backend replicas | fixed handful | up to 30, autoscaled on saturation | — |
| Frontend connection slots | ~10,240 across the tier | ~327,680 | **32×** |
| Database connection ceiling | 500, coupled to replica count | 1,800, pooled through ProxySQL | **3.6×** |
| DB statements per steady-state request | 5 over 11 table accesses | 4 over 6 table accesses | **−45%** |

The change in failure *character* is the most meaningful result. The platform no longer fails through
exhaustion; it sheds excess load deliberately and predictably. That is the precondition for operating
at 100,000 users.

**20 load-test runs** were executed across the period, each one producing the evidence for the next
round of fixes.

---

## 3. Timeline by phase

Nine phases, chronological. Each states its duration, which repositories it touched, how many of the
47 substantial commits it contains, and the resolution time per issue.

> **How to read "Detected".** Where a commit records the load-test run that surfaced the problem (several
> do, e.g. *"2026-08-05 loadtest"*), that date is used. Where it does not, the issue was found and fixed
> in the same working session and is marked *same session*.

---

### Phase 1 · Deployment foundation and portability
**23–26 June · 4 days · infrastructure only · 8 substantial commits**

The open-source Testcenter had no working chart. Nothing could be load-tested until it was deployable.

| Issue | Detected | Resolved | Elapsed |
|---|---|---|---|
| No deployable chart for the open-source Testcenter | 23 Jun | 26 Jun | 4 days |
| Chart tied to PCI-specific configuration | same session | 23 Jun | same day |
| Single generic storage class for both shared app data and the database | same session | 26 Jun | same day |
| No private-registry support | same session | 26 Jun | same day |
| Cache-server host hard-coded to one namespace | same session | 26 Jun | same day |
| Only Traefik ingress supported; 1 MB upload cap and 60 s timeout | same session | 26 Jun | same day |

- **Chart created** (`1ac6a5c` for TBA portal + Testcenter, then `04c94d1`, 37 files / 2,187 lines, for
  the open-source Testcenter): frontend, backend, database, file-server, ProxySQL, cache-server,
  broadcaster, Traefik routes, storage class.
- **Portability** (`d640972`, `0fbf256`): decoupled from PCI-specific configuration, and the
  cache-server host changed from a hard-coded `tc` namespace to `.Release.Namespace`. The chart now
  deploys into any namespace and any environment.
- **Storage matched to workload** (`d143395`): backend data and config to `nfs-client` (shared access,
  required for multiple backend replicas), database to `premium-perf1-stackit` (high-performance block
  storage). One generic class was wrong for both, in opposite directions.
- **Private registry** (`92f12bf`): `imagePullSecrets` threaded through all 9 workloads.
- **Second ingress implementation** (`1cf0f8c` — message reads *"fixed url for app"*, diff is 92 lines).
  Added a standard ingress-nginx `Ingress` alongside the Traefik route, gated by a value, mirroring the
  routing design. Critically, it also set `proxy-body-size` (ingress-nginx defaults to **1 MB**, so
  uploads failed with *413*) and the proxy read/send timeouts (default **60 s**, so admin uploads failed
  with *504*).
- Backend image reference corrected (`dd311ae`).

---

### Phase 2 · Architectural refactors — highest structural value
**24 June · 1 day (both delivered in a single session) · application only · 2 substantial commits**

Both were preconditions for *any* horizontal scaling.

| Issue | Detected | Resolved | Elapsed |
|---|---|---|---|
| Broadcaster could only ever run as one pod | known constraint | 24 Jun | same day |
| Apache/mod_php offered no concurrency control or saturation visibility | known constraint | 24 Jun | same day |

**Broadcaster made horizontally scalable** (`756d8d250`, 572 insertions / 191 deletions)
The broadcaster — the WebSocket service pushing live test-session state to monitors — held **all state
in process memory** (`private testSessions`, `private monitors`). Two pods would each hold a different
partial view, so a monitor connected to pod A would never see sessions registered on pod B. **The
service was structurally limited to a single pod**, a hard ceiling on total system concurrency.
Replaced with **Redis-backed shared state plus Redis pub/sub fan-out**: a new `RedisService` (190 lines)
providing hashes, sets, liveness partitioning and channel publish/subscribe, with every session and
monitor mutation converted to a shared-state operation and changes propagated to peer pods.
*Delivered with it:* unit-test adaptation, docker-compose topology, chart Redis dependency, docs.
**Result:** now runs 8–40 replicas.

**Backend migrated from Apache + mod_php to nginx + PHP-FPM** (`f8b1b380b`)
The Apache model ties one OS process to one request for its full lifetime, with no fine-grained
concurrency control, no request-admission limiting, and no visibility into saturation. Replaced with
nginx + PHP-FPM: a new nginx front controller (75 lines, replacing `.htaccess` mod_rewrite), a tuned
`fpm-pool.conf`, CORS moved into nginx, and a supervising `run-server.sh` so the container terminates
gracefully on SIGTERM.
**This is the enabling change for every later capacity gain** — worker tuning, `limit_conn` admission
control, FPM saturation metrics for autoscaling, and the nginx-level health endpoint all depend on it.

---

### Phase 3 · Deployment reliability
**24–26 June · 2 days · both repositories · 4 substantial commits**

Four deadlocks, each of which blocked deployment completely.

| Issue | Detected | Resolved | Elapsed |
|---|---|---|---|
| Seed job could never complete (circular wait) | same session | 24 Jun | same day |
| Frontend could never become ready (wave-0 deadlock) | same session | 25 Jun | same day |
| Backend-config PVC never bound | same session | 25 Jun | same day |
| Database redeploy deadlocked permanently | same session | 26 Jun | same day |

- **Seed job** (`61ab9bbd2`): registered as a `PostSync` hook, but PostSync waits for all resources to
  be healthy and the backend cannot become healthy until the seed job has written `config.ini` — a
  circular wait. Moved to a `Sync`-phase hook.
- **Wave-0 deadlock** (`5ade04333`): the frontend readiness probe calls `/api`, proxied to the backend,
  so the frontend could never be ready before the backend existed. Established explicit ordering:
  **db (0) → seed job (1) → backend (2) → frontend (3)**.
- **PVC never bound** (`d6f5bd1b5`): under a `WaitForFirstConsumer` storage class the PVC stayed
  `Pending` in wave 0, so ArgoCD waited indefinitely. Moved to wave 1, alongside the pod that triggers
  the bind.
- **Database redeploy** (`e1f30f3`): the default `RollingUpdate` surges a new pod before terminating the
  old one, but the new pod cannot attach the single ReadWriteOnce volume while the old pod holds it —
  a `Multi-Attach error` that never resolves. Switched to `Recreate`.

**Result:** deployment became repeatable and unattended.

---

### Phase 4 · Ending capacity collapse
**26–30 June · 4 days · both repositories · 5 substantial commits**

The most damaging early failure class, and entirely caused by default configuration.

| Issue | Detected | Resolved | Elapsed |
|---|---|---|---|
| Stock image capped each pod at **5** concurrent PHP requests | same session | 26 Jun | same day |
| Probes served through PHP-FPM → fleet-wide capacity collapse | 26 Jun | 30 Jun | **4 days** |
| File-server nginx workers blocking on NFS reads | same session | 29–30 Jun | 1 day |

- **FPM floor of 5** (`804e145c7`): the stock `php:8.3-fpm` image ships `pm.max_children = 5`. The FPM
  listen queue saturated so even the probes timed out, producing **cascading pod restarts**.
- **Probe-induced capacity collapse** (`88624d9c9` application, `cdc54c0` chart; interim mitigation
  `41356a0`). Both liveness and readiness were served *through PHP-FPM*, and readiness used
  `/system/status`, which additionally pings broadcaster, file-server and Redis — measured at **~4.7 s**
  under load. The consequences compounded into a self-reinforcing loop: readiness timed out, so **every
  pod dropped out of the Service simultaneously and capacity collapsed to a handful of pods**; liveness
  failed on busy-but-healthy pods, so Kubernetes **restarted them**, deepening the collapse. Fixed with
  a `/healthz` endpoint **served by nginx directly**, exempt from `limit_conn`, used by liveness and
  readiness — while the **startup** probe deliberately keeps the FPM-backed `/version` so a pod only
  joins the Service once PHP is genuinely serving.
- **File-server I/O stall** (`35fa8e5`, `cdc54c0`): nginx workers were **blocking on NFS reads** of the
  ~1.3 MB player asset every testee downloads — CPU idle while health checks timed out, an I/O stall
  that reads like a crash. Enabled Redis-backed file caching to remove the NFS reads; raised
  `worker_connections` 1,024 → 8,192 with a matching descriptor limit; lowered `keepalive_timeout`
  65 s → 15 s to release connection slots faster under churn. Added a MySQL exporter sidecar in the same
  commit, giving the database its first metrics endpoint.

**Result:** a saturated pod stays Ready and in rotation; overflow is shed as cheap 503s spread across
*all* pods instead of collapsing fleet capacity.

---

### Phase 5 · Connection architecture
**29 June – 11 August · 6 weeks · both repositories · 6 substantial commits**

The longest-running thread of the programme, revisited whenever another ceiling moved.

| Issue | Detected | Resolved | Elapsed |
|---|---|---|---|
| MySQL connection limit, not app capacity, was the scaling ceiling | same session | 29 Jun | same day |
| Every request opened a fresh DB connection (connect storm) | 3 Aug run | 5 Aug | **2 days** |
| ProxySQL sized per-pod, could open 1,200 against a limit of 800 | 3 Aug run | 3 Aug | same day |
| Connection headroom exhausted once autoscaling got faster | 5 Aug run | 5 Aug | same day |
| Database capacity left idle by proxy under-sizing | 11 Aug run | 11 Aug | same day |

- **ProxySQL introduced** (`d1938f5` chart, 232 lines; `e7dbadcab` application). Each backend pod had
  been opening direct MySQL connections, so worst-case demand grew as `workers × replicas`. The
  application-side change is notable for its care: the seed job must reach MySQL **directly**, since
  schema DDL should not pass through a pooler, so `run-server.sh` rewrites **only** the `[database]`
  section of the seed-written config, atomically, leaving cache-server credentials untouched.
  **This is the change that later allowed the backend to scale to 30 pods.**
- **Aggregate-versus-per-pod sizing error** (`f2dcb62`, message *"changed mysql connection"*).
  `maxMysqlConnections` is a **per-pod** cap, so three ProxySQL replicas could open 3 × 400 = **1,200**
  connections against a MySQL limit of only **800**. Two proxy pods could consume the entire budget and
  starve the third outright, surfacing to users as `Max connect timeout reached... hostgroup 0`.
- **Persistent connections** (`567759c8d`). Every request had been opening a fresh PDO connection — full
  TCP plus authentication handshake — and discarding it at request end, producing a *connect storm*
  through ProxySQL rather than a steady pool. Enabled `PDO::ATTR_PERSISTENT` so each PHP-FPM worker
  reuses one connection, with retry logic around setup.
- **Headroom restored twice** (`b0e8f0d`, `33aaf61`): once the new saturation metric let the backend
  scale 13 → 22 pods in ~90 s, `Max_used_connections` reached **1,276 of 1,300** — 24 short of the cap.
  Raised to 1,800; ProxySQL then raised 400 → 600 per pod so 3 × 600 = 1,800 matches MySQL exactly
  rather than leaving capacity idle.

---

### Phase 6 · Capacity ladder and the bulk-upload path
**26 June – 3 August · 5.5 weeks · both repositories · 3 substantial commits**

| Issue | Detected | Resolved | Elapsed |
|---|---|---|---|
| PHP-FPM worker count was the binding constraint, not CPU | 15 Jul run | 17 Jul | **2 days** |
| 100k-user upload exceeded ingress timeout | same session | 9 Jul | same day |
| Same upload exceeded the application's own hashing time limit | 3 Aug | 3 Aug | same day |

- **FPM workers raised 5 → 120 in four measured steps** (final step `11bc96e70`), each justified by
  evidence recorded in the config file rather than by guesswork: per-worker memory measured at
  ~1–1.5 MB RSS under load, ~440m CPU for 40 busy workers, sized against the pod's 2-core / 2.5 Gi
  limits. The final doubling was driven by an onboarding burst being shed as 503s for ~1m40s **while CPU
  sat at 2–4% of the pod's limit** — direct proof that the worker count, not CPU, was the ceiling. Each
  step raised MySQL `max-connections` in lockstep and kept nginx's `limit_conn` synchronised.
  *(The value is 300 today. The reasoning trail left in the config file is why the later proposal to
  lower it was correctly rejected.)*
- **Bulk-upload path** (`1a676d7` chart, `13860dd7c` application): ingress `proxyReadTimeout` 300 s →
  1,800 s → 3,600 s, and the application's password-hashing time limit 600 s → 1,200 s. A three-layer
  constraint — ingress, frontend nginx, backend PHP — that fails if only one layer is raised.

---

### Phase 7 · Autoscaling correctness — the key diagnostic insight
**3–5 August · 3 days · both repositories · 8 substantial commits**

| Issue | Detected | Resolved | Elapsed |
|---|---|---|---|
| HPA started too low; spawn rate outran the ramp | 3 Aug run | 4 Aug | **1 day** |
| **CPU-based HPA blind to shed load — scaled DOWN under overload** | 5 Aug run | 5 Aug | **same day** |
| FPM status page reported inconsistent process counts | 5 Aug | 5 Aug | same day |
| Pods scheduled faster than nodes could be provisioned | 5 Aug run | 5 Aug | same day |

**The finding worth presenting on its own.** nginx `limit_conn` rejects excess requests *before* they
reach PHP-FPM, so a shed request costs **~0 CPU**. Once shedding begins, aggregate CPU stops tracking
real demand and can read *low* while the fleet is badly overloaded. Measured directly (`151c9f4`):
**CPU read 63% while a single pod was shedding ~7,458 requests per minute, and the HPA scaled DOWN —
12 → 11 → 10 — during the incident.** The autoscaler was actively removing capacity mid-overload.

- **Ramp-up floor raised** (`20548d0`): a 40,000-user run hit a wall of `limit_conn` 503s even though
  the HPA scaled correctly (2 → 6 → 8 → 10 → 12 per its own event history) — it simply started too low
  and the spawn rate outran the ramp. `minReplicas` 2 → 6, matching where it settled under real load.
- **Interim mitigation** (`151c9f4`): CPU target lowered to move the trigger earlier, documented
  explicitly as *not* fixing the blind spot.
- **Real fix** (`d4ebea7` chart, `fb91a8334` application): enabled PHP-FPM's status page, deployed the
  `php-fpm_exporter` sidecar, and added a **PHP-FPM saturation metric** through Prometheus and
  prometheus-adapter, so the HPA scales on queue depth — a signal that *rises* under shedding instead of
  degrading. Two corrections followed as it was validated live: metric type `Pods/AverageValue` →
  `External/max` (`a1f964d`), and a required namespace mapping in the adapter rules (`55f3c05`).
- **Metric trustworthiness** (`fbac6f0`): enabled `--phpfpm.fix-process-count`, because PHP-FPM's status
  page can report process counts that don't match reality (a known upstream bug, observed here as
  repeated *"Inconsistent active and idle processes reported"*). Cited separately because this metric is
  a **hard scaling trigger, not a dashboard number**.
- **Node supply** (`0daccad`): pods sat `Pending` waiting on the cluster autoscaler while users were
  already arriving. Introduced a **warm-node overprovisioning buffer** of low-priority pause pods that
  hold real capacity and are preempted instantly by production workloads. Three refinements followed
  within the hour. Analysis of two 5 August latency spikes then confirmed the buffer was being **spent
  faster than replacement nodes arrived** — it is currently disabled pending retuning (open item).

---

### Phase 8 · Cross-service contention and database concurrency
**26 June – 12 August (main work 5–12 August, 8 days) · both repositories · 5 substantial commits**

| Issue | Detected | Resolved | Elapsed |
|---|---|---|---|
| Broadcaster autoscaled on CPU alone, a weak signal for this workload | same session | 26 Jun | same day |
| **Broadcaster lag feeding back into backend scale-up** | 5 Aug run | 5 Aug | same day |
| Broadcaster-redis stalls: O(n) tracking, health-check contention, leak | 11 Aug run | 11 Aug | **same day** |
| InnoDB concurrency thrash | 12 Aug run | 12 Aug | **same day** |

- **A genuine cross-service failure loop** (`7c13f68` — message mentions only replica floors; the diff
  documents the diagnosis). During a backend ramp to 30 pods the broadcaster reached only 4 → 5 → 7
  replicas via reactive CPU scaling, far behind demand — confirmed by **2,382
  `"Broadcaster responds Error … not available"` log lines across all 30 backend pods within a single
  minute**. Because the backend's client waits 1–2 s before giving up, each failed call **held a PHP-FPM
  worker longer, which fed the backend's own saturation metric and drove further backend scale-up**.
  The backend was scaling in response to a *broadcaster* shortage. Fixed by doubling the broadcaster
  floor and lowering both CPU (70% → 50%) and memory (80% → 60%) triggers. The broadcaster HPA had
  already gained a **memory metric** (`2c240fb`), since memory tracks held WebSocket connections and is
  the stronger signal for this workload.
- **Broadcaster-redis stalls** (`37672be14`, with `4194f59` for resourcing) — three compounding defects
  in one fix: **O(n) connection tracking** degrading quadratically with connection count (made
  **O(1)**), a **health check sharing the production code path** and competing with real traffic
  (isolated), and a **testee object leak**.
- **InnoDB concurrency thrash** (`90c1608`) — counter-intuitive, and worth stating plainly to
  management. InnoDB thread concurrency sat at the MySQL default of *unlimited*; under load this
  produced **1,802 simultaneously running threads at only 61% CPU**. The database was context-switching
  rather than working, and **throughput was lower at higher concurrency**. Capped at 12, later 24, with
  the DB CPU limit raised to 6 cores. Verified against history that the setting had never been
  configured — the pathology had been present from the start.

---

### Phase 9 · Final bottlenecks
**17–18 August · 2 days · both repositories · 6 substantial commits**

| Issue | Detected | Resolved | Elapsed |
|---|---|---|---|
| **Frontend nginx connection ceiling — 8× oversubscribed** | 17 Aug run | 17 Aug | **same day** (latent for weeks) |
| Frontend reserved 20 CPU cores for a ~1m workload | 17 Aug | 17 Aug | same day |
| Steady-state endpoint cost — 87.7% of all DB statements | 17 Aug | 18 Aug | **2 days** |
| ProxySQL escalating a full pool into an absent one | 18 Aug run | 18 Aug | **same day** |

- **Frontend connection ceiling** (`5bdb6d0a8` application, `14289e7` chart) — the highest-impact single
  fix. The frontend proxies *all* traffic, REST and WebSocket, and each proxied connection consumes
  **two** slots (client side plus upstream side). With stock `worker_connections 1024` × 2 workers,
  five pods offered ~10,240 slots against demand near 80,000 — **8× oversubscribed**.
  **Why it stayed hidden for weeks:** nginx accepts the TCP connection, finds no free slot, and closes
  it **without a response**. Probes report `EOF` and clients see 500/502, while frontend CPU sits at
  **1m of a 2000m limit** and the backend's PHP logs stay **completely clean** — the requests never
  arrived. Several earlier unexplained 500s are now attributed to this. Fixed by raising to 16,384
  connections with a 40,960 descriptor limit (**32× capacity**), exposing nginx `stub_status`, and
  adding an **nginx-prometheus-exporter sidecar with a connection-based HPA** so the frontend scales on
  real connection count rather than on a CPU signal that never moves.
- **Cost correction** (`085eaf9`): right-sized to 10/20 replicas at a **300m** CPU request, replacing
  20/40 at **1000m** — the previous sizing **reserved 20 CPU cores** for a workload consuming ~1m,
  capacity denied to the backend and broadcaster that needed it. The CPU *limit* was deliberately kept
  at 2000m because nginx derives its worker count from it.
- **Steady-state query optimisation** (`b010b012d`, then `b28a50fe4`). `PATCH /test/{id}/state` is the
  poll every logged-in user repeats forever and accounts for **87.7% of all database statements**.
  Auth-token resolution used a 3-branch `UNION` over 7 table accesses; ownership authorisation used a
  join; the current state was then read a second time. Reduced to **4 statements over 6 table accesses,
  from 5 over 11**, by selecting only the token branches the route accepts, turning authorisation into
  a single-table primary-key lookup that *also* returns the state, and reusing that row.
- **Proxy resilience** (`1963088`): with default settings, five connection failures made ProxySQL shun
  the database for 10 seconds, reporting *"Hostgroup 0 has no servers available"* — **turning a pool
  that was merely full into one that was absent.** Every request in that window waited a full
  10-second timeout and returned 500; observed **942 times in a single run.** Retuned
  `shun_on_failures` 5 → 1000, `shun_recovery_time_sec` 10 → 1, `free_connections_pct` 50.

### Phase 10 · Measurement discipline, and finding the real ceiling
**19–31 August · 13 days · both repositories + harness · 18 substantial commits**

The programme's best result came on **20 August: ~80,000 concurrent users at ~1% failures**, landing
immediately after the Phase 9 fixes. Everything in this phase followed from trying to push past it,
and much of it consisted of *disproving* earlier hypotheses.

| Issue | Detected | Resolved | Elapsed |
|---|---|---|---|
| `groupTokenExists` full scan — 99.76% of all rows examined | 19 Aug | 20 Aug | 1 day |
| `test_logs` had no PRIMARY KEY (global row-id serialisation) | 20 Aug | 26 Aug | 6 days |
| Raising workers 300→400 removed backpressure | 20 Aug run | 20 Aug | same day (reverted) |
| InnoDB concurrency cap became the constraint | 19 Aug run | 19 Aug | same day |
| **Database evicted mid-test** — 4 GiB pool inside a 4 GiB request | 26 Aug run | 27 Aug | 1 day |
| DB sharing a node at 98% memory / 97% CPU requests | 27 Aug | 27 Aug | same day |
| **Connections held idle — the real ceiling** | 31 Aug run | open | — |

- **The `groupTokenExists` full scan** (`96c198419`). A dead `LEFT JOIN logins` on an unindexed
  column — no column selected, no predicate referencing it — scanned **43,325 rows per call**,
  **99.76% of all rows examined in the database** (3.25 billion per run). Also a latent bug: being a
  LEFT JOIN on a non-unique key it *multiplied* rows, so `count(token)` returned 100 rather than 1.
  Removing it made this a unique-key lookup: **43,325 → 1 row.** `EXPLAIN` verified both plans and
  41/41 SessionDAO tests pass.
- **Commit durability** — `log_bin` was ON with `sync_binlog=1` and **no replica configured**, so every
  commit paid two fsyncs plus a three-stage coordination handshake, and binlogs occupied 2.2 GB of the
  2.9 GB used on the volume. Switching to `--skip-log-bin` + `innodb-flush-log-at-trx-commit=2`
  (values-gated as `durabilityProfile`) cut **fsyncs per write 0.108 → 0.0135 (8×)** and collapsed the
  write medians from 214/161 ms to **0.91/0.81 ms**, with the 2.3 ms floor disappearing entirely —
  proving the floor had been the fsync round trip.
- **InnoDB concurrency, reversed on evidence.** The cap added on 12 August became the constraint once
  commits were no longer fsync-bound: 287 samples showed `inside` pinned at 24 in **38%** of samples,
  a queue in **56%**, peak queue depth **1,690** — while CPU sat at **13%**. Set to 0. The chart
  comment records why the earlier "never set this to 0" warning no longer applied.
- **Database eviction.** A 4 GiB buffer pool inside a 4 GiB memory request meant the pool alone
  consumed the entire request; the kubelet evicted the pod mid-test (*"was using 4555076Ki, request is
  4Gi"*). Eviction selects on usage **relative to request**, so this was over-committed by
  construction. Buffer pool reduced to 2 GiB — measured at **zero throughput cost** (Δ0 disk reads,
  Δ0 page waits over a full run, 44% of the pool free). Anti-affinity (`6853559`) then moved ~1,128 Mi
  of co-tenants off the database's node, taking it from **98% → 79%** memory requests.
- **The real ceiling, found 31 August.** At 45,089 users with 5% failures, the database sat at **35%
  CPU** while **1,778 of 1,801 connections were held in `Sleep`** — idle but unavailable. ProxySQL
  multiplexing is enabled but is disabled per-connection by `LAST_INSERT_ID()`, and the three call
  sites that matter (`SessionDAO:225`, `SessionDAO:405`, `TestDAO:48`) are all on the **login path** —
  exactly where the failures appear (`createOrUpdat`, `getLogin`, `getTestsOfPer`). Combined with
  `PDO::ATTR_PERSISTENT`, each affected worker pins a backend connection rather than returning it to
  the pool. **This is the open item that now matters most.**

---

## 3b. Hypotheses tested and rejected

Recorded because each redirected effort, and because earlier drafts of this report asserted several of
them as fact.

| Hypothesis | Verdict |
|---|---|
| The `test_logs` foreign key is the dominant insert cost | **Rejected.** `tests.PRIMARY` fetches average 25 ms at 90k but **0.92 ms at 30k** — load-dependent contention, not a mechanism. Dropping the FK is not warranted and would create an orphaned-log problem |
| Adding a PRIMARY KEY to `test_logs` would fix the insert | **No measurable gain.** A real schema defect, worth keeping, but it did not move the cost |
| Insert batching needs to be built | **Already implemented** — the Angular client coalesces state changes over 700 ms (`bufferWhen` + `TestStateUtil.sort`) and `addTestLogs()` builds a multi-row INSERT. An A/B measured 5 rows at **2.94×** a 1-row statement, i.e. ~1.7× better per row |
| More workers per pod adds capacity | **Rejected.** 300→400 eliminated 503s and replaced them with ~1,100 ten-second DB timeouts per pod, crossing 1% *earlier*. `pm.max_children` was never reached. `limit_conn` was acting as admission control sized to the DB pool, not as a mirror of the worker count |
| Read replicas would relieve the database | **Rejected.** Reads are **18%** of DB time, and the two highest-volume reads are read-modify-write — routing them to a replica risks overwriting newer state |
| A `wait_time` change explained run-to-run differences | **Rejected.** `wait_time` has been `between(20, 60)` since 1 July. The actual difference is time at peak: the 20 Aug run had a 37.5-minute ramp inside a 40-minute test — **~2.5 minutes at full load** — versus ~10 minutes now |
| A 99.5% ProxySQL pool-miss rate proves starvation | **Rejected as a capacity signal.** Measured at 307 clients against 300 backend connections; it counts attempts that did not find an *immediately* free connection and then succeeded after waiting |

---

## 4. Resolution-speed summary

Across the 35 distinct issues resolved:

| Resolution time | Issues |
|---|---|
| Same day as detection | **28** |
| 1 day | 2 |
| 2 days | 3 |
| 4 days | 2 |
| **Total** | **35** |

**30 of 35 issues (86%) were resolved within one day of detection.** The longest single resolution was
four days — the probe-induced capacity collapse, which required a redesign of the health-check strategy
across both repositories rather than a parameter change.

**One distinction worth making explicit for planning purposes:** several *phases* span weeks while every
*issue* inside them resolved in days. Phase 5 runs six weeks and Phase 6 five and a half, not because
any single fix took that long, but because each was revisited whenever another ceiling moved — a new
load-test run would push connection usage or worker saturation to a new high-water mark, and the next
step would be taken against that fresh evidence. Those phases represent **repeated fast responses to new
data**, not slow work. This is also why they could not have been compressed: the evidence for step N+1
did not exist until step N had been deployed and tested.

---

## 5. Open items

Ranked by measured value per unit of effort, as of 31 August.

| # | Item | Effort | Rationale |
|---|---|---|---|
| 1 | **Stop `LAST_INSERT_ID()` pinning ProxySQL connections** | Days | The current ceiling. 1,778 of 1,801 connections held idle while the DB is 35% CPU. Three call sites on the login path (`SessionDAO:225`, `SessionDAO:405`, `TestDAO:48`) disable multiplexing per-connection. Replacing them (explicit ids, or `RETURNING`/`UUID` generation) should free most of the pool with no new hardware |
| 2 | **`ingress-nginx` memory request/limit** | Minutes (other team) | Requests 90 MiB, uses 388 MiB – 1.44 GiB, **evicted six times in 13 days**. Every request enters through it, so evictions surface as `ConnectionResetError`/`SSLEOFError` that look like application faults. Affects production, not only load tests |
| 3 | **Make the `test_logs` write asynchronous** | 1–2 days | 19% of statements and the largest remaining application cost — 202 ms average against a 0.05 ms floor, cause unexplained after eliminating the FK, the missing PK, batching, locks, I/O and the buffer pool. Deferring removes it from the request path regardless of cause; append-only and read only by an admin report |
| 4 | **Cache auth-token resolution in the existing Redis** | 1–2 days | `getToken` is ~24% of all statements resolving an immutable-per-token mapping. `cache-server` and `CacheService` already deployed. Fix the key-mismatch bug first: `storeAuthentication()` keys on the group token while `removeAuthentication()` deletes by person token, so entries never invalidate |
| 5 | **Dedicated DB node in eu01-2** | Cloud team | The db pool is 12.91 GiB but sits in eu01-3 while the DB's Cinder volume is pinned to eu01-2, so they cannot meet. Prerequisite for real connection headroom |
| 6 | **Connection headroom** | After #5 | ProxySQL 3×600 = MySQL 1,800 **exactly** — zero margin. Convention is the proxy aggregate at ~80% of the server limit |
| 7 | **Re-enable the overprovisioning buffer** | Days | Built and proven, currently `replicas: 0`; ramps still wait on node provisioning |
| 8 | **Protect DB volumes from cascading deletion** | Hours | `reclaimPolicy: Delete`; an ArgoCD application deletion destroyed the volume once |

### Explicitly not worth doing

- **Dropping the `test_logs` foreign key** — measured as not the cause (§3b), and removal creates an orphaned-log problem since `ON DELETE CASCADE` is the only cleanup mechanism
- **Building insert batching** — already implemented in both the client and the DAO (§3b)
- **Read/write replicas** — reads are 18% of DB time and the hot ones are read-modify-write (§3b)
- **More database CPU or RAM** — CPU peaks at 35–39%, buffer-pool hit rate 99.985%, zero page waits
- **More replicas or workers** — converts controlled 503s into slower database 500s until #1 and #6 land
- **Raising `innodb_redo_log_capacity`** — checkpoint age measured at 17% of capacity, `Innodb_log_waits` 0
- **Index tuning on the steady-state path** — rows examined is 0–3

---

## Appendix A · Consolidated tuning record

The 26 replica and threshold commits folded out of the timeline were the empirical search for each
service's working range between load-test runs. This is what they established.

| Parameter | Range explored | Final value | Basis for the final value |
|---|---|---|---|
| Backend HPA `minReplicas` | 2 → 6 → 25 | **5** | 2 lost the ramp; 25 was a forced floor for one full-scale test; 5 suffices with a fast saturation metric |
| Backend HPA `maxReplicas` | — | **30** | Currently the binding constraint (open item 2) |
| Backend HPA CPU target | 70 → 40 → 50 | **50** | 40 overcorrected for the blind spot; 50 once the saturation metric carried the real signal |
| Broadcaster HPA `minReplicas` | 4 → 8 → 25 | **5** | 8 after the feedback-loop diagnosis; reduced once the O(1) fix removed the underlying stall |
| Broadcaster HPA `maxReplicas` | 30 → 40 | **30** | Never approached under the fixed implementation |
| Broadcaster CPU / memory targets | 70 / 80 → 50 / 60 | **50 / 60** | Earlier trigger to cover the 1–3 min node ramp |
| Frontend replicas | 5 → 20/40 → 10/20 | **10 / 20** | Sized against measured connection load, not CPU |
| Overprovisioning buffer | 0 → 1 → 2 → 3 → 0 | **0 (disabled)** | Consumed faster than replacement nodes arrived; needs retuning |
| PHP-FPM `pm.max_children` | 5 → 24 → 40 → 60 → 120 → 300 | **300** | Each step measured; 300 required to stop ramp-up 503s |
| MySQL `max-connections` | 500 → 600 → 800 → 1,300 → 1,800 | **1,800** | Each step driven by an observed `Max_used_connections` high-water mark |
| ProxySQL `maxMysqlConnections` | 400 → 600 (per pod) | **600** | 3 × 600 = 1,800, matching MySQL exactly |
| InnoDB thread concurrency | unlimited → 12 → 24 | **24** | ≈4× the 6-core CPU limit; unlimited caused thrash |

**The pattern worth reporting.** Every value that was raised blindly had to be corrected later; every
value derived from a measurement held. The tuning commits cluster tightly around load-test runs
precisely because that is when evidence arrived — which is also why the three multi-week items in
Section 4 could not have been compressed.

---

## Appendix B · Companion documents

All under `docs_personal/`:

| Document | Contents |
|---|---|
| `database_optimization_report.md` | Full database analysis: every change, before/after, methodology |
| `database_request_analysis.md` | Per-statement analysis of `PATCH /state` with min/median/avg/max |
| `loadtest_bottleneck_timeline.md` | Bottleneck-and-resolution timeline with candidate tickets |
| `engineering_contribution_timeline_v2.md` | Application-repository contribution timeline (curated) |
| `helm_chart_change_timeline.md` | Infrastructure/Helm timeline with chart inventory |
| `run_report_20260819.md`, `run_report_concurrency0.md` | Individual run measurements |

**Note:** documents dated 18–19 August predate the findings in §3b and state several of the rejected
hypotheses as fact. Where they disagree with this report, this report supersedes them.
