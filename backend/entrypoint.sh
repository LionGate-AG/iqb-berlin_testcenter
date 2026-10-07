#!/usr/bin/env bash

set -e

php /var/www/testcenter/backend/check-db-compatibility.php

# exec, so the web server becomes PID 1 and receives the container's stop signal itself
# (run-server.sh starts php-fpm in the background and then execs nginx).
exec /run-server.sh
