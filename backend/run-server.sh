#!/usr/bin/env bash

# Runs the backend web server: PHP-FPM (FastCGI on 127.0.0.1:9000) behind nginx (HTTP on :8080).
# Used by entrypoint.sh and as the command of the Helm deployment.
#
# This script stays in the foreground and supervises both processes:
# - On SIGQUIT, SIGTERM or SIGINT it stops nginx gracefully first, so requests in flight are
#   answered, and then PHP-FPM.
# - If one of the two exits on its own, it stops the other one and exits with that status, so the
#   container is restarted instead of answering every request with 502.

php-fpm --nodaemonize &
fpm_pid=$!
nginx -g 'daemon off;' &
nginx_pid=$!

stop() {
  kill -QUIT "$nginx_pid" 2>/dev/null
  wait "$nginx_pid"
  kill -QUIT "$fpm_pid" 2>/dev/null
  wait "$fpm_pid"
}

trap 'stop; exit 0' QUIT TERM INT

wait -n "$fpm_pid" "$nginx_pid"
status=$?
trap - QUIT TERM INT
stop
exit "$status"
