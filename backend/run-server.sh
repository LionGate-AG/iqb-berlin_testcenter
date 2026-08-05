#!/usr/bin/env bash

# Starts the backend web stack: PHP-FPM (FastCGI) + nginx (HTTP on :8080).
# This is the serve-only counterpart of the former `apache2-foreground`.
# It is the container's long-running process and is used both by entrypoint.sh
# (docker compose: init + serve) and by the Helm deployment `command`
# (k8s: serve only, init runs in a separate Job via initialize_only.sh).

set -e

# PHP-FPM in the background (nodaemonize per fpm-pool.conf, so its logs reach
# the container's stderr). nginx then runs in the foreground as the main
# process, so it receives SIGTERM/SIGQUIT for graceful container shutdown.
php-fpm &

exec nginx -g 'daemon off;'
