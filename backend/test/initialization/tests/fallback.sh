#!/usr/bin/env bash

# if no test name is provided, this script is executed instead

source backend/test/initialization/functions/functions.sh

echo_fail "No test name given!"

exec bash /run-server.sh
