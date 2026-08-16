# syntax=docker/dockerfile:1

# Meridian deployment images.
#
# Technical spec section 4 asks each target for a built distribution artifact,
# and names the server's as a "Docker image / install bundle with
# apps/client/dist/admin". This file builds it, plus the reverse proxy image that
# terminates TLS in front of it (technical spec 5.1, 8.2).
#
# Build context is the repository root, because the server image needs both
# apps/server and the client artifact built from apps/client:
#
#   docker build -f deploy/docker/Dockerfile --target server .
#   docker build -f deploy/docker/Dockerfile --target web .
#
# Targets:
#   server  php-fpm running the Laravel server, with the Meridian Admin client
#           artifact and the packaged technician documentation.
#   web     Caddy holding the same public root and proxying PHP to `server`.
#
# The Field and Kiosk client artifacts are deliberately absent. They are
# packaged into the mobile and Electron installers instead (technical spec 4),
# and a server image carrying them would ship two artifacts nothing serves.
#
# There is no PowerSync service. ADR-0003 retired it: devices fetch the offline
# read set from the Laravel server itself (`GET /api/offline-read-set`) and queue
# writes back through the same node, so the deployment has no sync service to
# run.

# ---------------------------------------------------------------------------
# The PHP runtime both server-side stages share.
# ---------------------------------------------------------------------------
FROM php:8.5-fpm-alpine AS php-base

# gd decodes and resizes Field Report photos and staff profile pictures; zip and
# the bundled SimpleXML read spreadsheet imports (composer.json requires both
# explicitly); pdo_pgsql reaches PostgreSQL; bcmath matches the extension set CI
# tests against; pcntl lets `queue:work` handle restart and timeout signals rather
# than being killed.
#
# Zend OPcache is deliberately absent from this list: php:8.5-fpm-alpine already
# has it built in, and asking docker-php-ext-install for it produces a build with
# nothing to compile and an install step that fails on an empty modules directory.
# It is configured below rather than installed here.
RUN set -eux; \
    apk add --no-cache \
        freetype \
        libjpeg-turbo \
        libpng \
        libpq \
        libzip; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        postgresql-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        pcntl \
        pdo_pgsql \
        zip; \
    apk del --no-network .build-deps

# Opcache settings for a deployed node. `validate_timestamps=0` is safe because
# the code in the image never changes under a running container: a new build is a
# new image.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/meridian-opcache.ini

