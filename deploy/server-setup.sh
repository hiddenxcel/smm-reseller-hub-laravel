#!/bin/bash
#
# One-time server setup, run as root on the VPS.
#
# The box already runs HestiaCP with a PHP site on it. Nothing here touches
# Hestia: the app gets its own user, its own PHP-FPM pool, its own Postgres,
# and an nginx server block in conf.d/ — the one directory Hestia does not
# rewrite when it rebuilds a domain.
#
# Usage:  bash server-setup.sh
#
# Safe to run twice: every step checks before it acts.

set -euo pipefail

APP_USER=hxapp
APP_DIR=/home/$APP_USER/smm-reseller-hub
DOMAIN=smmresellershub.com
PHP_VERSION=8.3
DB_NAME=smmhub
DB_USER=smmhub

say() { printf '\n\033[1;32m==> %s\033[0m\n' "$1"; }
warn() { printf '\n\033[1;33m!! %s\033[0m\n' "$1"; }

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this as root." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
say "Checking what is already here"

# Hestia owns nginx. If it is not running, this is not the box we think it is.
if ! systemctl is-active --quiet nginx; then
    warn "nginx is not running. Stopping — check the server before continuing."
    exit 1
fi

echo "nginx: $(nginx -v 2>&1)"
echo "existing sites: $(ls /home/*/web/ 2>/dev/null | tr '\n' ' ' || echo none)"

# ---------------------------------------------------------------------------
say "Adding the PHP and Node repositories"

apt-get update -qq
apt-get install -y -qq software-properties-common curl ca-certificates gnupg lsb-release >/dev/null

if [ ! -f /etc/apt/sources.list.d/ondrej-ubuntu-php-*.list ]; then
    LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php >/dev/null
fi

if [ ! -f /etc/apt/keyrings/nodesource.gpg ]; then
    mkdir -p /etc/apt/keyrings
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
        | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list
fi

apt-get update -qq

# ---------------------------------------------------------------------------
say "Installing PHP $PHP_VERSION, PostgreSQL, Redis, Node"

apt-get install -y -qq \
    php$PHP_VERSION-fpm php$PHP_VERSION-cli php$PHP_VERSION-pgsql \
    php$PHP_VERSION-redis php$PHP_VERSION-mbstring php$PHP_VERSION-xml \
    php$PHP_VERSION-curl php$PHP_VERSION-zip php$PHP_VERSION-bcmath \
    php$PHP_VERSION-intl php$PHP_VERSION-gd \
    postgresql postgresql-contrib redis-server \
    nodejs git unzip acl >/dev/null

if ! command -v composer >/dev/null; then
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f /tmp/composer-setup.php
fi

echo "php: $(php$PHP_VERSION -v | head -1)"
echo "node: $(node -v)"
echo "composer: $(composer -V)"

# ---------------------------------------------------------------------------
say "Creating the app user"

if ! id "$APP_USER" >/dev/null 2>&1; then
    adduser --disabled-password --gecos "" "$APP_USER"
fi

# The deploy writes releases; php-fpm and nginx read them.
mkdir -p "$APP_DIR"/{releases,shared/storage}
chown -R "$APP_USER:$APP_USER" "/home/$APP_USER"

# nginx (www-data) has to traverse into the app directory to serve public/.
chmod 751 "/home/$APP_USER"

# ---------------------------------------------------------------------------
say "Setting up PostgreSQL"

systemctl enable --now postgresql >/dev/null

# A password we generate rather than ask for. Printed once at the end.
if [ -f "$APP_DIR/shared/.db_password" ]; then
    DB_PASSWORD=$(cat "$APP_DIR/shared/.db_password")
else
    DB_PASSWORD=$(openssl rand -base64 24 | tr -d '/+=' | head -c 32)
    echo "$DB_PASSWORD" > "$APP_DIR/shared/.db_password"
    chmod 600 "$APP_DIR/shared/.db_password"
    chown "$APP_USER:$APP_USER" "$APP_DIR/shared/.db_password"
fi

sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" | grep -q 1 || \
    sudo -u postgres psql -qc "CREATE USER $DB_USER WITH PASSWORD '$DB_PASSWORD';"

sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" | grep -q 1 || \
    sudo -u postgres psql -qc "CREATE DATABASE $DB_NAME OWNER $DB_USER;"

# Laravel migrations create and drop freely inside the public schema.
sudo -u postgres psql -qd "$DB_NAME" -c "GRANT ALL ON SCHEMA public TO $DB_USER;"

# ---------------------------------------------------------------------------
say "Setting up Redis"

# Hestia's own services may use Redis too, so the app gets database 1 rather
# than assuming 0 is free. Memory is capped: without a limit a runaway queue
# can push the box into swap and take the other site down with it.
sed -i 's/^# *maxmemory .*/maxmemory 512mb/' /etc/redis/redis.conf
sed -i 's/^# *maxmemory-policy .*/maxmemory-policy allkeys-lru/' /etc/redis/redis.conf
grep -q '^maxmemory ' /etc/redis/redis.conf || echo 'maxmemory 512mb' >> /etc/redis/redis.conf
grep -q '^maxmemory-policy ' /etc/redis/redis.conf || echo 'maxmemory-policy allkeys-lru' >> /etc/redis/redis.conf

