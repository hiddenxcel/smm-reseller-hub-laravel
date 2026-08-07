#!/bin/bash
#
# Everything, in one run. Paste this on the VPS as root and it sets the whole
# app up: packages, database, .env, code, nginx, SSL, workers.
#
#   nano /tmp/install.sh     (paste, Ctrl+O, Enter, Ctrl+X)
#   bash /tmp/install.sh
#
# The box already runs HestiaCP with another PHP site. Nothing here goes
# through the panel: the app gets its own user, its own PHP-FPM pool, its own
# Postgres, and an nginx block in conf.d/ — the one directory Hestia does not
# rewrite when it rebuilds a domain. The other site is never touched.
#
# Safe to run twice: every step checks before it acts.

set -euo pipefail

APP_USER=hxapp
APP_DIR=/home/$APP_USER/smm-reseller-hub
DOMAIN=smmresellershub.com
PHP_VERSION=8.3
DB_NAME=smmhub
DB_USER=smmhub
REPO=https://github.com/hiddenxcel/smm-reseller-hub-laravel.git
BRANCH=onboarding-payments-and-test-bot
APP_KEY='base64:aj2Lp7yp80sQpIaMqG288qMNlck6zE2THDU09w6sUMI='

say()  { printf '\n\033[1;32m==> %s\033[0m\n' "$1"; }
warn() { printf '\n\033[1;33m!! %s\033[0m\n' "$1"; }
die()  { printf '\n\033[1;31m!! %s\033[0m\n' "$1" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run this as root."

# ---------------------------------------------------------------------------
say "Looking at what is already here"

systemctl is-active --quiet nginx || die "nginx is not running — is this the right box?"
echo "nginx:  $(nginx -v 2>&1)"
echo "sites:  $(ls /home/*/web/ 2>/dev/null | tr '\n' ' ' || echo none)"
echo "memory: $(free -h | awk '/^Mem:/ {print $2 " total, " $7 " available"}')"

# Snapshot the other site's health so we can tell whether we broke it.
OTHER_SITE_BEFORE=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/ || echo 000)
echo "other site answers: $OTHER_SITE_BEFORE"

# ---------------------------------------------------------------------------
say "Adding the PHP and Node repositories"

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq software-properties-common curl ca-certificates gnupg git unzip acl >/dev/null

ls /etc/apt/sources.list.d/ondrej-ubuntu-php-*.list >/dev/null 2>&1 || \
    LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1

if [ ! -f /etc/apt/keyrings/nodesource.gpg ]; then
    mkdir -p /etc/apt/keyrings
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
        | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list
fi

apt-get update -qq

# ---------------------------------------------------------------------------
say "Installing PHP $PHP_VERSION, PostgreSQL, Redis and Node (a few minutes)"

apt-get install -y -qq \
    php$PHP_VERSION-fpm php$PHP_VERSION-cli php$PHP_VERSION-pgsql \
    php$PHP_VERSION-redis php$PHP_VERSION-mbstring php$PHP_VERSION-xml \
    php$PHP_VERSION-curl php$PHP_VERSION-zip php$PHP_VERSION-bcmath \
    php$PHP_VERSION-intl php$PHP_VERSION-gd \
    postgresql postgresql-contrib redis-server nodejs >/dev/null

if ! command -v composer >/dev/null; then
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f /tmp/composer-setup.php
fi

echo "php:      $(php$PHP_VERSION -v | head -1)"
echo "node:     $(node -v)"
echo "composer: $(composer -V 2>/dev/null | head -1)"

# ---------------------------------------------------------------------------
say "Creating the app user"

id "$APP_USER" >/dev/null 2>&1 || adduser --disabled-password --gecos "" "$APP_USER" >/dev/null
mkdir -p "$APP_DIR"/shared/storage
chown -R "$APP_USER:$APP_USER" "/home/$APP_USER"
# nginx has to traverse into the app directory to reach public/.
chmod 751 "/home/$APP_USER"

# ---------------------------------------------------------------------------
say "Setting up PostgreSQL"

systemctl enable --now postgresql >/dev/null

if [ -f "$APP_DIR/shared/.db_password" ]; then
    DB_PASSWORD=$(cat "$APP_DIR/shared/.db_password")
