# ADR-0003: Retire PowerSync in favor of scoped offline read sets

## Status

Proposed

## Context

Technical spec section 9.1 names PowerSync as the chosen device sync layer for
Alpha 1, and the technology baseline pins both the service (1.22.x) and the
JavaScript/Web Client SDK (1.38.x). Section 9.3 then describes what each role's
device should hold offline, and section 9.5 requires that set be bounded by the
user's effective roles and the organization's active modules.

Three milestones built the server half. M8.1 established the self-hosted
service, M8.2 defined the authorized device cache projections, and M16.13 held
those projections to the caller's effective roles.
`Tests\Feature\PowerSyncPermissionScopedReplicationTest` runs the shipped
streams against a database and proves the boundary as behavior: a designated
lead receives the roster their grant covers, an undesignated member of the same
granted team receives none of it, an event-scoped grant reaches only its own
event, a demoted lead stops receiving what they previously held.

No milestone was ever written for the client half. The word "SDK" does not
appear in the technical spec or the development plan. No task installs
`@powersync/web`, defines a local schema, issues a client JWT, or publishes a
JWKS endpoint. The gap was recorded honestly and repeatedly — traceability
matrix rows for sections 9.1-9.3 and 9.5, `deploy/powersync/README.md`, and the
About surface all say no device replicates yet — but always as "deferred to its
owning task", and that task does not exist. It also never reached the excluded
list, so it was never descoped either.

Meanwhile each milestone that needed offline behavior built its own seam: a
`localStorage` field report record (M9.2), the shared command outbox (M16.10),
encrypted pending photo storage (M9.8), and a sixty-entry read cache. Section
11A.5 was added on 2026-07-28 and describes the outbox that was implemented on
2026-07-30 — ten days after the hand-rolled queues it consolidates. The
specification followed the implementation rather than the reverse.

Two things became clear when the gap was examined rather than deferred again.

**PowerSync's write half does not apply to Meridian.** Its value is the
local-first loop: local SQLite is the working state, the application reads and
writes to it, and the engine reconciles. Meridian is server-authoritative —
Laravel owns validation, authorization, numbering, and audit, and the client
submits commands. There is no local transaction for the CRUD queue to operate
on. Section 11A.5 also requires behavior the CRUD queue does not attempt: a
rejected command held with the node's reason until a person dismisses it, and a
connected-only command refused at queue time rather than queued and rejected
later. Both were surveyed against `workbox-background-sync` and TanStack Query's
persisted mutations; neither covers them, and TanStack Query's paused-mutation
path carries a long history of open resumption defects. The outbox is the better
implementation for this architecture, and it already exists and is tested.

**`sync-config.yaml` is a second implementation of the authorization model.**
It expresses in a restricted SQL dialect what `App\Services\Permissions\EffectiveRoleResolver`
expresses in PHP for more than a dozen access services. The two must agree
forever. The dialect forbids `EXISTS`, forbids parameter queries that reference
another CTE, and — as `deploy/powersync/README.md` documents — surfaces some
errors only at replication time rather than at test time. Carrying that
permanently to obtain read caching is a poor trade, and it is the failure mode
least likely to be noticed before it reaches a device.

What remains true is the requirement. Section 9.3 exists because a volunteer at
an event with no signal needs their shift, their team, and the policies they are
held to. The current read cache stores only what its user already opened while
online, holds sixty entries in `localStorage`, and drops on sign-out. It cannot
grow into section 9.3, and section 9.3's department-scoped *searchable* staff,
equipment, and shift indexes cannot be served from a cache of page responses at
all.

## Decision

Retire PowerSync. Replace it with scoped offline read sets served by Laravel,
and keep the command outbox as the write path.

**1. The server composes the offline read set.** An authenticated endpoint
returns the section 9.3 set for the caller, resolved through the same
`EffectiveRoleResolver` every API read already uses, and bounded by the
organization's active modules as section 9.5 requires. One authorization model,
in PHP, exercised by the same tests as everything else. The set is versioned so
an unchanged one costs a 304 rather than a transfer.

**2. The client stores it proactively.** Fetched on login, on reconnect, and on
context switch; dropped on sign-out, context switch, and shared-workstation
session end through the registry the session cache and outbox already use. The
staleness rule is the one section 11A.4 already sets for the permission cache:
usable for the duration of the event the node is locked to.

