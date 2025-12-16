#!/bin/sh
set -e

# Apply umask
umask "${UMASK:-0002}"

# Start Memcached with standard entrypoint
exec docker-entrypoint.sh "$@"