# Uploaded Field Report photos and spreadsheet imports arrive through PHP, so the
# request body limits belong to the runtime rather than to the proxy alone.
RUN { \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=32M'; \
        echo 'post_max_size=40M'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/meridian-runtime.ini

# The image mirrors the monorepo's own layout rather than flattening the server
# into a web root, and that is load-bearing rather than tidiness. The server
# resolves its build version and its license from the root `package.json` at
# `base_path('../..')` and throws when it is absent (App\Support\
# RootPackageVersion, RootPackageLicense, both surfaced by GOD-032/GOD-033 and the
# health endpoint), and it resolves the client artifact from
# `base_path('../client/dist/admin')`. Keeping `apps/server` at the same depth
# makes every one of those relative paths correct with no override to maintain.
WORKDIR /var/www/meridian/apps/server

# ---------------------------------------------------------------------------
# Composer dependencies, resolved by the same PHP the server runs.
# ---------------------------------------------------------------------------
# Installing on php-base rather than on the composer image means the
# `php: >=8.5 <8.6` platform requirement in apps/server/composer.json is checked
# against the runtime that will actually execute the code.
FROM php-base AS vendor

COPY --from=composer:2.10 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# Manifests first: dependencies change far less often than application code, and
# this keeps their layer cached across ordinary source edits.
COPY apps/server/composer.json apps/server/composer.lock ./

# `--no-scripts` because the package discovery and autoload scripts need the
# application tree, which arrives in the next layer.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-progress

COPY apps/server ./

# The root manifest, at the depth the server resolves it from. It is needed here
# and not only in the final image, because the autoload script below boots the
# application and the boot reads the version out of it.
COPY package.json /var/www/meridian/package.json

# `post-autoload-dump` runs `package:discover` and `orchid:publish`, which boot
# the application. The sample environment stands in for the deployment's real one
# just long enough for that boot to have configuration to read, and is deleted in
# the same layer so no environment file is ever part of a shipped image.
RUN cp .env.example .env \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && composer run-script post-autoload-dump --no-dev \
    && rm .env

# ---------------------------------------------------------------------------
# The Meridian Admin client artifact.
# ---------------------------------------------------------------------------
FROM node:24-alpine AS client

WORKDIR /build

# Every workspace manifest is copied even though only the client is built,
# because `--frozen-lockfile` compares the lockfile against the whole workspace
# and reports a mismatch when a project the lockfile knows about is missing from
# the context.
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
COPY apps/client/package.json apps/client/
COPY apps/kiosk/package.json apps/kiosk/
COPY apps/mobile/package.json apps/mobile/
COPY packages/ui-tokens/package.json packages/ui-tokens/

# The filter installs the client and the workspace packages it depends on, and
# nothing else: an unfiltered install would fetch the Electron and Capacitor
# toolchains to build a browser bundle that uses neither.
RUN corepack enable \
    && corepack pnpm install --frozen-lockfile --filter "@meridian/client..."

COPY packages/ui-tokens packages/ui-tokens/
COPY apps/client apps/client/

# `build:admin` rather than `build`: the server serves the Admin artifact, and
# the type check that the full `build` script runs first belongs to CI, which
# runs it against every pull request, rather than to every image build.
RUN corepack pnpm --filter @meridian/client run build:admin

# ---------------------------------------------------------------------------
# Target: server
# ---------------------------------------------------------------------------
FROM php-base AS server

# The whole tree, which is the application plus the root manifest beside it. The
# manifest is the only thing from the repository root that a running server needs:
# it reads the build version and the license out of it (GOD-032, GOD-033) and
# throws when it is absent.
COPY --from=vendor /var/www/meridian /var/www/meridian

# Where config/meridian.php resolves the client artifact from, so no override is
# needed. The Field and Kiosk artifacts are deliberately not here.
COPY --from=client /build/apps/client/dist/admin /var/www/meridian/apps/client/dist/admin

COPY deploy/docker/entrypoint.sh /usr/local/bin/meridian-entrypoint

RUN chmod +x /usr/local/bin/meridian-entrypoint \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# The client dev-server default follows APP_ENV, and a deployed image serves the
# built artifact whatever the environment says.
ENV MERIDIAN_CLIENT_USE_DEV_SERVER=false

EXPOSE 9000

ENTRYPOINT ["meridian-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Target: web
# ---------------------------------------------------------------------------
FROM caddy:2-alpine AS web

# The public root is copied at the same path the server image holds it, because
# `php_fastcgi` sends the server a SCRIPT_FILENAME built from Caddy's own root.
# A path that resolves in the proxy but not in the PHP container produces a
# "Primary script unknown" error rather than a page.
COPY --from=vendor /var/www/meridian/apps/server/public /var/www/meridian/apps/server/public

# Every configuration, and the snippet they import. `MERIDIAN_CADDYFILE`
# chooses which one runs: an event node runs Caddyfile.onsite, a node serving
# organization subdomains runs Caddyfile.wildcard, and a node behind a
# TLS-terminating platform proxy runs Caddyfile.proxied.
COPY deploy/caddy/Caddyfile deploy/caddy/Caddyfile.onsite deploy/caddy/Caddyfile.wildcard deploy/caddy/Caddyfile.proxied deploy/caddy/meridian.snippet /etc/caddy/

# Ask Caddy whether its own configuration parses, at build time, so a malformed
# Caddyfile is a failed build rather than a container that will not start on an
# event network. `adapt` converts the Caddyfile to Caddy's JSON configuration,
# which means every directive is parsed by the module that owns it: a misspelled
# directive, a subdirective a directive does not accept, and an imported snippet
# that is never defined all fail here.
#
# `adapt` rather than `validate` because validate also provisions the modules,
# and provisioning the event-node configuration means loading a certificate that
# exists only on an event node. The values below exist only to satisfy
# substitution during the parse; nothing is kept.
RUN set -eux; \
    for config in /etc/caddy/Caddyfile /etc/caddy/Caddyfile.onsite /etc/caddy/Caddyfile.wildcard /etc/caddy/Caddyfile.proxied; do \
        MERIDIAN_SITE_ADDRESS=meridian.example.org \
        MERIDIAN_SERVER_UPSTREAM=server:9000 \
        MERIDIAN_ACME_EMAIL=meridian@example.org \
        MERIDIAN_TLS_CERTIFICATE=/etc/caddy/tls/node.crt \
        MERIDIAN_TLS_KEY=/etc/caddy/tls/node.key \
        MERIDIAN_WILDCARD_TLS_CERTIFICATE=/etc/caddy/tls/wildcard.crt \
        MERIDIAN_WILDCARD_TLS_KEY=/etc/caddy/tls/wildcard.key \
        caddy adapt --config "$config" --adapter caddyfile > /dev/null; \
    done

# Laravel writes the built client's assets through PHP, so the proxy serves only
# the server's own public files (favicon, robots.txt, console CSS and images) and
# hands everything else to php-fpm.
EXPOSE 80 443
