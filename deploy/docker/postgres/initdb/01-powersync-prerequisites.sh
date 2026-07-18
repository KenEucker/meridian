#!/usr/bin/env bash
#
# Provision the database prerequisites that dependent Meridian systems require.
#
# This runs once, on first boot, while the PostgreSQL data volume is empty. It
# creates:
#   - a least-privilege logical-replication role for the PowerSync data source;
#   - a dedicated bucket-storage database and owner role for PowerSync;
#   - read-only grants (including default privileges for future tables created
#     by the Laravel migrations) for the replication role;
#   - the logical replication publication PowerSync consumes.
#
# The canonical database, application role, and password are created by the
# postgres image from POSTGRES_DB/POSTGRES_USER/POSTGRES_PASSWORD before this
# script runs.
set -euo pipefail

: "${POWERSYNC_REPLICATION_USER:=powersync_replication}"
: "${POWERSYNC_REPLICATION_PASSWORD:?POWERSYNC_REPLICATION_PASSWORD must be set}"
: "${POWERSYNC_STORAGE_USER:=powersync_storage}"
: "${POWERSYNC_STORAGE_PASSWORD:?POWERSYNC_STORAGE_PASSWORD must be set}"
: "${POWERSYNC_STORAGE_DB:=powersync_storage}"
: "${POWERSYNC_PUBLICATION:=powersync}"

psql -v ON_ERROR_STOP=1 \
  --username "$POSTGRES_USER" \
  --dbname "$POSTGRES_DB" \
  --set=repl_user="$POWERSYNC_REPLICATION_USER" \
  --set=repl_password="$POWERSYNC_REPLICATION_PASSWORD" \
  --set=storage_user="$POWERSYNC_STORAGE_USER" \
  --set=storage_password="$POWERSYNC_STORAGE_PASSWORD" \
  --set=storage_db="$POWERSYNC_STORAGE_DB" \
  --set=publication="$POWERSYNC_PUBLICATION" \
  --set=app_user="$POSTGRES_USER" \
  --set=app_db="$POSTGRES_DB" <<'EOSQL'
\echo 'Provisioning PowerSync database prerequisites...'

-- Least-privilege logical-replication role used by the PowerSync data source.
-- psql does not interpolate :variables inside dollar-quoted blocks, so roles are
-- created with the same format()/\gexec pattern used for the database below.
SELECT format('CREATE ROLE %I WITH REPLICATION LOGIN PASSWORD %L', :'repl_user', :'repl_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'repl_user')\gexec

-- Dedicated owner role for the PowerSync bucket-storage database.
SELECT format('CREATE ROLE %I WITH LOGIN PASSWORD %L', :'storage_user', :'storage_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'storage_user')\gexec

-- Bucket storage must live in its own database, even on a shared server.
SELECT format('CREATE DATABASE %I OWNER %I', :'storage_db', :'storage_user')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'storage_db')\gexec

-- Give the replication role read-only visibility into the canonical database.
GRANT CONNECT ON DATABASE :"app_db" TO :"repl_user";
GRANT USAGE ON SCHEMA public TO :"repl_user";
GRANT SELECT ON ALL TABLES IN SCHEMA public TO :"repl_user";

-- Ensure tables created later by the Laravel migrations (owned by the app role)
-- are also readable by the replication role.
ALTER DEFAULT PRIVILEGES FOR ROLE :"app_user" IN SCHEMA public
  GRANT SELECT ON TABLES TO :"repl_user";

-- Publication PowerSync consumes. FOR ALL TABLES automatically includes tables
-- the Laravel migrations create after this init script runs.
SELECT format('CREATE PUBLICATION %I FOR ALL TABLES', :'publication')
WHERE NOT EXISTS (SELECT FROM pg_publication WHERE pubname = :'publication')\gexec

\echo 'PowerSync database prerequisites are ready.'
EOSQL
