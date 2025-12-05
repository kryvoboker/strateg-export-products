#!/bin/sh
set -e

# Apply umask for runtime
umask "${UMASK:-0002}"

# Start PHP-FPM
exec php-fpm