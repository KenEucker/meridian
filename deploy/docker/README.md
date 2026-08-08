# Docker Deployment

This directory contains the managed Meridian **database service**: a Dockerized
PostgreSQL 18 instance that runs the canonical Meridian database.

PostgreSQL is the canonical Meridian server database (data/API specification
section 3.1). This service exists so the database can be brought up, configured,
and managed consistently instead of relying on an ad-hoc `docker run`. It is the
recommended way to run PostgreSQL for local development, and it is the base the
Laravel server (`apps/server`) connects to.

The broader multi-service deployment bundle (server container, Caddy, DNS, and a
single top-level Compose file) is separate and still tracked as later
deployment work. This directory owns the database service only.

## What it provisions

On first boot (while the data volume is empty) the container:

- runs `postgres:18` with `wal_level=logical`, keeping logical replication
  available for node-to-node sync (technical spec section 10);
- creates the canonical `meridian` database and application role from the
  standard `postgres` image environment variables.

That is all it provisions. Until M18.51 it also created a `powersync_replication`
role, a `powersync_storage` database and owner role, and a `powersync`
publication `FOR ALL TABLES`. ADR-0003 retired PowerSync, and all three existed
only to serve it, so they are gone; the `wal_level` setting stays because node
sync is a separate mechanism that was never PowerSync's.

Devices no longer replicate from the database at all. They fetch the offline
read set from the Laravel server (`GET /api/offline-read-set`), which composes it
through the same authorization the rest of the API answers from.

## Files

- `compose.yaml` runs the `postgres:18` database service with logical
  replication enabled.
- `.env.example` documents the required configuration and development defaults.

## Prerequisites

- Docker with Docker Compose.

## Configure and start

The root `package.json` provides `db:*` scripts that wrap Docker Compose with the
correct `--env-file` and `-f` flags. From the repository root:

```bash
corepack pnpm run db:setup   # create deploy/docker/.env (if missing) and start, waiting for healthy
corepack pnpm run db:ps      # show service status
corepack pnpm run db:logs    # follow logs
corepack pnpm run db:down    # stop the service (data is preserved)
corepack pnpm run db:reset   # stop and delete the data volume (destroys local data)
corepack pnpm run db:config  # validate the resolved Compose configuration
```

`db:setup` runs `db:env` (which copies `.env.example` to `.env` only if it does
not already exist) and then `db:up`. The committed defaults work for local
development; replace every password with a strong secret for event or production
deployments.

The equivalent raw commands are:

```bash
cp deploy/docker/.env.example deploy/docker/.env
docker compose --env-file deploy/docker/.env \
  -f deploy/docker/compose.yaml up -d --wait
docker compose --env-file deploy/docker/.env \
  -f deploy/docker/compose.yaml ps
```

The database is exposed on `127.0.0.1:5432` by default, matching the
`DB_HOST`/`DB_PORT` defaults in `apps/server/.env.example`.

## Load the schema and development data

The container provides the database. The Meridian schema and seed data are owned
by the Laravel migrations and seeders, so run them with the existing server
tooling against this database:

```bash
# From the repository root, with the database service running:
php apps/server/artisan migrate
php apps/server/artisan db:seed
```

`db:seed` loads the permission catalog and the development scenario (Idaho
Burners / Emberfall 2026) described in `apps/server/README.md`.

## Data and reset

Database contents persist in the named `meridian-postgres-data` volume. To start
over from an empty database (this destroys all local data):

```bash
corepack pnpm run db:reset
# or: docker compose --env-file deploy/docker/.env -f deploy/docker/compose.yaml down -v
```

## Security notes

- The committed `.env.example` values are development-only. Replace every
  password for event or production deployments and never commit
  `deploy/docker/.env`.
- The example connections use unencrypted local traffic. For any deployment
  outside a private development network, terminate TLS in front of PostgreSQL or
  enable server TLS.
