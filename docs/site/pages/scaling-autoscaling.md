# Scaling: Autoscaling

The Helm chart (`scripts/helm/testcenter`) can scale the backend and the frontend automatically with
Horizontal Pod Autoscalers (HPA) that react to **saturation**: how many PHP-FPM workers are busy and how
many connections nginx holds. All parts are optional and **off by default**; without them the chart
renders exactly as before.

docker compose has no autoscaling; this page applies to Kubernetes only.

## Why not CPU alone

**Backend.** Each backend pod processes at most `pm.max_children` requests at a time (see
[Scaling: PHP-FPM](./scaling-php-fpm.md)). Requests beyond that are rejected by nginx with an immediate
503, which costs almost no CPU. So once a pod is saturated, its CPU usage stops rising with demand and can
even fall: requests that wait for the database or are shed do not show up in CPU. A CPU-only HPA may then
keep the replica count or even scale down while users receive 503s. The number of busy PHP-FPM workers
keeps rising until the pod is full and therefore is the signal that shows saturation.

**Frontend.** The frontend's nginx serves the Angular app and proxies `/api/` and the broadcaster
WebSocket. Its limit is `worker_connections`, not CPU: a proxied request occupies two connection slots
(client and upstream). When the slots are used up, nginx closes new connections without an answer while
its CPU is still nearly idle. A CPU target would never fire, so the frontend HPA scales on active
connections only.

**Maximum, not average.** An HPA averages a per-pod (`Pods`) metric over all pods, so a few saturated
pods are hidden behind idle ones. The chart therefore serves each signal as an `External` metric that
already is the **maximum across all pods** of the release namespace, and the HPA compares it directly
with the target (`target.type: Value`).

## Metrics

| External metric (default name) | Source | Meaning |
|---|---|---|
| `phpfpm_active_processes_max` | `phpfpm_active_processes` of php-fpm_exporter | busy PHP-FPM workers of the most loaded backend pod |
| `nginx_connections_active_max` | `nginx_connections_active` of nginx-prometheus-exporter | open client connections of the most loaded frontend pod |

The names can be changed with `fpmExporter.hpaMetricName` and `nginxExporter.hpaMetricName`.

## Components

