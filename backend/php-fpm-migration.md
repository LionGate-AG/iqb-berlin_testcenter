# Backend migration: Apache (mod_php) → nginx + php-fpm

This document describes the migration of the testcenter **backend** web server from
**Apache with `mod_php`** to **nginx + php-fpm**, plus the test and Helm-chart changes that
accompany it.

The work is split into four commits:

1. [The php-fpm migration](#1--the-php-fpm-migration) — the backend image and app.
2. [Unit / initialization test adaptation](#2--test-adaptation) — make the test suite stack-agnostic.
3. [Helm chart & orchestration updates](#3--helm-chart--orchestration-updates) — run the new image everywhere.
4. [Seed-job deployment fixes](#4--seed-job-deployment-fixes) — fix two pre-existing chart bugs that blocked the k8s deployment.

---

## Why

- **Stack consistency** — the frontend and file-server already run on nginx. Apache was the only
  remaining web-server technology in the stack; this removes it.
- **Performance & concurrency** — nginx (event-driven) + php-fpm worker pools handle concurrent and
  slow connections far more efficiently than Apache prefork + `mod_php`, which loads a full PHP
  interpreter into every connection process.
- **Tunable process management** — php-fpm `pm.*` settings give explicit control over worker counts
  and memory, which matters in containers / Kubernetes.
- **Fits horizontal scaling** — a leaner, pool-tunable backend scales more predictably per-pod.

### Architecture before vs. after

| | Before | After |
|---|---|---|
| Base image | `php:8.3-apache-bookworm` | `php:8.3-fpm-bookworm` |
| Web server | Apache `mod_php` | nginx → php-fpm (FastCGI, same container) |
| Listens on | `:8080` | `:8080` (unchanged) |
| Routing | `.htaccess` `mod_rewrite` | nginx front-controller (`fastcgi_pass`) |
| Response headers | `.htaccess` `mod_headers` | nginx `add_header` |
| Process model | `apache2-foreground` | `run-server.sh` (php-fpm + nginx) |
| Runs as | `www-data` (uid 33) | `www-data` (uid 33), rootless |

The backend is an **internal service** behind the frontend nginx (`/api/` proxy) and Traefik. Because
it keeps listening on `:8080` and keeps the same routes, the proxy chain, the `/version` healthcheck,
and the Helm probes are all unchanged — only the app server inside the container was swapped.

---

## 1 — The php-fpm migration

Commit: *migrate tc-backend from apache + php to php-fpm + nginx*

### New files

- **`backend/config/nginx.conf`** — the nginx server. Runs rootless (pid + temp paths under `/tmp`,
  listens on `:8080`). Responsibilities, all ported 1:1 from the old Apache config:
  - **Front controller**: every request is routed to `index.php` via `fastcgi_pass` to php-fpm
    (`127.0.0.1:9000`), with `SCRIPT_NAME=/index.php`. This replaces the `.htaccess` `mod_rewrite`
    rule. Because nothing is served from disk, `src/`, `vendor/`, and `config/` are never exposed.
  - **`Access-Control-Expose-Headers: SubscribeToken, Error-ID, Test-Mode`** — replaces the
    `.htaccess` `Header add`.
  - **410 `Cache-Control: no-store`** — a `map $status` adds a no-store header only on `410 Gone`
    responses (so re-activated accounts are not permanently cached as gone). Replaces the conditional
    `.htaccess` `Header set`.
  - **`gzip off`** — the backend serves uncompressed; see the SpeedtestController note below.
- **`backend/config/fpm-pool.conf`** — php-fpm pool overrides (loaded as `zz-testcenter.conf` so it
  wins over the image defaults):
  - `listen = 127.0.0.1:9000` — nginx talks to fpm over loopback in the same container.
  - **`clear_env = no`** — under `mod_php` the app inherited the container environment automatically;
    php-fpm clears it by default, which would hide `MYSQL_*` / `REDIS_*` / `OVERRIDE_CONFIG` from
    `getenv()`. (Request-time code reads `config.ini`, but this keeps exact parity.)
  - `catch_workers_output = yes`, `error_log = /proc/self/fd/2` — surface logs on the container stdout/stderr.
- **`backend/config/cors.conf`** — permissive CORS for **dev/test only**. The prod image ships an
  empty `/etc/nginx/conf.d/cors.conf`; the dev compose file mounts this over it. Replaces
  `config/no-cors.htaccess`.
- **`backend/run-server.sh`** — the serve-only launcher: starts php-fpm in the background and nginx in
  the foreground (so nginx is the main process and receives `SIGTERM` for graceful shutdown). This is
  the direct replacement for `apache2-foreground`, and is used by **both** the docker-compose
  entrypoint and the k8s Deployment `command`.

### Changed files

- **`backend/Dockerfile`** — base image `8.3-apache-bookworm` → `8.3-fpm-bookworm`; install `nginx`;
  copy the new nginx/fpm/cors configs and `run-server.sh`; create the rootless nginx temp dirs.
  Removed all Apache setup (`a2enmod`, vhost, ports). Still `EXPOSE 8080`, still `USER www-data`.
- **`backend/entrypoint.sh`** — last line `apache2-foreground` → `exec /run-server.sh` (run
  `initialize.php` first, then serve).
- **`backend/src/controller/SpeedtestController.class.php`** — removed the
  `apache_setenv('no-gzip', '1')` call. That function only exists under `mod_php` and would be a fatal
  error under php-fpm. Its purpose — keep the speed-test payload uncompressed so download throughput is
  measured accurately — is now handled by `gzip off` in nginx.

### Removed files (Apache-only, replaced as noted)

| Removed | Replaced by |
|---|---|
| `backend/.htaccess` | nginx front controller + `add_header` in `nginx.conf` |
| `backend/config/vhost.conf` | `nginx.conf` server block |
| `backend/config/security.conf` | `server_tokens off;` in `nginx.conf` |
| `backend/config/no-cors.htaccess` | `backend/config/cors.conf` |

> **Env-var note:** request-time code reads configuration from `config.ini` (written by
> `initialize.php`, a CLI process with the full environment), so the web-tier `clear_env` change is
> belt-and-suspenders. `Server::getUrl()` / `getProjectPath()` rely on `SCRIPT_NAME` / `PHP_SELF` /
> `SERVER_PROTOCOL`, which nginx FastCGI sets correctly (`SCRIPT_NAME` is pinned to `/index.php`).

---

## 2 — Test adaptation

Commit: *adapted tests to new php-fpm*

No test **logic** changed; the suite is web-server-agnostic. These edits only remove stale Apache
assumptions so the tests reflect the new runtime.

- **`backend/test/unit/helper/ServerTest.php`** — `ServerTest` feeds synthetic `$_SERVER` arrays into
  the pure `Server::getUrl()` function. Updated the inert `SERVER_SOFTWARE` fixtures (`Apache/...` →
  `nginx/...`) and the descriptive comments. Assertions are unchanged — `getUrl()` never reads
  `SERVER_SOFTWARE`.
- **`backend/test/initialization/tests/fallback.sh`** — `apache2-foreground` → `exec bash /run-server.sh`
  (keeps the container alive when no test name is given).
- **`backend/test/initialization/tests/14.0.0/file_deletion.sh`** — `service apache2 start` → start
  `run-server.sh` in the background with a readiness wait; the `curl` targets were aligned to
  `localhost:8080` (the backend port).
- **`backend/test/initialization/docker-compose.initialization-test.yml`** — mount `nginx.conf` +
  `run-server.sh` instead of the old `.htaccess`; publish `8080`.

> The CI `general/*` initialization suite never touches the web server, so it was unaffected. The
> unit suite passes; the only DB-backed test (`AdminDAOTest`) just needs a reachable MySQL/Redis.

---

## 3 — Helm chart & orchestration updates

Commit: *updated helm chart*

- **`scripts/helm/testcenter/templates/backend/deployment.yaml`** — the backend container `command`
  `[ "apache2-foreground" ]` → `[ "bash", "/run-server.sh" ]`. (In k8s the Deployment overrides the
  image `ENTRYPOINT` and serves only; init runs in a separate Job — see commit 4.)
- **`docker-compose.dev.yml`** — backend dev mounts: replaced the Apache `vhost.conf` / `.htaccess` /
  `no-cors.htaccess` overrides with `nginx.conf`, `fpm-pool.conf`, `cors.conf`, and `run-server.sh`.
- **`scripts/bom.json`** — the bill-of-materials probe for the backend reports `php-fpm` + `nginx`
  instead of `apache`.

> The backend image is referenced by **tag** (`.Values.image.backend.tag | default .Chart.AppVersion`),
> so a deployment only runs php-fpm once the backend image is **rebuilt from this branch and published**
> to that tag. Deploying the chart change against an old Apache image would crash (no `/run-server.sh`).

---

## 4 — Seed-job deployment fixes

Commit: *fixed env variables for tc-backend seed job and postSync issue*

While verifying the migration on a local **k3d + Argo CD** cluster, the backend pod crash-looped with
`Application config file is missing!`. Investigation found **two pre-existing chart bugs** (present on
`master` and the broadcaster branch too — not introduced by this migration) that prevented the seed
Job from ever producing `config.ini`. The backend has no env of its own; it boots entirely from the
`config.ini` that the seed Job (`initialize_only.sh`) writes to the shared config PVC.

### Bug 1 — seed Job was missing required env vars

`initialize.php` → `SystemConfig::readFromEnvironment()` requires these with **no default**:
`MYSQL_*`, `PASSWORD_SALT`, `PASSWORD_MIN_LENGTH`, `PASSWORD_REGEX_CHECK`, `ADMIN_INIT_PASSWORD`,
`REDIS_*`. The seed Job set `PASSWORD_SALT` but omitted the other three password/admin vars, so it
failed before writing `config.ini` (`Environment-variable missing: PASSWORD_MIN_LENGTH`).
Docker-compose worked only because `docker-compose.yml` already provided them.

Fixed the idiomatic way (matching how the chart handles other env), routing by value type:

- **`scripts/helm/testcenter/values.yaml`** — added `config.backend.passwordMinLength: 7`,
  `config.backend.passwordRegexCheck: "/.*/"`, and `secret.backend.adminInitPassword: user123`.
- **`scripts/helm/testcenter/templates/backend/configmap.yaml`** — added `PASSWORD_MIN_LENGTH` and
  `PASSWORD_REGEX_CHECK` (non-secret config).
- **`scripts/helm/testcenter/templates/backend/secret.yaml`** — added `ADMIN_INIT_PASSWORD` (it is a
  bootstrap credential).
- **`scripts/helm/testcenter/templates/backend/job.yaml`** — the seed Job now pulls all three via
  `configMapKeyRef` / `secretKeyRef` instead of literals.

> `ADMIN_INIT_PASSWORD` only governs the **first-ever** initialization (it creates the `super`
> Sys-Admin). Changing it later has no effect on an existing database.

### Bug 2 — seed Job never ran automatically under Argo CD (deadlock)

The seed Job was annotated `helm.sh/hook: post-install,post-upgrade,post-rollback`, which Argo CD maps
to a **PostSync** hook. PostSync only runs after all resources are **Healthy** — but the backend can't
be Healthy without `config.ini`, which only this Job creates. Deadlock: the backend waits for the Job,
the Job waits for the backend.

Fixed with **Argo sync-waves**, keeping the Helm hook annotations for plain-Helm compatibility (when
both annotation families are present, Argo honors `argocd.argoproj.io/*` and ignores `helm.sh/hook`;
the deadlock is Argo-specific):

- **`scripts/helm/testcenter/templates/backend/job.yaml`** — added:
  ```yaml
  helm.sh/hook-delete-policy: before-hook-creation      # Helm: replace previous run
  argocd.argoproj.io/hook: Sync                          # Argo: run in Sync phase, not PostSync
  argocd.argoproj.io/hook-delete-policy: BeforeHookCreation
  argocd.argoproj.io/sync-wave: "1"
  ```
- **`scripts/helm/testcenter/templates/backend/deployment.yaml`** — added
  `argocd.argoproj.io/sync-wave: "2"` to the backend Deployment.

**Resulting Argo ordering:**

| Wave | Resources | Gate |
|---|---|---|
| 0 (default) | db, cache-server, ConfigMap, Secret, PVCs | db readiness probe |
| 1 | seed Job (`Sync` hook) | Job must complete |
| 2 | backend Deployment | starts with `config.ini` present |

This runs the seed Job after the DB is ready and before the backend starts, on every install **and**
upgrade (so DB migrations re-apply on upgrade).

---

## Verification performed

- `docker build` of the new backend image (dev + prod targets) succeeds.
- Rootless container starts: php-fpm `ready to handle connections`, nginx serving on `:8080`.
- Full stack (mysql + redis + real `entrypoint.sh`): `initialize.php` runs, schema installs,
  **`GET /version` → 200** with `Server: nginx` and the `Access-Control-Expose-Headers` header.
- **Speed-test**: `GET /speed-test/random-package/1000` with `Accept-Encoding: gzip` returns
  **exactly 1000 uncompressed bytes** (no `Content-Encoding`) — confirms the `apache_setenv` removal.
- **k3d + Argo CD**: after the seed Job ran, the backend pod went `1/1 Running`; in-cluster
  `curl localhost:8080/version` → 200, `Server: nginx`, no `apache` binary present.
- `helm lint` passes; the seed Job / Deployment render with the expected env refs and sync-wave
  annotations.

## Notes for re-testing on k3d

- The backend image is local-only, so build it, `k3d image import` it, and point
  `image.backend.tag` / `imagePullPolicy: IfNotPresent` at it.
- After a code change, rebuild with the same tag, re-import, and delete the backend pod so the new
  image is picked up (`IfNotPresent` won't re-pull an existing tag).

## References

- [Argo CD — Sync Phases and Waves](https://argo-cd.readthedocs.io/en/stable/user-guide/sync-waves/)
- [Argo CD — Helm](https://argo-cd.readthedocs.io/en/latest/user-guide/helm/)
