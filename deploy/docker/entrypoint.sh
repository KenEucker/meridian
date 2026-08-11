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
# What this does not do is generate or refuse secrets. Those safeguards belong to
# the server itself (technical spec 26.2, task M19.3) so that they hold for every
# way a node is started, not only for the containerized one.

set -eu

log() {
    echo "meridian: $1"
}

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

    # Caching after the environment is injected rather than at build time, because
    # a cache built during `docker build` would freeze the builder's environment
    # into the image and serve it to every deployment that ran it.
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
fi

exec "$@"