else
    DB_PASSWORD=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 32)
    echo "$DB_PASSWORD" > "$APP_DIR/shared/.db_password"
    chmod 600 "$APP_DIR/shared/.db_password"
    chown "$APP_USER:$APP_USER" "$APP_DIR/shared/.db_password"
fi

sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" | grep -q 1 || \
    sudo -u postgres psql -qc "CREATE USER $DB_USER WITH PASSWORD '$DB_PASSWORD';"
sudo -u postgres psql -qc "ALTER USER $DB_USER WITH PASSWORD '$DB_PASSWORD';"

sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" | grep -q 1 || \
    sudo -u postgres psql -qc "CREATE DATABASE $DB_NAME OWNER $DB_USER;"

sudo -u postgres psql -qd "$DB_NAME" -c "GRANT ALL ON SCHEMA public TO $DB_USER;"

# ---------------------------------------------------------------------------
say "Setting up Redis"

# Capped on purpose: without a limit a runaway queue can push a 4GB box into
# swap and take the other site down with it.
grep -q '^maxmemory ' /etc/redis/redis.conf || echo 'maxmemory 512mb' >> /etc/redis/redis.conf
grep -q '^maxmemory-policy ' /etc/redis/redis.conf || echo 'maxmemory-policy allkeys-lru' >> /etc/redis/redis.conf
systemctl enable --now redis-server >/dev/null
systemctl restart redis-server

# ---------------------------------------------------------------------------
say "Creating the PHP-FPM pool"

# Its own pool, so Hestia's PHP settings and this app's cannot disturb each
# other, and a crash in one does not take the other's workers with it.
cat > /etc/php/$PHP_VERSION/fpm/pool.d/$APP_USER.conf <<POOL
[$APP_USER]
user = $APP_USER
group = $APP_USER
listen = /run/php/php$PHP_VERSION-fpm-$APP_USER.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; ondemand keeps idle workers from holding memory the other site may need.
pm = ondemand
pm.max_children = 12
pm.process_idle_timeout = 20s
pm.max_requests = 500

php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 20M
php_admin_value[max_execution_time] = 60
php_admin_flag[display_errors] = off
POOL

systemctl enable --now php$PHP_VERSION-fpm >/dev/null
systemctl restart php$PHP_VERSION-fpm

# ---------------------------------------------------------------------------
say "Fetching the code"

if [ -d "$APP_DIR/current/.git" ]; then
    sudo -u "$APP_USER" git -C "$APP_DIR/current" fetch --quiet origin "$BRANCH"
    sudo -u "$APP_USER" git -C "$APP_DIR/current" reset --hard --quiet "origin/$BRANCH"
else
    rm -rf "$APP_DIR/current"
    sudo -u "$APP_USER" git clone --quiet --branch "$BRANCH" "$REPO" "$APP_DIR/current"
fi

echo "at: $(sudo -u "$APP_USER" git -C "$APP_DIR/current" log --oneline -1)"

# ---------------------------------------------------------------------------
say "Writing .env"

# Written once. A re-run keeps whatever you have edited since — the mail key,
# the Meta secrets — rather than resetting them.
if [ ! -f "$APP_DIR/shared/.env" ]; then
    cat > "$APP_DIR/shared/.env" <<ENV
APP_NAME="Resellers Hub"
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=https://$DOMAIN

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASSWORD

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=1
REDIS_CACHE_DB=2

CACHE_STORE=redis
QUEUE_CONNECTION=redis

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=.$DOMAIN
SESSION_SECURE_COOKIE=true

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

# Mail is off until you add a provider. Password resets will not arrive
# until MAIL_MAILER is switched to smtp and a key is filled in below.
MAIL_MAILER=log
MAIL_SCHEME=tls
MAIL_HOST=smtp.resend.com
MAIL_PORT=587
MAIL_USERNAME=resend
MAIL_PASSWORD=
MAIL_FROM_ADDRESS="noreply@$DOMAIN"
MAIL_FROM_NAME="\${APP_NAME}"

VITE_APP_NAME="\${APP_NAME}"

META_APP_SECRET=
META_VERIFY_TOKEN=

