# PowerSync Deployment

This directory contains the self-hosted PowerSync service baseline from M8.1,
the authorized device cache projections from M8.2, and the permission scoping
those projections are held to from M16.13.
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

The managed database service under [`deploy/docker`](../docker/README.md)
satisfies the PostgreSQL prerequisites out of the box: it enables logical
replication and provisions the `powersync_replication` role, the
`powersync_storage` database/user, and the `powersync` publication. Point the
connection URIs below at that service and set the credentials to match
`deploy/docker/.env`.

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

### Sync-rules SQL constraints

`sync-config.yaml` uses `config: edition: 3`. The PowerSync sync-rules SQL dialect
is more restrictive than PostgreSQL, and the same construct can be valid in one
position but not another. When editing the sync streams, keep these rules in mind
(the file's header comment documents them inline):

- Parameter (`with`) queries must be self-contained. They may reference
  `auth.user_id()` and base tables (including nested `IN (SELECT ...)`
  subqueries), but must not reference another named CTE. A bareword
  `IN other_cte` inside a `with` query compiles to a broken `json_each()` lookup
  and fails at replication time, so each CTE re-derives the current user's
  staff/team/department scope directly from `staff_user`.
- Data queries (inside `queries:`) may reference named CTEs with `IN cte_name`,
  may use `INNER JOIN` (all selected columns must come from a single table, with
  simple equality join conditions), and may use nested subqueries.
- `EXISTS(...)` is not supported anywhere; express correlated checks as `INNER
  JOIN`s in data queries instead.

Because some of these errors only surface at replication time (not at YAML load),
validate changes by running the service against a real PostgreSQL source (for
example the [`deploy/docker`](../docker/README.md) database service) and
confirming the logs report no `Failed to update sync config`, `Fatal replication
error`, or `Failed to evaluate parameter query ... json_each` messages.

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

## Permission-scoped replication

Offline data replicated to a device is limited to what the device's user is
permitted to read (CLIENT-021; technical spec 9.5, 11A.7; data/API 7.3). The
cache lists in technical spec 9.3 say what a role *should* receive; this is the
boundary on what it *may* receive, and the projections below are held to it.

Scope comes from the caller's effective roles, resolved the way
`App\Services\Permissions\EffectiveRoleResolver` resolves them for the API:

- the grant must be active — a revoked grant scopes nothing;
- the user must hold an active, unarchived membership in the granted team;
- an event-scoped grant reaches only that event's records;
- a `shift_lead` grant reaches only members designated `membership_role = 'lead'`
  in the granted team (TEAM-009). The grant is held by the team and exercised by
  its designated leads, so bare membership of a granted team replicates nothing
  the member could not already read.

Every one of those conditions is evaluated against the database on each
evaluation rather than carried in the client's token. That is what makes
CLIENT-022 hold: revoking a grant, removing a lead designation, or archiving a
membership changes what subsequently replicates, without waiting for the device
to sign in again or for a token to expire.

`Tests\Feature\PowerSyncPermissionScopedReplicationTest` proves this by running
the streams in `sync-config.yaml` against a database — including a demoted lead
who stops receiving the roster they previously held — so a change to the rules
that widened a scope would fail a test rather than reach a device. It executes
the rules through the test database's SQL driver, so it proves *scope*, not
dialect validity; validate the dialect against a running service as described
above.

## M8.2 projection boundaries

- Every stream is automatically scoped from the signed JWT `sub` claim through
  `auth.user_id()`. No client-controlled subscription or connection parameter
  grants access.
- Regular staff receive their own current organization/department/team
  memberships, assigned shifts, basic event data, visible published
  policy/procedure content and referenced fragments, and their acknowledgment
  state.
- Designated team leads additionally receive safe roster fields and assignments
  for teams/shifts covered by an active `shift_lead` team grant.
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
- No PowerSync client JWT or JWKS endpoint is implemented. The scoping above is
  written against the signed subject PowerSync would supply; until a node issues
  that credential and a client holds the SDK, no device replicates anything, and
  the boundary is enforced by the rules and their tests rather than in the field.
- Field Report/form, attendance, readiness, incident-cache, and map projections
  remain deferred until their canonical tables and owning tasks exist.

When incident and Field Report projections are added, Name References must
remain text-first derived artifacts. Incident note and Field Report source text
must sync only through authorized projections; any local Name Reference index
must be rebuildable from that source text, must not become a business-rule
engine, and must not expose references from records the active user cannot
otherwise access.
