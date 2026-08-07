#!/bin/bash
#
# Start the app locally without Docker.
#
# Sail is the documented way to run this (see compose.yaml), but it needs a
# working Docker daemon. This script is the fallback for a WSL box running
# Postgres and Redis natively: WSL does not use systemd here, so neither
# service survives a shell restart and both have to be started by hand.
#
# Usage: ./dev.sh            web only
#        ./dev.sh --queue    web + queue worker (needed for panel submissions)

set -e
cd "$(dirname "$0")"

service postgresql start
service redis-server start

# Postgres accepts connections a moment after the service returns.
for _ in $(seq 1 30); do
    pg_isready -h 127.0.0.1 -q && break
    sleep 1
done

php artisan migrate --force

if [ "$1" = "--queue" ]; then
    php artisan queue:work --tries=1 &
    trap 'kill $!' EXIT
fi

# 0.0.0.0 so a browser on the Windows host can reach it across the WSL boundary.
php artisan serve --host=0.0.0.0 --port=8000
