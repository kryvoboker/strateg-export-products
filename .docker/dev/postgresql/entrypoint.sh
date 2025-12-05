#!/bin/sh
set -e

# Apply umask for runtime
umask "${UMASK:-0002}"

# Start PostgreSQL with standard entrypoint
exec docker-entrypoint.sh "$@"