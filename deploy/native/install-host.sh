#!/usr/bin/env bash
# Meridian native host provisioning — Ubuntu 26.04, no Docker.
#
# Installs the four things the Compose stack ran as containers, as host services:
#
#   postgres  -> PostgreSQL 18 with wal_level=logical (node-to-node sync, spec 10)
#   server    -> php8.5-fpm running the Laravel server
#   worker    -> meridian-worker.service   (queue:work)
#   scheduler -> meridian-scheduler.service (schedule:work)
#   web       -> Caddy, running the bundle's own Caddyfiles from /etc/caddy
#
# Run once on a fresh machine. Idempotent enough to re-run.
# Application build and release is deploy-release.sh, which you run again on
# every upgrade.

set -euo pipefail

PHP_VERSION="${PHP_VERSION:-8.5}"
PG_VERSION="${PG_VERSION:-18}"
MERIDIAN_ROOT="${MERIDIAN_ROOT:-/var/www/meridian}"
MERIDIAN_REPO="${MERIDIAN_REPO:-https://github.com/KenEucker/meridian.git}"
MERIDIAN_REF="${MERIDIAN_REF:-production}"
BUNDLE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log() { echo "meridian-install: $1"; }

if [ "$(id -u)" -ne 0 ]; then
    log "run this with sudo"
    exit 1
fi

# ---------------------------------------------------------------------------
# Package repositories
# ---------------------------------------------------------------------------
# apps/server/composer.json pins `php: >=8.5 <8.6`, so the PHP major.minor is not
# negotiable. Ubuntu's own php8.5-* packages are used when present; the PPA is
# the fallback, and it is what carries 8.5 on releases that shipped 8.4.
log "installing base packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl gnupg git unzip debian-keyring debian-archive-keyring apt-transport-https

if ! apt-cache show "php${PHP_VERSION}-fpm" >/dev/null 2>&1; then
    log "php${PHP_VERSION} not in the distro archive; adding ppa:ondrej/php"
    apt-get install -y software-properties-common
    add-apt-repository -y ppa:ondrej/php
    apt-get update
fi

if ! apt-cache show "postgresql-${PG_VERSION}" >/dev/null 2>&1; then
    log "postgresql-${PG_VERSION} not in the distro archive; adding PGDG"
    install -d /usr/share/postgresql-common/pgdg
    curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
        -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
    echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(. /etc/os-release && echo "$VERSION_CODENAME")-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list
    apt-get update
fi

# Caddy's own apt repository. The bundle's Caddyfiles are what runs; only the
# binary comes from here.
if [ ! -f /etc/apt/sources.list.d/caddy-stable.list ]; then
    log "adding the Caddy apt repository"
    curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key \
        | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
    curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt \
        > /etc/apt/sources.list.d/caddy-stable.list
    apt-get update
fi

# ---------------------------------------------------------------------------
# Runtimes
# ---------------------------------------------------------------------------
# The extension list is the one deploy/docker/Dockerfile builds into php-base:
# gd for Field Report photos and profile pictures, zip + simplexml for
# spreadsheet imports (composer.json requires both explicitly), pgsql for the
# database, bcmath to match what CI tests against. pcntl is compiled into the
# Debian CLI SAPI already, which is what queue:work needs for signal handling.
log "installing PHP ${PHP_VERSION}, PostgreSQL ${PG_VERSION}, Caddy, Node"
apt-get install -y \
    "php${PHP_VERSION}-fpm" \
    "php${PHP_VERSION}-cli" \
    "php${PHP_VERSION}-bcmath" \
    "php${PHP_VERSION}-gd" \
    "php${PHP_VERSION}-pgsql" \
    "php${PHP_VERSION}-zip" \
    "php${PHP_VERSION}-xml" \
    "php${PHP_VERSION}-mbstring" \
    "php${PHP_VERSION}-curl" \
    "php${PHP_VERSION}-intl" \
    "php${PHP_VERSION}-opcache" \
    "postgresql-${PG_VERSION}" \
    postgresql-client-common \
    caddy

if ! command -v composer >/dev/null 2>&1; then
    log "installing Composer"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    "php${PHP_VERSION}" /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi

if ! command -v node >/dev/null 2>&1; then
    log "installing Node 24"
    curl -fsSL https://deb.nodesource.com/setup_24.x | bash -
    apt-get install -y nodejs
fi
corepack enable

# ---------------------------------------------------------------------------
# PHP configuration
# ---------------------------------------------------------------------------
# The same two ini files the image writes, applied to both SAPIs so `artisan`
# and a served request agree on limits.
log "applying PHP configuration"
for sapi in fpm cli; do
    install -m 0644 "${BUNDLE_DIR}/php/meridian-runtime.ini" \
        "/etc/php/${PHP_VERSION}/${sapi}/conf.d/99-meridian-runtime.ini"
    install -m 0644 "${BUNDLE_DIR}/php/meridian-opcache.ini" \
        "/etc/php/${PHP_VERSION}/${sapi}/conf.d/99-meridian-opcache.ini"
done

sed "s/@PHP_VERSION@/${PHP_VERSION}/g" "${BUNDLE_DIR}/php/meridian-pool.conf" \
    > "/etc/php/${PHP_VERSION}/fpm/pool.d/meridian.conf"