| Values key | What it adds | Scope |
|---|---|---|
| `fpmExporter` | [php-fpm_exporter](https://github.com/hipages/php-fpm_exporter) sidecar in the backend pod. It reads PHP-FPM's status page (`pm.status_path = /status`) over FastCGI on `127.0.0.1:9000`, bypassing nginx, with `--phpfpm.fix-process-count` because PHP-FPM's own summary can be inconsistent. | pod |
| `nginxExporter` | [nginx-prometheus-exporter](https://github.com/nginx/nginx-prometheus-exporter) sidecar in the frontend pod. It reads `stub_status` from `/nginx_status`, which the frontend image answers only for requests from inside the pod (`127.0.0.1`, `::1`). | pod |
| `postgresExporter` | [postgres_exporter](https://github.com/prometheus-community/postgres_exporter) sidecar in the database pod (connections by state, transactions, locks, cache hit ratio). For monitoring only, no HPA uses it. | pod |
| `prometheus` | One Prometheus instance with short retention on an `emptyDir`. It scrapes every pod of the release namespace with the annotation `prometheus.io/scrape: "true"`, which the exporter sidecars set. Not a monitoring stack: no alerting, no dashboards, no persistence. | namespace |
| `prometheusAdapter` | [prometheus-adapter](https://github.com/kubernetes-sigs/prometheus-adapter) that serves the two metrics above through `external.metrics.k8s.io`. | **cluster** |
| `deployment.backend.autoscaling` | HPA on CPU and, with `fpmExporter`, on `phpfpm_active_processes_max`; it scales to whichever metric asks for more replicas. | namespace |
| `deployment.frontend.autoscaling` | HPA on `nginx_connections_active_max` only. Rendering fails if `nginxExporter` is off. | namespace |
| `deployment.backend.podDisruptionBudget` | PodDisruptionBudget with `minAvailable: 1`, rendered only if the minimum replica count is above 1. | namespace |

With autoscaling enabled, the Deployment's `replicas` field is omitted so that upgrades do not reset the
replica count chosen by the HPA.

## Permissions of the prometheus-adapter

The adapter is an **aggregated API server**. Installing it needs **cluster-admin rights**, and it changes
cluster-wide state:

- It registers the `APIService` `v1beta1.external.metrics.k8s.io`. Only **one** server in the whole
  cluster can serve this API. If the cluster already has one (another prometheus-adapter, KEDA, a
  cloud provider's metrics adapter), enabling `prometheusAdapter` takes it over and breaks the other
  user. Check with `kubectl get apiservice v1beta1.external.metrics.k8s.io`. Two releases of this chart
  in one cluster cannot both enable it.
- `ClusterRole`/`ClusterRoleBinding`: read nodes, namespaces, pods and services; bind
  `system:auth-delegator` (delegated authentication and authorization, required for every aggregated
  API server); allow the HPA controller (`kube-system/horizontal-pod-autoscaler`) to read external
  metrics.
- A `RoleBinding` **in `kube-system`** to `extension-apiserver-authentication-reader`.

`metrics.k8s.io` (CPU and memory for the CPU target) is not touched; it keeps coming from the
cluster's metrics-server, which must be installed.

Prometheus itself needs only a namespaced `Role` that lists pods in the release namespace.

**Using an existing Prometheus or adapter.** Set `prometheusAdapter.prometheusUrl` to use an existing
Prometheus instead of the chart's own (`prometheus.enabled: false`); it must scrape the exporter
sidecars, for example via the `prometheus.io/*` pod annotations. If the cluster already runs a
prometheus-adapter, leave `prometheusAdapter` off and add equivalent rules to it:

```yaml
externalRules:
  - seriesQuery: 'phpfpm_active_processes'
    resources:
      overrides:
        namespace: {resource: "namespace"}
    metricsQuery: 'max(<<.Series>>{<<.LabelMatchers>>})'
    name:
      matches: "phpfpm_active_processes"
      as: "phpfpm_active_processes_max"
  - seriesQuery: 'nginx_connections_active'
    resources:
      overrides:
        namespace: {resource: "namespace"}
    metricsQuery: 'max(<<.Series>>{<<.LabelMatchers>>})'
    name:
      matches: "nginx_connections_active"
      as: "nginx_connections_active_max"
```

The `namespace` override is required: without it the adapter rejects the HPA's namespaced request.

## Enabling

```yaml
fpmExporter:
  enabled: true
nginxExporter:
  enabled: true
prometheus:
  enabled: true
prometheusAdapter:
  enabled: true
deployment:
  backend:
    autoscaling:
      enabled: true
      minReplicas: 2
      maxReplicas: 10
    podDisruptionBudget:
      enabled: true
  frontend:
    autoscaling:
      enabled: true
      minReplicas: 1
      maxReplicas: 3
persistence:
  # Several backend pods on several nodes need volumes they can all mount.
  backendPvcAccessMode: ReadWriteMany
  backendConfigPvcAccessMode: ReadWriteMany
```

Check that the metrics arrive:

```sh
kubectl get --raw "/apis/external.metrics.k8s.io/v1beta1/namespaces/<namespace>/phpfpm_active_processes_max"
kubectl describe hpa <release>-backend-hpa
```

Until Prometheus has scraped the pods, the HPA reports `FailedGetExternalMetric`. While the external
metric is unavailable, the backend HPA still scales up on CPU, but does not scale down.

**Requirements.** The backend's data and config volumes are `ReadWriteOnce` by default. More than one
backend pod on more than one node needs `ReadWriteMany` storage. The database must accept the
connections of all backend pods at `maxReplicas` (one per PHP-FPM worker in the worst case).

## Sizing the targets

- **`fpmActiveProcessesTarget`** (default `180`) is an absolute number of busy workers, not a percentage.
  Set it to about 60% of `pm.max_children` (300 in the image). The remaining 40% absorb the load
  while a new pod is scheduled, pulled and started, which takes one to several minutes. Change it
  together with `pm.max_children`.
- **`targetCPUUtilizationPercentage`** (default `50`) is relative to the CPU **request**. Keep it low
  enough that it still fires before the pod saturates; the FPM metric covers saturation itself.
- **`nginxActiveConnectionsTarget`** (default `300`): a frontend pod holds about
  `worker_processes × worker_connections / 2` clients. With one worker and nginx's default of 1024
  connections, that is about 512; 300 leaves room for scale-up. Raise it together with
  `worker_connections` and the number of workers.
- **`minReplicas`** must carry the load of the first minutes. The HPA reacts within about a minute
  (scrape interval, adapter relist, HPA sync), and new pods need further time, longer when the cluster
  first has to add nodes. Raise it before a large planned test.
- **`maxReplicas`** is bounded by what the database, Redis and connection poolers accept: every backend
  pod opens up to `pm.max_children` connections to each.
- **`scaleUp`** defaults to the Kubernetes defaults (up to +100% or +4 pods per 15 s, no stabilization
  window). Raise `percent` and `pods` for steep ramps such as many users logging in at the same time.
- **Prometheus memory** grows with the number of scraped pods. When it is OOM-killed, the HPAs lose their
  external metrics until it has restarted.