**3. The storage layer is chosen by measurement, not in advance.** The
regular-staff set is small and blob-shaped; the Logistics searchable indexes may
not be. The first two tasks measure real payloads against seeded event data, and
that measurement decides between plain IndexedDB with in-memory filtering and
RxDB with its free Dexie storage adapter and pull-only replication over these
same endpoints. RxDB is Apache 2.0 at the core; its IndexedDB, OPFS, SQLite, and
Filesystem adapters are premium at $99–$239/month billed annually, so adopting
it commits only to the free adapter unless a later decision says otherwise.

**4. Writes do not change.** The outbox keeps the section 9.4 offline write
scope. A device-generated record and its accepted counterpart share a primary
key — `field_reports` has been device-UUID-keyed since M9.1 — so the read models
union local pending rows with replicated rows on an identifier match rather than
a heuristic.

**5. The scope tests are ported, not deleted.** `Tests\Support\PowerSyncRules`
and the scope assertions in `PowerSyncPermissionScopedReplicationTest` and
`PowerSyncDeviceCacheProjectionTest` encode decisions that remain correct under
any transport. They move onto the read-set endpoint. The rules YAML is retired;
what it proved is not.

## Consequences

- **Incremental replication is lost.** The read set refreshes whole rather than
  by delta. Version/ETag negotiation keeps an unchanged set cheap, and this data
  is low-churn for the duration of an event, but a large set on a weak
  connection is the failure mode to watch. It is the reason payload measurement
  is a task and not an assumption.
- **A second authorization implementation goes away.** This is the main win.
  Every future permission change is made once, in PHP, and is wrong in one place
  at most.
- **The operational surface shrinks substantially.** No PowerSync service, no
  logical replication slot, no dedicated bucket-storage database, no JWKS
  endpoint, no client JWT issuance, and no client schema tracking every
  projection change. `deploy/docker` keeps logical replication available for
  node sync but stops provisioning PowerSync's role, database, and publication.
- **Event mode loses a readiness check.** `PowerSyncCheck` currently fails event
  mode closed on a service no client connects to, which asserts a dependency
  that does not exist. It is replaced by a check on the read-set endpoint, which
  is the thing offline readiness actually depends on.
- **Two deferred connectivity states lose the milestone they were waiting on.**
  `apps/client/src/offline/useConnectivity.ts` defers `local_node_reachable` and
  `central_unreachable` to "PowerSync client sync state and Meridian node-sync
  signals owned by later Alpha 1 milestones". Retiring PowerSync removes half of
  that, and these states are how the client expresses the distinction between a
  write that needs any node and a write that needs central. M18.52 picks them
  up. Left unowned, this repeats the failure this ADR documents.
- **Meridian keeps no live-update path for read data.** PowerSync would have
  pushed changes while connected. Connected clients continue to read from the
  API as they do today, so this affects only how quickly an offline device's
  stored set goes stale — bounded by refresh on reconnect.
- **The Alpha 1 baseline loses a pinned dependency and may gain one.** Rows for
  PowerSync Service 1.22.x and the Web Client SDK 1.38.x are removed. RxDB is
  added only if measurement selects it.
- **Section 9.3 becomes real for the first time.** No device has ever replicated
  anything. Whatever this ships is strictly more offline capability than exists
  today, not a regression from a working system.

## Requirements affected

CLIENT-021 and CLIENT-022 are preserved, not weakened. CLIENT-021 — a device
never holds records its user could not retrieve through the API — becomes easier
to hold, because the set is built by the resolver that answers that question for
every other read. CLIENT-022 — a role change changes what subsequently
replicates — holds because the set is composed per request against live grants
and carries nothing in a token.

MOD-016 module scoping is preserved for the same reason.

No product behavior changes. No requirement is relaxed.

## Technical spec sections affected

- Section 9.1 chosen sync layer, and 9.2 sync responsibility: PowerSync is
  replaced as the server-to-device mechanism. Node-to-node sync (section 10) is
  untouched and was always separate.
- Section 9.3 client data model: the lists stand unchanged; only what delivers
  them changes.
- Section 9.5 permission scoping of sync rules, and section 11A.7
  permission-scoped replication: rewritten from sync rules to read-set
  composition, with the boundary and its consequences unchanged.
- Section 8.6 and 26.2 event-mode fail-closed: the PowerSync probe becomes the
  read-set probe.
- Section 22A.8 diagnostics (SYS-033 sync category): `sync.powersync` is
  replaced.
- Technology baseline: offline sync service and offline sync web/client SDK rows
  removed.

Section 9.4 offline write scope is unaffected.
