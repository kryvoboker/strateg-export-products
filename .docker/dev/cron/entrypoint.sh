#!/bin/sh
set -e

# Fix line endings in crontab file (CRLF -> LF)
if [ -f /etc/crontabs/crontab ]; then
    echo "Fixing line endings in crontab file..."
    dos2unix /etc/crontabs/crontab
    chmod 0600 /etc/crontabs/crontab
    echo "Crontab file prepared successfully"
fi

# Apply umask for runtime
umask "${UMASK:-0002}"

# Start supercronic
exec /usr/local/bin/supercronic /etc/crontabs/crontab