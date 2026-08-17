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

# src/environments/environment.ts is gitignored (see /.gitignore), so it is never
# present in the build context -- yet main.ts and customtext.pipe.ts import it
# directly, so `ng build` fails to resolve the module without it:
#   ✘ [ERROR] Could not resolve "./environments/environment"
#   ✘ [ERROR] TS2307: Cannot find module '../../../../environments/environment'
# angular.json's production config then REPLACES this file via fileReplacements
# (environment.ts -> environment.prod.ts), so the copied contents are not what
# ends up in the bundle -- the file only has to exist for module resolution.
# Every other build path already does exactly this (scripts/ci/frontend.yml and
# e2e.yml both `cp environment.dev.ts environment.ts` first); the Dockerfile is
# the only one that has to create it itself. Copying the prod variant rather
# than dev so that if fileReplacements ever stops applying, the fallback is
# production values, not development ones.
RUN cp src/environments/environment.prod.ts src/environments/environment.ts

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

USER nginx
COPY --chown=nginx:root --from=base /usr/src/testcenter/frontend/dist/browser /usr/share/nginx/html
COPY --chown=nginx:root frontend/nginx.conf /etc/nginx/templates/default.conf.template

EXPOSE 8080

CMD ["nginx", "-g", "daemon off;"]
