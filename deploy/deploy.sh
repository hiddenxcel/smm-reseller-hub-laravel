#!/bin/bash
#
# Release whatever CI just built. Runs on the VPS as root, called over SSH.
#
# The app lives under Hestia's web root, so this updates in place rather than
# swapping symlinked releases: Hestia owns public_html and pointing it
# somewhere new on every deploy would fight the panel. What that costs is a
# few seconds where the code is new and the caches are not, which is why
# maintenance mode goes up first.
#
# A failed migration or a failed health check rolls the code back to the
# commit that was live before.
#
# Lives at /root/deploy.sh.

set -uo pipefail

APP=/home/user/web/smmresellershub.com/private/app
PHP=/usr/bin/php8.3
WORKER_INI=/etc/php/8.3/cli/queue-worker/php.ini
BRANCH="${BRANCH:-onboarding-payments-and-test-bot}"

say()  { printf '\n\033[1;32m==> %s\033[0m\n' "$1"; }
fail() { printf '\n\033[1;31m!! %s\033[0m\n' "$1" >&2; }

cd "$APP" || { fail "No app at $APP"; exit 1; }

# What is live right now — the thing to go back to.
PREVIOUS=$(sudo -u user git rev-parse HEAD)
say "Currently live: $(sudo -u user git log --oneline -1)"

rollback() {
    fail "Deploy failed — rolling back to $PREVIOUS"
    sudo -u user git reset --hard --quiet "$PREVIOUS"
    sudo -u user composer install --no-dev --no-interaction --prefer-dist \
        --no-progress --optimize-autoloader --quiet 2>&1 | tail -2
    sudo -u user $PHP artisan config:cache >/dev/null 2>&1
    sudo -u user $PHP artisan route:cache  >/dev/null 2>&1
    sudo -u user $PHP artisan view:cache   >/dev/null 2>&1
    sudo -u user $PHP artisan up           >/dev/null 2>&1
    systemctl restart smmhub-queue 2>/dev/null
    fail "Rolled back. The site is on the previous commit."
    exit 1
}

# ---------------------------------------------------------------------------
say "Putting the site into maintenance mode"

# --secret lets you check the new code yourself while everyone else waits.
sudo -u user $PHP artisan down --render=errors::503 --secret=deploying 2>/dev/null \
    || sudo -u user $PHP artisan down 2>/dev/null || true

# ---------------------------------------------------------------------------
say "Fetching $BRANCH"

sudo -u user git fetch --quiet origin "$BRANCH" || rollback
sudo -u user git reset --hard --quiet "origin/$BRANCH" || rollback
echo "now at: $(sudo -u user git log --oneline -1)"

# ---------------------------------------------------------------------------
say "Installing PHP dependencies"

sudo -u user composer install --no-dev --no-interaction --prefer-dist \
    --no-progress --optimize-autoloader 2>&1 | tail -3 || rollback

# ---------------------------------------------------------------------------
say "Unpacking the assets CI built"

# public/build is gitignored, so it arrives as a tarball rather than in the
# checkout. Without this the page loads with no styling at all.
if [ -f /tmp/build.tar.gz ]; then
    sudo -u user rm -rf public/build
    sudo -u user tar -xzf /tmp/build.tar.gz -C public
    rm -f /tmp/build.tar.gz
    echo "assets in place: $(find public/build -type f | wc -l) files"
else
    fail "No build.tar.gz — the site would serve unstyled pages"
    rollback
fi

# ---------------------------------------------------------------------------
say "Migrating"

sudo -u user $PHP artisan migrate --force 2>&1 | tail -6 || rollback

# ---------------------------------------------------------------------------
say "Rebuilding caches"

sudo -u user $PHP artisan config:cache 2>&1 | tail -1 || rollback
sudo -u user $PHP artisan route:cache  2>&1 | tail -1 || rollback
sudo -u user $PHP artisan view:cache   2>&1 | tail -1 || rollback
sudo -u user $PHP artisan storage:link >/dev/null 2>&1 || true

sudo -u user chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# ---------------------------------------------------------------------------
say "Restarting the worker"

# It holds the old code in memory until it is restarted.
systemctl restart smmhub-queue
sleep 3
systemctl is-active --quiet smmhub-queue \
    || { fail "Queue worker did not come back"; rollback; }
echo "queue: active"

# ---------------------------------------------------------------------------
say "Bringing the site back"

sudo -u user $PHP artisan up 2>&1 | tail -1

# ---------------------------------------------------------------------------
say "Health check"

sleep 2
CODE=$(curl -s -o /dev/null -w '%{http_code}' \
    -H 'Host: smmresellershub.com' http://186.240.153.29/ || echo 000)

if [ "$CODE" != "200" ] && [ "$CODE" != "302" ] && [ "$CODE" != "301" ]; then
    fail "Site answered $CODE"
    rollback
fi
echo "site: $CODE"

# The other site shares this box; a deploy that breaks it is still a failure.
OTHER=$(curl -s -o /dev/null -w '%{http_code}' \
    -H 'Host: latestsafari.com' http://186.240.153.29/ || echo 000)
echo "latestsafari: $OTHER"

say "Deployed $(sudo -u user git log --oneline -1)"
