#!/bin/sh
# nginx resolves REDIS_HOST and BACKEND_URL at request time through its `resolver`, which cannot read environment
# variables. Use the name server the container itself got: Docker's embedded DNS under compose, the cluster DNS
# (or a node-local DNS cache) under Kubernetes. nginx applies no search domains, so both names must be resolvable
# as given (under Kubernetes: fully qualified).
set -eu

nameserver=$(awk '/^nameserver/ { print $2; exit }' /etc/resolv.conf)
case "$nameserver" in
  *:*) nameserver="[$nameserver]" ;; # IPv6 addresses need brackets in nginx
esac
echo "resolver ${nameserver:-127.0.0.11};" > /tmp/resolver.conf

exec "$@"
