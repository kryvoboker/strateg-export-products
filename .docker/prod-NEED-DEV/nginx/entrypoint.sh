#!/bin/sh
set -e

# Apply umask
umask "${UMASK:-0002}"

# Start nginx
exec nginx -g "daemon off;"