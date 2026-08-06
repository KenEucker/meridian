# ADR-0002: Audit volume controls

## Status

Accepted

## Context

`audit_events` is append-only by design. The model refuses updates and deletes,
every foreign key is `restrict on delete` so an audit row prevents the silent
destruction of the actor or scope it names, requirements 2.4 asks that important
changes preserve history, and development process 7.5 says Meridian generally
should not destroy operational history.

Nothing in the requirements, technical spec, or data/API specification defines a
retention policy for it. The table therefore grows for the life of a deployment,
across every organization the node hosts, and there was no way to see how large
it had become, no way to influence what went into it, and no way to bound it.

Two things made this urgent rather than theoretical when the audit surfaces were
built (M18.29, M18.34):

- Reads by organization and department were sequential scans. PostgreSQL does
  not index a foreign key column, and `foreignUuid()->constrained()` had left
  `organization_id`, `department_id`, `event_id`, and `actor_user_id` unindexed.
  Nothing read the table by those columns until the audit surfaces did.
- The largest single writer is `incident.viewed`, one row per opened incident
  per reader, and data/API section 8 requires auditing sensitive incident reads.
  Volume was going to arrive from a source no configuration could switch off.

## Decision

Four controls, in the order they take effect.

**1. Index the columns the surfaces read.** `(organization_id, created_at)` for
the scoped, time-ordered read both surfaces make, plus `department_id`,
`event_id`, and `actor_user_id`. The product surface's filter-option query,
which was a `DISTINCT` over an organization's whole history on every page turn,
is cached for a minute.

**2. Configure how much is written, per organization.** Five ordered verbosity
levels — Minimal, Low, Standard (the default), Detailed, Complete — with an
action catalogued at the level it first appears at, plus per-action overrides
for the exceptions. `AuditActionCatalog::REQUIRED` is a floor no level and no
override reaches below, holding everything requirements 2.4 and data/API section
8 oblige. An action the catalogue does not know is written at every level,
because actions are composed at runtime in several places and a gap in the
catalogue must not become missing history.

**3. Configure how much is kept, per organization.** Maximum rows, maximum
estimated size, and a retention window in days; every one null by default,
meaning unbounded, which is what every organization has today. Reaching a limit
does not delete: `AuditArchivalService` writes the oldest rows to a JSON Lines
archive with every column including both payloads, records the archival as its
own audit entry — `audit.archived`, which is in the required floor — and only
then removes them, inside a scoped escape that is the single path in the
application permitted to remove an audit row. The order is the point: a crash
leaves an archive with no deletion, never a deletion with no archive.

**4. Partition by month.** PostgreSQL declarative range partitioning on
`created_at`, on by default, so time-ordered reads prune to the months they
touch and a month can later be detached and archived without a delete running
against live rows. A default partition catches any row whose timestamp falls
outside the created months, because a partitioned table refuses such a row and
an audit write that fails is a change nobody recorded.

All four controls are configured from God Mode only for now. Deciding what an
organization records is a different kind of decision from the ones on the
organizer configuration surface: getting it wrong does not inconvenience an
organization, it costs it the record of what it did.

## Consequences

- **Audit rows can now be removed**, which was not previously true. The scope is
  narrow — one service, behind a scoped escape, only for a configured limit,
  always after an archive and a record — but the invariant has changed from "no
  path exists" to "one path exists and is audited". This is the part of the
  decision most worth revisiting if it proves uncomfortable.
- **An organization can now record less than it did.** The floor means it cannot
  record less than it is obliged to, and the biggest single writer is inside the
  floor, so the realistic saving is routine operational noise rather than the
  bulk. Verbosity is not the lever for the largest contributor; retention and
  partitioning are.
- **A partitioned table loses database-enforced uniqueness on `id` alone.** The
  primary key becomes `(id, created_at)`, because PostgreSQL requires the
  partition key in every unique constraint. `id` remains unique in practice
  because it is a UUID.
- **`created_at` becomes not-null.** A partition key cannot be null; existing
  nulls are filled with the epoch rather than dropped.
- **SQLite deployments get controls 1 to 3 and not 4.** The test suite runs on
  SQLite, so the partitioning path is verified by running the audit tests
  against PostgreSQL directly; the assertions answer for whichever driver they
  find.
- **Attaching a new monthly partition has to check the default partition** for
  rows belonging to it, which is slow on a large default partition. That is the
  cost of never losing a write.

## Requirements affected

Requirements 2.4 Auditability — extended rather than contradicted: history is
still preserved, but "preserved" now admits an archive file alongside the table.
A retention policy did not previously exist in any governing document.

## Technical spec sections affected

Data/API specification section 8 (audit model) and section 14.1
(`audit_events`); section 10.1 (`organizations`) gains the five configuration
columns.
