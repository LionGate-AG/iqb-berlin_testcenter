#!/usr/bin/env bash

set -e

php /var/www/testcenter/backend/check-db-compatibility.php

# exec, so run-server.sh becomes PID 1 and receives the container's stop signal itself.
exec /run-server.sh