# The stock www pool would listen on a second socket and serve nothing.
rm -f "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"

# ---------------------------------------------------------------------------
# PostgreSQL
# ---------------------------------------------------------------------------
log "configuring PostgreSQL"
install -m 0644 -o postgres -g postgres "${BUNDLE_DIR}/postgres/meridian.conf" \
    "/etc/postgresql/${PG_VERSION}/main/conf.d/meridian.conf"
systemctl restart "postgresql@${PG_VERSION}-main"

DB_DATABASE="${DB_DATABASE:-meridian}"
DB_USERNAME="${DB_USERNAME:-meridian}"
if [ -z "${DB_PASSWORD:-}" ]; then
    log "set DB_PASSWORD in the environment before running this script"
    exit 1
fi

if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='${DB_USERNAME}'" | grep -q 1; then
    log "creating role ${DB_USERNAME}"
    sudo -u postgres psql -c "CREATE ROLE ${DB_USERNAME} LOGIN PASSWORD '${DB_PASSWORD}';"
else
    sudo -u postgres psql -c "ALTER ROLE ${DB_USERNAME} WITH LOGIN PASSWORD '${DB_PASSWORD}';"
fi

if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_DATABASE}'" | grep -q 1; then
    log "creating database ${DB_DATABASE} owned by ${DB_USERNAME}"
    # Owned by the application role so it owns `public` through pg_database_owner
    # and migrations can create tables without a further grant.
    sudo -u postgres createdb -O "${DB_USERNAME}" "${DB_DATABASE}"
fi

# ---------------------------------------------------------------------------
# The application tree
# ---------------------------------------------------------------------------
# /var/www/meridian and not somewhere tidier, because two things resolve from
# it: deploy/caddy/meridian.snippet roots the site at
# /var/www/meridian/apps/server/public, and the server reads its build version
# and license from the root package.json at base_path('../..') and the client
# artifact at base_path('../client/dist/admin'). Keeping the monorepo layout at
# this path is what makes every one of those correct with no override.
if [ ! -d "${MERIDIAN_ROOT}/.git" ]; then
    log "cloning Meridian into ${MERIDIAN_ROOT}"
    install -d -o www-data -g www-data "$(dirname "${MERIDIAN_ROOT}")"
    git clone --branch "${MERIDIAN_REF}" "${MERIDIAN_REPO}" "${MERIDIAN_ROOT}"
    chown -R www-data:www-data "${MERIDIAN_ROOT}"
fi

# ---------------------------------------------------------------------------
# Caddy
# ---------------------------------------------------------------------------
# The bundle's own configurations, unmodified. MERIDIAN_CADDYFILE chooses which
# one runs, exactly as it did in Compose.
log "installing the Meridian Caddy configuration"
install -m 0644 \
    "${MERIDIAN_ROOT}/deploy/caddy/Caddyfile" \
    "${MERIDIAN_ROOT}/deploy/caddy/Caddyfile.onsite" \
    "${MERIDIAN_ROOT}/deploy/caddy/Caddyfile.wildcard" \
    "${MERIDIAN_ROOT}/deploy/caddy/meridian.snippet" \
    /etc/caddy/
install -d -m 0755 /etc/caddy/tls

install -d /etc/systemd/system/caddy.service.d
install -m 0644 "${BUNDLE_DIR}/systemd/caddy-meridian.conf" \
    /etc/systemd/system/caddy.service.d/meridian.conf

# The proxy half of the configuration. Never overwritten, because a re-run must
# not discard the site address and certificate paths a node is already serving.
if [ ! -f /etc/meridian-proxy.env ]; then
    install -m 0640 -g caddy "${BUNDLE_DIR}/.env.proxy.example" /etc/meridian-proxy.env
    sed -i "s|php8.5-fpm-meridian.sock|php${PHP_VERSION}-fpm-meridian.sock|" /etc/meridian-proxy.env
    log "wrote /etc/meridian-proxy.env — set MERIDIAN_SITE_ADDRESS in it"
fi

# Caddy serves the server's public files directly, so its own user needs to read
# them; the fpm socket is group-owned by caddy so it can reach PHP.
usermod -aG www-data caddy || true

# ---------------------------------------------------------------------------
# Meridian services
# ---------------------------------------------------------------------------
log "installing the worker and scheduler units"
for unit in meridian-worker meridian-scheduler; do
    sed -e "s/@PHP_VERSION@/${PHP_VERSION}/g" \
        -e "s|@MERIDIAN_ROOT@|${MERIDIAN_ROOT}|g" \
        "${BUNDLE_DIR}/systemd/${unit}.service" \
        > "/etc/systemd/system/${unit}.service"
done
systemctl daemon-reload
systemctl enable "postgresql@${PG_VERSION}-main" "php${PHP_VERSION}-fpm" caddy \
    meridian-worker meridian-scheduler

# The worker and scheduler are started by deploy-release.sh, not here: they boot
# the application, and the application has no configuration or database schema
# yet.

log "host provisioning done"
log ""
log "next:"
log "  1. write ${MERIDIAN_ROOT}/apps/server/.env  (see README.md)"
log "  2. set MERIDIAN_SITE_ADDRESS and the TLS choice in /etc/meridian-proxy.env"
log "  3. run deploy-release.sh"
