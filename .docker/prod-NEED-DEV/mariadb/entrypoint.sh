#!/bin/sh
set -e

# Apply umask for runtime
umask "${UMASK:-0002}"

# Start MariaDB with standard entrypoint
exec docker-entrypoint.sh "$@"