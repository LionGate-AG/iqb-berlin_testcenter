ARG REGISTRY_PATH=""
ARG NODE_VERSION=22.16-bookworm


FROM ${REGISTRY_PATH}node:${NODE_VERSION} AS base
ARG NODE_ENV=development

RUN --mount=type=cache,sharing=locked,target=/var/cache/apt \
    apt-get update && apt-get install -y --no-install-recommends \
    chromium

# Update npm to latest version
RUN npm --version
RUN --mount=type=cache,target=~/.npm \
    npm install -g --no-fund npm
RUN npm --version

WORKDIR /usr/src/testcenter/frontend

COPY frontend/package*.json .
RUN --mount=type=cache,sharing=locked,target=~/.npm \
    npm ci --no-fund

COPY frontend/angular.json .
COPY frontend/tsconfig*.json .
COPY frontend/src src/
COPY common ../common
COPY definitions ../definitions

RUN --mount=type=cache,sharing=locked,target=~/.angular/cache \
    npx ng build \
      --configuration production \
      --output-path=dist \
      --output-hashing all


FROM base AS dev
EXPOSE 8080

COPY frontend/proxy.conf.json .
RUN sed -i "s|localhost:3000|broadcaster:3000|" proxy.conf.json
RUN sed -i "s|localhost:8090|backend:8080|" proxy.conf.json
RUN sed -i "s|localhost:8099|file-server:8080|" proxy.conf.json

ENTRYPOINT ["npx", "ng"]


FROM ${REGISTRY_PATH}nginxinc/nginx-unprivileged:stable AS prod
USER root
RUN chown -R nginx:root /usr/share/nginx/html

# This nginx proxies all traffic, the /api requests and the broadcaster WebSockets, and
# every proxied connection takes two worker_connections (client and upstream side). The
# stock 1024 per worker leaves a pod at ~1,000 concurrent test takers; beyond that nginx
# silently closes new connections while its CPU stays idle. worker_connections and
# worker_rlimit_nofile live in the base image's main nginx.conf, which frontend/nginx.conf
# (a server block) cannot reach, hence the sed. nofile covers ~2 fds per connection.
# `nginx -t` runs as root and leaves a root-owned /tmp/nginx.pid in the image, which the
# unprivileged runtime user cannot overwrite, so it is removed again.
RUN sed -i \
      -e 's/^worker_processes\(.*\)$/worker_rlimit_nofile 40960;\nworker_processes\1/' \
      -e 's/worker_connections  *[0-9]\+;/worker_connections 16384;/' \
      /etc/nginx/nginx.conf \
 && nginx -t \
 && rm -f /tmp/nginx.pid

USER nginx
COPY --chown=nginx:root --from=base /usr/src/testcenter/frontend/dist/browser /usr/share/nginx/html
COPY --chown=nginx:root frontend/nginx.conf /etc/nginx/templates/default.conf.template

EXPOSE 8080

CMD ["nginx", "-g", "daemon off;"]
