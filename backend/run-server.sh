#!/usr/bin/env bash

# Starts the backend web stack: PHP-FPM (FastCGI) + nginx (HTTP on :8080).
# This is the serve-only counterpart of the former `apache2-foreground`.
# It is the container's long-running process and is used both by entrypoint.sh
# (docker compose: init + serve) and by the Helm deployment `command`
# (k8s: serve only, init runs in a separate Job via initialize_only.sh).

set -e

# Optional DB-host override. The seed Job writes config.ini with the DIRECT MySQL
# host (so its schema DDL never goes through a pooler). If MYSQL_HOST is set on the
# backend (e.g. the ProxySQL service), rewrite ONLY [database].host/port in the
# seed-written config.ini so the backend's runtime traffic pools through ProxySQL.
# Surgical (awk touches only the [database] section -> other sections, incl.
# [cacheServer] host/port and passwords, are preserved byte-for-byte) and atomic
# (temp + mv in the same dir) so concurrent readers never see a partial file.
if [ -n "${MYSQL_HOST:-}" ]; then
  CONFIG_FILE="${CONFIG_FILE:-/var/www/testcenter/backend/config/config.ini}"
  if [ -f "$CONFIG_FILE" ]; then
    TMP_FILE="$CONFIG_FILE.tmp.$$"
    awk -v host="$MYSQL_HOST" -v port="${MYSQL_PORT:-}" '
      /^\[/            { section = $0 }
      section == "[database]" && /^host=/             { print "host=" host; next }
      section == "[database]" && /^port=/ && port!="" { print "port=" port; next }
      { print }
    ' "$CONFIG_FILE" > "$TMP_FILE" && mv "$TMP_FILE" "$CONFIG_FILE"
    echo "[run-server] backend DB host overridden to ${MYSQL_HOST}:${MYSQL_PORT:-3306}"
  else
    echo "[run-server] WARNING: MYSQL_HOST set but $CONFIG_FILE missing; skipping override"
  fi
fi

# PHP-FPM in the background (nodaemonize per fpm-pool.conf, so its logs reach
# the container's stderr). nginx then runs in the foreground as the main
# process, so it receives SIGTERM/SIGQUIT for graceful container shutdown.
php-fpm &

exec nginx -g 'daemon off;'