BILLING_SNIPPE_API_KEY=
BILLING_SNIPPE_WEBHOOK_SECRET=
BILLING_SNIPPE_USD_TO_TZS=2600
BILLING_NOWPAYMENTS_API_KEY=
BILLING_NOWPAYMENTS_IPN_SECRET=
BILLING_NOWPAYMENTS_PAY_CURRENCY=usdttrc20
BILLING_CRYPTOMUS_API_KEY=
BILLING_CRYPTOMUS_MERCHANT_ID=
BILLING_HELEKET_API_KEY=
BILLING_HELEKET_MERCHANT_ID=

SUPERADMIN_USERNAME=
SUPERADMIN_PASSWORD=
SUPERADMIN_NAME="Super Admin"
ENV
    chmod 600 "$APP_DIR/shared/.env"
    chown "$APP_USER:$APP_USER" "$APP_DIR/shared/.env"
    echo "written"
else
    echo "already there — left alone"
fi

ln -sfn "$APP_DIR/shared/.env" "$APP_DIR/current/.env"

# ---------------------------------------------------------------------------
say "Installing dependencies and building (a few minutes)"

cd "$APP_DIR/current"

sudo -u "$APP_USER" composer install \
    --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader 2>&1 | tail -3

# storage/ lives in shared/ so uploads and logs survive a re-clone.
mkdir -p "$APP_DIR/shared/storage"/{app/public,framework/{cache/data,sessions,testing,views},logs}
chown -R "$APP_USER:$APP_USER" "$APP_DIR/shared/storage"
rm -rf "$APP_DIR/current/storage"
sudo -u "$APP_USER" ln -sfn "$APP_DIR/shared/storage" "$APP_DIR/current/storage"

# Vite is the memory-hungry step on a shared 4GB box; give node room and let
# it go when it is done.
sudo -u "$APP_USER" npm ci --no-audit --no-fund 2>&1 | tail -3
sudo -u "$APP_USER" env NODE_OPTIONS=--max-old-space-size=1536 npm run build 2>&1 | tail -5
sudo -u "$APP_USER" rm -rf node_modules

# ---------------------------------------------------------------------------
say "Setting up the database"

sudo -u "$APP_USER" php$PHP_VERSION artisan migrate --force 2>&1 | tail -10

# The landing page prices itself from the plans table, so an empty one shows
# a page with no pricing at all. Seeding is idempotent.
sudo -u "$APP_USER" php$PHP_VERSION artisan db:seed --class=PlanSeeder --force 2>&1 | tail -3

sudo -u "$APP_USER" php$PHP_VERSION artisan storage:link >/dev/null 2>&1 || true
sudo -u "$APP_USER" php$PHP_VERSION artisan config:cache >/dev/null
sudo -u "$APP_USER" php$PHP_VERSION artisan route:cache >/dev/null
sudo -u "$APP_USER" php$PHP_VERSION artisan view:cache >/dev/null

# ---------------------------------------------------------------------------
say "Creating the workers"

cat > /etc/systemd/system/smmhub-queue.service <<UNIT
[Unit]
Description=Resellers Hub queue worker
After=network.target redis-server.service postgresql.service

[Service]
Type=simple
User=$APP_USER
Group=$APP_USER
Restart=always
RestartSec=5
ExecStart=/usr/bin/php$PHP_VERSION $APP_DIR/current/artisan queue:work --tries=3 --timeout=120 --sleep=3 --max-time=3600

[Install]
WantedBy=multi-user.target
UNIT

cat > /etc/systemd/system/smmhub-schedule.service <<UNIT
[Unit]
Description=Resellers Hub scheduler
[Service]
Type=oneshot
User=$APP_USER
Group=$APP_USER
ExecStart=/usr/bin/php$PHP_VERSION $APP_DIR/current/artisan schedule:run
UNIT

cat > /etc/systemd/system/smmhub-schedule.timer <<UNIT
[Unit]
Description=Run the Resellers Hub scheduler every minute
[Timer]
OnCalendar=*:0/1
AccuracySec=10s
[Install]
WantedBy=timers.target
UNIT

