# Running large exams

This page brings the scaling features together for exams with tens of thousands of concurrent test takers on
Kubernetes. The Helm chart ships a profile, `scripts/helm/testcenter/values-large.yaml`, with the settings that served
**100,000 concurrent test takers without failed requests**: 0 of 3.26 million requests failed during the ramp-up
(17 minutes) and at full load. Every block of the profile carries a short comment with its reason; this page explains
the architecture, the order in which to switch the features on, the node sizing and the known limits.

```sh
helm upgrade --install <release> scripts/helm/testcenter \
  -f scripts/helm/testcenter/values-large.yaml -f <your-values>.yaml
```

The profile sets only what is specific to the size. Your own values still need the domain, the secrets, the storage
classes, and the object store if you use one.

## Architecture at 100,000 test takers

```
 browsers ──▶ ingress ──┬── /api ─────────▶ backend (60 pods, nginx + PHP-FPM, 300 workers each)
                        │                     │          │
                        ├── /bs/public/ws ──▶ broadcaster (30 pods) ◀──▶ broadcaster-redis
                        │                     │          │
                        └── everything else ▶ frontend (5 pods)       cache-server (Redis):
                                                │                     tokens, test states,
                                                └── /fs ─▶ file-server   log queue, presigned URLs
                                                          │
           backend ──▶ PgBouncer (3 pods) ──▶ PostgreSQL (1 pod, dedicated node)
           log-drainer, state-drainer ─────▶ PostgreSQL (directly)
           exporters ──▶ Prometheus ──▶ prometheus-adapter ──▶ HPAs of backend and frontend
```

- **Backend:** scales between 2 and 60 pods on CPU and on busy PHP-FPM workers. Every worker holds one connection to
  PgBouncer and one to Redis, so 60 pods hold 18,000 of each.
- **PgBouncer:** 3 pods multiplex those 18,000 connections onto 210 PostgreSQL connections.
- **Redis (cache-server):** answers the token check of every request, holds the state of every running test and the
  queue of test logs. The two drainers write states and logs to the database in batches.
- **Broadcaster:** one WebSocket per open browser, about 3,300 per pod; its shared state lives in
  `broadcaster-redis`.
- **Frontend:** with `/api` and the WebSocket routed directly by the ingress, it serves only the application and the
  file requests.
- **PostgreSQL:** a single instance on a node of its own.

## Switching the features on

Switch the features on in this order, one at a time, and check the installation after each step. Each step builds on
the ones before it; all switches are off by default, and the profile switches all of them on.

1. **Backend runtime and broadcaster.** Nothing to switch: the backend runs PHP-FPM with 300 workers per pod
   ([Scaling: PHP-FPM](./scaling-php-fpm.md)), and the broadcaster runs with several pods
   ([Scaling: Broadcaster](./scaling-broadcaster.md)).
2. **Persistent connections and PgBouncer** (`config.db.persistentConnections`, `config.pgbouncer.enabled`):
   [Scaling: Connection Pooling](./scaling-connection-pooling.md).
3. **Auth-token cache** (`config.backend.cacheAuthToken`): [Scaling: Auth-Token Cache](./scaling-auth-cache.md).
4. **Async test logs** (`config.backend.asyncTestLogs`, deploys the log drainer):
   [Scaling: Async Test Logs](./scaling-async-logs.md).
5. **Test-state buffer** (`config.backend.bufferTestState`, needs step 3, deploys the state drainer):
   [Scaling: Test-State Buffer](./scaling-test-state-buffer.md).
6. **Presigned-URL cache** (`objectStore.cachePresignedUrls`, only with the object store):
   [Scaling: Object Store](./scaling-object-store.md).
7. **Metrics and autoscaling** (`fpmExporter`, `nginxExporter`, `prometheus`, `prometheusAdapter`,
   `deployment.*.autoscaling`): [Scaling: Autoscaling](./scaling-autoscaling.md).
8. **Node layout:** topology spread and node sizing, below.

**XML schema validation** (`config.backend.xmlSchemaValidation`) is off in the profile. The backend validates an XML
file against its schema every time it loads the file, not only on upload, and at this size that keeps the workers
busy reading schemas and files. Uploaded files are then not checked against their schema either, so check them on an
installation with validation on before uploading them.

**Direct `/api` route** (`ingress.apiDirectToBackend`, with `ingress.traefikEnabled`): Traefik sends `/api` straight
to the backend, stripping the prefix and setting `X-Forwarded-Prefix: /api`. The WebSocket already goes straight to the
broadcaster. Request size and timeouts are then those of the Traefik entry point, not the frontend's 2 GB and 600 s;
workspace uploads need both to be large enough. With another ingress controller, configure the same route yourself:
`/api` to the Service `backend` on port 8080, prefix stripped, `X-Forwarded-Prefix: /api` set. Without the direct
route, every API request also passes the frontend, which then needs more pods.

## Nodes

