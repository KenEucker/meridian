# PowerSync Deployment

This directory contains the self-hosted PowerSync service baseline from M8.1
and the initial authorized device cache projections from M8.2.
It connects PowerSync to Meridian's canonical PostgreSQL database, dedicated
PostgreSQL bucket storage, and a JWKS endpoint used to validate client tokens.

PowerSync handles only Meridian server-to-device synchronization. Central and
on-site Meridian nodes use the separate application-level node sync path.
Laravel remains responsible for authorization, validation, auditing, and
accepting all domain-sensitive writes.

## Files

- `compose.yaml` pins `journeyapps/powersync-service:1.22.0`.
- `service.yaml` defines replication, bucket storage, client authentication,
  and logging.
- `sync-config.yaml` defines authenticated regular-staff, shift-lead, and
  department-lead cache streams.
- `.env.example` documents the required non-secret configuration shape.

## Prerequisites

- Docker with Docker Compose.
- A PostgreSQL source connection with logical replication enabled and a
  least-privilege replication role.
- A separate PostgreSQL database/user for PowerSync bucket storage.
- A real JWKS endpoint and matching JWT audience. The sample JWKS URL is a
  configuration placeholder only; M8.1 does not issue PowerSync client tokens
  or publish signing keys.

For local/private networks, the sample uses `sslmode=disable`. Set both SSL
mode values to `verify-full` and provide trusted certificates for any
connection outside a private development network.

## Configure and validate

```bash
cp deploy/powersync/.env.example deploy/powersync/.env
# Replace every replace-me value and configure a reachable JWKS endpoint.
docker compose --env-file deploy/powersync/.env \
  -f deploy/powersync/compose.yaml config
```

The `config` command validates Compose interpolation without starting the
service or printing secrets beyond values already present in the local
environment file. Never commit `deploy/powersync/.env`.

Validate the sync-stream syntax against a configured PowerSync instance with:

```bash
powersync validate \
  --sync-config-file-path=deploy/powersync/sync-config.yaml \
  --validate-only=sync-config
```

After configuring real services:

```bash
docker compose --env-file deploy/powersync/.env \
  -f deploy/powersync/compose.yaml up -d
docker compose --env-file deploy/powersync/.env \
  -f deploy/powersync/compose.yaml ps
```

The PowerSync container reports healthy only after
`/probes/liveness` succeeds. The API is available at
`http://127.0.0.1:8080` by default.

## M8.2 projection boundaries

- Every stream is automatically scoped from the signed JWT `sub` claim through
  `auth.user_id()`. No client-controlled subscription or connection parameter
  grants access.
- Regular staff receive their own current organization/department/team
  memberships, assigned shifts, basic event data, visible published
  policy/procedure content and referenced fragments, and their acknowledgment
  state.
- Shift leads additionally receive safe roster fields and assignments for
  teams/shifts covered by an active `shift_lead` team grant.
- Department leads additionally receive safe department roster/schedule fields
  and documents/fragments they may maintain through an active
  `department_lead` team grant.
- Staff projections deliberately omit email, phone, date of birth, emergency
  contacts, status reasons, and profile-picture storage metadata.
- Incidents, global configuration, audit archives, permission administration,
  exports, and attachment binaries are not projected.

## Deliberate limits

- No client SDK, local SQLite schema, upload queue, or offline mutation path is
  installed.
- No readiness UI or event-mode fail-closed behavior is enabled.
- No PowerSync client JWT or JWKS endpoint is implemented.
- Field Report/form, attendance, readiness, incident-cache, and map projections
  remain deferred until their canonical tables and owning tasks exist.

When incident and Field Report projections are added, Name References must
remain text-first derived artifacts. Incident note and Field Report source text
must sync only through authorized projections; any local Name Reference index
must be rebuildable from that source text, must not become a business-rule
engine, and must not expose references from records the active user cannot
otherwise access.
