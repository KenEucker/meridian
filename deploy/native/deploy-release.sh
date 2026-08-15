#!/usr/bin/env bash
# Meridian native release — build the artifacts and run the per-boot work.
#
# This is deploy/docker/entrypoint.sh plus the parts of the image build that a
# native install has to do itself. Run it on the first install and again on
# every upgrade. The order below is the entrypoint's order and is load-bearing:
#
#   migrate         before secrets, because the node keypair is stored as node
#                   configuration and needs its table
#   secrets         before config:cache, because a generated key has to be in
#                   the cache served requests read
#   config caches   before event-mode, so what is validated is the cached
#                   configuration the served requests will actually read
#   event-mode      last, and `set -e` stops here on a failing check — a node
#                   that refuses event mode should stop, not come up and answer
#                   503 to everything
#
# Technical spec 26.2 requires the migration warning to arrive before the
# migration, not with the result.

set -euo pipefail

PHP_VERSION="${PHP_VERSION:-8.5}"
MERIDIAN_ROOT="${MERIDIAN_ROOT:-/var/www/meridian}"
SERVER_DIR="${MERIDIAN_ROOT}/apps/server"
PHP="/usr/bin/php${PHP_VERSION}"
BUNDLE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SKIP_BUILD="${SKIP_BUILD:-no}"
SKIP_PREFLIGHT="${SKIP_PREFLIGHT:-no}"

log() { echo "meridian: $1"; }
as_app() { sudo -u www-data --preserve-env=PATH,COMPOSER_HOME "$@"; }

if [ "$(id -u)" -ne 0 ]; then
    log "run this with sudo"
    exit 1
fi

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------
# Before anything is built or migrated, because the configuration problems it
# finds otherwise surface as a Composer error with no line number, or sixty
# seconds of waiting for a database that was never going to answer, or a failed
# `meridian:event-mode` at the very end of a release. It reads only, changes
# nothing, and reports every problem at once. Run as root so it can compare the
# server's environment file against the proxy's.
if [ "${SKIP_PREFLIGHT}" != "yes" ]; then
    log "checking this node's configuration"
    MERIDIAN_ROOT="${MERIDIAN_ROOT}" "${PHP}" "${BUNDLE_DIR}/preflight.php"
elif [ ! -f "${SERVER_DIR}/.env" ]; then
    log "${SERVER_DIR}/.env is missing; write it before releasing"
    exit 1
fi

cd "${MERIDIAN_ROOT}"

# ---------------------------------------------------------------------------
# Build — what `docker build` did
# ---------------------------------------------------------------------------
if [ "${SKIP_BUILD}" != "yes" ]; then
    log "installing server dependencies"
    # --no-dev matches the vendor stage. Scripts run here rather than being
    # deferred, because the application tree is already present.
    as_app composer install --working-dir="${SERVER_DIR}" \
        --no-dev --prefer-dist --no-progress --no-interaction \
        --optimize-autoloader --classmap-authoritative

    log "building the Meridian Admin client artifact"
    # The same filtered install and `build:admin` the client stage runs: the
    # server serves the Admin artifact, and an unfiltered install would fetch
    # the Electron and Capacitor toolchains to build a browser bundle that uses
    # neither. Field and Kiosk are deliberately not built — they ship in the
    # mobile and desktop installers.
    as_app corepack pnpm install --frozen-lockfile --filter "@meridian/client..."
    as_app corepack pnpm --filter @meridian/client run build:admin

    log "packaging technician documentation and the changelog"
    # God Mode renders both from packaged files and never fetches them from a
    # network service (GOD-012, GOD-021), so they have to be generated here.
    as_app corepack pnpm run docs:package
    as_app corepack pnpm run changelog:generate
fi

# ---------------------------------------------------------------------------
# Per-boot work — what entrypoint.sh did
# ---------------------------------------------------------------------------
log "preparing the storage tree"
install -d -o www-data -g www-data \
    "${SERVER_DIR}/storage/app/private" \
    "${SERVER_DIR}/storage/app/public" \
    "${SERVER_DIR}/storage/framework/cache/data" \
    "${SERVER_DIR}/storage/framework/sessions" \
    "${SERVER_DIR}/storage/framework/views" \
    "${SERVER_DIR}/storage/logs" \
    "${SERVER_DIR}/bootstrap/cache"
chown -R www-data:www-data "${SERVER_DIR}/storage" "${SERVER_DIR}/bootstrap/cache"

cd "${SERVER_DIR}"

APP_ENV_VALUE="$(as_app "${PHP}" -r 'echo getenv("APP_ENV") ?: (parse_ini_file(".env")["APP_ENV"] ?? "production");')"

log "waiting for the database"
attempt=1
until as_app "${PHP}" artisan db:show --quiet >/dev/null 2>&1; do
    if [ "${attempt}" -ge 30 ]; then
        log "the database did not become reachable; refusing to continue"
        exit 1
    fi
    log "waiting for the database (attempt ${attempt}/30)"
    sleep 2
    attempt=$((attempt + 1))
done

if [ "${APP_ENV_VALUE}" = "local" ] || [ "${APP_ENV_VALUE}" = "testing" ]; then
    log "APP_ENV=${APP_ENV_VALUE}; skipping automatic migrations"
else
    log "----------------------------------------------------------------"
    log "About to run database migrations."
    log "Take a database backup BEFORE a version upgrade, not after it."
    log "Stop this script now if this node has no current backup."
    log "----------------------------------------------------------------"
    sleep 5
    as_app "${PHP}" artisan migrate --force --no-interaction
fi

# Generates what Meridian owns and refuses what it does not (technical spec
# 7.4, 26.2). `set -e` stops the release here when a secret it cannot generate
# is still a sample value.
as_app "${PHP}" artisan meridian:secrets --generate --no-interaction

as_app "${PHP}" artisan config:cache
as_app "${PHP}" artisan route:cache
as_app "${PHP}" artisan view:cache
as_app "${PHP}" artisan event:cache

# The event-mode fail-closed checks (technical spec 8.2, 8.6, 26.2): HTTPS
# validation and the offline read set.
as_app "${PHP}" artisan meridian:event-mode --no-interaction

# ---------------------------------------------------------------------------
# Services
# ---------------------------------------------------------------------------
# opcache.validate_timestamps=0 means new code is invisible until the pool
# restarts, which is the trade the image makes too — there, a new build was a
# new container.
log "restarting services"
systemctl restart "php${PHP_VERSION}-fpm"
systemctl restart meridian-worker meridian-scheduler
systemctl reload-or-restart caddy

systemctl --no-pager --lines=0 status \
    "php${PHP_VERSION}-fpm" meridian-worker meridian-scheduler caddy || true

log "release complete"
