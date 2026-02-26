#!/bin/sh
set -e

# Apply umask for runtime
umask "${UMASK:-0002}"

# Start Redis with standard entrypoint
exec /usr/local/bin/docker-entrypoint.sh "$@"