# The deploy restarts these two and nothing else — a general sudo grant would
# turn the deploy key into a root key.
cat > /etc/sudoers.d/$APP_USER-deploy <<SUDO
$APP_USER ALL=(root) NOPASSWD: /bin/systemctl restart php$PHP_VERSION-fpm
$APP_USER ALL=(root) NOPASSWD: /bin/systemctl restart smmhub-queue
$APP_USER ALL=(root) NOPASSWD: /bin/systemctl restart php$PHP_VERSION-fpm smmhub-queue
SUDO
chmod 440 /etc/sudoers.d/$APP_USER-deploy
visudo -cf /etc/sudoers.d/$APP_USER-deploy >/dev/null

systemctl daemon-reload
systemctl enable --now smmhub-queue.service smmhub-schedule.timer >/dev/null

# ---------------------------------------------------------------------------
say "Adding the nginx site"

# conf.d/ is outside everything Hestia rebuilds. A block in Hestia's own
# directories gets overwritten the next time the panel touches a domain.
cat > /etc/nginx/conf.d/smmresellershub.conf <<NGINX
# Resellers Hub — managed by hand, not by HestiaCP.
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN www.$DOMAIN;

    root $APP_DIR/current/public;
    index index.php;

    charset utf-8;
    client_max_body_size 20M;

    location ^~ /.well-known/acme-challenge/ { root /var/www/html; allow all; }

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php$PHP_VERSION-fpm-$APP_USER.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        include fastcgi_params;
        fastcgi_read_timeout 60s;
    }

    location /build/ { expires 1y; access_log off; add_header Cache-Control "public, immutable"; }
    location ~ /\.(?!well-known) { deny all; }

    access_log /var/log/nginx/smmhub-access.log;
    error_log  /var/log/nginx/smmhub-error.log;
}
NGINX

# A bad block would take the other site down too, so verify before reloading.
if nginx -t 2>/dev/null; then
    systemctl reload nginx
    echo "nginx config valid, reloaded"
else
    warn "nginx config failed — removing our block so the other site keeps working"
    rm -f /etc/nginx/conf.d/smmresellershub.conf
    nginx -t
    die "Fix the config above, then re-run."
fi

# ---------------------------------------------------------------------------
say "Checking both sites"

OTHER_SITE_AFTER=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/ || echo 000)
OURS=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $DOMAIN" http://127.0.0.1/ || echo 000)

echo "other site: $OTHER_SITE_BEFORE -> $OTHER_SITE_AFTER"
echo "this app:   $OURS"

[ "$OURS" = "200" ] || [ "$OURS" = "302" ] || warn "The app answered $OURS — check /var/log/nginx/smmhub-error.log"

# ---------------------------------------------------------------------------
say "Getting the SSL certificate"

apt-get install -y -qq certbot python3-certbot-nginx >/dev/null

if certbot --nginx -d "$DOMAIN" -d "www.$DOMAIN" \
        --non-interactive --agree-tos --register-unsafely-without-email \
        --redirect 2>&1 | tail -5; then
    echo "certificate installed"
else
    warn "certbot did not finish. The site still works on http://; run this again once DNS is settled:"
    warn "  certbot --nginx -d $DOMAIN -d www.$DOMAIN"
fi

# ---------------------------------------------------------------------------
say "Done"

FINAL=$(curl -s -o /dev/null -w '%{http_code}' "https://$DOMAIN/" || echo 000)

cat <<SUMMARY

  Site:      https://$DOMAIN   (answered $FINAL)
  Other site still answering: $OTHER_SITE_AFTER

  Database:  $DB_NAME / $DB_USER
  Password:  saved at $APP_DIR/shared/.db_password

  Still to do, when you are ready:
    - Mail: edit $APP_DIR/shared/.env, set MAIL_MAILER=smtp and a Resend key,
      otherwise password-reset mail never arrives
    - Super admin login: set SUPERADMIN_USERNAME and SUPERADMIN_PASSWORD
      in that same file, then: sudo -u $APP_USER php$PHP_VERSION $APP_DIR/current/artisan db:seed --class=SuperadminSeeder

  After editing .env:
    sudo -u $APP_USER php$PHP_VERSION $APP_DIR/current/artisan config:cache
    systemctl restart smmhub-queue

  Watching it:
    tail -f $APP_DIR/shared/storage/logs/laravel.log
    journalctl -u smmhub-queue -f

SUMMARY
