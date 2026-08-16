#!/bin/sh
# Meridian server container entrypoint.
#
# Runs the once-per-boot work a deployed node needs before it serves traffic, then
# hands off to whatever the container was asked to run: php-fpm for the web
# container, `queue:work` for the worker, `schedule:work` for the scheduler.
#
# Technical spec 26.2 requires production and event modes to run database
# migrations automatically, with backup warnings first. That is what this does,
# and the warning is deliberately printed before the migration rather than after:
# a warning that arrives with the result is a note, not a warning.
#
# It also runs the secret safeguards before the container serves anything
# (technical spec 7.4, 26.2). The refusal itself belongs to the server and holds
# for every way a node is started, not only the containerized one; running the
# check here is what turns it into one legible line in `docker compose logs` at
# start, instead of a 503 on every request from a container that looks like it
# came up. Generation covers the secrets Meridian owns; the rest are named and
# the container stops.

set -eu

log() {
    echo "meridian: $1"
}

# A one-click platform install (Runtipi; deploy/runtipi/README.md) has no step
# to run `key:generate` and edit a file, and SecretGenerator deliberately
# refuses to write a key it cannot persist. So when the deployment supplies a
# high-entropy seed instead of a key, derive the key from it: SHA-256 gives
# exactly the 32 bytes Laravel requires for any seed length, and the same seed
# always yields the same key, which is what makes it survive a container
# recreate, an app update, and a restore from the platform's own backup.
# Every container role needs this — the worker and scheduler decrypt what the
# web container encrypted — and a node with neither APP_KEY nor a seed still
# stops at `meridian:secrets` and says so (technical spec 7.4, 26.2).
if [ -z "${APP_KEY:-}" ] && [ -n "${MERIDIAN_APP_KEY_SEED:-}" ]; then
    APP_KEY="base64:$(php -r 'echo base64_encode(hash("sha256", getenv("MERIDIAN_APP_KEY_SEED"), true));')"
    export APP_KEY
    log "APP_KEY derived from MERIDIAN_APP_KEY_SEED"
fi

# The storage tree is usually a volume, and a volume mounted over the image's own
# directory arrives empty. Laravel treats a missing framework subdirectory as an
# unwritable one, so the node has to be able to rebuild the tree on any boot.
mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

APP_ENV_VALUE="${APP_ENV:-production}"

# Only the process that serves or works does the shared setup. A worker and a
# scheduler starting beside the web container must not each run migrations
# against the same database.
case "${MERIDIAN_CONTAINER_ROLE:-web}" in
    web)
        run_shared_setup=yes
        ;;
    *)
        run_shared_setup=no
        ;;
esac

wait_for_database() {
    attempts="${MERIDIAN_DB_WAIT_ATTEMPTS:-30}"
    attempt=1

    while [ "$attempt" -le "$attempts" ]; do
        if php artisan db:show --quiet >/dev/null 2>&1; then
            return 0
        fi

        log "waiting for the database (attempt ${attempt}/${attempts})"
        sleep 2
        attempt=$((attempt + 1))
    done

    log "the database did not become reachable; refusing to continue"
    return 1
}

if [ "$run_shared_setup" = yes ]; then
    wait_for_database

    if [ "$APP_ENV_VALUE" = "local" ] || [ "$APP_ENV_VALUE" = "testing" ]; then
        log "APP_ENV=${APP_ENV_VALUE}; skipping automatic migrations"
    else
        log "----------------------------------------------------------------"
        log "About to run database migrations against ${DB_DATABASE:-meridian}."
        log "Take a database backup BEFORE a version upgrade, not after it."
        log "Stop this container now if this node has no current backup."
        log "----------------------------------------------------------------"
        php artisan migrate --force --no-interaction
    fi

    # Generate what Meridian owns and refuse what it does not (technical spec
    # 7.4, 26.2). This runs after migrations because the node keypair is stored
    # as node configuration, and before the config cache because a generated key
    # has to be in the cache the served requests read. `set -e` stops the
    # container when a secret it cannot generate is still a sample value, which
    # is the point: a node that will not serve should not look started.
    php artisan meridian:secrets --generate --no-interaction

    # Caching after the environment is injected rather than at build time, because
    # a cache built during `docker build` would freeze the builder's environment
    # into the image and serve it to every deployment that ran it.
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache

    # The event-mode fail-closed checks (technical spec 8.2, 8.6, 26.2): HTTPS
    # validation and the offline read set. After the caches, so what is
    # validated is the cached configuration and route set the served requests
    # will read. `set -e` stops the container on a failing check, which is the
    # fail-closed working: a node that refuses event mode stops at start with
    # one legible line instead of coming up and answering 503 to everything.
    php artisan meridian:event-mode --no-interaction
fi

exec "$@"
