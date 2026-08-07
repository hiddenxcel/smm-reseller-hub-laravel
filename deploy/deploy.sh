#!/bin/bash
#
# Release the uploaded build. Run on the VPS as the app user, by CI.
#
# Releases are directories and `current` is a symlink, so going live is one
# atomic swap and going back is the same swap in reverse. A deploy that fails
# its health check puts the previous release back rather than leaving the site
# down.
#
# Lives at ~/smm-reseller-hub/deploy.sh on the server.

set -euo pipefail

APP_DIR="$HOME/smm-reseller-hub"
RELEASES="$APP_DIR/releases"
SHARED="$APP_DIR/shared"
CURRENT="$APP_DIR/current"
TARBALL="$APP_DIR/release.tar.gz"
PHP=/usr/bin/php8.3
KEEP=5

STAMP=$(date +%Y%m%d-%H%M%S)
NEW="$RELEASES/$STAMP"

say() { printf '\n\033[1;32m==> %s\033[0m\n' "$1"; }
fail() { printf '\n\033[1;31m!! %s\033[0m\n' "$1" >&2; }

# What `current` pointed at when we started — the thing to go back to.
PREVIOUS=""
if [ -L "$CURRENT" ]; then
    PREVIOUS=$(readlink -f "$CURRENT")
fi

rollback() {
    fail "Deploy failed"

    if [ -n "$PREVIOUS" ] && [ -d "$PREVIOUS" ]; then
        say "Rolling back to $(basename "$PREVIOUS")"
        ln -sfn "$PREVIOUS" "$CURRENT"
        $PHP "$CURRENT/artisan" config:cache >/dev/null 2>&1 || true
        sudo systemctl restart php8.3-fpm smmhub-queue || true
        fail "Site restored to the previous release."
    else
        fail "No previous release to fall back to — the site may be down."
    fi

    rm -rf "$NEW"
    exit 1
}

trap rollback ERR

# ---------------------------------------------------------------------------
say "Unpacking $STAMP"

[ -f "$TARBALL" ] || { fail "No release.tar.gz found"; exit 1; }

mkdir -p "$NEW"
tar -xzf "$TARBALL" -C "$NEW"
rm -f "$TARBALL"

# ---------------------------------------------------------------------------
say "Linking shared state"

# .env and storage/ outlive any single release: the key, the uploads and the
# logs must not reset every time we deploy.
[ -f "$SHARED/.env" ] || { fail "No shared/.env on the server — create it first"; exit 1; }

ln -sfn "$SHARED/.env" "$NEW/.env"

rm -rf "$NEW/storage"
ln -sfn "$SHARED/storage" "$NEW/storage"

# Laravel expects these whether or not the tarball carried them.
mkdir -p "$SHARED/storage"/{app/public,framework/{cache/data,sessions,testing,views},logs}

# ---------------------------------------------------------------------------
say "Migrating"

# --force because production has no TTY to confirm at. Migrations run before
# the swap: a failure here rolls back without users ever seeing the release.
$PHP "$NEW/artisan" migrate --force

# ---------------------------------------------------------------------------
say "Caching config, routes and views"

$PHP "$NEW/artisan" config:cache
$PHP "$NEW/artisan" route:cache
$PHP "$NEW/artisan" view:cache

# public/storage → storage/app/public, for anything user-uploaded.
$PHP "$NEW/artisan" storage:link >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
say "Going live"

ln -sfn "$NEW" "$CURRENT"

# opcache still holds the old paths until php-fpm restarts.
sudo systemctl restart php8.3-fpm

# The worker must pick up the new code, and it holds the old release's files
# open until it does.
sudo systemctl restart smmhub-queue

# ---------------------------------------------------------------------------
say "Checking the site answers"

# Straight at the socket, so a DNS or TLS problem is not mistaken for a broken
# release. Give php-fpm a moment to come back first.
sleep 3

CODE=$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: smmresellershub.com' http://127.0.0.1/ || echo 000)

if [ "$CODE" != "200" ] && [ "$CODE" != "302" ]; then
    fail "Health check returned $CODE"
    false  # trips the ERR trap
fi

echo "Health check: $CODE"

# ---------------------------------------------------------------------------
trap - ERR

say "Pruning old releases"

# Keep a few to roll back through; delete the rest.
cd "$RELEASES"
ls -1dt */ 2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -rf

say "Deployed $STAMP"
ls -1dt "$RELEASES"/*/ | head -n "$KEEP" | sed 's#.*/\([^/]*\)/#  \1#'