systemctl enable --now redis-server >/dev/null
systemctl restart redis-server

# ---------------------------------------------------------------------------
say "Creating the PHP-FPM pool"

# Its own pool, so Hestia's PHP settings and this app's cannot disturb each
# other, and so a crash in one does not take the other's workers with it.
cat > /etc/php/$PHP_VERSION/fpm/pool.d/$APP_USER.conf <<POOL
[$APP_USER]
user = $APP_USER
group = $APP_USER
listen = /run/php/php$PHP_VERSION-fpm-$APP_USER.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; 4GB box shared with another site: ondemand keeps idle workers from holding
; memory the other site may need.
pm = ondemand
pm.max_children = 12
pm.process_idle_timeout = 20s
pm.max_requests = 500

php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 20M
php_admin_value[max_execution_time] = 60
php_admin_flag[display_errors] = off
php_admin_value[error_log] = /var/log/php$PHP_VERSION-fpm-$APP_USER.log
POOL

systemctl enable --now php$PHP_VERSION-fpm >/dev/null
systemctl restart php$PHP_VERSION-fpm

# ---------------------------------------------------------------------------
say "Creating the queue worker service"

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

; --tries=3 so a panel that is briefly down does not lose the job outright.
; The worker is stopped by the deploy, not by a timeout.
ExecStart=/usr/bin/php$PHP_VERSION $APP_DIR/current/artisan queue:work \\
    --queue=default --tries=3 --timeout=120 --sleep=3 --max-time=3600

[Install]
WantedBy=multi-user.target
UNIT

# Laravel's scheduler, which the app needs for anything time-based.
cat > /etc/systemd/system/smmhub-schedule.service <<UNIT
[Unit]
Description=Resellers Hub scheduler
After=network.target

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

systemctl daemon-reload
# Not started yet — there is no release to run against until the first deploy.
systemctl enable smmhub-queue.service smmhub-schedule.timer >/dev/null

# The deploy restarts php-fpm and the worker. Those two commands and nothing
# else — a general sudo grant would make the deploy key a root key.
cat > /etc/sudoers.d/$APP_USER-deploy <<SUDO
$APP_USER ALL=(root) NOPASSWD: /bin/systemctl restart php$PHP_VERSION-fpm
$APP_USER ALL=(root) NOPASSWD: /bin/systemctl restart smmhub-queue
$APP_USER ALL=(root) NOPASSWD: /bin/systemctl restart php$PHP_VERSION-fpm smmhub-queue
SUDO
chmod 440 /etc/sudoers.d/$APP_USER-deploy
visudo -cf /etc/sudoers.d/$APP_USER-deploy >/dev/null

# ---------------------------------------------------------------------------
say "Writing the nginx server block"

# conf.d/ is outside everything Hestia rebuilds. A block placed in Hestia's
# own directories gets overwritten the next time the panel touches a domain.
cat > /etc/nginx/conf.d/smmresellershub.conf <<NGINX
# Resellers Hub — managed by hand, not by HestiaCP.
# Hestia rebuilds /home/*/conf/web/. It does not rebuild conf.d/, so this
# file survives panel changes to the other site.

server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN www.$DOMAIN;

    root $APP_DIR/current/public;
    index index.php;

    charset utf-8;
    client_max_body_size 20M;

    # Certbot writes its challenge here before the TLS block exists.
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/html;
        allow all;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php$PHP_VERSION-fpm-$APP_USER.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        include fastcgi_params;
        fastcgi_read_timeout 60s;
    }

    # Vite output is content-hashed, so it can be cached hard.
    location /build/ {
        expires 1y;
        access_log off;
        add_header Cache-Control "public, immutable";
    }

    location ~ /\.(?!well-known) {
        deny all;
    }

    access_log /var/log/nginx/smmhub-access.log;
    error_log  /var/log/nginx/smmhub-error.log;
}
NGINX

# A broken block would take the other site down too, so verify before reload.
if nginx -t 2>/dev/null; then
    systemctl reload nginx
    echo "nginx config valid and reloaded"
else
    warn "nginx config test FAILED — removing our block so the other site keeps working"
    rm -f /etc/nginx/conf.d/smmresellershub.conf
    nginx -t
    exit 1
fi

# ---------------------------------------------------------------------------
say "Done"

cat <<SUMMARY

  Database:  $DB_NAME
  DB user:   $DB_USER
  DB pass:   $DB_PASSWORD
             (also saved at $APP_DIR/shared/.db_password)

  App dir:   $APP_DIR
  PHP sock:  /run/php/php$PHP_VERSION-fpm-$APP_USER.sock

  Next:
    1. Point $DOMAIN's DNS A record at this server
    2. Put the deploy key in place (see deploy/README.md)
    3. Push to main — the GitHub Action does the first deploy
    4. Then run: certbot --nginx -d $DOMAIN -d www.$DOMAIN

SUMMARY