For 100,000 test takers, plan **at least 30 nodes with 4 vCPU and 8 GB** for the application, with **at most 2
backend pods per node**, plus **one node with 8 vCPU and 32 GB** for the database. The ingress controllers ran on the
same 30 nodes (12 controllers with 1.2 CPU each, at most one per node).

**Why at most 2 backend pods per node.** A backend pod requests 900m but uses 0.9 to 1.5 CPU at peak. By its request a
third pod still fits on a node with 3.92 allocatable CPU, but three pods at peak saturate it. In a run on 25 nodes,
the 10 nodes with 3 backend pods produced all 503 responses, up to 102% node CPU; together with DNS failures from a
starved DNS pod on such a node, 0.3% of the requests failed. With 30 nodes and 2 pods per node, no node went above
81%, and nothing failed.

**Topology spread.** The profile spreads backend and broadcaster pods over the nodes with a soft constraint
(`whenUnsatisfiable: ScheduleAnyway`). A hard constraint (`DoNotSchedule`) stalls rolling updates, because the pods of
the old and the new ReplicaSet are counted separately. The values are rendered with `tpl`, so they can use template
expressions such as the release name, as in the profile.

**Check the placement before an exam.** A soft constraint is only a preference. Some managed clusters run a
scheduler profile that fills nodes before it uses empty ones (bin-packing), and then every backend rollout puts 3 pods
on some nodes again. Before a large exam, count the backend pods per node and move pods away from overfull nodes by
deleting them one at a time:

```sh
kubectl get pods -l app=<release>-backend-pod -o wide --no-headers | awk '{print $7}' | sort | uniq -c | sort -rn
```

**Overprovisioning.** With a cluster autoscaler, new nodes take one to several minutes to join. The chart can keep
spare capacity with low-priority placeholder pods (`overprovisioning.*`, off by default): a pod that needs room
preempts a placeholder at once, and the autoscaler adds a node for the placeholder in the background. Size a
placeholder close to the free capacity of an empty node. Each placeholder costs a node. The 100,000-user runs used a
fixed node pool and no placeholders.

**Database node.** `deployment.db.nodeSelector` and `deployment.db.tolerations` keep the database on a node of its
own; `deployment.db.settings` passes PostgreSQL settings and `deployment.db.sharedMemorySize` sizes `/dev/shm` for
parallel queries.

## Resources per component

Requests and limits of the profile, and the use at 100,000 test takers where it was measured.

| Component | Pods | CPU request / limit | Memory request / limit | Use at peak |
|---|---|---|---|---|
| backend (+ php-fpm exporter) | 2–60 | 900m / 1500m | 1280Mi / 2048Mi | 0.9–1.5 CPU per pod |
| broadcaster | 2–30 | 150m / 500m | 200Mi / 512Mi | 120–150m, about 200 MiB |
| frontend (+ nginx exporter) | 2–5 | 300m / 2000m | 128Mi / 1024Mi | about 70m, below 100 MiB |
| PgBouncer | 3 | 250m / 1000m | 128Mi / 512Mi | |
| cache-server (Redis) | 1 | 1000m / 2000m | 1Gi / 2Gi | about 190m; 18,000 connections |
| broadcaster-redis | 1 | 500m / 1500m | 128Mi / 512Mi | about 100m, below 128 MiB |
| log drainer, state drainer | 1 each | 100m / 500m | 128Mi / 512Mi and 256Mi | |
| Prometheus, prometheus-adapter | 1 each | 100m / 500m, 100m / 250m | 512Mi / 1536Mi, 180Mi / 256Mi | |
| PostgreSQL | 1 | 4000m / 7500m | 4Gi / 12Gi | |

At 100,000 test takers the application requests about 63 CPU and 86 GiB, without the database and the ingress
controllers. The node count follows from the CPU use at peak, not from the requests: see above.

**Smaller exams.** The backend, the broadcaster and the frontend grow with the number of test takers; everything else
stays the same size. For 10,000 test takers, about 6 backend pods, 3 broadcaster pods and 2 frontend pods on 4 nodes
plus the database node are a reasonable start. This is derived from the 100,000-user measurement, not measured itself.

## Known limits

- **End of an exam.** When all test takers disconnect at the same moment, the broadcaster's event loop stalls for a
  while. The broadcaster reports every lost connection to the backend, and the backend's push back to the stalled
  broadcaster waits up to 2 seconds, holding a PHP worker each time. At 100,000 test takers that produced about 600
  failed requests in the last minute. It does not happen while an exam runs.
- **Broadcaster autoscaling on memory.** The memory of a broadcaster pod is not released after a load peak, so an
  autoscaler on memory scales up but never down again. The chart scales the broadcaster on CPU only.
- **XML schema validation on every read.** See above; validating only on upload would allow switching it back on.
- **One database.** PostgreSQL runs as a single instance, and PgBouncer's pool does not grow with the backend. More
  backend pods add no database throughput.
- **File server.** The profile keeps the chart's file server, which has the backend sign the object-store URL for each
  resource request and redirects the browser there. In the 100,000-user runs the backend answered these requests
  without a file server in between, so the file server was not measured at this size.
