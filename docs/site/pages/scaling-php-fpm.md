# Scaling: PHP-FPM

The backend container runs **nginx in front of PHP-FPM**. Both processes live in the same container and
are started by `/run-server.sh`. nginx listens on port `8080` (unchanged) and passes every request to the
front controller `index.php` via FastCGI on `127.0.0.1:9000`.

| | |
|---|---|
| Base image | `php:8.3-fpm-bookworm` + Debian's `nginx` |
| HTTP port | `8080` |
| Process | `/run-server.sh` (entrypoint: `/entrypoint.sh`, which checks the database schema first) |
| Stop signal | `SIGQUIT` (`SIGTERM` works as well) |
| Runs as | `www-data` (uid 33) |
| Configuration | `backend/config/nginx.conf` → `/etc/nginx/nginx.conf`<br>`backend/config/fpm-pool.conf` → `/usr/local/etc/php-fpm.d/zz-testcenter.conf`<br>`backend/config/local.php.ini` → `/usr/local/etc/php/conf.d/local.ini` |

## Why PHP-FPM

- **Fixed, visible capacity.** `pm.max_children` sets how many requests are processed in parallel per
  container. The FPM status page shows how many workers are busy, which is the signal to scale on.
- **Admission control.** nginx passes at most `pm.max_children` requests to PHP-FPM at a time
  (`limit_conn`). A request beyond that gets an immediate **503** instead of waiting in a queue until it
  times out. The frontend treats a 503 as a temporary network problem. Before this, requests beyond
  the worker count queued until they timed out.
- **Health checks without PHP.** `/healthz` is answered by nginx itself, so a container whose workers are
  all busy is still reported as alive.

## Health endpoints

| Path | Answered by | Use |
|---|---|---|
| `/healthz` | nginx, without a PHP worker and outside the admission limit | liveness and readiness |
| `/version` | PHP | startup probe, docker compose healthcheck: proves that PHP serves requests |
| `/system/status` | PHP, also contacts broadcaster, file server and Redis | diagnostics only; too slow under load for a probe |

The Helm chart uses `/version` as startup probe and `/healthz` for liveness and readiness. If nginx or
PHP-FPM exits, `run-server.sh` stops the other one and the container ends, so a broken pod is restarted
even though `/healthz` does not touch PHP.

## Shutdown

On `SIGQUIT`, `SIGTERM` or `SIGINT`, `run-server.sh` first stops nginx gracefully (it stops accepting
connections and answers the requests in flight), then stops PHP-FPM gracefully. Give the container enough
time for the longest request you expect (Docker's default is 10 s, Kubernetes' 30 s:
`stop_grace_period` / `terminationGracePeriodSeconds`).

## Configuration

The values are literals in the configuration files. To change them, mount your own copy over the file in
the container (docker compose `volumes:`, a ConfigMap in Kubernetes) or build your own image.

### PHP-FPM (`fpm-pool.conf`)

| Setting | Default | Meaning |
|---|---|---|
| `pm` | `dynamic` | Workers are started on demand between the spare limits and `pm.max_children`. |
| `pm.max_children` | `300` | Maximum number of requests processed in parallel per container. |
| `pm.start_servers` | `15` | Workers started with the container. |
| `pm.min_spare_servers` / `pm.max_spare_servers` | `12` / `30` | Idle workers kept ready. |
| `pm.max_requests` | `500` | A worker is replaced after this many requests, bounding memory growth. |
| `pm.status_path` | `/status` | FPM status page, reachable only over FastCGI on `127.0.0.1:9000` (for example by a php-fpm exporter in the same pod), not through nginx. |
| `clear_env` | `no` | Required: the backend reads its configuration from environment variables. |

### nginx (`nginx.conf`)

| Setting | Default | Meaning |
|---|---|---|
| `limit_conn fpm` | `300` | Requests passed to PHP-FPM at a time. **Keep equal to `pm.max_children`** (it appears in two `location` blocks). |
| `client_max_body_size` | `2G` | Largest upload; matches `upload_max_filesize` / `post_max_size` in `local.php.ini`. |
| `fastcgi_read_timeout` | `600s` | Longest time nginx waits for PHP to answer. Keep PHP's `max_execution_time` below it. |
| `gzip` | on for text, XML, CSS and JavaScript; off for `/speed-test/` | The speed test needs uncompressed payloads to measure throughput. |

### PHP (`local.php.ini`)

`memory_limit` applies **per worker**. It caps a single request, not the container.

## Sizing `pm.max_children`

1. **Memory.** Every worker is an OS process. Measure the memory of busy workers under realistic load
   (for example `ps -o rss= -C php-fpm` inside the container) and size so that
   `pm.max_children × memory per worker + ~100 MB` (nginx, FPM master) fits into the container's memory
   limit. Most requests need only a few MB; workspace uploads need much more, so leave headroom.
2. **Database connections.** Each busy worker can hold a database connection. Across all backend
   replicas, `replicas × pm.max_children` should stay below PostgreSQL's `max_connections` (500 in the
   shipped compose files and chart) minus what other clients need, unless a connection pooler sits in
   between.
3. **Process limit.** In Kubernetes, stay well below the pod's PID limit (often 1024); otherwise forking
   new workers fails.
4. **CPU.** A worker waiting for the database or Redis uses no CPU. If CPU stays low while requests are
   answered with 503, the worker count is the limit, not the CPU; raise `pm.max_children` (and
   `limit_conn`) or add replicas. If CPU is saturated, add replicas instead.

When the backend is scaled horizontally, prefer more replicas with a moderate `pm.max_children` over
one very large pool.

## Custom images

Images built on top of `iqbberlin/testcenter-backend` no longer contain Apache. Apache directives,
`.htaccess` files and `apache2-foreground` have no effect anymore. Start the container with its
entrypoint or `/run-server.sh`; add headers and rewrites in `nginx.conf`.
