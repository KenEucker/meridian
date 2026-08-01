# Docker Deployment

This directory contains the managed Meridian **database service**: a Dockerized
PostgreSQL 18 instance that runs the canonical Meridian database and provisions
the prerequisites every dependent system needs to run.

PostgreSQL is the canonical Meridian server database (data/API specification
section 3.1). This service exists so the database can be brought up, configured,
and managed consistently instead of relying on an ad-hoc `docker run`. It is the
recommended way to run PostgreSQL for local development, and it is the base the
PowerSync service (`deploy/powersync`) and the Laravel server (`apps/server`)
connect to.

The broader multi-service deployment bundle (server container, Caddy, DNS, and a
single top-level Compose file) is separate and still tracked as later
deployment work. This directory owns the database service only.

## What it provisions

On first boot (while the data volume is empty) the container:

- runs `postgres:18` with `wal_level=logical`, which PowerSync's logical
  replication requires;
- creates the canonical `meridian` database and application role from the
  standard `postgres` image environment variables;
- creates a least-privilege `powersync_replication` role (login + replication)
  with read-only access to the canonical database, including default privileges
  so tables the Laravel migrations create later remain readable;
- creates a dedicated `powersync_storage` database and owner role for PowerSync
  bucket storage;
- creates the `powersync` publication (`FOR ALL TABLES`) that PowerSync consumes.

These are the "requisite items for all systems to run": the roles, databases,
replication settings, and publication that PowerSync (and later services) depend
on. The provisioning logic lives in
[`postgres/initdb/01-powersync-prerequisites.sh`](postgres/initdb/01-powersync-prerequisites.sh).

## Files

- `compose.yaml` runs the `postgres:18` database service with logical
  replication enabled.
- `postgres/initdb/01-powersync-prerequisites.sh` provisions PowerSync roles,
  the storage database, grants, and the publication on first boot.
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

The container provides the database and its system prerequisites. The Meridian
schema and seed data are owned by the Laravel migrations and seeders, so run them
with the existing server tooling against this database:

```bash
# From the repository root, with the database service running:
php apps/server/artisan migrate
php apps/server/artisan db:seed
```

`db:seed` loads the permission catalog and the development scenario (Idaho
Burners / Emberfall 2026) described in `apps/server/README.md`. The
`powersync` publication is defined `FOR ALL TABLES`, so the tables created by
`migrate` are included automatically without any further configuration.

## Connect PowerSync

The roles, storage database, and publication this service creates line up with
the connection URIs in `deploy/powersync/.env.example`. Set the PowerSync
environment so its credentials match the values in `deploy/docker/.env`:

```env
# deploy/powersync/.env
PS_DATA_SOURCE_URI=postgresql://powersync_replication:powersync_replication@host.docker.internal:5432/meridian
PS_BUCKET_STORAGE_URI=postgresql://powersync_storage:powersync_storage@host.docker.internal:5432/powersync_storage
```

`host.docker.internal` works when PowerSync reaches the published `5432` port on
the host (macOS and Windows, or Linux with `host.docker.internal` mapped).
Alternatively, attach the PowerSync stack to this service's `meridian` network as
an external network and connect to the `postgres` hostname on port `5432`.

> The PowerSync client JWT/JWKS endpoint is still not implemented in the server,
> so `PS_JWKS_URI` remains a configuration placeholder until that work lands. See
> `deploy/powersync/README.md`.

## Data and reset

Database contents persist in the named `meridian-postgres-data` volume. The
first-boot provisioning script only runs while that volume is empty. To
re-provision from scratch (this destroys all local data):

```bash
corepack pnpm run db:reset
# or: docker compose --env-file deploy/docker/.env -f deploy/docker/compose.yaml down -v
```

## Security notes

- The committed `.env.example` values are development-only. Replace every
  password for event or production deployments and never commit
  `deploy/docker/.env`.
- The example connections use unencrypted local traffic. For any deployment
  outside a private development network, terminate TLS in front of PostgreSQL (or
  enable server TLS) and set the PowerSync `sslmode` values to `verify-full`.
