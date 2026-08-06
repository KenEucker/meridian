# Meridian Data Model and API Specification

Draft: 0.1
Status: Working Draft  
Scope: Canonical data model, API contract, sync boundaries, node-operation boundaries, and cross-layer consistency.

---

## 1. Purpose

This document defines Meridian's canonical data model and API contract.

It is intended to keep the same concepts, relationships, identifiers, permissions, lifecycle states, sync behavior, and business rules consistent across:

- Laravel backend
- PostgreSQL database
- Meridian Admin shared Vue product UI
- Orchid God Mode / repair tooling
- OpenAPI-described Laravel API
- PowerSync device projections
- local SQLite client databases
- Vue/Capacitor Meridian Field app
- Vue/Electron Meridian Kiosk app
- Electron on-site wrapper
- node-to-node synchronization
- export/reporting layers

This document is intentionally an implementation contract, not an exhaustive migration-by-migration schema. The goal is to define enough model shape for consistent development without over-specifying every database column before implementation.

---

## 2. Source Documents and Alignment Notes

This version aligns the data model with:

- Meridian Requirements Document, Draft v0.3 (including The Briefing additive update)
- Meridian Technical Specification, Draft v0.2 (including section 21B The Briefing)

Important alignment changes from earlier data-model drafts:

1. Teams replace the earlier operational grouping concept of department roles; system authority comes from permission roles/grants assigned through teams.
2. Shifts derive eligibility from team membership, not role membership.
3. The `Roles` module now refers to permission/effective-authority roles, not operational shift roles.
4. Attendance is operation-based and append-only, with derived current state.
5. Field Reports use event-specific FRA numbers, support immutable appends, and may include image attachments.
6. Incidents are online-only for Alpha 1 and are not greedily synced to devices.
7. Policy documents and procedure documents are separate domain modules and persistence models/tables.
8. Fragments are shared by policy and procedure documents.
9. Policy/procedure acknowledgments use a shared model that can reference either document type.
10. Policy/procedure packets are post-Alpha 1 and should not be modeled as an MVP packet entity.
11. Name References are text-first markers in IMS notes and Field Reports with a rebuildable derived index for search/display, not canonical identity data.

---

## 3. Architectural Authority

### 3.1 Canonical Source of Truth

PostgreSQL is the canonical server database.

Laravel migrations define the canonical schema. Laravel Eloquent models represent canonical records. Laravel services/actions/command handlers enforce domain rules.

### 3.2 Laravel Responsibility

Laravel owns:

- canonical migrations
- Eloquent models
- OpenAPI-described API endpoints
- API resources/serializers
- request validation
- authorization enforcement
- domain services/actions
- command acceptance flows
- audit event generation
- PowerSync upload handling
- server-side human-number assignment
- node operation acceptance and application
- sync conflict creation
- export generation

Laravel remains the canonical writer to PostgreSQL. Clients do not directly mutate canonical tables.

### 3.3 Orchid / God Mode Responsibility

Orchid owns permission administration and trusted God Mode / repair tooling.

Orchid provides:

- permission catalog display
- role and grant administration
- team-based grant administration
- direct god-mode/admin assignment where allowed
- repair/admin screens for domain records
- sync conflict review in God mode
- audit log review
- policy/procedure authoring and preview screens
- dangerous repair/admin actions where required

Orchid does not bypass Laravel authorization, validation, audit, or domain rules.

All Orchid actions must call the same policies, services, and command/action classes used by the API and sync upload flows where practical.

Meridian Admin is the server-hosted shared Vue product UI. Normal Admin,
organizer, department, staff, and operational workflows belong in the shared Vue
client unless they are explicitly God Mode repair tooling.

### 3.4 PowerSync Responsibility

PowerSync provides server-to-device synchronization and local SQLite projections.

PowerSync handles:

- authorized data caching on devices
- offline reads
- local queued writes for supported offline operations
- upload of device-originated operations to Laravel
- reflection of accepted canonical state back to devices

PowerSync is not the business-rule engine.

Device writes are accepted through Laravel validation and acceptance flows. Domain-sensitive writes should be represented as operations or commands, not blind row edits.

Name Reference extraction may happen server-side, locally, or both, but any derived index remains rebuildable from authorized source text and must not become an authorization source.

### 3.5 Node Sync Responsibility

Meridian node sync is separate from PowerSync.

PowerSync handles:

```text
Meridian server ↔ user devices
```

Meridian node sync handles:

```text
central node ↔ on-site node
```

Central/on-site node sync is operation-based, not raw database replication.

### 3.6 UI Mode Authority Boundary

Meridian UI mode is fixed by deployment target:

| Deployment target | UI mode | Product name |
|---|---|---|
| Server-hosted web application | `admin` | Meridian Admin |
| Capacitor mobile application | `field` | Meridian Field |
| Electron desktop/on-site application | `kiosk` | Meridian Kiosk |

UI mode is not a data authority source. APIs, policies, PowerSync rules, node
sync handlers, Orchid actions, and domain services must not grant access because
a request came from `admin`, `field`, or `kiosk` mode.

Authorization comes from authenticated user identity, organization/event/
department scope, roles, grants, trusted device/workstation state where
explicitly required, operations-window rules, and server-side domain policy.

Kiosk pinned context constrains route/shell framing and can be used as an input
to scope selection, but it does not grant the active user authority.

---

## 4. Identifier, Numbering, and Timestamp Policy

### 4.1 Canonical IDs

All externally referenced canonical records use UUID primary keys.

UUIDs are used for:

- primary keys
- foreign keys
- API references
- sync identity
- audit references
- node operation references
- authorization checks

UUIDs may be generated by the creating node or trusted device before sync.

Central preserves original on-site/device UUIDs forever.

### 4.2 Origin Metadata

Records created or accepted through device/node sync should store, where relevant:

- `origin_node_id`
- `origin_device_id`
- `created_node_id`
- `accepted_node_id`

This allows operational records to retain provenance even after syncing to central.

### 4.3 Human-Facing Numbers

Human-facing numbers are separate from UUIDs.

Human-facing numbers must never be used as:

- primary keys
- foreign keys
- sync identifiers
- authorization identifiers

### 4.4 Incident Numbers

Incident numbers are event-specific, system-generated, and chronological by the assigning server/node.

During the active event window, incident numbers are assigned by the on-site primary node.

The IMS number should support organization-year-event-count display structure, with shortened display allowed inside an event context.

Example:

```text
INC-2027-000042
```

Incident numbers are never reused.

### 4.5 Field Report Numbers

Field Report Assignment numbers are event-specific.

The first server/node that accepts the Field Report assigns the FRA number.

Offline-created Field Reports use a clearly temporary local label until synced.

Example:

```text
FRA-2027-000123
```

FRA numbers are never reused.

### 4.6 Document Versions

Policy and procedure documents use two-part versions:

```text
document_revision.fragment_revision
```

The first published version is:

```text
1.00
```

Document content changes increment `document_revision` and reset `fragment_revision` to `00`.

Referenced-fragment changes increment `fragment_revision` for each published referencing document.

The two version components are stored as separate integers.

### 4.7 Timestamps

Where applicable, models should support operational timestamp provenance:

```text
device_created_at
device_submitted_at
onsite_received_at
central_received_at
server_corrected_at
created_at
updated_at
deleted_at
```

The UI normally displays event-local time.

If a submitted timestamp is obviously invalid, impossible, or outside a sanity window, the server should accept the operation if otherwise valid, preserve the raw timestamp in audit data, use a corrected/interpreted event-local timestamp for operational display, and mark the timestamp as suspect.

---

## 5. API Design

Meridian uses a hybrid API model.

### 5.1 Resource API

Resource-oriented endpoints are used for:

- reads
- list screens
- admin browsing
- lookup data
- exports
- lower-risk CRUD where no domain workflow is involved

Examples:

```text
GET /api/me
GET /api/organizations
GET /api/organizations/{organization}
GET /api/organizations/{organization}/departments
GET /api/organizations/{organization}/departments/{department}
GET /api/departments/{department}/teams
GET /api/departments/{department}/teams/{team}
GET /api/departments/{department}/shifts
GET /api/departments/{department}/shifts/{shift}
GET /api/events
GET /api/events/{event}
GET /api/events/{event}/departments
GET /api/events/{event}/info
GET /api/events/{event}/teams
GET /api/events/{event}/shifts
GET /api/events/{event}/shift-board
GET /api/events/{event}/departments/{department}/overview
GET /api/events/{event}/departments/{department}/logistics
GET /api/events/{event}/departments/{department}/operations
GET /api/events/{event}/departments/{department}/planning
GET /api/events/{event}/field-reports
GET /api/events/{event}/incidents
GET /api/events/{event}/incidents/{incident}/pdf
GET /api/organizations/{organization}/documents
GET /api/policy-documents
GET /api/policy-documents/{policyDocument}
GET /api/procedure-documents
GET /api/procedure-documents/{procedureDocument}
GET /api/document-fragments
GET /api/document-fragments/{fragment}
GET /api/document-acknowledgments/me
GET /api/organizations/{organization}/document-acknowledgments
```

All read APIs return permission-filtered resources.

Department operations read models are purpose-built and separate:

- Overview returns selected-shift exceptions, summary counts, checked-in staff,
  assignments, and compact equipment/deployment summaries for department leads.
- Logistics returns the department-scoped index the desk works from: staff,
  equipment, and shifts to search across, and one operational workspace per
  department member.
- Operations Center returns the deployment options and the staff on shift who may
  be moved between them; its other modules are composed from capabilities the
  actor already holds and read their own endpoints.
- Planning returns identity-free plan-versus-actual aggregate rows and must not
  include staff identities, signup lists, or team-member lists.

Every one of the four carries the same `access` block — the presence,
attendance, equipment, deployment, planning, and administration answers the
matching commands enforce — so a surface offers only what the node would accept
and the node decides again regardless (CLIENT-006).

The Logistics read is one request rather than a search endpoint plus a
per-staff-member detail endpoint, which is what this section described before
M16.21. A desk that looks somebody up by asking the node has no search when the
node is unreachable, and SLB-021 requires department-scoped offline search
across staff, equipment, and shifts. One read of the whole department index is
what a device can hold and search; splitting it would have made the requirement
unimplementable at the surface it belongs to. The index is bounded by the desk's
own horizon: shifts whose window falls between twelve hours before and
thirty-six hours after the read, which covers an overnight handover without
handing a desk every shift of a ten-day event.

Each shift card in a workspace carries the node's answer on the four things the
desk may do with it — check in, check out, mark no-show, add to the shift — and
each workspace carries whether that person may go off-site and, when they may
not, the sentence `DepartmentPresenceService` refuses with (SLB-017, SLB-018).
`can_add_to_shift` is the loose side of the question, but only where being loose
costs nothing. It weighs presence, an existing assignment, whether the shift has
started, and eligible-team membership, while `UnscheduledShiftAdditionService`
additionally weighs trainings, waivers, and organization status and refuses in
its own words — those three are a person's own record, and having them guessed
at from a list endpoint would put a stale answer on screen.

Eligible-team membership is not one of the three, and M18.3 stopped it being
approximated. The read and the command both resolve it through
`TeamMembership::onEligibleShiftTeam`: an unarchived membership, on an unarchived
team, hanging off the department membership that puts the person in the
department. Archiving a team leaves its memberships and its shifts alone, so a
read that weighed only the membership went on offering an addition the command
would refuse. Where a card cannot be added to, `add_to_shift_blocked_reason`
carries the sentence, and the archived-team case has its own: telling an operator
somebody "is not a member of" a team they are still on would send them after a
team that no longer takes anybody.

Hours correction is the fifth thing a card answers (SLB-031). Each card carries
`hours_worked_id` with the recorded `actual_started_at`, `actual_ended_at`, and
`minutes_worked` behind it, or null until a check-out has created them — which
is what makes correction the completed shift's control rather than the running
one's. The recorded times travel with the id because a correction is an edit to
two numbers already on file, and a dialog that opened empty would send the
operator to find them somewhere else first. `can_correct_hours` is true while
the record exists and is not frozen, and `correct_hours_blocked_reason` carries
one of two sentences: the frozen one, which is `HoursCorrectionService`'s own
and names the date the grace period closed (HOURS-008), or — for a shift
somebody was assigned to, that has ended, and that produced no hours — one
naming the missing check-out, because that is what the operator actually has to
do. A running shift with no hours yet carries neither: hours arrive at
check-out, and a card saying so mid-shift would appear on every workspace at the
desk.

Event credential administration (`GET /api/events/{event}/credentials`) returns
the event's credentials for the surface a revocation is aimed from (CRED-009
through CRED-014). It is not the eligibility export in another format and does
not answer to the export's permission: it requires `event.credentials.revoke`,
which only organizers and Incident Command leads hold, while a department lead
holding `reports.credential_eligibility.export` reads the same records as a file
for their own department (REPORT-007) and is refused here.

The rows are the union of the event's `event_credentials` and the staff members
carrying active assignments to its shifts, which is exactly the set the command
can act on — a staff member with unscheduled work and no credential row is
revocable (CRED-014 grants them no eligibility and they are still on the
schedule), and a revoked row belongs in the list because reviewing a past
decision is half of why somebody opens it. A staff member the domain has
recorded nothing for reports a null status rather than `blocked`; the two are
different states and neither is presented as the other.

Each row carries what revocation would cost before anybody commits to it:
`future_shift_count` is what would be removed and `completed_shift_count` and
`recorded_minutes` are what would stand (CRED-012, CRED-013), split by the same
predicate `CredentialRevocationService` applies when it acts rather than by a
second copy of the rule. Identity is the legal and preferred name, the handle,
and the departments those shifts belong to, and no further: contact details are
absent for the reason REPORT-010 keeps them out of the export, and an Incident
Command lead reaching this surface holds no export capability at all.

Event Info (`GET /api/events/{event}/info`) returns the staff-facing event
information surface: event context plus one entry per Event Info section, in the
documented order, each carrying the published documents the caller may see or an
`empty_description` naming the gap. Access requires staff standing in the event's
organization and nothing more. Assembly rules are in 11.4A.

The document library read (`GET /api/organizations/{organization}/documents`) is
the one read the policy, procedure, and fragment surfaces render from, the staff
reading library of 12.3 included. It returns the documents the caller may see —
published within their scopes, plus anything they maintain — narrowed by an
optional `?state=` filter (`all`, `draft`, `published`, `archived`) and an
optional `?q=` title search, and the fragments they may maintain. Both filters
are applied after the visibility decision and never before it, which is what
POL-055 asks for by qualifying searchability with visibility permissions: a
title in a scope the caller is outside stays absent from a search that names it,
rather than becoming a way to learn that the document exists. The search matches
titles only, per 11.12. It also
carries the two answers an authoring form needs before there is any document to
read them off: `access.scopes` lists the organization, department, and team
scopes the caller may maintain in, ordered by scope breadth then name, and
`event_info_sections` lists the placements of 11.4A with their labels. Each
document carries `can_maintain`, because a reader and a maintainer both receive
published documents and only the row itself can say which of the two is looking
at it. Rendering is the node's: every document carries `rendered_html` with its
fragment text inline, raw HTML stripped, and unsafe links refused.

The two acknowledgment reads are a pair rather than one endpoint with a filter,
because they answer different questions and carry different authority
(POL-023 through POL-027, POL-043 through POL-047).

`GET /api/document-acknowledgments/me` is a fact about the caller and requires no
capability: being asked to acknowledge a document is not a permission somebody
grants. It returns every active requirement that reaches them — organization
scope reaches anybody holding a status with the organization, prospective
included because POL-024 puts acknowledgment in signup, and department scope
reaches that department's active members — with the document's title, its
current version, and `rendered_html` carrying its fragment text inline (POL-022),
because somebody cannot acknowledge what they were never shown. A requirement
pointing at an unpublished document is absent: the command refuses it, and
listing an item nobody can act on lists a fault as a task. Each row carries
`acknowledged`, the version that was accepted (`acknowledged_version`, POL-043),
and `document_changed_since` — which is reported and never makes a row
outstanding, because POL-045 is explicit that a document change does not
re-require anything. The response also carries a `gating` block whose two halves
are both false and stay false: POL-026 and POL-027 keep acknowledgment out of
shift signup and credential eligibility, and a list of outstanding items reads as
a list of blockers unless the answer says otherwise.

`GET /api/organizations/{organization}/document-acknowledgments` is an
organizer's read of other people and requires
`documents.acknowledgments.review`. It returns every requirement in the
organization — retired ones included, because checking whether something was ever
asked is half of why somebody opens it — each with the people its scope reaches
and whether each of them has answered. Identity is the display name and the
handle and stops there, for the reason the credential administration list stops
there: reading who acknowledged a policy is not a reason to read anybody's
contact details. It also carries the three answers the create form needs before
there is a requirement to read them off: the organization's published policy and
procedure documents, the organization and department scopes of POL-047 (team
scope is deliberately absent), and the signup and training contexts of POL-046.

The event Field Report read (`GET /api/events/{event}/field-reports`) is the IC
review list and the source the incident link picker chooses from. It requires
`field_reports.view_event` (17.6) — authorship alone grants no event-wide read —
and returns every Field Report for the event, newest acceptance first, each
carrying its display number, title, author, body, and acceptance time. Each
report also names the incidents it is *actively* linked to; that part is gated
separately on `incidents.view`, because a Field Report naming its incidents is
an incident disclosure.

Incident APIs must return data only to IC-authorized users.

The incident list (`GET /api/events/{event}/incidents`) accepts explicit search,
filter, and sort query parameters and echoes the applied selection plus the
event's filter options:

- `search` matches the incident number, title, location fields, incident type
  labels, responder names, active timeline notes, actively attached Field
  Reports, and Name Reference tokens. A leading `@` or `#` is stripped, so Name
  Reference chips and tag pills route through the same list search. Stricken
  notes and unlinked Field Reports never match.
- `state` is `active` (default, excludes Closed), `all`, or one canonical
  incident status.
- `priority` is `all` (default) or one priority label.
- `type` is `all` (default) or one incident type label, matched
  case-insensitively.
- `responder` is `all` (default) or one `staff_id`.
- `started_from` and `started_to` bound `started_at`; a bare date upper bound
  covers that whole day.
- `sort` is one of `updated` (default), `incident`, `state`, `priority`,
  `started`, or `location`, with `direction` `asc` or `desc`. State and priority
  sort by documented operational order, not alphabetically.
- `page` (default 1) and `per_page` (default 25, maximum 100) page the filtered
  result. The response `pagination` block reports `page`, `per_page`, `total`,
  `total_pages`, and `has_more`, where `total` counts the filtered result rather
  than every incident in the event. Sorting always applies deterministic
  tiebreakers so a record cannot appear on two pages or be skipped between them.

The response also carries the caller's saved filter presets (see 10.16A).

Alongside `filter_options`, which says what a reader may narrow this list by, the
response carries an `assignable` block saying what an authoring form may put on
an incident: `statuses` and `priorities` are the canonical vocabularies the
create and update commands accept, `types` is every unarchived incident type the
organization has configured (wider than the in-use `filter_options.types`), and
`responders` is the event's configured Incident Command department roster.
Incident types are configurable per organization and are chosen from rather than
typed: an organization starts with a documented default set and maintains its
own list from there. The two blocks differ deliberately: `active` and `all`
are ways of asking a question rather than states an incident can be in, and a
type or responder not yet on any incident is still a legitimate thing to add.

The IC permission check runs before any parameter is parsed, so filters never
widen visibility and an unauthorized actor learns nothing from a filtered,
paged, or preset-bearing request. Unknown filter values are refused with 422
rather than silently falling back to a default.

Incident PDF print (`GET /api/events/{event}/incidents/{incident}/pdf`) is
server-generated, online-only, and restricted to IC leads (`incidents.print`).
Successful exports are audited. Incidents remain excluded from general
spreadsheet exports for MVP.

APIs may include derived Name Reference tokens or chips on permitted Field Report and Incident resources. There is no dedicated Name Reference detail API for Alpha 1.

### 5.2 Command / Operation API

Domain-sensitive mutations use explicit commands or operation acceptance endpoints.

Examples:

```text
POST /api/commands/submit-application
POST /api/commands/approve-application
POST /api/commands/reject-application
POST /api/commands/assign-staff-to-department
POST /api/commands/assign-staff-to-team
POST /api/commands/remove-staff-from-team
POST /api/commands/assign-staff-to-event
POST /api/commands/create-shift
POST /api/commands/assign-staff-to-shift
POST /api/commands/remove-staff-from-shift
POST /api/commands/check-in-staff
POST /api/commands/check-out-staff
POST /api/commands/mark-no-show
POST /api/commands/correct-hours
POST /api/commands/mark-staff-on-site
POST /api/commands/mark-staff-off-site
POST /api/commands/add-staff-to-shift
POST /api/commands/sign-up-for-shift
POST /api/commands/withdraw-from-shift
POST /api/commands/update-my-profile
POST /api/commands/request-handle-change
POST /api/commands/submit-profile-picture
POST /api/commands/remove-profile-picture
POST /api/commands/withdraw-profile-change-request
POST /api/commands/dismiss-profile-change-request
POST /api/commands/approve-profile-change-request
POST /api/commands/reject-profile-change-request
POST /api/commands/revoke-credential
POST /api/commands/set-current-deployment
POST /api/commands/checkout-equipment
POST /api/commands/return-equipment
POST /api/commands/create-equipment-item
POST /api/commands/update-equipment-item
POST /api/commands/archive-equipment-item
POST /api/commands/restore-equipment-item
POST /api/commands/import-equipment-inventory
POST /api/commands/submit-field-report
POST /api/commands/append-field-report
POST /api/commands/create-incident
POST /api/commands/update-incident-title
POST /api/commands/append-incident-note
POST /api/commands/change-incident-status
POST /api/commands/close-incident
POST /api/commands/reopen-incident
POST /api/commands/link-field-report-to-incident
POST /api/commands/unlink-field-report-from-incident
POST /api/commands/save-incident-list-preset
POST /api/commands/delete-incident-list-preset
POST /api/commands/create-policy-document
POST /api/commands/update-policy-document
POST /api/commands/publish-policy-document
POST /api/commands/archive-policy-document
POST /api/commands/create-procedure-document
POST /api/commands/update-procedure-document
POST /api/commands/publish-procedure-document
POST /api/commands/archive-procedure-document
POST /api/commands/create-document-fragment
POST /api/commands/update-document-fragment
POST /api/commands/acknowledge-document
POST /api/commands/create-document-acknowledgment-requirement
POST /api/commands/set-document-acknowledgment-requirement-active
POST /api/commands/export-document
POST /api/commands/designate-placement-department
POST /api/commands/publish-event-map
POST /api/commands/archive-event-map
POST /api/commands/override-locked-map-data
POST /api/commands/create-note
POST /api/commands/add-note-to-briefing
POST /api/commands/create-department
POST /api/commands/update-department
POST /api/commands/archive-department
POST /api/commands/restore-department
POST /api/commands/update-department-details
POST /api/commands/create-team
POST /api/commands/update-team
POST /api/commands/archive-team
POST /api/commands/restore-team
POST /api/commands/select-team-lead
POST /api/commands/remove-team-lead
POST /api/commands/update-shift
POST /api/commands/cancel-shift
POST /api/commands/restore-shift
```

Fragments do not have Draft/Published/Archived states in Alpha 1, so fragment publish/archive commands are not part of the Alpha 1 command surface.

Policy/procedure create and update commands accept an optional
`event_info_section`. Omitting the key leaves the current assignment alone;
sending `null` clears it. Unknown section values are refused with 422 rather
than silently ignored, because a placement the maintainer thinks they made and
staff never see is worse than an error. See 11.4A.

`create-note` and `add-note-to-briefing` are the Alpha 1 Notes/Briefing write commands. Remaining Briefing commands in section 11A are post–Alpha 1.

Organization department administration commands (`create-department`, `update-department`, `archive-department`, `restore-department`) are organizer/lead-organizer scoped through `organization.departments.manage` and preserve history via soft archive (`archived_at`).

Department self-administration commands (`update-department-details`, `create-team`, `update-team`, `archive-team`, `restore-team`) are department-scoped through `department.administer` (granted to `department_lead` and `department_administration`). Default teams may be renamed but cannot be archived. Team archive/restore preserves history via soft archive (`archived_at`).

Team lead designation commands (`select-team-lead`, `remove-team-lead`) are department-scoped through `department.administer`. Designation sets the team membership `membership_role` to `lead` and ensures an active team-scoped `shift_lead` grant on that team; removal returns the membership to `member` while preserving team membership. Only designated lead memberships resolve the `shift_lead` effective role, so other members of a grant-bearing team do not gain lead authority.

Team staff assignment commands (`assign-staff-to-team`, `remove-staff-from-team`) are open to department `department.administer` authority and to designated leads of the target team. Removal archives the membership (`archived_at`) rather than deleting it, cannot remove the department default-team membership, and cannot leave a department membership without at least one active team membership.

Equipment inventory setup commands (`create-equipment-item`, `update-equipment-item`, `archive-equipment-item`, `restore-equipment-item`, `import-equipment-inventory`) build the department inventory that the Logistics checkout/check-in commands consume. They are department-scoped and event-independent, authorized by `department.equipment.manage` (`department_logistics`) or `department.administer` (`department_lead`, `department_administration`) on a team in the target department. Equipment without a department stays Orchid/God Mode repair tooling, and no command accepts a department other than the one that owns the item, so department-to-department allotments remain excluded by EQUIP-006. New equipment is always created `available`; inventory setup may set only `available`, `missing`, or `damaged`, because `checked_out` and `returned` are produced by `checkout-equipment`/`return-equipment`. State changes and archiving are refused while an item has an open checkout. Asset tags are unique among equipment in a department, which lets a re-run of the same import skip rows instead of duplicating equipment. Archiving is a soft transition on `archived_at` that preserves checkout history. `import-equipment-inventory` accepts spreadsheet CSV text with a required `name` header column plus optional `asset_tag` and `serial_number` columns, takes event scope from the request rather than the file, processes each row independently, and returns per-row imported/skipped results with reasons.

Department presence commands (`mark-staff-on-site`, `mark-staff-off-site`) are
scoped to one event, department, and staff member (SLB-015) and authorized by
`department.presence.manage` (`department_logistics`). Only an active department
member may be marked on-site, and going off-site is refused while that person is
checked into a department shift or still holding department equipment that has
not been returned or marked missing or damaged (SLB-017, SLB-018). Marking
somebody into the state they are already in is accepted and records no state
change, so a desk that presses the button twice does not produce two audit
entries.

The unscheduled shift addition command (`add-staff-to-shift`) is the Logistics
Window's "add to shift" (SLB-008). It is authorized by the same attendance
authority as check-in, requires the shift to have started and not be cancelled,
and requires the staff member to be on-site, an active department member, an
eligible-team member on the terms above, clear of do-not-staff, and to meet the
shift's training and waiver requirements. On-site presence comes first in the
desk's order of work as well as in the command's: a staff member this department
has not marked on-site is refused, and the Logistics read offers their workspace
no unassigned shift cards at all (requirements 5.8). Overlapping assignments are accepted with a warning
rather than refused (technical spec 20.5), and the warnings travel back with the
acceptance so the desk can show them.

The hours correction command (`correct-hours`) is the Logistics Desk's edit to a
recorded actual start and end (SLB-007, SLB-031, SLB-032; HOURS-007). It
addresses the `hours_worked` record by id rather than by shift and staff member,
because the record is what is corrected and it is what the workspace is already
holding, and it requires both actual times: a correction naming one end of the
window would leave the other to be inferred, and the service recomputes minutes
from both. It is authorized by the same attendance authority as check-out, and
refused once `hours_worked.frozen_at` is set. The refusal names the moment the
grace period closed, read in the event's own time zone, rather than stating that
a grace period exists — a desk deciding whether to escalate to an organizer
needs to know whether it is an hour late or a month late. The correction is
appended to attendance history rather than replacing it: a `correct` attendance
operation is written beside the check-out that produced the record, the audit
entry carries the prior actual times and minutes as well as the new ones, and no
earlier operation is rewritten or removed. Repeating the same `operation_uuid`
is the same correction and applies once.

`correct-hours` is the one command in the attendance family that section 7.2
does not list as an offline write, so a client sends it connected or is refused
where it stands. Its queued siblings are recorded against a shift the device
already holds; whether a correction is allowed at all is measured against a
grace period the node's clock owns and closes on its own, so one held on a
device may be delivered into a window that has already shut, after the operator
walked away believing the total was fixed.

Attendance commands (`check-in-staff`, `check-out-staff`, `mark-no-show`,
`correct-hours`) accept `origin_node_id` as an optional field. A client replaying an operation that
originated elsewhere names that node; a client posting its own work omits it and
the node that receives the command records itself as the origin, because nothing
publishes a node id to a browser and provenance is better recorded than guessed.
`origin_device_id` stays required: the device that captured the work is the
device that knows which one it is.

The credential revocation command (`revoke-credential`) is the one write of the
credential administration surface (CRED-011 through CRED-013). It names the
event and the staff member rather than a credential id, because a staff member
with shifts and no credential row is revocable and there would be no id to name,
and it carries an optional free-text `reason` that reaches the audit entry and
that nothing branches on. Authorization is the service's, not the controller's:
`CredentialRevocationAccess` resolves `event.credentials.revoke` for the event,
so a grant scoped to another event or to a department that is not this event's
Incident Command Department does not answer here. The two refusals are different
answers and carry different statuses — an unauthorized caller is a 403, a staff
member with no credential and no shift history is a 422 — and the command
answers with the staff member's whole row rebuilt rather than an acknowledgment,
so the surface replaces what it was showing with what is now true, including the
completed shifts and recorded hours that survived. Revoking an already-revoked
credential changes nothing and writes no second audit entry.

`revoke-credential` is not in section 7.2's offline writes and is the least
queueable command in the catalog. It removes the future shifts somebody was on,
which is a roster other people are being scheduled around, so one held on a
device is an event still planning around somebody who was removed from it hours
ago — and the decision is not local either: the node weighs it against the
credential and the assignments as they stand when it arrives.

The acknowledgment commands are three writes over one path (POL-023, POL-043,
POL-046, POL-047).

`acknowledge-document` names the requirement and nothing else. Who is
acknowledging comes from the caller's own session, and which staff profile the
acknowledgment is recorded against is the node's answer from the requirement's
scope — a client that named its own subject would be a client that could name
somebody else's. A requirement the caller is not inside the scope of is refused
with 403 (`This acknowledgment was not asked of you.`), which is the half a read
that hides the row does not cover: hiding is presentation and this is
authorization. The accepting node is this install's own; nothing publishes a node
id to a browser, and an install with none configured refuses with 422 rather than
recording a guess. Acceptance is idempotent on the version already accepted
(POL-045), so pressing the button twice or on a second device returns the row
that already exists rather than writing a second one. The answer is the rebuilt
row, including the version that was recorded.

`acknowledge-document` is not in section 7.2's offline writes, for a reason
peculiar to it: the record has to name the version the person actually read
(POL-043), and one held on a device would name whichever version that device last
cached — arriving as agreement to text that may have moved on, which is worse
than not arriving, because the record would look complete.

`create-document-acknowledgment-requirement` and
`set-document-acknowledgment-requirement-active` both require
`documents.acknowledgments.review` for the requirement's organization and are
refused with 403 otherwise. Creation additionally refuses a document that is not
published (422): a requirement pointing at a draft is one the acknowledge command
refuses for everybody it is addressed to, which makes it a trap rather than a
requirement. Retiring is a switch rather than a delete and leaves the
acknowledgments recorded against it untouched — somebody who read a policy and
said so did that, and an organizer deciding to stop asking the next person is a
different fact. Both write audit events
(`document_acknowledgment_requirement.created`, `.retired`, `.restored`) carrying
the requirement's organization and, at department scope, its department.

Incident list preset commands (`save-incident-list-preset`, `delete-incident-list-preset`) manage one user's saved incident list selections for one event. They reuse the `incidents.view` gate rather than adding a capability: if a user may read the event's incident list, they may name their own way of reading it. Presets are always addressed by owner, so an IC user can neither overwrite nor delete another's, and a preset never grants access to an incident the applying user could not already see. Saving an existing name overwrites that preset; paging position is never stored. Presets are personal view state rather than operational records, so they are not audited.

The department shift read (`GET /api/departments/{department}/shifts`) answers three kinds of caller and shapes its response to each. Department `department.administer` authority reads every department shift; a designated team lead reads shifts for the teams they lead; and a department member with active team membership reads the active shifts their teams are eligible for, read-only. The response carries `access.can_manage` for the surface as a whole and `can_manage` on each shift, which are the same answers the commands enforce, along with `has_started` so the started-shift locks are the node's reading of the clock rather than the client's. Its option lists — `teams`, `events`, `training_options`, `waiver_options` — are for the create/edit form and are therefore absent from a member's response; `teams` carries the active teams the caller may schedule for rather than every team in the department. The `?status=` filter (`all`, `active`, `cancelled`) applies to callers who may manage shifts; a member's read is always the active ones. The shift detail read (`GET /api/departments/{department}/shifts/{shift}`) stays manage-scoped, because it exists for the edit surface.

The staff shift board read (`GET /api/events/{event}/shift-board`) is the staff
signup surface's one read (SHIFT-018). It is event-scoped in the path and
self-scoped in its answer: who the board is about is the caller's own staff
profiles, never anything the request names. It returns every shift in the event
belonging to a department the caller holds an active membership in, resolved per
department, because a login speaking for two staff records may reach one
department through one of them and the other through the other. Each shift
carries `signed_up`, `can_sign_up`, `can_withdraw`, `schedule_locked`, and — when
the shift will not take the caller — `unavailable_reason_code` and
`unavailable_reason`. Those two come from the same evaluation `sign-up-for-shift`
performs, so a shift the board offers is one the command accepts and a refusal
reads the same on both. Being signed up is read from the assignment rather than
from the evaluation, so somebody already on a shift still sees that they are on
it after the cutoff closes. Cancelled shifts stay on the board with cancellation
as their reason. Overlap advisories (SHIFT-014) are carried on the shifts that
are still offered.

Staff shift self-service commands (`sign-up-for-shift`, `withdraw-from-shift`)
act on the caller's own staff profile and are authorized by that link rather than
by a role: `ShiftSignupService` and `ShiftRemovalService` refuse a staff profile
the user does not hold. `shift_id` is required and `staff_id` is optional, taken
as the caller's profile that is an active member of the shift's department when
omitted. Signup enforces the requirements 3.12 eligibility set and answers 422
with the service's sentence and a stable `reason_code`; overlap travels with the
acceptance as a warning rather than refusing it. Withdrawal is refused once the
schedule locks, which is where staff self-service ends and lead removal
(`remove-staff-from-shift`) continues. Both are connected-only: they are not in
the 7.2 offline-writable set, because eligibility and capacity are answers a
device cannot hold.

The staff profile self-service pair (`GET /api/me/profile`,
`POST /api/commands/update-my-profile`) is the staff profile surface's read and
write (VOL-009, VOL-014 through VOL-016, VOL-026; section 10.4; UI contract
12.3). The read answers with the staff records the caller's login speaks for and
needs no authority beyond a credential, carrying the VOL-009 field set and
`self_editable_fields` so the edit form renders the boundary the server
enforces. The command is self-scoped the way the two shift commands above are:
`staff_id` is optional with one linked record, required when the login speaks
for several, and a `staff_id` outside the caller's own is refused with 403. It
accepts exactly the VOL-015 set — `preferred_name`, `phone`, `city`, `state`,
each nullable — and applies immediately with no review. A submitted
`legal_name`, `email`, or `date_of_birth` is refused with 422 naming the
assisted path rather than silently dropped (VOL-016), and `handle`,
`formerly_known_as`, and the emergency contact fields are refused the same way:
handle changes travel through `staff_profile_change_requests` below, and nothing
in VOL-015 makes the rest self-service. The edit is audited
as `staff.profile.self_updated` with the previous and new value of each changed
field and only the changed fields (VOL-026); an edit that changes nothing writes
no audit entry. `update-my-profile` is not in the 7.2 offline-writable set, so a
client sends it connected or is refused where it stands.

The profile change request commands are the handle and picture halves of the
same surface (VOL-017 through VOL-025; section 10.4).

`request-handle-change` records every handle change as a request row. The first
two applied changes on a staff record are written `approved` with `self_service`
true and applied in the same transaction; each one after that is written
`pending` and applies only on approval. Setting a first handle is not a change
and consumes nothing. The allowance is the count of approved handle rows
carrying a `previous_handle`, so a rejected or withdrawn request restores
nothing because it consumed nothing, and the response says which of the two
outcomes happened rather than leaving the client to predict it.

`submit-profile-picture` is multipart rather than JSON, because its body is an
image. It refuses a staff member who is not `active` in any organization
(technical spec 18A.1), applies the 18A.2 limits — JPEG, PNG, or WebP, 10 MB
before processing, resized within 1024 x 1024, EXIF stripped by re-encoding —
and stores the processed image on the private attachments disk rather than the
public one the current picture uses, because a pending picture is readable only
by its submitter and its reviewers (VOL-021). The staff record's current picture
columns are untouched while the request is pending. A short-lived scoped URL
(`GET /staff-profile-pictures/{changeRequest}/pending`, technical spec 11A.6)
serves the submitted image to those two audiences and to nobody else,
re-checking authority at stream time rather than trusting issuance.

`remove-profile-picture` clears the current picture immediately, creates no
request row, and deletes the stored blob (VOL-023). Alpha 1 preserves no
previous pictures.

`withdraw-profile-change-request` withdraws the caller's own pending request of
either kind and discards a submitted image with it (VOL-024). A request that is
not the caller's own is refused with 403 in the same words a missing one is, so
the refusal discloses neither whose it was nor whether it exists.

`dismiss-profile-change-request` is withdrawal's counterpart for a request
nobody is deciding any more (VOL-029): a rejection the staff member has read, or
an approval they no longer need announced. It sets `dismissed_at` rather than
deleting the row, because the row is audit history and an approved handle change
is one of the facts the VOL-018 allowance is counted from — "I have read this"
must not be able to give somebody a change back. A pending request is refused
with a message naming withdrawal instead, and another staff member's request is
refused with the same 403 withdrawal gives.

`GET /api/me/profile` carries `latest_handle_request` and
`latest_picture_request`: the most recent request of each kind that the staff
member has not dismissed, in whatever state it reached. A pending one is
something to withdraw and blocks a second submission; a decided one is a notice
to read and clear, and does not stand in the way of trying again. The read also
carries `handle_change_policy`, `profile_picture_change_policy`,
`can_submit_picture`, and `remaining_self_service_handle_changes`, so the
surface words its controls for the outcome the node will actually produce rather
than promising one and reporting another. `remaining_self_service_handle_changes`
is zero under any policy that reviews every change, because an allowance nobody
may spend is not an allowance.

`approve-profile-change-request` and `reject-profile-change-request` answer to
`staff.profile-change-requests.review`, resolved for the organization the
request belongs to — organizers, Lead Organizers, and Staff Coordinators hold
it and no other role does (VOL-019). A reviewer never decides their own request
even where they hold the capability. Approval applies the handle or promotes the
submitted image to the staff record's current picture and clears the pending
columns; rejection requires a reason, which reaches the submitter (VOL-025), and
discards the submitted image (VOL-022). A requested handle already held by
another active staff member in the organization is named to the reviewer on
`GET /api/staff-profile-change-requests` and does not block the decision
(VOL-020), resolved at read time rather than stored. Creation, decision, and
withdrawal are all audited, and an applied handle or promoted picture writes a
second audit entry against the staff record itself, because "a decision
happened" and "this person's handle is now different" are different questions.
None of these are in the 7.2 offline-writable set; VOL-014 and technical spec
18A.3 additionally make the picture path online-only by requirement.

Shift administration commands (`create-shift`, `update-shift`, `cancel-shift`, `restore-shift`) are open to department `department.administer` authority for any department team and to designated team leads for shifts whose eligible team they lead. They enforce the documented eligibility and time-window rules: the event must belong to the department organization; exactly one eligible team from the same department; end after start; signup close after signup open; capacity at least 1 when set and never below current active assignments; once a shift has started its scheduled times and eligible team are locked and it can no longer be cancelled or restored; cancelled shifts must be restored before editing. Cancellation is a soft transition on `cancelled_at`.

Map publishing, archiving, Placement department designation, and locked-map data overrides use command-style writes because their business rules (operations-window locking, Placement-assignment validation, and elevated override authority) matter. Routine creation/editing of draft maps, camps, map locations, and map assets before the operations window may use the resource API under map permissions. Map records are not offline-writable for MVP.

### 5.3 Command Requirements

Every command/operation should include:

- command/operation UUID
- command/operation type
- actor user ID
- actor device ID when applicable
- actor node ID when applicable
- organization ID where applicable
- event ID where applicable
- target entity type and ID where applicable
- payload
- device timestamp where applicable
- server/node received timestamp
- idempotency key or operation UUID
- source context
- resulting audit event IDs where applicable
- conflict status where applicable

Commands should be idempotent wherever possible.

A repeated command with the same command/operation UUID must not create duplicate domain effects.

### 5.4 API authentication

Client applications authenticate with a bearer token issued by Laravel Sanctum. This applies to the web client, the mobile Field application, and the desktop application alike.

Login endpoints:

```text
POST /api/auth/magic-link          request a magic link or code for an email
POST /api/auth/magic-link/verify   exchange a verified link or code for a token
GET  /api/auth/{provider}/start    begin a system-browser provider handoff
POST /api/auth/session             exchange a completed handoff for a token
DELETE /api/auth/session           revoke the calling token
```

Rules:

- every token is bound to a `devices` record; a request that cannot supply a resolvable device identity is refused rather than issued an unbound token
- tokens expire on a node-configured lifetime with a documented default
- token expiry is independent of the shared-workstation inactivity timeout in 12.3
- issuance, expiry, and revocation are audited; raw token values are never logged, audited, or exported
- shared-workstation login codes do not issue tokens; see 12.4
- the Alpha 1 `local.field` shared-token middleware is superseded by this mechanism and removed
- every operational endpoint — every command in 5.3 and every read a client makes — requires either a bearer token or a shared-workstation session key; the only routes that carry no credential are node-to-node exchange, the login paths themselves, and the branding profile read a device resolves before it has a session

#### Provider handoff

Google and Discord login completes in a system browser and returns the token to the requesting application. The provider exchange is performed by the node and is not reimplemented in any client.

`GET /api/auth/{provider}/start` is called by the client, not opened in the browser. It takes the client target the caller is (`web`, `mobile`, or `desktop`) and a PKCE `code_challenge` (`S256` only), and answers with the provider authorization URL to open the system browser at, the return address the browser will be sent to, and the handoff `state`.

The provider returns the browser to the node's existing provider callback, which recognizes a handoff state, resolves the identity, and sends the browser on to the return address carrying a one-time `code` and the `state`. A failed handoff returns the same way carrying an `error` reason instead, because the client application is the only surface that can explain the failure to the person.

`POST /api/auth/session` exchanges that code, the PKCE `code_verifier`, and a device identity for a bearer token.

Rules:

- the return address is resolved from node configuration per client target, never from the request, so a handoff cannot be turned into an open redirect
- a client target with no configured return address does not offer provider login
- the exchange code is single use and expires with the handoff
- redemption requires the PKCE verifier, because on mobile and desktop the return leg travels through a custom scheme another application can register
- a handoff establishes no browser session; the credential belongs to the client application that started it
- state values and exchange codes are stored only as keyed hashes

#### Shared workstation login codes

A shared-workstation login code is not an API credential and issues no token (12.4, 12.5). One endpoint exists because generating a code is something a signed-in client does for its own user:

```text
POST /api/auth/shared-workstation-login-code   generate a code for the calling user
```

The request carries the trusted shared workstation the code will be typed at, and nothing else. The response carries the code, once, along with the workstation, the event resolved from that workstation's pinned Kiosk context, and the code's expiry.

Rules:

- the endpoint requires a bearer token, because holding a session on the calling device is the whole authority for the request (AUTH-026)
- the code is for whoever holds that session; the endpoint accepts no user field, and a request naming another user is refused with `self_service_scope` (AUTH-028)
- it requires only reachability of the node that will accept the code — no internet, no central node, no mail, no other out-of-band channel (AUTH-027)
- the event comes from the workstation's pinned context, never from the request, so a code cannot be scoped to an event the workstation does not serve
- an untrusted, revoked, or unpinned workstation is refused rather than issued a code that could not be entered
- generation is rate limited per user and per node (AUTH-029)
- the code appears in this response and nowhere else: it is stored only as a keyed hash, is not logged or audited, and cannot be printed or exported

God mode generating a code for another user is a console screen rather than an endpoint, because it is operator tooling. Entering a code is the shared-workstation session below.

#### Shared workstation sessions

Entering a login code at a trusted shared workstation establishes a shared-workstation session (12.6, technical spec 13.3). It is not an API credential: it issues no bearer token and trusts no device (AUTH-030), so it has its own endpoints and its own guard rather than arriving through `POST /api/auth/session`.

```text
POST   /api/auth/shared-workstation-session   enter a code and start a session
GET    /api/auth/shared-workstation-session   read the session, and record activity
DELETE /api/auth/shared-workstation-session   end the session
```

The start request carries the shared workstation and the typed code. Its response carries the session key once, and every response carries the session's start, last activity, and inactivity deadline, the active user's identity, the workstation with its pinned organization and optional department, and the event the session is scoped to.

Requests thereafter carry the session key in the `X-Meridian-Workstation-Session` header. Deliberately not `Authorization: Bearer`, so nothing on either side can treat a workstation session as a personal token.

Rules:

- the typed code is the whole credential for the start request, which therefore carries no session and is rate limited, on top of the per-workstation entry limit in 12.4 (AUTH-029)
- a successful entry issues no API token under 12.5 and establishes no trusted personal device session under 12.2 (AUTH-030)
- the session key exists in the start response and nowhere else; only a keyed hash is stored, so it cannot be re-read from the node or recovered from a backup
- the response carries the node's inactivity deadline rather than a duration, so the workstation counts down against the node's clock and the 5-minute rule in 12.3 has one owner
- reading the session is activity: it is the action behind the timeout warning, so a person saying they are still there slides the window by saying it
- roles and capabilities are not in these responses; the session key authenticates `GET /api/me`, so there is one answer to what the active user may do
- a workstation holds one session at a time, so starting one closes whatever was still open there: `superseded` if it was live, `timed_out` if it had already lapsed
- ending is answered the same way whether or not there was a session to end, because a workstation asking to be signed out gets to be signed out

### 5.5 Session resolution

```text
GET /api/me
GET /api/me?event_id={event}
```

Returns, for the calling user:

- `user`: identity fields, plus `staff_ids` — the staff records the login speaks for, which a client needs to recognize its own user in a roster it has been handed
- `roles`: effective role codes resolved from active team memberships and active team grants, each with the scope it was resolved at and the reason it was granted
- `capabilities`: permission capability codes carried by those roles, as published by the permission catalog
- `organizations`: organizations the user holds an association with
- `events`: events the user holds an association with, and which one the node is locked to when it is locked
- `departments` and `teams`: the user's associations within the resolved context
- `context`: the resolved organization, event, and department, and whether context switching is available
- `refreshed_at`: server time of resolution, used by the client to display permission staleness

The response returns codes, not navigation. It carries no screen list, menu structure, or precomputed surface availability. Clients derive navigation from `capabilities`, which keeps the permission catalog the single source of truth.

Each entry in `roles` also carries the capability codes that role alone brings, because authority is scoped — a person may run logistics for one department and be ordinary staff in another — and the client holds no copy of the role-to-capability mapping to narrow the flat list for itself. Both lists are read from the same catalog the server enforces from.

Each entry in `events` carries the event's own window and its active event window, which is what bounds the staleness of a cached session in 11A.4.

Association is defined per record type: an organization by a `staff_organization_statuses` row, a department or team by a membership that is not archived, and an event by any of a department assignment to a department the user belongs to, a team grant scoped to that event on a team they belong to, or an unrevoked `event_credentials` row. The organizations of the listed departments and events are always listed too, so a client is never left displaying an organization it was not told about.

Client-side capability checks are presentation only. Every endpoint enforces its own authorization regardless of what the client rendered.

`GET /api/me` is permission-filtered like every other read: it returns the caller's own associations and never another user's.

`event_id` selects which of the caller's own events roles are resolved at, so event-scoped roles — Incident Command in particular — resolve only where they apply. It narrows the answer and never widens it: an event the caller holds no association with is refused with `event_context_unavailable`, and so is an event that does not exist, because whether an unrelated organization is running an event under a guessed identifier is not the caller's business. A node locked to an event refuses any other value with `node_locked_to_event`; that node holds one event's records and resolving another there would answer for data it does not have. Omitted, context resolves as described in technical spec 11A.3.

### 5.6 Client command outbox

Clients submit commands through one durable local queue rather than through per-feature queues.

Each queued command carries a client-generated idempotency key, satisfying the idempotency requirement in 5.3. A repeated key is treated as the same command, which is what makes replay after an interrupted sync safe.

The client surfaces queued, accepted, and rejected commands. A rejected command is reported, not discarded.

Commands restricted to connected operation are refused at queue time rather than queued and rejected later. In Alpha 1 these are incident creation, policy and procedure acknowledgment, event application submission, and map editing. Offline-writable commands remain those listed in 7.2.

### 5.7 Authenticated downloads

A bearer token cannot be attached to a plain browser navigation, so authenticated file retrieval does not place credentials in a link.

An authenticated client requests a short-lived URL for one resource, then navigates to it:

```text
POST /api/events/{event}/exports/credential-eligibility/download-url
POST /api/events/{event}/exports/shift-roster/download-url
POST /api/events/{event}/exports/staff-contact/download-url
POST /api/events/{event}/exports/hours-worked/download-url
POST /api/events/{event}/exports/credits-earned/download-url
POST /api/events/{event}/incidents/{incident}/pdf/download-url
POST /api/policy-documents/{policyDocument}/export/{format}/download-url
POST /api/procedure-documents/{procedureDocument}/export/{format}/download-url
POST /api/field-report-photos/{attachment}/download-url
POST /api/field-report-photos/{attachment}/preview-url
```

The field report photo endpoints already establish this pattern; the remaining entries extend it to exports and generated documents.

Rules:

- a short-lived URL expires
- it is scoped to the single resource it was issued for
- issuing it applies the same authorization as a direct request for that resource
- following it is not a second authorization decision

An export URL is scoped to its report and to any `department_id` narrowing decided at issuance, and it carries the user it was issued to. The file is generated from that user's own scope resolved again at the moment it is served, so the same request from an organizer and from a department lead produces different files, and a role withdrawn between issuance and navigation stops the download.

Insight Sheet PDFs are not on this list. They are generated in the browser from the rendered view and never leave the client, so there is no server resource to issue a URL for.

### 5.8 Insights API

Reads:

```text
GET /api/insights/sheets
GET /api/insights/sheets/{sheet}
GET /api/insights/metric-definitions
GET /api/events/{event}/insights/sheets/{sheet}
GET /api/events/{event}/insights/me
```

`GET /api/insights/sheets` returns the sheets available to the caller. Sheets the caller cannot access are omitted, not returned as inaccessible entries.

`GET /api/events/{event}/insights/sheets/{sheet}` compiles the sheet against one event, accepting the sheet's enabled filters and an optional department context as query parameters. It returns per-placement values, state, presentation, action link, and freshness disclosure. Placements whose required capability the caller lacks are omitted from the response rather than returned empty.

`GET /api/events/{event}/insights/me` returns the calling volunteer's own event aggregates: completed shifts, missed shifts, late arrivals, hours worked, hours worked by team, and credits. It returns only the caller's own data and is available without `insights.view`.

Every Insights read applies small-cohort suppression server-side. A suppressed value is returned as suppressed with a reason, never as zero, null, or an empty string.

Commands:

```text
POST /api/commands/create-insight-sheet
POST /api/commands/update-insight-sheet
POST /api/commands/archive-insight-sheet
POST /api/commands/add-insight-metric-placement
POST /api/commands/update-insight-metric-placement
POST /api/commands/remove-insight-metric-placement
POST /api/commands/reorder-insight-metric-placements
POST /api/commands/share-insights-with-command
POST /api/commands/unshare-insights-with-command
POST /api/commands/favorite-insight-sheet
POST /api/commands/unfavorite-insight-sheet
```

Sheet and placement commands require `insights.sheets.manage` and validate placement configuration against metric registration at write time. Share and unshare require `insights.share_with_command` and are audited. `share-insights-with-command` accepts an optional metric placement; omitting it shares the whole sheet. Favorite commands are personal view state and are not audited.

Insights are read-compiled and have no offline write path, so no Insights command enters the outbox in 5.6.

### 5.8A Event Horizon API

Reads:

```text
GET /api/events/{event}/event-horizon
```

Returns the calling staff member's readiness list for one event: the registered item kinds (HORIZON-003) with their items, each carrying a stable identity for the underlying record, `state` (`outstanding` or `complete`), the evaluation behind that state, what would complete it, an optional `due_at`, and an action link.

The response is ordered by the server (HORIZON-006), so two clients render the same list in the same order and ordering is not a presentation decision.

The response also carries whether the surface applies at all: the resolved event, whether the caller is inside the lead-up window (10.1), and whether the caller has hidden it. A caller outside the window receives the same shape with the surface reported as not applicable rather than a `404`, so a client can distinguish "not yet" from "no such event".

Authorization is event access alone. The endpoint requires no capability of its own (HORIZON-002). Each kind is evaluated under the caller's existing authorization for the domain it reads, and a kind whose records the caller cannot read is omitted from the response rather than returned empty or as inaccessible.

Commands:

```text
POST /api/commands/hide-event-horizon
POST /api/commands/show-event-horizon
```

`hide-event-horizon` is refused while any item is outstanding (HORIZON-013). The refusal is server-side rather than a hidden control, so a client that offers the action early does not succeed. Both commands write personal view state, are not audited, and act only on the caller's own preference — neither accepts a subject staff member.

The Event Horizon compiles on read and stores no result, so no read enters the outbox in 5.6. The two preference commands may be queued offline like any other command.

### 5.9 Module Gating

Every endpoint in this specification belongs either to a product module (MOD-002) or to core (MOD-004). The owning module is declared once per route group rather than checked per controller.

Module-owned endpoint groups:

```text
scheduling      /api/departments/{department}/shifts*
                /api/events/{event}/shifts*
                /api/events/{event}/shift-board
                /api/commands/*-shift*, *-signup*
ims             /api/events/{event}/field-reports*
                /api/events/{event}/incidents*
                /api/commands/*-field-report*, *-incident*
documents       /api/organizations/{organization}/documents
                /api/policy-documents*, /api/procedure-documents*
                /api/document-fragments*, /api/document-acknowledgments*
                /api/commands/*-document*, *-acknowledg*, *-waiver*
qualifications  /api/trainings*, /api/events/{event}/credentials*
                /api/commands/*-training*, *-credential*
equipment       /api/commands/*-equipment*
geography       /api/events/{event}/maps*, /api/events/{event}/camps*
                /api/commands/*-map*, *-camp*, *-deployment*
briefing        /api/events/{event}/briefing*, /api/notes*
                /api/commands/*-note*, *-briefing*
insights        /api/insights/* (5.8)
```

Gate behavior:

- The gate runs after organization resolution and before authorization, so the response does not vary by caller.
- An inactive module answers `404` with `{"error": {"code": "module_inactive", "module": "<key>"}}`.
- Command endpoints are gated by the module that owns the command, using the same rule. A gated command never reaches its handler and never writes an audit event for an attempted domain change.
- A command replayed from the outbox against an inactive module is refused the same way, and the refusal is recorded as a sync conflict (MOD-017) rather than returned as a plain client error, because the submitting device may be long gone.
- Session resolution (5.5) returns the organization's active module set. It is core and is never gated.
- Exports owned by a module are unavailable when it is inactive; the short-lived download URL (5.7) is not issued.

Departments operations read models (Overview, Logistics, Operations, Planning) are core endpoints that compose module-owned data. They omit the sections whose modules are inactive and return the rest (MOD-019). They never refuse on module state.

The Event Horizon read (5.8A) is core on the same footing. Each item kind declares its owning module, a kind owned by an inactive module is omitted, and the endpoint returns the rest. Where no kind remains available to the caller it reports the surface as not applicable rather than returning an empty list, so an organization running none of the owning modules does not present a staff member with a readiness page that reads as "you are ready" (HORIZON-017).

---

## 6. Permission Model

### 6.1 Permission Administration

Permission administration is owned by Orchid.

Orchid provides the UI for:

- permission catalog
- roles/effective permission levels
- team-based grants
- IC team grants
- god-mode/admin assignment where allowed
- permission audit review

### 6.2 Permission Enforcement

Permission enforcement is owned by Laravel.

Laravel policies, gates, middleware, command handlers, and domain services enforce authorization for:

- API requests
- Orchid actions
- PowerSync upload handling
- mobile/PWA actions
- Electron/shared-workstation actions
- exports
- incident access
- Field Report visibility
- Name Reference visibility through source Incident notes and Field Reports
- policy/procedure visibility
- document acknowledgment
- staff status changes
- department/team/event assignment
- attendance operations
- department presence operations
- equipment operations
- deployment assignment operations
- credit calculation and exports

### 6.3 Authority Sources

System authority should come through organization, department, or team membership.

Primary authority model:

- organization roles
- event roles
- department roles
- team roles/grants
- organizer roles granted through the configured Organizers Department
- IC roles granted to teams inside the event-selected IC department

Direct user roles are restricted to god-mode/admin repair needs. Department, event, shift, and IC exceptions should use contextual membership/grants rather than free-floating user permissions.

### 6.4 Effective Permission Levels

Alpha 1 effective permission levels include:

```text
staff
shift_lead
department_lead
department_logistics
department_operations
department_administration
department_planning
department_operator
ic_lead
ic_operator
ic_viewer
organizer
lead_organizer
staff_coordinator
god_mode
```

Alpha 1 department operational grants are department-scoped and assigned through
teams:

- `department_logistics` manages department presence, staff-mediated attendance, and equipment checkout/check-in through the Logistics Desk staff workspace.
- `department_operations` opens the Operations Center and manages current deployment/location assignment. The shell does not grant incident or equipment module access.
- `department_planning` views identity-free Planning Table aggregates comparing plan versus actual by shift/team window.
- `department_administration` manages department/team administrative settings as permitted.
- `department_operator` is the department dispatch/console function (requirements 4.8A). It enters the catalog with the team designation model (TEAM-012); its capability set and the derived event-scoped `ic_operator` elevation are owned by M18.10A.
- `staff_coordinator` is organization-scoped through a designated team within the configured Organizers Department (TEAM-014). It carries `organization.applications.review` — application review, approval, rejection, and deferral, plus assigning approved applicants to departments (requirements 4.4) — and no other organizer governance capability. Organizers and Lead Organizers hold the same capability, so one policy answers for the whole reviewer population.

Authorized attendance managers (TEAM-015) are the holders of
`department_logistics` for the department together with department leads and
shift leads for that department, so `department.attendance.manage` is carried
by all three roles — for `shift_lead`, through its own lead-designated-membership
scoping — and check-in, check-out, mark-no-show, and hours correction all
answer to that one capability (SLB-007, SLB-029; HOURS-007). Presence and
equipment stay with `department_logistics`.

Department team designations (TEAM-011 through TEAM-013) attach these grants to
a named team per function through `team_designations` (section 10.6); the
designation creates and owns a `team_grants` row rather than becoming a second
authority path.

Permission decisions should be explainable in the UI.

Example:

```text
You can mark no-show because your team has Department Logistics for this department.
```

Denied actions should show a reason when practical.

### 6.5 Incident Command Permissions

Incident Command is event-specific.

IC access is derived from:

```text
event
+ selected IC department
+ team membership
+ team-granted IC role
```

Normal teams inside the IC department do not automatically receive incident visibility.

Incidents are visible only to IC roles. Organizers do not see incidents unless their department/team is functioning as IC and they hold an IC role.

Incident rows must not sync to users/devices without IC access.

UI hiding is not sufficient.

Name References inherit source-record visibility. A derived Name Reference token must not sync to a user or device unless the user is allowed to receive the underlying Incident note or Field Report text that produced it.

### 6.6 Event Map and Placement Permissions

Event map authority is event-scoped and follows the same per-event designation pattern as the IC department. Map capabilities are granted primarily through event organizers/admins and the event's designated Placement department.

Suggested map capabilities (module.action style):

```text
maps.view
maps.view_camp_data
maps.view_sensitive
maps.manage_draft
maps.publish
camps.manage
map_assets.manage
maps.override_locked
```

Authority sources:

- organizers/admins (`organizer`, `lead_organizer`, `god_mode`) may view, manage, publish/archive, and perform locked-map overrides per documented rules
- the designated Placement department's lead(s) (`department_lead` scoped to that department) may manage draft maps, camps/locations, and map assets/packages, and may publish/archive maps for that event before the operations window begins
- non-lead Placement department members receive view capabilities by default and edit capabilities only when granted a map-management grant within the Placement department, using the same event/team grant mechanism as IC roles
- if no Placement department is designated, map editing falls back to organizers/admins/map managers

Scoping rules:

- the Placement designation applies only for the event where the department is designated and never makes a department globally special
- the Placement designation does not grant IC/organizer authority, and IC/organizer authority does not grant map-management authority by itself
- a department may be designated as both Placement and another special department for the same event, but each designation grants only its own authority
- `maps.override_locked` is reserved for organizers/admins for MVP and is not part of the Placement department lead role
- only `published` maps are visible to permitted operational users; `draft`/`archived` maps require map edit/admin permission
- camp names and operational map data are not exposed to users who lack map permissions
- the exact effective-permission-level role codes for the Placement department are left to the implementing milestone (see section 16)

### 6.7 Insights Permissions

Insights reuse the effective roles in 6.4 and the team-grant mechanism. No parallel permission system is introduced.

Capabilities (module.action style):

```text
insights.view
insights.sheets.manage
insights.share_with_command
```

Authority sources:

- `insights.view` is granted to the operational roles that need compiled views: `department_lead`, `department_logistics`, `department_operations`, `department_planning`, `organizer`, `lead_organizer`, and the IC roles. Staff hold a personal-Insights path without this capability, limited to their own aggregates.
- `insights.sheets.manage` is granted to `department_lead`, `organizer`, and `lead_organizer`.
- `insights.share_with_command` is granted to `department_lead`, so a department decides what leaves it.

Scoping rules:

- a metric renders only when the viewer holds the capability the metric's registration declares; the framework refuses otherwise, so a new metric cannot leak a domain by omitting a check
- `insights.sheets.manage` grants configuration authority only. It never widens data access, and a sheet cannot be configured to expose data its author cannot read
- organizers see across departments for the selected event, subject to domain restrictions defined elsewhere
- a department lead not also authorized through a team under the Organizers Department sees only the department being viewed
- Command means `ic_lead`, `ic_operator`, or `ic_viewer` in the event's designated Incident Command Department, matching the Briefing's Command pool. Command sees its own department plus content shared with it
- `department_planning` and `department_logistics` see only their own department unless another capability independently grants more
- incident- and Field-Report-derived metrics require the corresponding IC capabilities from 6.5
- an aggregate covering fewer than 5 people is suppressed, and suppression is applied server-side rather than by the rendering client

### 6.8 Module Gating and Permissions

Module state and permissions are separate boundaries evaluated in a fixed order: module gate first, permission check second (technical spec 15A.4).

- A capability an organization does not run is unreachable to everyone in it, God Mode included, through the product API. God Mode changes module state from the console; it does not bypass the gate.
- Role grants for an inactive module are retained, not revoked (MOD-014). `PermissionCatalog` keeps every permission constant regardless of module state, and the effective-permission resolver is unchanged by it.
- A permission a user holds for an inactive module is simply never consulted. It becomes effective again the moment the module is active.
- Permission-explaining UI (technical spec 15.1) does not explain module absence as a permission denial. "Your organization does not use Scheduling" is a different sentence from "you cannot manage shifts", and the client renders the one the refusal reason names.

Module entitlement administration requires God Mode. Module enablement requires `organization.configuration.manage`, the same capability that governs the rest of ORG-018 configuration.

---

## 7. Sync Model

### 7.1 PowerSync Device Cache

Devices cache authorized data.

Regular staff may cache:

- their own shifts
- their department/team info
- Field Report form
- basic event info
- their own submitted Field Reports
- published policies/procedures visible to them
- published fragments referenced by those visible documents
- their policy/procedure acknowledgment status
- Notes they authored
- Briefing-included Note presentations for events they can access
- readiness/sync state

Command-capable devices may additionally cache Command-visible Notes for the event (author+Command pool).

Department Logistics users may additionally cache:

- department-scoped searchable staff, equipment, and shift indexes for the current event/department
- department on-site/off-site presence state
- current, upcoming, and outgoing shift context for selected staff
- check-in/check-out/no-show state for those department shifts
- department equipment state and open checkouts they are permitted to manage
- future shift signups needed for the selected staff workspace

Department Operations users may additionally cache:

- current and upcoming shift assignments for their department
- current deployment/location assignment for those shifts
- active deployment/location options
- capability-authorized overview module payloads only; the Operations Center shell does not expand cache authority by itself

Department Planning users may additionally cache:

- identity-free plan-versus-actual aggregate rows by shift/team window
- aggregate fields only: capacity target, signed-up/assigned count, checked-in count, no-show count, unscheduled additions, planned hours, actual hours, and variance/status
- explicit data-freshness metadata

Department leads may additionally cache:

- Department Overview selected-shift summaries, exceptions, checked-in staff, assignments, and compact equipment/deployment readiness identifiers
- department roster
- department schedule
- department attendance data
- draft, published, and archived policy/procedure documents and fragments they are allowed to maintain

IC roles may cache:

- last viewed limited incident data
- related Field Reports where permitted
- derived Name Reference tokens from cached Incident notes and related Field Reports where permitted

Incidents should not be greedily synced.

Derived Name Reference caches must be rebuildable from source text and must not broaden offline access.

Permitted users/devices may additionally cache the event map package by default:

- published placement maps and published topographic map packages they may view
- permitted camp/location records
- kiosk devices receive the event map offline by default when published and permitted
- lead/IC devices receive map data according to permissions

Sensitive map layers/features must not sync to users/devices without permission; UI hiding is not sufficient. Locked operations-window map data remains stable offline.

### 7.2 Alpha 1 Offline Writes

Alpha 1 offline writes include:

- Field Report creation
- Field Report photo attachment sync
- check-in
- check-out
- mark no-show

Supported offline writes are available wherever the corresponding product
surface is available and the device has the required synced local data. Offline
write support is not granted or denied by UI mode alone.

Policy/procedure acknowledgments are not creatable offline in Alpha 1.

Incident creation requires server connection.

Incident reads may be available offline when synced and authorized. Incident
creation and mutation are online-only in every UI mode.

Map editing (maps, camps, map locations, assets, publishing/archiving, locked-data overrides) is not an offline write for MVP; map packages and permitted camp/location data sync down read-only.

Field Reports are finalized when submitted. There are no Field Report drafts.

### 7.3 Server-Only or Restricted Data

The following data is server-only or restricted unless explicitly included in an authorized projection:

- incident list/details/creation/edit for non-IC devices
- incident rows for users/devices without IC access
- image binaries downloaded to user devices
- exported reports
- global admin configuration
- full audit archives
- sensitive DNS/removal details except where needed for enforcement
- staff emergency contact data except on trusted devices for users authorized to access it
- permission administration records except where needed for local authorization decisions
- draft and archived maps for users without map edit/admin permissions
- sensitive map layers/features and the camp/location data they contain for users without permission

Beyond this list, sync rules are scoped by the caller's effective roles. A device does not receive records its user could not retrieve through the API, and a change to a user's effective roles changes what subsequently replicates to that user's devices. The cache lists in 7.1 describe what a role should receive; this rule is the boundary on what it may receive.

Insights compile on the device from data already synchronized there under this rule. Insight Sheet and placement definitions sync to users who may view them; no compiled Insight value syncs, because none is stored. This is why offline Insights work and also why they are bounded — a device compiles what it holds, and it holds only what its user may read. A metric that cannot compile from local data reports incomplete rather than reaching past the boundary.

### 7.4 Node Sync

Central and on-site sync is operation-based.

Node operations are append-only, idempotent, signed, and stored before application.

During the active event window:

- the on-site primary node is authoritative for event-scoped records
- central is read-only for that event except for data arriving from the on-site primary node
- permission changes happen only on the on-site primary node
- policy/procedure edits and fragment edits are blocked
- fragment changes are disallowed to avoid silent document-version bumps mid-event
- acknowledgments collected on-site sync back to central

After the event closes, post-event corrections happen on central.

Enforcing event authority:

An event's phase is derived from `events.active_event_window_starts_at` and
`active_event_window_ends_at`: preparation before the window, active inside it,
closed after it. An archived event and an event with no window start are never
active, because authority is a handover and neither records when the handover
would happen; a window with no end stays active until an end is recorded.

The on-site primary node for an event is an active, paired `nodes` record with
role `onsite` whose `event_id` names that event or names nothing, preferring the
one that names it. Alpha 1 has exactly one active on-site node per event
(technical spec 10.1) and pairing does not carry an event, so an on-site node
that has not recorded which event it serves is taken as the on-site node of the
single event the install is running.

Both write paths defer to that node while the window is open:

- local writes to event-scoped records are refused on any node that is not the
  on-site primary node. Refusal is by `event_id`, including records that reach
  their event through a parent row, and including event-scoped `team_grants`,
  which is how the permission-change rule is enforced. `node_operations`,
  `audit_events`, incident list presets, and the `events` row itself are
  exempt: a read-only node must still record what it received and refused, and
  must still be able to close or extend the window that makes it read-only
- an authentic node operation naming an event is refused unless its origin node
  is the on-site primary node for that event (section 13.3). Applying a stored
  operation is not refused, because data arriving from the on-site primary node
  is the documented exception to central being read-only

Nothing is refused outside the active window, and nothing is refused while no
on-site primary node is known, since there would be no node to hand authority
to.

Freezing governance content:

Policy/procedure and fragment edits are blocked for the duration of the window
on every node, including the on-site primary node that holds authority over
event-scoped records. This is a separate rule from authority: authority moves a
write to one node, whereas a frozen edit may be made on none. A fragment edit
raises the version of every published document that references it, and staff
acknowledge a document at a version, so a mid-event edit would change what an
acknowledgment already taken refers to.

Governance content carries no `event_id`, so the window is resolved through
`organization_id`: `policy_documents`, `procedure_documents`, and
`document_fragments` are frozen while any event of their organization is in its
active window, and `document_fragment_references` and
`document_fragment_version_bumps` reach their organization through their
fragment. An organization's window freezes only that organization's content.

`document_acknowledgments` and `document_version_snapshots` stay writable, since
acknowledgments collected on-site sync back to central and the snapshot is
written as part of accepting one. `document_acknowledgment_requirements` stay
writable too: a requirement is the live signup gate rather than document
content, changing one bumps no version, and a requirement blocking signups has
to be liftable while the event runs.

A refused edit is HTTP 409 with reason code
`governance_frozen_during_active_event`, naming the event whose window is open
and no authoritative node, because no node may make the edit. Applying a
received node operation is not refused: no node can create a document or
fragment operation while the window is open, so one arriving mid-window carries
an edit made before it opened.

### 7.5 Sync Conflicts

Conflicts go to a sync conflict queue.

For Alpha 1, conflicts are visible only in God Mode / Orchid.

Conflicts should be grouped by entity type and resolved by choosing either:

- accept on-site
- accept central

Conflict resolution is audited.

Unresolved conflicts should not block unrelated sync.

Offline server rejections and conflicts are deferred to the God Mode conflict
queue. Until that queue exists, product surfaces may fail silently after
recording enough queued/sync-failed local state for later repair.

### 7.6 Module-Scoped Replication

Sync rules are scoped by the organization's active modules in addition to the user's effective roles (MOD-016, technical spec 9.5 and 11A.7).

- Every synced table declares its owning module, or declares itself core. A table with no declaration is core, so an undeclared table stays replicating rather than silently disappearing from devices.
- A device receives a module's records only when the module is active for the organization *and* the user's effective roles permit them. Both conditions are evaluated server-side in the sync rules; neither is a client filter.
- Deactivating a module removes its records from devices at the next sync. Activating one replicates the permitted records back. No local purge is required beyond the normal rule-change behavior.
- Module state itself replicates to devices as part of the organization record, so an offline client gates navigation on the last known state.
- Node-to-node sync is not module-scoped. An on-site node holds its organization's event records as it does today, and module state syncs with the rest of organization governance data from the authoritative central node.

---

## 8. Audit Model

Every meaningful change should be attributable to a user and timestamped.

Audit applies to:

- permission changes
- auth/device trust events
- shared workstation login code generation/use
- node pairing/config changes
- attendance direct edits
- dangerous God Mode actions
- sync conflict resolution
- incident status/title/link changes
- incident note creation
- node operation acceptance/rejection
- failed sync thresholds
- policy/procedure publish/archive/version changes
- fragment edits and fragment version changes
- policy/procedure acknowledgments
- policy/procedure export and print events
- sensitive read/view events for incidents, DNS status, and exports
- event map publishing and archiving
- Placement department designation changes
- locked-map data overrides
- sensitive map reads/exports, where existing sensitive-read audit principles apply
- Note creation
- add-note-to-briefing / add-note-to-aar (reference or link)
- AAR submit/publish/auto-assemble/freeze (post–Alpha 1)
- Direction, Action Plan, and Notice mutations and Notice dismissals (post–Alpha 1)
- Insight Sheet creation and material configuration change
- Insight Sheet sharing with Command, metric-placement sharing with Command, and unsharing
- automatic no-show determination, through the audited attendance operation it writes

Insight PDF generation is not audited. The browser generates it from a view the caller is already authorized to see, and a user can print any page they can read, so an entry would record only the cases where someone used the button while presenting itself as a record of who exported what. This differs deliberately from incident PDF export, which the server produces and therefore audits.

Insight favorites and pins are personal view state and are not audited.

Audit payloads for Insights reference staff and users by identifier. They do not carry volunteer names or other personal data.

Audit entries should capture:

- actor user
- actor device, if applicable
- actor node, if applicable
- action
- entity type
- entity ID
- organization ID where applicable
- event ID where applicable
- department ID where applicable
- before/after values where relevant
- timestamp
- reason/comment where required
- source context
- signature metadata where relevant

Automatic document version bumps caused by fragment changes do not need separate audit entries beyond the audited fragment edit and resulting document version metadata.

---

## 9. Core Data Domains

Meridian's Alpha 1 data model is organized into these domains:

1. Organizations
1A. Organization modules
2. Events
3. Users and authentication
4. Staff
5. Applications
6. Departments
7. Teams
8. Memberships
9. Permissions and effective roles
10. Trainings
11. Waivers
12. Shifts
13. Attendance and hours
14. Credentials
15. Credits
16. Equipment
17. Deployments/locations
18. Field Reports
19. Incidents
20. Attachments/files
21. Policy Documents
22. Procedure Documents
23. Document Fragments
24. Document Acknowledgments
25. Document Exports
26. Event maps and geography
27. Notes and The Briefing (Notes + Briefing inclusions; post–Alpha 1: AARs, Directions, Action Plans, Notices)
28. Devices and trust
29. Shared workstations
30. Nodes and node sync
31. Audit
32. Sync conflicts

---

## 10. Canonical Entity Map

### 10.1 Organizations

#### `organizations`

Represents an organization that manages staff.

Key fields:

- `id`
- `name`
- `slug`
- `branding_display_name`
- `branding_palette_json`
- `branding_full_lockup_attachment_id`
- `branding_compact_mark_attachment_id`
- `department_branding_enabled`
- `branding_updated_at`
- `organizers_department_id`
- `default_ic_department_id`
- `default_placement_department_id`
- `default_credit_policy_id`
- `active_inactive_threshold_years`
- `prospective_inactive_threshold_years`
- `calendar_year_start_month`
- `calendar_year_start_day`
- `hours_correction_grace_period_days`
- `event_horizon_lead_days`
- `handle_change_policy`, nullable
- `profile_picture_change_policy`, nullable
- `handle_self_service_change_limit`, nullable
- `created_at`
- `updated_at`
- `archived_at`

Configuration fields (ORG-017, ORG-018, VOL-027, VOL-028, HORIZON-011):

- `hours_correction_grace_period_days` is the ORG-017 hours correction window, expressed in days after event end. It defaults to 14 and is never null: an organization that has not configured one still has the documented default.
- `event_horizon_lead_days` is the HORIZON-011 lead-up window, expressed in days before the event's active window start. It defaults to 30 and is never null. It is held in days rather than as a date so that moving an event's dates moves the window with it, on the same reasoning as the SHIFT-017 relative schedule cutoff.
- `handle_change_policy` and `profile_picture_change_policy` each hold one of `organizer_only`, `organizer_sets_first`, `auto_approved`, or `staff_sets_first` (VOL-027). Null reads as `organizer_only`, the documented default. The value is stored null rather than defaulted in the schema so an organization that never chose stays distinguishable from one that chose the default deliberately, and so a later change of default reaches the first of them.
- `handle_self_service_change_limit` is the VOL-028 allowance, and null reads as two. It is consulted only while `handle_change_policy` is `auto_approved`; zero switches the allowance off without changing the policy. Profile pictures have no equivalent column because pictures applied without review are not rationed.
- `notifications_suppressed_at` is the NOTIFY-009 per-organization send-suppression switch. Null means this organization sends notification email; a timestamp means it does not, and records when that was decided. It is a timestamp rather than a boolean because "suppressed since" is the fact an operator wants when a restored copy of an organization's data stops mailing. The global development suppression is separate configuration, and either alone stops a send.

Branding fields (BRAND-001, BRAND-004, BRAND-006, BRAND-013):

- `branding_display_name` is the name shown on signed-in product surfaces. Null falls back to `name`.
- `branding_palette_json` holds the ten settable colors — `primary`, `secondary`, `tertiary`, `accent`, `canvas`, `surface`, `foreground`, `muted_foreground`, `border`, `focus` — each an opaque `#rrggbb`. Null means Meridian's default palette. Derived tokens are never stored here.
- The two logo columns reference the *current* attachment for each slot. Replacing a logo repoints the reference at a new attachment; removing it nulls the reference. Superseded assets are not preserved.
- `department_branding_enabled` is the organization-wide switch. When false, departments retain logo and accent identity only.

Relationships:

- has many events
- has many departments
- belongs to Organizers Department
- has many staff through staff organization status records
- has many policy documents
- has many procedure documents
- has many document fragments
- has many credit policies
- has current full-lockup and compact-mark branding attachments
- has many organization modules

---

### 10.1A Organization Modules

#### `organization_modules`

Records one organization's state for one module in the fixed catalogue (MOD-002). One row per organization per module.

Key fields:

- `id`
- `organization_id`
- `module_key`
- `entitled`
- `enabled`
- `entitlement_changed_at`
- `entitlement_changed_by_user_id`
- `enablement_changed_at`
- `enablement_changed_by_user_id`
- `created_at`
- `updated_at`

Field notes:

- `module_key` is one of `scheduling`, `ims`, `documents`, `qualifications`, `equipment`, `geography`, `briefing`, `insights`. It is validated against the code-defined catalogue on write; an unknown key is rejected rather than stored. The catalogue is not a table, because it is Meridian's own build-time constant and a database row must never be able to invent a module the code does not implement.
- `entitled` is the God Mode decision (MOD-006). `enabled` is the organization's decision (MOD-008). Both are non-null booleans.
- The two are stored separately and never collapsed into one effective column. Revoking entitlement must not destroy the organization's own choice, so `enabled` survives an entitlement revoke and is honored again when entitlement returns (MOD-007).
- Active is computed as `entitled AND enabled`. It is not stored.
- A missing row means entitled and enabled, so an organization created before this table existed and a module added to the catalogue in a later build both default to available rather than silently absent. Rows are written for every module at organization creation (MOD-009), making the missing-row case a migration and upgrade safety net rather than a normal state.
- The `*_changed_at` / `*_changed_by_user_id` pairs record the last transition of each state for display. They do not replace the audit trail; every transition writes an audit event with previous and new state, actor, and any supplied reason (MOD-011).

Constraints:

- unique on (`organization_id`, `module_key`)
- `module_key` validated against the code catalogue on write
- writes are refused during the organization's active event window (MOD-010), on the same governance rule as organization configuration and published documents
- the central node is authoritative; an on-site node does not originate module state changes

Relationships:

- belongs to organization

Replication:

- replicates to devices as organization-scoped governance data, so an offline client can gate navigation on the last known state (7.6)

---

### 10.2 Events

#### `events`

Represents a specific event produced by an organization.

Key fields:

- `id`
- `organization_id`
- `name`
- `slug`
- `starts_at`
- `ends_at`
- `timezone`
- `status`
- `ic_department_id`
- `placement_department_id`
- `branding_logo_attachment_id`
- `branding_updated_at`
- `active_event_window_starts_at`
- `active_event_window_ends_at`
- `created_at`
- `updated_at`
- `archived_at`

Branding fields (BRAND-028, BRAND-029, BRAND-030):

- An event carries a logo reference and nothing else. There is no event accent and no event palette: the palette is the organization's, and a second settable palette would be a second set of contrast pairs nobody validated.
- The logo is an attachment reference on the existing branding asset path, subject to the same MIME and size constraints as every other branding logo (BRAND-023).
- Where a node is locked to this event, the logo replaces the organization mark in the application header, the browser tab icon, and the desktop window icon. Most staff working an event were recruited by the event rather than by the company producing it, and a producer's mark identifies nothing to someone who has never heard of the producer.
- The lock is read from `nodes.event_id`, not from the signed-in user — a Kiosk has no user, and the node is what knows which event the install is running. A node whose lock names an event belonging to a different organization resolves to no locked event, so one organization's chrome can never show another's mark.
- Event logos are edited under organization branding authority (`organization.branding.manage`), not under a department's. An event spans every department in it, and its mark is what most of its staff will take the whole product to be.
- Only the locked event appears in the unauthenticated branding profile read. The full list of an organization's events and their marks is served separately, behind a session.

Relationships:

- belongs to organization
- has a current branding logo attachment
- has many event department assignments
- has many shifts
- has many attendance operations
- has many hours records
- has many Field Reports
- has many incidents
- has many credentials
- has many policy/procedure acknowledgment records where relevant
- has many event maps
- has many camps
- has many map locations
- belongs to Placement department (nullable, through `placement_department_id`)

Rules:

- `placement_department_id` is nullable; an event may designate zero or one Placement department.
- When set, `placement_department_id` must reference a department assigned to the event.
- `placement_department_id` defaults from the organization `default_placement_department_id` where set, and the event may override it.
- The Placement designation is event-scoped: it grants map/placement authority only for this event and does not make the department globally special, IC, or Organizers.
- A department may be the Placement department and also the IC department (or Organizers) for the same event; each designation grants only its own authority.

---

### 10.3 Users and Authentication

#### `users`

Represents a login-capable person.

Key fields:

- `id`
- `name`
- `email`
- `secondary_email`, nullable
- `secondary_email_verified_at`, nullable
- `current_staff_id`
- `created_at`
- `updated_at`
- `disabled_at`

Relationships:

- has many auth identities
- may link to one or more staff profiles
- may have god-mode/admin direct roles where allowed
- appears as actor in audit events and node operations

Rules:

- `email` is the primary email address.
- `secondary_email` is optional and limited to one address per user.
- Primary and secondary email addresses are globally unique across all users.
- `secondary_email` is ignored for login matching until `secondary_email_verified_at` is set.
- Users may add or remove their own secondary email address.
- God mode may change `email` and is trusted to treat the new primary email as verified.
- Magic-link account creation is controlled by a system setting and defaults to enabled for Alpha 1 testing and development.
- Adding, verifying, or removing a secondary email address and changing a primary email address should create audit/history entries when the audit service is available.

#### `auth_identities`

Represents external authentication providers.

Key fields:

- `id`
- `user_id`
- `provider`
- `provider_subject`
- `provider_email`
- `provider_email_verified`
- `created_at`
- `updated_at`

Supported Alpha 1 providers:

- email magic link
- Google OAuth
- Discord OAuth

There is no password login.

---

### 10.4 Staff

#### `staff`

Represents a person working with one or more organizations.

Key fields:

- `id`
- `legal_name`
- `preferred_name`
- `handle`
- `formerly_known_as`
- `email`
- `phone`
- `city`
- `state`
- `date_of_birth`
- `emergency_contact_name`
- `emergency_contact_phone`
- `profile_picture_path`, nullable
- `profile_picture_mime_type`, nullable
- `profile_picture_size_bytes`, nullable
- `profile_picture_width`, nullable
- `profile_picture_height`, nullable
- `profile_picture_uploaded_at`, nullable
- `created_at`
- `updated_at`
- `archived_at`

Rules:

- `handle` is the operational/radio handle.
- `formerly_known_as` is a simple text field for Alpha 1.
- Emergency contact data is server-only except on trusted devices for users authorized to access it.
- Staff profile pictures are optional and store only the current picture.
- Staff profile records require legal name and email at creation. Preferred name, handle, phone, city/state, date of birth, and emergency contact fields are nullable so records can be completed later.
- Staff may upload, replace, or remove their own profile picture only after becoming `active` in at least one organization.
- Replacing or removing a profile picture does not preserve previous image blobs for Alpha 1.
- Staff profile picture uploads support JPEG, PNG, and WebP.
- Staff profile picture uploads are limited to 10 MB before server processing.
- Large profile pictures are resized so stored dimensions do not exceed 1024 x 1024 pixels.
- Staff profile picture blobs sync lazily as they are accessed; metadata may sync before the image blob.
- Missing profile picture blobs should render as a placeholder or pending image state until synced.
- Profile picture visibility follows staff profile visibility.
- Preferred name, phone, and city/state are self-service fields a staff member edits directly (VOL-015).
- Legal name, email, and date of birth are not self-editable and change only through an assisted path (VOL-016).
- `handle` changes through `staff_profile_change_requests`, self-service for the first two applied changes and by review after that (VOL-017).
- The current picture columns hold the approved picture only. A submitted picture awaiting review lives on its request row and never on `staff` (VOL-021).

Relationships:

- may link to one or more users
- has staff organization status records
- has department memberships
- has team memberships
- has event assignments
- has shift assignments
- has attendance/hours records
- may submit Field Reports
- may be associated with incidents
- has profile change requests

#### `staff_profile_change_requests`

Represents a staff-initiated profile change that a reviewer decides: a handle change beyond the self-service allowance, or a profile picture submission.

Key fields:

- `id`
- `staff_id`
- `organization_id`, the organization whose organizers and Staff Coordinators review it
- `requested_by_user_id`
- `kind`, one of the kinds below
- `status`, one of the statuses below
- `previous_handle`, nullable
- `requested_handle`, nullable
- `pending_picture_path`, nullable
- `pending_picture_mime_type`, nullable
- `pending_picture_size_bytes`, nullable
- `pending_picture_width`, nullable
- `pending_picture_height`, nullable
- `self_service`, whether the change applied without review under the VOL-017 allowance
- `decided_by_user_id`, nullable
- `decided_at`, nullable
- `decision_reason`, nullable
- `dismissed_at`, nullable
- `created_at`
- `updated_at`

Kinds:

```text
handle
profile_picture
```

Statuses:

```text
pending
approved
rejected
withdrawn
```

Rules:

- every handle change is recorded here, including the two self-service changes VOL-017 allows. A self-service change is written `approved` with `self_service` true, no `decided_by_user_id`, and `decided_at` set to when it applied.
- the VOL-017 allowance is the count of applied handle changes for the staff record, so no separate counter column exists and the history is the accounting. How many changes the allowance grants, and whether it applies at all, come from the reviewing organization's `handle_self_service_change_limit` and `handle_change_policy` (VOL-027, VOL-028).
- `dismissed_at` marks a decided request the staff member has cleared from their own surface (VOL-029). It hides the notice and nothing else: the row stays readable to reviewers and audit, and an approved handle row keeps counting against the allowance.
- setting a handle where the staff record holds none is not a change and is written with `previous_handle` null and `self_service` true without consuming the allowance (VOL-017).
- only `approved` rows count against the allowance. `rejected` and `withdrawn` rows do not (VOL-018).
- a staff member holds at most one `pending` row per kind (VOL-024).
- a `pending` handle row names any other staff member with `active` status in `organization_id` already holding `requested_handle`, resolved at review time rather than stored, so the reviewer decides a collision (VOL-020).
- a submitted picture is stored under the same processing and limits as a current picture — JPEG/PNG/WebP, 10 MB before processing, resized within 1024 x 1024, EXIF stripped — and is readable only by the submitting staff member and the users who may review it (VOL-021).
- approving a `profile_picture` row moves its stored image to the staff record's current picture columns and clears the pending columns; rejecting or withdrawing one discards the stored image (VOL-022).
- removing a current picture is not a request and creates no row here (VOL-023).
- a decision notifies the submitting staff member through the notification path in requirements section 7.24, carrying `decision_reason` on a rejection (VOL-025).
- creation, decision, and withdrawal are audited with actor, kind, and previous and requested value (VOL-026).

Relationships:

- belongs to a staff record
- belongs to an organization
- references the requesting user and, once decided, the deciding user

#### `staff_organization_statuses`

Represents a staff member's organization-level status within one organization.

This is an internal status record for a staff profile, not a separate kind of staff. Product UI should refer to Staff and staff organization status, not "organization staff" or "organizational staff."

Key fields:

- `id`
- `organization_id`
- `staff_id`
- `status`
- `status_reason`
- `status_changed_at`
- `status_changed_by_user_id`
- `created_at`
- `updated_at`

Statuses:

```text
prospective
active
inactive
emeritus
retired
do_not_staff
```

Rules:

- organization-level staff status supersedes department status
- `do_not_staff` prevents system access and participation
- DNS is permanent unless changed by organizers
- DNS applicants are auto-rejected without automatic notice
- working for any department prevents automatic organization-level inactivity

---

### 10.5 Applications

Applications name the scope they were made to (APP-001): an event, or the organization itself. `event_applications.event_id` is nullable, and a null event is the whole of what an organization-scoped application is.

One nullable column rather than a second table, because the two intakes differ in what the application is *about* and in nothing else. They share the status set (APP-003), the reviewers (APP-005), Do Not Staff auto-rejection (STAT-006), applicant-only withdrawal (APP-004), and the approval outcome — approval has always created organization-level Prospective status (APP-006), so an application naming no event already lands exactly where an approved event application lands.

Uniqueness of an open application is per scope, not per organization: one address may hold an open event application and an open organization application at once, because "I want to work Emberfall" and "I want to join Northwood" are different offers.

`organizations.accepts_organization_applications` (APP-018) decides whether the organization scope is offered at all. It defaults to false.

#### `event_applications`

Represents event-specific intake.

Key fields:

- `id`
- `event_id`
- `organization_id`
- `staff_id`, nullable until matched/created
- `applicant_email`
- `applicant_legal_name`
- `status`
- `submitted_at`
- `reviewed_at`
- `reviewed_by_user_id`
- `decision_reason`
- `withdrawn_at`
- `created_at`
- `updated_at`

Statuses:

```text
submitted
approved
rejected
deferred
withdrawn
auto_rejected_dns
```

Rules:

- applicants apply to events, not directly to departments
- optional department interest (APP-011) may be recorded at submission as a non-binding intake signal; it is not department assignment, membership, approval, or access
- approval happens at the organization level
- approved applicants become organization-level prospective staff
- department/team assignment happens after organization approval
- approved applications may be rescinded before team assignment
- applications cannot be rescinded after team assignment

#### `event_application_department_interests`

Represents optional, unordered, non-binding department interest recorded with an event application.

Key fields:

- `id` (UUID)
- `event_application_id`
- `department_id`
- `created_at`
- `updated_at`

Rules:

- one department may appear at most once per application (unique on `event_application_id` + `department_id`)
- no `preference_order` or ranking fields
- interest records are preserved when a department is later archived, removed from the event, or renamed; review UI may show current department name with inactive or archived treatment
- department interest does not create department membership, team membership, assignment, access, routing, or notifications

Relationships:

- belongs to `event_applications`
- belongs to `departments`

#### `POST /api/commands/submit-application`

Submits an event application online. Department interest is captured as part of this command in Alpha 1 and has no separate offline or sync behavior.

Request payload (domain fields):

- `event_id` (required)
- `applicant_legal_name` (required for public applicants; may be omitted when authenticated applicant identity is already known)
- `applicant_email` (required for public applicants; may be omitted when authenticated applicant identity is already known)
- `department_interest_ids` (optional): array of department UUIDs

`department_interest_ids` validation:

- optional; omitted or empty array means no preference
- values must be unique within the array
- each department must belong to the event’s organization
- each department must be non-archived at submission time
- each department must participate in the event through an active `event_department_assignments` row (or equivalent)
- team IDs are not accepted
- invalid values reject the entire submission with validation errors; do not partially submit

Example request:

```json
{
  "event_id": "550e8400-e29b-41d4-a716-446655440000",
  "applicant_legal_name": "Alex Applicant",
  "applicant_email": "alex@example.com",
  "department_interest_ids": [
    "660e8400-e29b-41d4-a716-446655440001",
    "660e8400-e29b-41d4-a716-446655440002"
  ]
}
```

Example response (abbreviated):

```json
{
  "id": "770e8400-e29b-41d4-a716-446655440010",
  "event_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "submitted",
  "department_interests": [
    { "department_id": "660e8400-e29b-41d4-a716-446655440001", "department_name": "Gate" },
    { "department_id": "660e8400-e29b-41d4-a716-446655440002", "department_name": "Rangers" }
  ]
}
```

#### Applicant portal (APP-012 through APP-015)

An applicant reaches their own applications through a signed link mailed to the address the application was submitted with. The link uses the same signed-URL mechanism as primary email verification (AUTH-010) and establishes no user session: the person following it may hold no user record, which is the situation the portal exists for.

Server-rendered and session-scoped rather than an API surface, because the reader holds no bearer token and has no account to issue one against. Following the link opens a bounded portal session naming one verified address; every read and write afterwards takes the address from that session and never from the request.

Endpoints:

- `GET /applications/link` and `POST /applications/link` — request a link for an address.
- `POST /api/public/applicant-portal/link-requests` — the same request from the public participation surface. Organization-independent, because an address may hold applications to more than one organization and one link covers all of them.
- `GET /applications/open` — the signed link's landing. Validates signature and expiry, opens the portal session, and redirects to an address carrying no credential.
- `GET /applications` — the applicant's own applications: scope applied to, submission date, current status, and a withdraw control where the application is still Submitted (APP-004, APP-013).
- `POST /applications/{application}/withdraw` — applicant withdrawal, recorded through the same domain path as every other withdrawal.
- `POST /applications/close` — end the portal session, for a browser that is not the applicant's own.

Disclosure rules (APP-014):

- The request response is identical for an address with applications, an address with none, and an address whose only application was auto-rejected for Do Not Staff. A link is mailed only where there is something to show, so the mailbox does not answer the question the response refuses to.
- An auto-rejected Do Not Staff application is absent from the portal, is not counted when deciding whether to issue a link, and cannot be withdrawn through it.
- An application that does not exist, one belonging to another address, and one auto-rejected are the same 404.

Rate limiting and audit (APP-015):

- Link requests are limited per email address and per requesting client, per hour, counted in the domain service so both entry points share one set of counters. Requests for unknown addresses are counted too — a limit that only counted known addresses would itself disclose which are known.
- Link issuance is audited as `applicant_portal.link_issued` against the pseudo-entity `applicant_portal_link`, recording the address and how many applications the link covers, with no organization scope because a link may cross several.
- Portal withdrawal is audited as `event_application.withdrawn`, with a reason naming the portal.

---

### 10.6 Departments and Teams

#### `departments`

Represents a persistent operational unit within an organization.

Key fields:

- `id`
- `organization_id`
- `name`
- `code`
- `description`
- `default_team_id`
- `branding_logo_attachment_id`
- `branding_accent_color`
- `branding_surface_color`
- `branding_updated_at`
- `created_at`
- `updated_at`
- `archived_at`

Branding fields (BRAND-009, BRAND-011, BRAND-012):

- A department branding profile carries a logo reference, one accent color, and one surface background color, and nothing else. Foreground, border, focus, status, severity, attention, and chart values resolve from the organization palette.
- `branding_surface_color` applies only to department-scoped surfaces. It is not applied to incident/IMS surfaces, The Briefing, or organization-level and cross-department surfaces.
- Both color columns are ignored while the owning organization has `department_branding_enabled` set to false.

Relationships:

- belongs to organization
- has many teams
- has many department memberships
- has many shifts
- may be assigned to many events
- may be selected as the event IC department
- may own policy/procedure documents and fragments
- has a current branding logo attachment

Historical records should snapshot department name/code where human readability requires it.

#### `teams`

Represents a named group within a department.

Teams replace the earlier operational concept of roles.

Key fields:

- `id`
- `department_id`
- `name`
- `code`
- `description`
- `is_default`
- `branding_logo_attachment_id`
- `branding_updated_at`
- `created_at`
- `updated_at`
- `archived_at`

Branding fields (BRAND-025, BRAND-026, and see `events` for BRAND-028):

- A team carries a logo reference and nothing else. There is no team accent and no team surface background: a team appears inside a department's surface, so a team color would put a second identity color on a screen the department already colors, against a background nobody validated it for.
- The logo is an attachment reference on the existing branding asset path, subject to the same MIME and size constraints as organization and department logos (BRAND-023).
- A team with no logo renders a generated lettermark from the team name; only teams that have uploaded one appear in the branding read payload.
- Team logos are edited under the department's branding authority (`department.branding.manage`, or an organizer of the owning organization), not under a permission of their own.

Rules:

- every department has a default team
- departments may rename their default team
- staff cannot belong to a department without belonging to at least one team
- staff may belong to multiple teams
- teams are persistent across events
- archived teams remain visible in historical records
- historical worked shifts preserve the team/function name used at the time

Relationships:

- belongs to department
- has many team memberships
- may grant shift eligibility
- may grant system authority
- may receive IC grants when its department is the selected IC department
- may own team-scoped policy/procedure documents and fragments

#### `department_memberships`

Represents a staff member's persistent membership in a department.

Key fields:

- `id`
- `department_id`
- `staff_id`
- `status`
- `status_reason`
- `created_at`
- `updated_at`
- `archived_at`

Statuses:

```text
prospective
active
inactive
ineligible
emeritus
retired
```

Rules:

- department status is changed by department leads only
- `ineligible` is department-specific and indefinite until changed
- department status cannot override organization-level blocking status

#### `team_memberships`

Represents a staff member's membership in a team.

Key fields:

- `id`
- `team_id`
- `staff_id`
- `department_membership_id`
- `membership_role`, optional display/use value such as member or lead
- `created_at`
- `updated_at`
- `archived_at`

Rules:

- team membership may grant shift eligibility
- team membership may impose training/waiver requirements
- team membership may grant system authority

#### `team_designations`

Represents a designation attaching an operational grant to a named team
(TEAM-011 through TEAM-014, TEAM-017). Department rows carry the section 4.8A
functions (`logistics`, `operations`, `planning`, `administration`,
`operator`); the organization row carries `staff_coordinator` on a team within
the configured Organizers Department.

Key fields:

- `id`
- `organization_id`
- `department_id`, null for the organization-level Staff Coordinator designation
- `function_code`
- `team_id`
- `team_grant_id`, the grant this designation created and owns
- `created_at`
- `updated_at`
- `removed_at`

Rules:

- a department designates zero or one team per function; the same team may hold more than one designation
- an active designation owns exactly one active `team_grants` row, so authority resolves through team membership and grants, not through a second path
- removing a designation revokes only the grant the designation created; direct team grants remain available and untouched (TEAM-013)
- removed designations keep their row for history
- creation, change, and removal are audited with the department or organization, the function, and the team recorded (TEAM-017)

Administration (M18.12; TEAM-016, TEAM-018):

- department designations are maintained from the department administration surface by `department.administer` holders: the `GET /api/departments/{department}/teams` workspace read carries one designation row per function (undesignated functions included with a null team), and `POST /api/commands/designate-department-team` / `POST /api/commands/remove-department-team-designation` change them
- the organization-level Staff Coordinator designation is maintained from the organization configuration surface by `organization.designations.manage` holders (organizer, lead_organizer): `GET /api/organizations/{organization}/designations` answers with the current designation and the eligible teams — active teams of the configured Organizers Department — and `POST /api/commands/designate-staff-coordinator-team` / `POST /api/commands/remove-staff-coordinator-team` change it
- a permission explanation for a designation-owned grant names the designation (TEAM-018), e.g. "You have the Department Logistics role because your team, Gate Crew, is the department's designated Logistics team."

#### `event_department_assignments`

Represents a department participating in an event.

Key fields:

- `id`
- `event_id`
- `department_id`
- `created_at`
- `archived_at`

#### `event_staff_assignments`

Represents a staff member assigned to an event.

Key fields:

- `id`
- `event_id`
- `staff_id`
- `organization_id`
- `created_at`
- `updated_at`
- `removed_at`

---

### 10.7 Permissions and Effective Roles

#### `permission_roles`

Represents effective authority roles, not operational shift roles.

Key fields:

- `id`
- `name`
- `code`
- `scope_type`
- `created_at`
- `updated_at`

Examples:

```text
staff
shift_lead
department_lead
ic_lead
ic_operator
ic_viewer
organizer
lead_organizer
god_mode
```

#### `permissions`

Represents registered permission capabilities.

Key fields:

- `id`
- `code`
- `description`
- `created_at`
- `updated_at`

#### `role_permissions`

Maps effective roles to permissions.

Key fields:

- `id`
- `permission_role_id`
- `permission_id`
- `created_at`

#### `team_grants`

Represents system authority granted through team membership.

Key fields:

- `id`
- `team_id`
- `event_id`, nullable when not event-specific
- `permission_role_id`
- `created_at`
- `updated_at`
- `revoked_at`

Rules:

- shift lead is team-scoped
- organizer and lead organizer grants are organization-scoped and valid only for teams in the configured Organizers Department
- lead organizer may be granted to any subset of the configured Organizers Department, including all members
- IC roles are event/team-scoped through the selected IC department
- authority should be explainable in UI

#### `direct_user_roles`

Restricted direct user role assignment for god-mode/admin repair.

Key fields:

- `id`
- `user_id`
- `permission_role_id`
- `node_id`, nullable where global/system-wide
- `created_by_user_id`
- `created_at`
- `revoked_at`

Rules:

- direct user roles are not used for ordinary department/event/IC exceptions
- direct user roles are audited

---

### 10.8 Trainings and Waivers

#### `trainings`

Represents a qualification associated with organization, department, team, event, or shift eligibility.

Key fields:

- `id`
- `organization_id`
- `department_id`, nullable
- `team_id`, nullable
- `event_id`, nullable
- `name`
- `description`
- `expires_after_days`, nullable
- `delivery`, `in_person` or `online`
- `online_url`, nullable; required for online trainings
- `scheduled_start_at`, nullable; set when the MVP workflow requires scheduled training attendance
- `scheduled_end_at`, nullable
- `location`, nullable
- `capacity`, nullable
- `time_commitment`, nullable; shown on the training page
- `after_training`, nullable; training page description of what follows completion
- `provisions`, nullable; training page description of provisions that come with the training
- `linked_shift_id`, nullable; the shift an event-bound in-person scheduled training materializes as
- `created_at`
- `updated_at`
- `archived_at`

Rules:

- in-person trainings with a scheduled session and an event materialize a linked shift (`Training: <name>`) for the training's team or the department default team, and signups flow through normal shift signup; the training's prerequisites are registered as the linked shift's training requirements
- online trainings take no signups and carry the training URL instead
- every training has a staff-visible training page presenting delivery, schedule/URL, time commitment, prerequisites, and after-training information

#### `training_prerequisites`

Represents prerequisite relationships between trainings.

Key fields:

- `id`
- `training_id`
- `prerequisite_training_id`
- `created_at`

#### `training_signups`

Represents a staff member's signup for a training that requires scheduled attendance.

Key fields:

- `id`
- `training_id`
- `staff_id`
- `signed_up_at`
- `cancelled_at`, nullable
- `created_at`
- `updated_at`

Rules:

- signups exist only for in-person trainings with scheduled attendance
- used when the training has no linked shift; event-bound in-person trainings take signups through the linked shift's normal shift assignments instead
- one signup row per training/staff pair; cancellation is recorded, not deleted
- active signups form the training roster used by authorized trainers/leads

#### `training_completions`

Represents a staff member's completed training.

Key fields:

- `id`
- `training_id`
- `staff_id`
- `completed_at`
- `expires_at`
- `recorded_by_user_id`
- `origin_node_id`
- `created_at`

Rules:

- trainings cannot be waived in MVP
- authorized trainers/leads record completion
- completions may be imported from spreadsheets

#### `waivers`

Represents a required acknowledgment/document outside the policy/procedure document system.

Key fields:

- `id`
- `organization_id`
- `scope_type`
- `scope_id`
- `name`
- `description`
- `expires_after_days`, nullable
- `document_type`, nullable (`policy` or `procedure`; WAIVER-007)
- `document_id`, nullable
- `created_at`
- `updated_at`
- `archived_at`

Rules:

- Meridian tracks completion as complete/incomplete
- signed document contents are not stored in MVP
- a waiver may reference a published policy/procedure document in its organization as the text being agreed to (WAIVER-007); the reference is optional, and a waiver with no reference behaves exactly as before (WAIVER-009)

#### `waiver_completions`

Represents waiver completion.

Key fields:

- `id`
- `waiver_id`
- `staff_id`
- `completed_at`
- `expires_at`
- `recorded_by_user_id`
- `document_type`, nullable
- `document_id`, nullable
- `document_revision`, nullable
- `fragment_revision`, nullable
- `created_at`

Rules:

- completing a document-backed waiver records the acknowledged document and document version alongside the completion, using the same version-recording rule as policy/procedure acknowledgments (WAIVER-008; POL-043), and retains the acknowledged version's source and resolved text as a `document_version_snapshots` row
- a completion of a waiver with no document reference records no document columns (WAIVER-009)

#### Waiver administration endpoints

Waiver administration authority follows the scope of the waiver, matching the policy/procedure maintenance rule (WAIVER-010): organization-scoped waivers by organizers, department-scoped by department leads, team-scoped by team leads. No separate capability code carries it; every endpoint resolves the caller's maintainable scopes.

- `GET /api/organizations/{organization}/waivers` answers with the waivers in the caller's maintainable scopes, the scope options a new waiver may be assigned to, and the organization's published documents a waiver may reference (WAIVER-007). A caller maintaining no scope is refused.
- `GET /api/organizations/{organization}/waivers/{waiver}` answers with one waiver, its referenced document rendered with fragment text inline (POL-022) when one is configured, and the completion roster: everybody the waiver's scope asks, with complete, lapsed, and incomplete distinguished — lapsed is the state WAIVER-006 turns into a credential block.
- `POST /api/commands/create-waiver`, `update-waiver`, `archive-waiver`, and `restore-waiver` administer waivers within the caller's scope authority. Scope is fixed at creation; archiving keeps every recorded completion. All are audited.
- `POST /api/commands/record-waiver-completion` records a completion for a staff member the waiver's scope asks; a staff member outside the scope, or an archived waiver, is refused. Recording is audited and connected-only, because a document-backed completion names the document version shown (WAIVER-008).

---

### 10.9 Shifts

#### `shifts`

Represents a planned block of staff coverage for a department.

Key fields:

- `id`
- `event_id`
- `department_id`
- `eligible_team_id`
- `title`
- `department_name_snapshot`
- `team_name_snapshot`
- `starts_at`
- `ends_at`
- `capacity`
- `signup_opens_at`
- `signup_closes_at`
- `schedule_lock_at`
- `schedule_lock_offset_minutes`, nullable (SHIFT-017)
- `credit_policy_id`, nullable
- `meeting_map_location_id`, nullable
- `created_at`
- `updated_at`
- `cancelled_at`

Rules:

- shifts belong to an event and department
- shifts define exactly one eligible team membership
- a shift may have its own displayed title/function
- a shift does not require membership in multiple teams
- required trainings and waivers must be enforced for scheduled and unscheduled additions
- overlap warnings are shown by default rather than hard-blocking
- elevated leads may assign overlapping shifts
- a schedule lock/cutoff (SHIFT-009) is expressed either as an absolute `schedule_lock_at` or as `schedule_lock_offset_minutes` before the event's active event window start, never both (SHIFT-017); a relative cutoff resolves to an absolute moment whenever the window is known, so it moves when event dates move, and resolves to nothing — leaving self-service changes governed by other rules — while the window is unset
- `meeting_map_location_id` is optional; a shift may reference an operational map meeting/check-in location but is not required to have one

#### `shift_training_requirements`

Key fields:

- `id`
- `shift_id`
- `training_id`
- `created_at`

#### `shift_waiver_requirements`

Key fields:

- `id`
- `shift_id`
- `waiver_id`
- `created_at`

#### `shift_assignments`

Represents planned shift signup/assignment.

Key fields:

- `id`
- `shift_id`
- `staff_id`
- `assigned_by_user_id`, nullable for self-signup
- `assignment_status`
- `created_at`
- `updated_at`
- `removed_at`

Rules:

- shift signup is planned coverage, not actual hours
- lead removal from shifts is allowed
- unscheduled work can create an assignment during operations if the staff member satisfies eligibility

---

### 10.10 Attendance and Hours

#### `attendance_operations`

Represents append-only attendance actions from devices or server UI.

Key fields:

- `id`
- `operation_uuid`
- `event_id`
- `department_id`
- `team_id`, nullable
- `shift_id`, nullable until reconciled if not selected at operation time
- `shift_assignment_id`, nullable
- `staff_id`
- `operation_type`
- `device_created_at`
- `server_received_at`
- `created_by_user_id`
- `origin_device_id`
- `origin_node_id`
- `created_at`

Operation types:

```text
check_in
check_out
mark_no_show
correct
```

Rules:

- attendance operations can be created offline
- check-in requires the staff member to be on-site for the shift department/event
- duplicate check-in is idempotent
- overlapping check-ins are allowed but warn
- offline check-out without known server-side check-in is accepted and reconciled later
- attendance conflicts go to the sync conflict queue

Automatic no-show determination:

- a scheduled shift whose assigned staff member has not checked in by the end of the accepted sign-in window produces a `mark_no_show` operation without a lead marking it
- the accepted sign-in window extends before and after the scheduled shift start by 5% of the scheduled shift duration
- the operation is written by the node holding authority for the event — the on-site primary node during the active event window, central otherwise — and carries that node in `origin_node_id`, with no `origin_device_id`
- cancelled shifts and shifts carrying an excused attendance record are excluded
- a later `check_in` supersedes it: the derived state in `attendance_records` becomes `checked_in` and both operations remain in the append-only history
- a staff member checked in after the end of the accepted window arrived late; late arrival is exactly this superseded case and is derived from the operation history rather than stored as its own state
- the manual `mark_no_show` operation remains available to authorized attendance managers, and `created_by_user_id` distinguishes a manual operation from an automatic one
- determination is idempotent per shift assignment: re-running it produces no second operation

#### `attendance_records`

Represents derived/current attendance state for a staff member/shift.

Key fields:

- `id`
- `event_id`
- `department_id`
- `shift_id`
- `shift_assignment_id`, nullable
- `staff_id`
- `current_state`
- `checked_in_at`
- `checked_out_at`
- `no_show_at`
- `corrected_at`
- `created_at`
- `updated_at`

Derived states:

```text
scheduled
checked_in
checked_out
no_show
excused
corrected
```

#### `hours_worked`

Represents finalized or correctable actual work time.

Key fields:

- `id`
- `event_id`
- `department_id`
- `shift_id`
- `staff_id`
- `attendance_record_id`
- `actual_started_at`
- `actual_ended_at`
- `minutes_worked`
- `status`
- `corrected_by_user_id`, nullable
- `server_corrected_at`, nullable
- `frozen_at`, nullable
- `created_at`
- `updated_at`

Rules:

- hours are distinct from scheduled shifts
- hours always belong to an event, department, shift, and staff
- no free-floating hours exist in MVP
- checkout creates hours
- authorized attendance managers may correct hours during the correction grace period
- hours freeze after the grace period
- staff do not self-report hours in MVP

---

### 10.10A Department Presence

#### `event_department_presences`

Represents whether an eligible staff member is currently on-site or off-site for
an event department.

Key fields:

- `id`
- `event_id`
- `department_id`
- `staff_id`
- `current_state`
- `marked_on_site_at`
- `marked_off_site_at`
- `last_marked_by_user_id`
- `created_at`
- `updated_at`

States:

```text
on_site
off_site
```

Rules:

- there is at most one presence row per event, department, and staff member
- on-site/off-site state is department-specific
- only `department_logistics` may mark staff on-site/off-site
- staff must be an active member of the department before being marked on-site
- on-site state makes staff eligible for Logistics shift add/check-in
- staff cannot be marked off-site while checked into a shift for that department/event
- staff cannot be marked off-site while holding checked-out department/event equipment unless the equipment is returned or marked Missing/Damaged
- presence changes are audit logged

---

### 10.11 Credentials

#### `event_credentials`

Represents event-specific approval to work an event.

Key fields:

- `id`
- `event_id`
- `staff_id`
- `status`
- `status_reason`
- `changed_by_user_id`
- `created_at`
- `updated_at`
- `revoked_at`

Statuses:

```text
eligible
blocked
revoked
```

Rules:

- at most one credential per staff member per event
- credential is not a physical item
- physical items are provisions or external workflows
- credential eligibility requires at least one signed-up shift, required waivers, age requirements, no organization blocking status, and no department Ineligible status for worked departments
- credential revocation is restricted to organizers and IC department leads, through the `event.credentials.revoke` capability those three roles carry and no other role does
- revocation removes future shifts where possible while preserving completed shifts and hours

---

### 10.12 Credits

#### `credit_policies`

Represents credit calculation policy.

Key fields:

- `id`
- `organization_id`
- `event_id`, nullable
- `shift_id`, nullable
- `name`
- `credit_multiplier`
- `created_at`
- `updated_at`
- `archived_at`

Rules:

- organization default is fallback
- shift-specific credit policy overrides organization default
- there is no department default credit policy

`GET /api/organizations/{organization}/credit-policies` is the administration
read behind the `organizer.configuration` surface, and `create-credit-policy`,
`update-credit-policy`, `archive-credit-policy`, and `restore-credit-policy`
are its commands; `calculate-event-credits` starts a CREDIT-001 calculation
run for one event from the same surface. All six require
`organization.credit_policies.manage` (organizers and Lead Organizers,
ORG-020) and are audited. Policy edits answer to the ORG-021 configuration
governance — central-owned, frozen during the active event window — and the
read returns archived rows alongside active ones, because a shift may still
name one and restoring one is half of what the surface is for. The
organization default cannot be archived while it holds that job, and a shift
names a policy through `create-shift` / `update-shift` (`credit_policy_id`),
where an archived policy cannot be newly chosen but a shift already naming
one keeps it.

Alternatively a shift carries a custom rate through the same commands
(`custom_credit_multiplier`, 0 to 2 credits per hour, three decimal places,
mutually exclusive with `credit_policy_id`). The rate is stored as one
shift-scoped `credit_policies` row per shift — created on first use, re-rated
in place after — that the shift's `credit_policy_id` points at, so the
resolver, ledger, and export read it exactly like a named policy. Shift-scoped
rows stay out of the named catalog: the administration read and the shift
form's options exclude them, and another shift naming one is refused, because
a re-rate of one shift must never reprice a second. God Mode carries the same
configuration: Orchid credit policy screens route through the same admin
service (audited `SOURCE_ORCHID`, same governance freeze), the Orchid
organization screen routes the ORG-017/ORG-018 configuration fields through
`OrganizationConfigurationService`, and the Orchid shift screen sets
`credit_policy_id` and the custom rate.

#### `credit_ledger_entries`

Represents calculated or adjusted credits.

Key fields:

- `id`
- `event_id`
- `department_id`
- `shift_id`
- `staff_id`
- `hours_worked_id`
- `credit_policy_id`
- `entry_type`
- `hours`
- `credits`
- `status`
- `calculation_basis`
- `created_by_user_id`
- `created_at`
- `frozen_at`

Rules:

- credits are calculated from finalized hours after the correction grace period
- credits freeze after calculation
- historical credit calculations do not change retroactively after freeze
- credits earned exports include calculation basis

---

### 10.13 Equipment

#### `equipment_items`

Represents a trackable physical item.

Key fields:

- `id`
- `organization_id`
- `event_id`, nullable
- `department_id`, nullable
- `name`
- `tracking`, one of the tracking kinds below
- `asset_tag`
- `serial_number`
- `quantity_total`, the pool size for pooled records and 1 for individually tracked records
- `status`
- `created_at`
- `updated_at`
- `archived_at`

Tracking kinds:

```text
individual
pooled
```

Statuses:

```text
available
checked_out
returned
missing
damaged
```

#### `equipment_checkouts`

Represents equipment checked out to an individual staff member.

Key fields:

- `id`
- `equipment_item_id`
- `event_id`
- `staff_id`
- `shift_id`, nullable
- `quantity`, 1 for an individually tracked item and the handed-out count for a pooled one
- `quantity_returned`, nullable
- `checked_out_at`
- `checked_out_by_user_id`
- `returned_at`
- `returned_by_user_id`
- `return_condition`
- `created_at`
- `updated_at`

Rules:

- MVP tracking is visible/manual
- checkout/check-in is to individual staff members
- an `individual` record is one physical unit and carries `quantity_total` 1; a
  `pooled` record is interchangeable units of one kind and carries no
  `asset_tag` or `serial_number` (EQUIP-010)
- a checkout of an `individual` record names the unit and carries `quantity` 1; a
  checkout of a `pooled` record carries the quantity handed out (EQUIP-011)
- the quantity of a `pooled` record available to hand out is
  `quantity_total` less the sum of `quantity` over its open checkouts, derived
  rather than stored, and a `pooled` record is never stored `checked_out`
  (EQUIP-016)
- a `pooled` checkout may be returned in parts; `quantity_returned` accumulates
  and the checkout closes when it reaches `quantity`
- pooled units returned `missing` or `damaged` reduce `quantity_total` through an
  audited adjustment carrying a reason, rather than changing the pooled record's
  `status` (EQUIP-017)
- equipment lookup at checkout matches `name`, `asset_tag`, and `serial_number`
  within the operator's authorized department and event scope, and discloses
  nothing outside it (EQUIP-015)
- a lookup value matching exactly one `asset_tag` or `serial_number` resolves to
  that item directly; a value matching several or none reports that rather than
  choosing (EQUIP-013)
- equipment does not need to be tied to a shift for MVP
- equipment may be checked out before, during, or after a shift
- checkout department scope is derived from the shift when present, otherwise from the equipment item's event/department scope
- `department_logistics` authorizes equipment checkout/check-in
- department-to-department allotments and full custody chains are out of scope
- inventory setup (create/edit/archive/restore/import of `equipment_items`) is a
  separate product path from checkout: it is department-scoped and
  event-independent, authorized by `department.equipment.manage` or
  `department.administer`, never writes `checked_out`/`returned`, and refuses to
  change state or archive an item that has an open checkout
- `asset_tag` is unique among equipment items in a department, so bulk import is
  re-runnable without duplicating equipment. A `pooled` record has no
  `asset_tag`, so import matches one by department, name, and tracking kind and
  updates its `quantity_total` rather than adding a second pool of the same
  kind
- equipment is archived (`archived_at`), never deleted, so checkout history and
  historical labels survive

---

### 10.14 Deployments / Locations

#### `deployments`

Represents a current assignment/location option for a staff member during a shift.

Key fields:

- `id`
- `event_id`
- `department_id`
- `name`
- `description`
- `location_details`
- `map_location_id` (nullable)
- `created_at`
- `updated_at`
- `archived_at`

`map_location_id` is optional. A deployment may reference an operational map location, but deployments are not required to have a map location, and the reference does not replace `location_details`.

#### `current_deployment_assignments`

Represents current deployment/location state for MVP.

Key fields:

- `id`
- `event_id`
- `department_id`
- `shift_id`
- `staff_id`
- `deployment_id`
- `assigned_by_user_id`
- `assigned_at`
- `updated_at`

Rules:

- MVP only tracks current deployment/location
- current state is unique per shift/staff pair
- a staff member has at most one current deployment/location per shift
- `department_operations` authorizes current deployment/location assignment
- assignment may happen before or during the shift
- deployment movement history is out of scope for MVP

---

### 10.15 Field Reports

#### `field_reports`

Represents an immutable low-friction report created by an authorized user.

Key fields:

- `id`
- `event_id`
- `department_id`, nullable
- `team_id`, nullable
- `submitted_by_user_id`
- `staff_id`
- `fra_number`, nullable until server/node assignment
- `temporary_local_number`, nullable
- `title`
- `body`
- `device_submitted_at`
- `server_received_at`
- `origin_device_id`
- `origin_node_id`
- `sync_status`
- `created_at`

Rules:

- Field Reports are not incidents
- Field Reports can be created offline
- there are no draft Field Reports
- `title` is required plain text, trimmed of outer whitespace, 1–200 characters after trimming
- duplicate titles are allowed within an event
- original title and body never change after submission
- Name References in `body` are parsed after submission as derived search/display artifacts
- `title` is not parsed for Name References
- Field Reports are not editable
- Field Reports are not stricken
- Field Reports may exist independently
- Field Reports may be attached to one or more incidents
- when a Field Report is attached to an incident, content is copied into incident notes as `Field Report: <title>`, followed by the Field Report author and body
- users see only their own Field Reports by default
- IC roles see all Field Reports for the event
- department leads, organizers, and shift leads do not automatically see Field Reports from their department/shifts
- original submitter is not shown that their Field Report has been attached to an incident

#### `field_report_appends`

Represents immutable append-only additions to a Field Report.

Key fields:

- `id`
- `field_report_id`
- `appended_by_user_id`
- `body`
- `device_submitted_at`
- `server_received_at`
- `origin_device_id`
- `origin_node_id`
- `created_at`

Rules:

- only the original submitter may append to their own Field Report
- elevated users can append only to their own Field Reports, not to other users' Field Reports
- appends share the same FRA number with timestamped entries
- appends do not have titles and cannot alter the original Field Report title
- Name References in append `body` are parsed after submission as derived search/display artifacts
- when a Field Report is appended, only the added content is copied into associated incidents

---

### 10.16 Incidents

#### `incidents`

Represents an online-only operational record managed by Incident Command roles.

Key fields:

- `id`
- `event_id`
- `incident_number`
- `status`
- `priority_label`
- `started_at`
- `title`
- `location_name`
- `location_address`
- `location_details`
- `camp_id` (nullable)
- `map_location_id` (nullable)
- `created_by_user_id`
- `created_at`
- `updated_at`
- `closed_at`

Statuses:

```text
open
on_scene
monitoring
on_hold
closed
```

Rules:

- incident creation requires active server connection
- incidents are visible only to IC roles
- incidents are not greedily synced to devices
- elevated IC users may cache limited last-viewed incident data
- incident-level Name Reference chips are derived from incident notes and attached Field Reports
- incidents are not destroyed
- incidents are not merged away
- incidents may be edited regardless of status
- status affects filtering/status, not editability
- incident list search and filters are a read concern only: they narrow rows the
  requesting user may already see and never grant, widen, or cross-event
  visibility (see section 5.1)
- priority label, incident type labels, and involved/responding staff are current incident fields and their changes are preserved in history
- incident timestamps are not retroactively changed
- incident title edits create timeline entries
- incident body/history is append-only
- closing requires a note/reason
- reopening is allowed
- IC department leads may print a single incident to a server-generated PDF; IC operators and viewers cannot
- incidents are not part of general spreadsheet exports for MVP
- `camp_id` and `map_location_id` are optional and must not be required to create an incident
- a camp/map-location reference does not replace the free-text `location_*` fields and does not introduce arbitrary dropped pins
- when an incident references a camp, the IMS view may show camp location details to permitted IC roles
- incident map/location visibility follows existing IMS permissions

#### `incident_timeline_entries`

Represents append-only incident history.

Key fields:

- `id`
- `incident_id`
- `actor_user_id`
- `entry_type`
- `body`
- `previous_value`
- `new_value`
- `reason`
- `created_at`
- `stricken_at`
- `stricken_reason`

#### `incident_field_reports`

Links Field Reports to incidents.

Key fields:

- `id`
- `incident_id`
- `field_report_id`
- `linked_by_user_id`
- `linked_at`
- `unlinked_by_user_id`
- `unlinked_at`
- `stricken_reason`

Rules:

- IC leads and IC operators can link/unlink Field Reports
- link/unlink activity appears on the incident timeline only
- when a Field Report is attached, Field Report content is copied into incident notes as `Field Report: <title>`, followed by the Field Report author and body
- when removed from an incident, the incident history shows that relationship as stricken

#### `incident_staff`

Links involved/responding staff to incidents.

Key fields:

- `id`
- `incident_id`
- `staff_id`
- `relationship_label`
- `created_at`

#### `incident_links`

Links related incidents.

Key fields:

- `id`
- `source_incident_id`
- `target_incident_id`
- `link_type`
- `created_by_user_id`
- `created_at`

#### `incident_types`

Represents configurable incident type labels, scoped to one organization.

Every organization starts with a default set (`IncidentTypeDefaults`) and
maintains its own list from there; Meridian branches on none of the names.
Archiving a type keeps it on the incidents that already carry it and on the list
filter, and removes it from what an incident may be given next.

`GET /api/organizations/{organization}/incident-types` is the administration
read behind the `organizer.configuration` surface, and `create-incident-type`, `rename-incident-type`,
`archive-incident-type`, and `restore-incident-type` are its commands. All five
require `organization.incident_types.manage` (organizers and Lead Organizers,
ORG-020) and are audited. Unlike the other organization reads, this one returns
archived rows alongside active ones, because restoring one is what it is for.

An incident command names a configured type or is refused: the create and update
commands no longer bring a type into existence by mentioning it. An *archived*
type still resolves on a command, so an incident filed before a list changed
stays editable, while the authoring form no longer offers it.

Key fields:

- `id`
- `organization_id`
- `name`
- `created_at`
- `archived_at`

#### `incident_incident_types`

Key fields:

- `id`
- `incident_id`
- `incident_type_id`
- `created_at`

#### `incident_tags`

Represents tags extracted from incident notes.

Key fields:

- `id`
- `incident_id`
- `tag`
- `first_seen_at`

Rules:

- tags are extracted from hashtags in notes
- removing a tag pill does not edit original notes

#### Name Reference derived index

Name References are extracted from Incident timeline entry bodies, Field Report bodies, and Field Report append bodies. Field Report titles are not extracted for Name References.

The source text remains authoritative. The derived index may be represented in PostgreSQL, local SQLite, a search index, or a combination of those storage layers, but the exact physical storage shape is not mandated by this document.

Derived entries should support:

- normal permission-filtered search;
- incident summary chips near tags;
- rendering/highlighting support;
- permitted offline/local-first search or display where the source text is synced.

Rules:

- the derived index is rebuildable from source text
- derived tokens normalize case for search/matching
- original typed casing may be preserved in rendered source text
- supported tokens start with `@` and continue through letters, numbers, hyphens, and underscores
- whitespace or punctuation ends a token
- bracket syntax such as `@[Ranger Bucket]` is not supported for MVP
- Field Report bodies and appends are parsed immediately after submission; titles are not parsed
- derived entries inherit source record visibility
- derived entries must not create user mentions, notifications, autocomplete, alias merge behavior, profile links, canonical identity/entity records, or dedicated detail pages
- clicking a Name Reference runs normal search for the reference text without the `@` prefix
- no dedicated Name Reference API or management screen is required for Alpha 1

---

### 10.16A Incident list presets

#### `incident_list_presets`

Represents one user's saved IMS incident list search/filter/sort selection for
one event.

Key fields:

- `id`
- `event_id`
- `user_id`
- `name`
- `filters`
- `created_at`
- `updated_at`

Rules:

- a preset is personal view state, not an operational record: it stores only the
  list selection and never incident content
- a preset is never an authorization source; applying one still runs the normal
  IC-gated list read, so a preset can only ever narrow rows the user may already
  see
- presets are addressed by owner, so one IC user cannot read, overwrite, or
  delete another user's preset, and presets do not cross events
- names are unique per user per event, compared case-insensitively; saving an
  existing name overwrites that preset rather than failing
- paging position (`page`, `per_page`) is per-visit and is never stored in a
  preset, so applying one always starts at the first page
- a preset saved before a filter vocabulary change degrades to the default list
  rather than breaking the list read that carries it
- presets are managed through `save-incident-list-preset` and
  `delete-incident-list-preset`, authorized by the same `incidents.view`
  capability as the list itself, adding no new capability or role mapping
- presets are personal and non-operational, so they are not audited and are not
  synced to devices

### 10.17 Attachments and Files

#### `attachments`

Represents shared attachment metadata.

Key fields:

- `id`
- `attachable_type`
- `attachable_id`
- `uploaded_by_user_id`
- `filename`
- `mime_type`
- `byte_size`
- `storage_disk`
- `storage_path`
- `checksum`
- `metadata_json`
- `origin_device_id`
- `origin_node_id`
- `created_at`
- `stricken_at`, nullable
- `deleted_at`, nullable only where future policy allows

Rules:

- attachment metadata may sync when authorized
- binary files do not sync down to user devices by default
- Field Report photos are stored locally encrypted until synced
- Field Report photos are images only
- Field Reports allow max 2 photos total, including append photos
- compressed image dimensions max 2560 × 1900
- compressed file size max 5 MB
- GIFs are unsupported
- multi-image phone photos are converted to a single image
- original full-resolution photos are not kept
- EXIF and GPS EXIF are stripped
- incident attachments are images only for MVP
- incident attachments may be stricken but not deleted
- attachment downloads are available only to authorized users; Field Report photo downloads are restricted to `ic_lead`
- image URLs are short-lived signed URLs, not public file paths

---

### 10.18 Event Maps and Geography

Event maps, camps, and map locations are event-scoped. Canonical data lives in PostgreSQL; PowerSync projects only permitted records to devices. Map records are not offline-writable for MVP.

#### `event_maps`

Represents an event-scoped map.

Key fields:

- `id`
- `event_id`
- `name`
- `type` (`placement` or `topographic`)
- `state` (`draft`, `published`, `archived`)
- `primary_asset_id`, nullable
- `coordinate_system` (`local` for placement, `geo` for topographic)
- `metadata_json`
- `created_by_user_id`
- `published_at`, nullable
- `published_by_user_id`, nullable
- `archived_at`, nullable
- `created_at`
- `updated_at`

Rules:

- the map feature is enabled by default for new events; an event may have zero or more maps
- an event may have multiple maps of different types
- only the whole map has `draft`/`published`/`archived` state; camps and map locations do not have separate lifecycle states
- only `published` maps are visible to permitted operational users; `draft`/`archived` maps are limited to users with map edit/admin permissions
- `placement` maps use a local coordinate plane; `topographic` maps use real-world coordinates or prepared map packages
- placement and topographic maps should be linkable/georeferenced over time; georeferencing is not required for MVP; no `hybrid` type is introduced
- publishing and archiving use command-style writes and are audited
- when the event enters its operations window, published map geometry and related camp/location records are locked against normal editing; corrections require an organizer/admin `override-locked-map-data` command with an explicit reason

#### `map_assets`

Represents an uploaded/imported map asset or prepared map package referenced by a map. Also serves the role of `map_packages` for topographic basemaps.

Key fields:

- `id`
- `event_id`
- `event_map_id`, nullable
- `kind` (for example `image`, `svg`, `pdf_derived`, `topo_package`)
- `attachment_id`, nullable
- `package_ref`, nullable
- `metadata_json`
- `created_by_user_id`
- `created_at`
- `updated_at`

Rules:

- map assets follow existing Meridian file-storage and offline principles
- topographic basemaps are treated as map packages/offline-capable assets rather than assumed-online basemaps
- there is no automatic geocoding and no public map builder for MVP

#### `map_layers`

Optional grouping of features for toggling and sensitive-layer permission control.

Key fields:

- `id`
- `event_map_id`
- `name`
- `is_sensitive` (boolean)
- `sort_order`
- `created_at`
- `updated_at`

Rules:

- sensitive layers/features must not sync to users/devices without permission; UI hiding is not sufficient
- sensitive map reads/exports follow existing sensitive-read audit principles where appropriate

#### `camps`

Represents an event-scoped camp. Camps are their own entity, not generic map features, not a hierarchy level under organizations or departments, and not children of the Placement department.

Key fields:

- `id`
- `event_id`
- `name`
- `geometry_id`, nullable
- `created_by_user_id`
- `created_at`
- `updated_at`

Rules:

- for MVP a camp has only a name and a location; no description, contact, affiliation, public/private flag, or notes
- camp location may be a point, a simple footprint/area, local placement-map coordinates, or geospatial coordinates where available
- camps are not required to have GPS coordinates
- camp names are not public to all volunteers by default; camp visibility follows map permissions and operational role needs
- the schema is designed so future versions can add description, lead/contact, affiliation, public/private flags, and additional placement metadata

#### `map_locations`

Represents a lightweight non-camp operational location on an event map.

Key fields:

- `id`
- `event_id`
- `event_map_id`, nullable
- `map_layer_id`, nullable
- `type` (`department_hq`, `gate`, `road`, `landmark`, `deployment_location`, `service_location`, `restricted_area`, `parking`, `other`)
- `name`
- `department_id`, nullable
- `geometry_id`, nullable
- `created_by_user_id`
- `created_at`
- `updated_at`

Rules:

- map locations remain lightweight for MVP and do not form a large GIS subsystem
- map locations carry enough structure to display and to be referenced by operational workflows
- arbitrary dropped pins are not supported for MVP

#### `map_geometries`

Represents geometry for a camp or map location.

Key fields:

- `id`
- `event_id`
- `geometry_kind` (`point`, `line`, `polygon`)
- `coordinate_space` (`local` or `geo`)
- `geojson` (GeoJSON-compatible geometry for geo space)
- `local_coordinates_json` (local placement-plane coordinates)
- `created_at`
- `updated_at`

Rules:

- geometry should be capable of representing points, lines, and polygons over time
- GeoJSON-compatible concepts are used where appropriate while allowing local/non-geographic placement coordinates
- placement-map geometry may use `local` space without real-world coordinates

#### Operational references

- `incidents.camp_id` and `incidents.map_location_id` are optional references (see 10.16).
- `deployments.map_location_id` is an optional reference (see 10.14).
- `shifts.meeting_map_location_id` is an optional reference (see 10.9).
- Equipment/storage locations may reference a map location only where equipment locations are already modeled; equipment items do not gain a location field in this update.
- Field Reports do not reference camps/map locations; they have one required title and one unstructured body.

---

### 10.19 Insights

Insights compile current authorized domain data. The entities below store what a sheet *is*, never what a metric *computed*. No table holds a calculated Insight result.

#### `insight_metric_definitions`

Represents a developer-defined Insight Metric type, registered as data.

Key fields:

- `id`
- `code`, unique
- `name`
- `description`
- `domain` (the operational domain the metric draws on, e.g. `shifts`, `equipment`, `incidents`)
- `required_capability`
- `configuration_schema_json` (the configuration keys a placement may carry)
- `enabled`
- `created_at`
- `updated_at`

Rules:

- definitions are seeded and maintained by developers; Orchid may view and administer registration metadata but cannot create metric behavior
- `required_capability` is enforced at render; a metric whose capability the viewer lacks does not render
- no organization-authored formula, expression, or query is stored here

#### `insight_sheets`

Represents an organization-owned configurable page of metrics.

Key fields:

- `id`
- `organization_id`
- `name`
- `description`, nullable
- `enabled_filters_json` (which supported filters this sheet offers viewers)
- `created_by_user_id`
- `created_at`
- `updated_at`
- `archived_at`, nullable

Rules:

- sheets are organization-owned, not user-owned; the creator is recorded for audit, not for ownership
- a sheet reads one event at a time; the event is a viewing selection, not a stored property of the sheet
- filters are sheet-level; individual metrics carry no independent user-facing filters
- lifecycle is create, edit, soft-archive. There is no publication, approval, or version history

#### `insight_metric_placements`

Represents one appearance of a registered metric on a sheet.

Key fields:

- `id`
- `insight_sheet_id`
- `insight_metric_definition_id`
- `position`
- `configuration_json`
- `created_at`
- `updated_at`
- `archived_at`, nullable

Rules:

- a metric type may be placed on many sheets and more than once on one sheet
- `configuration_json` is validated against the definition's `configuration_schema_json` at write time; unknown keys are refused rather than ignored
- a placement referencing a disabled or unknown definition is refused at write time

#### `insight_sheet_shares`

Represents a department sharing a sheet, or one metric placement from it, with Command.

Key fields:

- `id`
- `insight_sheet_id`
- `insight_metric_placement_id`, nullable — null means the whole sheet is shared
- `originating_department_id`
- `event_id`
- `share_mode` (`ongoing` or `temporary`)
- `shared_by_user_id`
- `shared_at`
- `removed_at`, nullable
- `removed_by_user_id`, nullable

Rules:

- Command is the `ic_lead`/`ic_operator`/`ic_viewer` pool in the event's designated Incident Command Department
- a temporary share stops applying when the event's operations window closes; expiry is evaluated on read against the event window, so no scheduled job is required
- an ongoing share applies until removed
- sharing grants no data Command is otherwise prohibited from seeing; restricted metrics on a shared sheet remain restricted, so sheet-level sharing cannot leak a metric the sharer forgot was present
- placement-level sharing applies to that placement only, never to every use of the metric type
- `originating_department_id` is rendered wherever shared content appears
- share and unshare are audited

#### `insight_sheet_favorites`

Represents a user pinning a sheet.

Key fields:

- `id`
- `insight_sheet_id`
- `user_id`
- `created_at`

Rules:

- favorites are personal view state, belong in the product interface rather than Orchid, and are not audited

#### Session filter state

A viewer's filter selections are held for the session and are not persisted across sessions. They are client-side session state and have no table, following the same reasoning as incident list paging position in 10.16A.

#### What is deliberately absent

- no table stores a computed metric value; Insights compile on read
- no snapshot, export, or PDF entity exists; a PDF is generated in the browser and downloaded
- no saved Insight result, trend history, or cross-event aggregate is stored

---

### 10.20 Notification Deliveries

Meridian sends transactional email for the NOTIFY-001 set and nothing else. There is no preference, digest, category, or notification-history entity, because NOTIFY-010 puts all four out of scope for MVP.

#### `notification_deliveries`

Represents one person told, or one person deliberately not told (NOTIFY-007).
### 10.21 Event Horizon

The Event Horizon compiles one staff member's readiness for one event out of records the other domains already own. Nothing here stores an item, an outstanding count, or a readiness state; the only row the feature owns is one person's preference about whether to keep seeing it.

#### `event_horizon_dismissals`

Represents one staff member having hidden the Event Horizon for one event (HORIZON-012).

Key fields:

- `id`
- `notification_type` (one of the closed NOTIFY-001 set, plus the VOL-025 profile change decision)
- `organization_id`, `event_id`, `department_id`, each nullable — the scope the notification concerns (NOTIFY-004)
- `subject_entity_type`, `subject_entity_id` — the record the notification is about
- `recipient_user_id`, nullable — null for an applicant with no user account
- `recipient_staff_id`, nullable
- `recipient_email` — empty where there was no address to use
- `status` (`queued`, `sent`, `failed`, `suppressed`, `no_verified_address`, `held_for_central`)
- `outcome_reason`, nullable
- `context_json` — identifiers and names for reading the trail back
- `attempts`
- `queued_at`, `sent_at`, `resolved_at`, each nullable
- `origin_node_id`, nullable
- `origin_operation_uuid`, nullable — the node operation that handed this notification to central (NOTIFY-008)
- `staff_id`
- `event_id`
- `dismissed_at`
- `created_at`
- `updated_at`

Rules:

- the row is written **before** the decision to send is taken, so every outcome is recorded. `sent` and `failed` are the two a mail transport log would also know; `suppressed`, `no_verified_address`, and `held_for_central` are the three it would not, because in those cases nothing was handed to a transport at all. NOTIFY-007 asks whether a person was told, and only a record written first distinguishes those from a send that failed
- **no message body is stored** (NOTIFY-007). `context_json` carries identifiers, names, and scope; the message itself is composed from the subject record when the send runs, so a notification that crossed a sync describes the record as the sending node holds it
- delivery is queued and never blocks the operation that caused it, and a delivery failure never rolls that operation back (NOTIFY-006)
- an unverified address is recorded as `no_verified_address` rather than sent to (NOTIFY-005). An applicant with no user account is addressed at the application email address
- an application auto-rejected due to Do Not Staff produces **no row at all** (NOTIFY-002): a record naming that application beside a rejection type would itself disclose the match
- `origin_operation_uuid` is not unique. One node operation may be the origin of several deliveries, because a cancelled shift is one operation and one notification per person who was signed up for it
- the audit trail carries the same five facts under `notification.<status>` actions, and outlives the row
- one row per staff member per event; restoring the surface deletes the row rather than adding a second state, because "not hidden" is the absence of a decision and needs no record
- writing a row is refused while any item is outstanding for that staff member and event (HORIZON-013), enforced server-side rather than by withholding the control
- the row is personal view state on the footing of `insight_sheet_favorites`: it is invisible to every other user, it is not audited, and no other feature reads it
- it replicates to the staff member's own devices so that hiding on one device does not leave the surface showing on another
- the row does not survive a new outstanding item: an item becoming outstanding again returns the surface (HORIZON-015), and the row is discarded when that happens rather than suppressing a list that now has work on it
- no `hidden_by_user_id` is stored. A staff member is the only person who can create or remove their own row, so the column would record the same fact as `staff_id` and imply that someone else could

#### Item kind registration

Item kinds are code-defined and registered in the application rather than stored as configuration (HORIZON-003). There is no `event_horizon_item_kinds` table, no organization-owned threshold, and no per-organization ordering: an organization configures the lead-up window in 10.1 and nothing else about the catalogue.

This deliberately differs from `insight_metric_definitions` in 10.19, which is registered *as data* so Orchid can display and administer registration metadata. The Event Horizon catalogue has five members fixed by requirement, no administrable metadata, and no Orchid surface, so a table would carry rows nobody may edit.

#### What is deliberately absent

- no table stores a compiled item, an evaluation, an outstanding count, or a readiness state; the view compiles on read
- no per-item dismissal, snooze, or acknowledgement entity exists (HORIZON-007); the only dismissal is the whole-surface preference above
- no notification entity is added; the Event Horizon sends nothing of its own (HORIZON-018)
- no lead-facing or organizer-facing readiness table exists; the surface is one person's own list

---

### 10.22 Organization Inquiries

An organization that wrote in from the public marketing surface (PUBLIC-002, PUBLIC-003). It is a message, not a tenant.

#### `organization_inquiries`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `organization_name` | string | as typed; no organization record exists for it |
| `contact_name` | string | |
| `contact_email` | string | lowercased on write; unverified — nobody proved control of it to submit the form |
| `description` | text | free text: what the organization runs |
| `status` | string | `new`, `reviewed`, `closed` |
| `review_notes` | text, nullable | internal; the contact never sees it |
| `reviewed_by_user_id` | uuid, nullable | the God Mode operator who last recorded a decision |
| `reviewed_at` | timestamp, nullable | |
| `submitted_at` | timestamp | |

The table carries no `organization_id`, no `user_id`, and no staff reference, and it never gains one. PUBLIC-003 puts organization, user, staff, and operational records out of reach of a public form, and PUBLIC-004 keeps organization creation a God Mode action. An organization created after a conversation that started here is created the ordinary way; linking the two rows would invite a create-from-inquiry path that PUBLIC-004 does not want.

No IP address is stored. Rate limiting bounds submission and does it in the cache against a hashed client key, so the deterrent does not require keeping a log of who visited a marketing page.

#### Surfaces

The marketing surface itself is a client application view, not a server-rendered page. The node owns two endpoints and the review screens; everything a visitor reads and fills in is rendered by the client.

- `GET /api/public/marketing-surface` — whether this node serves the surface, and the form token a submission has to carry. Unauthenticated. A 404 on an on-site node and on a node locked to an event (PUBLIC-006).
- `POST /api/public/organization-inquiries` — an organization interest submission. Unauthenticated, and the second unauthenticated write in the API after the participation surface's.
- `public.marketing` (client route `/platform`) and the client's root route — the surface as the visitor sees it. The root renders the marketing surface for a client holding nothing and the home directory for a client holding a session, because PUBLIC-001 describes the surface as being for organizations that do not yet use Meridian. A client holding nothing on a node that does not serve the surface goes to sign in.
- `platform.organization-inquiries` and `platform.organization-inquiries.show` — God Mode review (PUBLIC-004), behind the `platform.organization-inquiries` capability. There is no create-organization action on either screen.

The surface carries Meridian identity and resolves no organization branding profile (PUBLIC-001, BRAND-003). This is the one public client surface that does not: the participation page deliberately wears an organization's colours, and this one has no organization in the request to resolve any from.

#### Node scope (PUBLIC-006)

The marketing surface is not served by an on-site node, and is not served by any node locked to an event. Both endpoints answer 404 there — the page is not there rather than withheld — and the client asks before it renders, so the surface never appears on a node that would refuse its submissions.

#### Automated-submission protection and audit (PUBLIC-005)

- Two rate limits, per contact address and per submitting client, per hour, configurable and falling back to documented defaults rather than to no limit. Both are counted around every submission, including the ones the traps discard.
- A hidden field no visitor can see. A submission that fills it is discarded, and the response is byte-for-byte the one a real submission gets.
- A form token, issued by the node with the availability read and encrypted with the application key. It carries an issue time the client can neither read nor move. A submission with no token never opened the page, which is the shape almost every automated submission takes; a token younger than the configured floor arrived faster than a person types. Both are refused with a message rather than silently, and both are recoverable — the client still holds everything the visitor wrote, so asking again costs a button press rather than the message. Tokens expire after a day.
- The timing floor is configurable to zero. The token itself stays required, so turning the timing rule down does not turn the mechanism off.
- No challenge of any kind is presented to the submitter.
- Submission is audited as `organization_inquiry.submitted` against the entity type `organization_inquiry`, with no organization scope and no actor, recording the organization name, contact name, contact address, and status. The free-text description is not copied into the audit payload; it lives on the row the entry names.
- A discarded submission is audited as `organization_inquiry.discarded` with the reason and nothing else, so an operator can tell a trap that is misfiring from a quiet week.
- A console decision is audited as `organization_inquiry.reviewed`, with the actor and the before and after status and notes.

---

## 11. Policies, Procedures, and Fragments

Policies and procedures are included in Alpha 1 and should be represented as first-class data modules.

### 11.1 Document Modeling Decision

Policy documents and procedure documents are separate product/domain types.

Therefore, they use separate modules and separate persistence models/tables:

- `policy_documents`
- `procedure_documents`

They share:

- `document_fragments`
- `document_fragment_references`
- `document_acknowledgments`
- `document_acknowledgment_requirements`
- `document_version_snapshots`
- document export behavior

### 11.2 `policy_documents`

Represents a Markdown governance document describing expectations, rules, agreements, or policy.

Key fields:

- `id`
- `organization_id`
- `scope_type`
- `scope_id`
- `title`
- `slug`
- `event_info_section`
- `markdown_source`
- `state`
- `document_revision`
- `fragment_revision`
- `published_at`
- `archived_at`
- `created_by_user_id`
- `updated_by_user_id`
- `created_at`
- `updated_at`

States:

```text
draft
published
archived
```

Rules:

- no separate Active state
- Markdown only for Alpha 1
- raw HTML is disallowed
- published documents are visible according to scope
- draft/archived documents sync only to maintainers allowed to edit them
- organization-scoped policy documents are maintained by organizers
- department-scoped policy documents are maintained by department leads
- team-scoped policy documents are maintained by team leads
- organizers can view all published policy documents across organization, department, and team scopes
- organizers cannot edit department/team documents merely by being organizers
- `event_info_section` is nullable and, when set, is one of the Event Info section keys in 11.4A

### 11.3 `procedure_documents`

Represents a Markdown governance document describing how operational work should be performed.

Key fields mirror `policy_documents`:

- `id`
- `organization_id`
- `scope_type`
- `scope_id`
- `title`
- `slug`
- `event_info_section`
- `markdown_source`
- `state`
- `document_revision`
- `fragment_revision`
- `published_at`
- `archived_at`
- `created_by_user_id`
- `updated_by_user_id`
- `created_at`
- `updated_at`

Rules mirror policy documents unless and until procedures diverge after MVP.

### 11.4 Document Scope

Allowed scopes:

```text
organization
department
team
```

Visibility:

- organization-scoped published documents are visible to everyone in the organization
- department-scoped published documents are visible to members of that department
- team-scoped published documents are visible to members of that team
- department leads and team leads may see policies/procedures within their department according to leadership scope
- documents are not generally public-facing before login except as part of staff signup for an organization

### 11.4A Event Info Section Assembly

Event Info (`event.info`, `GET /api/events/{event}/info`) is the staff-facing
answer to how to reach the event, what to bring, and what is expected. It is
assembled from published policy/procedure documents rather than authored
separately, so there is exactly one place event guidance lives.

Section keys, in display order:

```text
directions
arrival
packing
food
housing
requirements
```

Selection rules:

- A maintainer assigns a document to at most one section through
  `event_info_section` while authoring it. Nothing is inferred from titles or
  slugs; a surface that guesses which document means "directions" eventually
  guesses wrong for someone driving to a gate at night.
- Assignment requires no capability beyond the maintain-scope authority that
  already governs the document. If a maintainer may publish the text, they may
  say where it appears.
- Assigning, changing, or clearing a section does not bump the document version.
  Placement is where a document is shown, not what it says, and a version bump
  would tell acknowledgment review that the text changed when it did not. The
  change is still recorded in the document audit snapshot.

Assembly rules:

- Only `published` documents appear, including for the maintainer who wrote
  them. Event Info answers what is in force now, and a maintainer reading their
  own draft here would read it as published guidance.
- Visibility is exactly the published-document rule in 11.4. Event Info grants
  no access of its own, so the same section may legitimately differ between two
  staff members.
- The document pool is the event's organization; department and team scope
  narrow it further through the visibility rule above.
- Within a section, documents are ordered by scope breadth (`organization`,
  then `department`, then `team`), then title, then id. Broad guidance is read
  before the narrower guidance that qualifies it, and the order is stable across
  requests.
- A section with no visible published document returns an `empty_description`
  naming the gap. It never returns placeholder prose, because staff cannot tell
  placeholder guidance from published guidance.

Event Info access requires staff standing in the event's organization. It is not
gated on any operational capability, because it is the surface a staff member
needs before their first shift.

### 11.5 `document_fragments`

Represents reusable named Markdown text that can be referenced by policy/procedure documents.

Key fields:

- `id`
- `organization_id`
- `scope_type`
- `scope_id`
- `name`
- `slug`
- `markdown_source`
- `version`
- `created_by_user_id`
- `updated_by_user_id`
- `created_at`
- `updated_at`

Rules:

- fragments are shared by policy and procedure documents
- fragments are Markdown only for Alpha 1
- fragments do not have Draft/Published/Archived states in Alpha 1
- fragment version auto-increments when text changes
- fragments cannot reference other fragments
- nested fragments are prohibited
- organization fragments may be referenced below organization scope
- department fragments may be referenced within that department and its teams
- team fragments may be referenced by that team
- fragment references always resolve to the latest fragment text
- documents cannot pin old fragment versions for display

### 11.6 `document_fragment_references`

Represents a document's reference to a fragment.

Key fields:

- `id`
- `document_type`
- `document_id`
- `fragment_id`
- `token`
- `fragment_version_at_last_edit`
- `created_at`
- `updated_at`

Rules:

- document Markdown stores custom Markdown fragment tokens
- example author-facing token shape: `{{fragment:org-behavioral-agreement}}`
- internal references use UUIDs
- editors display human-friendly fragment names and current fragment version
- viewing renders fragment text inline as normal document text
- broken references cannot be published

### 11.7 Fragment-Driven Version Bumps

When a fragment changes:

1. fragment version increments
2. every published policy/procedure document referencing it receives a `fragment_revision` bump
3. rendered views use the latest fragment text
4. acknowledgments continue to point to the document version acknowledged

Fragment-driven document version bumps should be performed by Laravel queue/jobs.

### 11.8 `document_version_snapshots`

Stores preserved document source/render state only when required to prove what was acknowledged.

Key fields:

- `id`
- `document_type`
- `document_id`
- `document_revision`
- `fragment_revision`
- `markdown_source_snapshot`
- `resolved_markdown_snapshot`
- `snapshot_reason`
- `created_at`

Rules:

- rendered Markdown snapshots are required only for document versions that have associated acknowledgments
- snapshots are not required for every edit/version by default
- acknowledgments store document ID and version; snapshots provide proof when acknowledgment history exists

### 11.9 `document_acknowledgment_requirements`

Represents a requirement to acknowledge a policy or procedure document.

Key fields:

- `id`
- `organization_id`
- `scope_type`
- `scope_id`
- `document_type`
- `document_id`
- `requirement_context`
- `active`
- `created_at`
- `updated_at`

Allowed Alpha 1 requirement scopes:

```text
organization
department
```

Allowed Alpha 1 contexts:

```text
signup
training
```

Rules:

- team-scoped acknowledgment requirements are out of scope for Alpha 1
- acknowledgments are not direct shift-signup gates
- acknowledgments are not direct credential-eligibility gates
- acknowledgments are not required outside signup or training in Alpha 1
- requirement administration is restricted to the two organizer roles, through the `documents.acknowledgments.review` capability they carry and no other role does; it is the authority to say a document must be acknowledged and to read who has, not the authority to publish the document, which follows whoever maintains its scope
- a requirement may only name a published document, because an unpublished one is refused at acceptance for everybody the requirement is addressed to
- `active` is a retirement switch rather than a deletion; the acknowledgments recorded against a retired requirement stand

### 11.10 `document_acknowledgments`

Records that a user acknowledged a specific policy/procedure document version.

Key fields:

- `id`
- `user_id`
- `staff_id`, nullable/reporting convenience
- `document_type`
- `document_id`
- `document_revision`
- `fragment_revision`
- `scope_type`
- `scope_id`
- `acknowledged_at`
- `accepted_by_node_id`
- `created_at`

Rules:

- acknowledgments are not creatable offline in Alpha 1
- creation requires server connection and Laravel acceptance
- acknowledgment records do not store the full rendered text directly
- associated document versions with acknowledgments must preserve enough snapshot state to prove what was acknowledged
- acknowledgments do not need to be automatically re-required when a document or included fragment changes, and a surface reports a version that has moved rather than reopening the requirement
- acknowledgments are audit events as well as acknowledgment records
- a user may only acknowledge a requirement whose scope reaches them: organization scope reaches anybody holding a status with the organization, prospective included, and department scope reaches that department's active members
- `staff_id` and `accepted_by_node_id` are resolved by the node from the requirement's scope and from this install, never named by the client

### 11.11 Document Exports

Document exports are generated server-side by Laravel.

Markdown export returns document Markdown with fragment references resolved inline.

PDF export renders document content with fragment text inline.

Exports include:

- document type
- document title
- document version
- scope
- export timestamp

Export/print events are audited.

Policy/procedure packet assembly is post-Alpha 1. When implemented later, packet assembly should be stored as an ordered list of document IDs.

### 11.12 Document Search

Alpha 1 supports title search only.

Full-text search within policy/procedure document bodies or fragments is not required.

PostgreSQL full-text search is not required for this feature in Alpha 1.

Search is the `?q=` parameter on the document library read (section 5.1), applied
after the caller's visibility has been decided. It is case-insensitive and
matches a substring of the title, because somebody looking a policy up types part
of what they remember of its name. An empty term is not a filter and returns the
whole visible library.

### 11.13 PowerSync Rules for Policies/Procedures

PowerSync should sync:

- published documents visible to the active user
- published fragments referenced by synced documents
- acknowledgment status for required visible documents
- draft/published/archived documents and fragments only for maintainers allowed to edit them

The mobile app includes a Policies & Procedures area.

The app shows document scope and version subtly, such as near the bottom of the document view.

The app does not need to show a special notice that the document includes automatically updated fragments.

---

## 11A. The Briefing and Notes

### 11A.1 Purpose

Notes are a standalone event-scoped domain. The Briefing is a hub that displays Command-added Note inclusions plus AARs, Directions, Action Plans, and Notices.

Alpha 1 persists Notes, Briefing Note inclusions (reference/link), and hub shell read models for the other types. Full AAR/Directions/Action Plan/Notice tables and commands are specified for post–Alpha 1 implementation.

### 11A.2 `notes`

Key fields:

- `id` (UUID)
- `event_id`
- `author_staff_id`
- `author_user_id`
- `title`, nullable
- `body_markdown`
- `created_at`
- `origin_node_id`, nullable
- `origin_device_id`, nullable

Rules:

- immutable after insert (no update of body/title)
- no soft-edit or append table
- creatable by department leads, team leads, `ic_lead`, and `ic_operator`
- readable by author and Command until included in The Briefing
- not owned by The Briefing or AARs

### 11A.3 `briefing_note_inclusions`

Represents Command adding a Note to The Briefing.

Key fields:

- `id`
- `event_id`
- `note_id`
- `inclusion_mode` (`reference` | `link`)
- `audience` (`event_staff` | `department_leads_only`), default `event_staff`
- `summary_markdown`, nullable (required when mode is `reference`)
- `linked_body_markdown`, nullable (optional snapshot when mode is `link`)
- `credited_author_staff_id`
- `added_by_staff_id`
- `position`, nullable
- `created_at`

Rules:

- `reference`: Command summary is shown; readers may open the original Note; credit to original author
- `link`: Note body shown verbatim as Command-attributed content credited to original author
- `event_staff` audience: visible to all approved event staff for the event
- `department_leads_only` audience: visible to department leads for the event, Command, and organizers; team leads excluded unless they also hold one of those roles
- same Note may also be included in an AAR via `after_action_report_note_inclusions`

### 11A.4 `after_action_reports`

Key fields:

- `id`
- `event_id`
- `kind` (`submission` | `final`)
- `scope_type` (`department` | `team` | `event`)
- `department_id`, nullable
- `team_id`, nullable
- `status` (`draft` | `submitted` | `published` | `frozen`)
- `document_revision`
- `submitted_at`, nullable
- `published_at`, nullable
- `frozen_at`, nullable
- `created_by_staff_id`
- `updated_at`
- `created_at`

ICS section content (JSON or related `after_action_report_sections` rows):

- `command`
- `operations`
- `logistics`
- `planning`
- `admin`

Each section stores Markdown body.

Rules:

- Submission AARs scoped to department or team of the authoring lead
- Final AAR is event-scoped (`scope_type=event`, `kind=final`)
- One logical Final AAR per event (new revisions bump `document_revision`)
- Submission window: through event end + 30 days
- Auto-assemble Final at event end + 45 days if none published
- Peer leads cannot read other submissions until Final is published/frozen

### 11A.5 `after_action_report_note_inclusions`

Key fields:

- `id`
- `after_action_report_id`
- `note_id`
- `inclusion_mode` (`reference` | `link`)
- `summary_markdown`, nullable (required when mode is `reference`)
- `linked_body_markdown`, nullable (optional snapshot when mode is `link`)
- `credited_author_staff_id`
- `ics_section` (`command` | `operations` | `logistics` | `planning` | `admin`)
- `position`
- `created_at`
- `created_by_staff_id`

Rules mirror Briefing inclusions: reference = summary + view original; link = verbatim with author credit.

### 11A.6 `briefing_directions`

Key fields:

- `id`
- `event_id`
- `title`
- `body_markdown`
- `target_scope` (`event` | `department` | `team` | `multi`)
- `audience` (`event_staff` | `department_leads_only`), default `event_staff`
- `status` (`draft` | `published` | `archived`)
- `created_by_staff_id`
- `published_at`, nullable
- `created_at`
- `updated_at`

Related: `briefing_direction_targets` (`direction_id`, `department_id` nullable, `team_id` nullable)

Related: `briefing_direction_links` (`direction_id`, `link_type`, `link_id`, `label` nullable, `position`)

`department_leads_only` audience visibility matches Briefing Note inclusions (department leads + Command + organizers; team leads excluded unless also holding one of those roles).

### 11A.7 `action_plans` and sections

`action_plans`:

- `id`
- `event_id`
- `title`
- `status` (`draft` | `published` | `archived`)
- `audience` (`event_staff` | `department_leads_only`), default `event_staff`
- `document_revision`
- `published_at`, nullable
- `created_by_staff_id`
- `created_at`
- `updated_at`

`action_plan_sections`:

- `id`
- `action_plan_id`
- `title`
- `body_markdown`
- `target_department_id`, nullable
- `target_team_id`, nullable
- `audience` (`event_staff` | `department_leads_only`), default `event_staff`
- `banner_enabled` (bool)
- `banner_screen_ids` (JSON array of UI contract screen IDs from fixed allowlist)
- `position`

Rules:

- banners only during active event window and only for allowlisted screen IDs
- department-leads-only plan/section content and banners only for permitted viewers
- publishing/updating may create `briefing_notices` rows

### 11A.8 `briefing_notices` and dismissals

`briefing_notices`:

- `id`
- `event_id`
- `title`
- `body_markdown`
- `severity` (`info` | `process` | `emergency`)
- `source_type` (`manual` | `action_plan`)
- `source_action_plan_id`, nullable
- `target_scope` (`event` | `department` | `team` | `multi`)
- `audience` (`event_staff` | `department_leads_only`), default `event_staff`
- `expires_at`, nullable
- `created_by_staff_id`, nullable
- `created_at`

`briefing_notice_targets` mirrors direction targets.

`briefing_notice_dismissals`:

- `id`
- `notice_id`
- `user_id`
- `dismissed_at`

### 11A.9 API surface

Reads:

```text
GET /api/events/{event}/briefing
GET /api/events/{event}/notes
GET /api/notes/{id}
GET /api/events/{event}/briefing-note-inclusions
GET /api/events/{event}/after-action-reports
GET /api/after-action-reports/{id}
GET /api/events/{event}/briefing-directions
GET /api/events/{event}/action-plans
GET /api/events/{event}/briefing-notices
```

Commands (Alpha 1 requires create-note and add-note-to-briefing):

```text
POST /api/commands/create-note
POST /api/commands/add-note-to-briefing
POST /api/commands/create-aar-submission
POST /api/commands/update-aar-submission
POST /api/commands/submit-aar-submission
POST /api/commands/publish-aar-final
POST /api/commands/add-note-to-aar
POST /api/commands/create-briefing-direction
POST /api/commands/update-briefing-direction
POST /api/commands/create-action-plan
POST /api/commands/update-action-plan
POST /api/commands/publish-action-plan
POST /api/commands/create-briefing-notice
POST /api/commands/dismiss-briefing-notice
```

### 11A.10 Sync rules

Alpha 1:

- sync `notes` to author and Command
- sync `briefing_note_inclusions` (and linked Note content needed for presentation) according to each inclusion’s audience
- Note create and add-to-Briefing are online-only via Laravel command acceptance

Post–Alpha 1:

- sync published Directions, Action Plans, Notices, Final AARs to users permitted by their audience and targeting rules
- sync Submission AARs to author, IC, and organizers
- sync notice dismissals for the authenticated user
- Action Plan banner payloads are derived from synced sections; hub access does not expand cache authority

### 11A.11 Audit

Audit Note create, Briefing/AAR Note add (reference or link), AAR submit/publish/auto-assemble/freeze, Direction/Action Plan/Notice mutations, and Notice dismissals.

---

## 12. Devices, Trust, and Shared Workstations

### 12.1 `devices`

Represents an app install/browser profile or managed workstation device.

Key fields:

- `id`
- `device_label`
- `platform`
- `device_public_key`
- `first_seen_at`
- `last_seen_at`
- `revoked_at`
- `created_at`

### 12.2 `device_trusts`

Represents a trusted relationship between a user and device.

Key fields:

- `id`
- `user_id`
- `device_id`
- `trusted_node_fingerprint`
- `first_trusted_at`
- `last_seen_at`
- `expires_at`
- `created_at`
- `revoked_at`

Rules:

- device trust is per user/device pair
- trust duration is 6 weeks
- local data is encrypted
- event mode fails closed if local encryption or device signing is unavailable

### 12.3 `shared_workstations`

Represents a special trusted device intended for multiple users and the pinned
context for Meridian Kiosk.

Key fields:

- `id`
- `device_id`
- `organization_id`
- `event_id`
- `department_id`, nullable
- `name`
- `trusted`
- `context_pinned_at`
- `context_pinned_by_user_id`, nullable
- `created_at`
- `revoked_at`

Rules:

- every Kiosk shared workstation must be pinned to one organization and one event before normal operation
- a Kiosk shared workstation may optionally be pinned to one department
- missing pinned organization/event context sends Meridian Kiosk to setup
- authorized organizers, lead organizers, and God Mode users may change pinned context
- pinned context constrains Kiosk shell/scope selection but does not grant authority
- inactivity timeout is 5 minutes for MVP; the session it bounds is `shared_workstation_sessions` in 12.6
- timeout abandons unsaved work while saved local queued operations remain queued for sync
- Admin mode may configure, review, and support Kiosk context/session surfaces but does not provide quick switching for Admin's own session

### 12.4 `shared_workstation_login_codes`

Represents human-typable login codes for shared workstation login.

Key fields:

- `id`
- `user_id`
- `event_id`
- `shared_workstation_id`
- `code_hash`
- `expires_at`
- `generated_by_user_id`
- `used_at`
- `revoked_at`
- `created_at`

Rules:

- raw login codes are not logged
- generation and use are audited
- codes are scoped to one user, event, and trusted shared workstation
- codes are valid for 6 weeks
- a successful code entry establishes a shared workstation session under 12.6 and does not issue an API token under 12.5

Generation authority:

- God mode may generate a code for any known user; `generated_by_user_id` records the operator
- a user may generate a code for themselves from a device on which they already hold a valid session; `generated_by_user_id` equals `user_id`
- self-service generation requires only reachability of the node that will accept the code, and does not require internet access, central reachability, or email delivery
- a user may not generate a code on behalf of another user
- generation is rate limited per user and per node
- code entry attempts are rate limited per workstation, and failures are audited after a threshold

### 12.5 API tokens

Represents a Sanctum bearer token issued to a client application, bound to a device.

Key fields:

- `id`
- `tokenable_type` and `tokenable_id`, resolving to the owning user
- `device_id`
- `name`
- `token`, stored hashed
- `abilities`
- `last_used_at`
- `expires_at`
- `revoked_at`
- `created_at`

Rules:

- a token is always bound to a `devices` record; issuance without a resolvable device is refused
- expiry uses a node-configured lifetime with a documented default
- revocation is evaluated at request time, so a revoked token stops authenticating on its next request without client cooperation
- God mode may list tokens by user and by device, revoke one token, and revoke every token bound to a device
- revoking a device revokes its tokens
- raw token values are never logged, audited, or exported; audit entries reference the token identifier and bound device
- token lifetime is independent of the 5-minute shared-workstation inactivity timeout in 12.3

### 12.6 `shared_workstation_sessions`

Represents the session a shared-workstation login code establishes: one user signed in at one workstation, scoped to that workstation's pinned event, ending 5 minutes after their last activity.

A separate table from 12.5 because these are separate credentials with separate rules. A code entry issues no token (AUTH-030) and the two lifetimes are independent, so storing a workstation session as a token would have made the distinction a column rather than a type.

Key fields:

- `id`
- `shared_workstation_id`
- `user_id`
- `event_id`
- `login_code_id`
- `session_key_hash`
- `started_at`
- `last_activity_at`
- `ended_at`, nullable
- `ended_reason`, nullable: `signed_out`, `timed_out`, or `superseded`
- `created_at` and `updated_at`

Rules:

- the raw session key is never stored; only a keyed hash, so a leaked database or backup hands out no live sessions
- `event_id` comes from the code, which took it from the workstation's pinned Kiosk context in 12.3, and never from a request
- expiry is derived from `last_activity_at` rather than stamped, so there is one 5-minute rule and no second copy of it to drift
- every authenticated request slides `last_activity_at`; the session is over 5 minutes after the last slide whether or not anything has yet observed it
- a session observed past its window is ended and stamped at the moment it expired, not at the moment it was noticed, so a workstation nobody touched for an hour records an hour of nobody being signed in
- the session is not authority of its own: it resolves to a user, and a disabled user resolves to nobody (technical spec 13.3, permissions come entirely from the active user)
- a session end is audited as `shared_workstation_session.ended` with the reason, which is the part not already recorded by the code's use
- ending a session touches nothing a device has queued; queued commands are not session data

---

## 13. Nodes and Node Operations

### 13.1 `nodes`

Represents a Meridian server node.

Key fields:

- `id`
- `node_name`
- `node_role`
- `is_local`
- `public_key`
- `organization_id`, nullable
- `event_id`, nullable for central/standalone
- `central_node_url`, nullable
- `paired_at`, nullable
- `created_at`
- `updated_at`
- `revoked_at`

Once nodes pair, this table holds peer node records as well as the install's
own node. `is_local` marks the install's own node so learning about a peer never
changes which node is ours, and `paired_at` records when a peer completed
pairing.

Node roles:

```text
development
standalone
central
onsite
```

### 13.2 `node_config_values`

Represents database-backed config overrides.

Key fields:

- `id`
- `node_id`
- `key`
- `value_json`
- `source`
- `updated_by_user_id`
- `created_at`
- `updated_at`

God mode must show whether a config value came from:

- file config
- database override
- runtime/default

### 13.3 `node_operations`

Represents append-only node-to-node sync operations.

Key fields:

- `id`
- `uuid`
- `origin_node_id`
- `target_node_id`, nullable
- `actor_user_id`
- `actor_device_id`, nullable
- `operation_type`
- `entity_type`
- `entity_id`
- `event_id`, nullable
- `created_at`
- `sent_at`
- `received_at`
- `applied_at`
- `status`
- `signature`
- `hash`
- `payload_json`
- `failure_reason`
- `retry_count`

Rules:

- operation signatures cover normalized operation fields
- operations are signed with node private keys
- device-originated operations are signed by the device and countersigned by the accepting node
- both signatures are retained in audit data

Append-only means operations are never deleted and their content is never
rewritten. Content is the normalized operation fields a signature covers
(`uuid`, `origin_node_id`, `target_node_id`, `actor_user_id`,
`actor_device_id`, `operation_type`, `entity_type`, `entity_id`, `event_id`,
`created_at`) together with `payload_json`, `signature`, and `hash`. The
delivery lifecycle columns (`sent_at`, `received_at`, `applied_at`, `status`,
`failure_reason`, `retry_count`) do change on the same row as an operation is
sent, received, applied, or retried, and are the only columns an update may
touch.

Other rules:

- `uuid` is unique and is the idempotency key; receiving the same operation
  more than once is safe because the receiver resolves it by `uuid` rather than
  by local primary key (technical spec 10.1)
- `created_at` is the origin node's creation time and travels with the
  operation; there is no `updated_at`, so a received operation keeps the
  originating timestamp rather than the receiving node's insert time
- `entity_id` is an unconstrained UUID because it is polymorphic across every
  synced entity type, matching `audit_events` (sections 4.1, 14.1)
- foreign keys restrict on delete so sync history is not destroyed by removing
  a node, user, device, or event

Status values:

```text
pending
sent
received
applied
failed
conflicted
```

- `pending` is created at the origin and not yet sent; on-site queues
  operations in this state while there is no internet (technical spec 10.2)
- `received` is stored by the receiver but not yet applied, because receivers
  store remote operations before applying them (technical spec 10.1)
- `failed` carries `failure_reason` and `retry_count`
- `conflicted` could not be safely applied and belongs in the sync conflict
  queue (section 14.2; technical spec 10.3)

Signature canonicalization:

Two nodes that do not share a code path must agree byte for byte on what a
signature covers, so the signed message is canonical rather than incidental. The
canonical payload is the format marker `meridian.node-operation.v1`, a newline,
and the normalized operation fields encoded as a JSON object in the field order
listed above, with unescaped slashes and unicode and null values preserved.
`created_at` is rendered as ISO-8601 UTC so the payload does not shift with a
node's configured timezone.

- `hash` is the SHA-256 hex digest of that canonical payload. A receiver
  recomputes it instead of trusting the value it was handed, so a rewritten
  normalized field is detected before any signature check runs
- `signature` is the base64 detached signature over the same canonical payload.
  The signing algorithm is derived from the signer's key material rather than
  stored in the column: Ed25519 for base64 sodium keys and RSA over SHA-256 for
  PEM keys, matching the key formats node setup generates (technical spec 7.3)
- `payload_json`, `signature`, `hash`, and the delivery lifecycle columns are
  not part of the signed message, because they either carry the signature itself
  or change after the origin node signs
- signing happens before the operation row is inserted, because `signature` and
  `hash` are append-only content
- for a device-originated operation, `signature` holds the accepting node's
  countersignature over the same canonical payload the device signed; the
  device signature is retained in `audit_events.signature_metadata_json`
  (section 14.1), which is where both signatures are kept
- verification fails closed: a blank signature, a hash mismatch, missing or
  unusable key material, an unknown origin node, or a signature made by another
  node is refused rather than treated as unverified-but-acceptable. Whether a
  cryptographically valid operation is then accepted, including node and device
  revocation and event authority, is decided by the receive/apply path

Receive, store, and apply:

Receivers store remote operations before applying them (technical spec 10.1), so
storing and applying are separate steps with separate transactions. An operation
is committed as `received` first, and only then is application attempted. An
application that throws, or a node that loses power mid-apply, therefore leaves
the operation in the log to be applied later rather than losing it.

What travels between nodes is the normalized operation fields, `signature`,
`hash`, and `payload_json`. The delivery lifecycle columns do not travel: each
node tracks an operation's progress on its own row, so a receiver sets
`received_at` and leaves `sent_at` unset rather than copying a delivery attempt
it did not make. `created_at` does travel, because it is the origin node's
creation time and is covered by the signature.

Refusal and failure are different outcomes:

- a refused operation is never written to `node_operations`. Refusals are
  malformed envelopes, a `target_node_id` naming another node, an unknown or
  revoked origin node, an unknown acting user, an unknown or revoked acting
  device, a node signature that does not verify, a `uuid` already held by an
  operation with different content, and an event-scoped operation whose origin
  node does not hold event authority for the event it names during that event's
  active event window (section 7.4; technical spec 10.2). Event authority is
  checked after the signature, so an unauthentic operation is refused as a
  forgery rather than reported as an authority problem, and an operation naming
  an event this install does not hold is stored rather than refused, because
  there is no window to evaluate. Because a refused operation leaves no row
  behind to record itself, refusals past envelope parsing are audited as
  `node_operation.rejected` with a stable reason code (section 14.1); the
  refused operation's own scope is recorded in `after_json` rather than in the
  audit event's scope columns, since a refused operation may name an event,
  node, or device this install does not have
- a failed operation was accepted and stored. It is marked `failed` with a
  `failure_reason` and an incremented `retry_count`, stays available for retry,
  and does not stop the rest of a sync run, because failed sync actions remain
  recoverable (technical spec 9.2) and unapplied operations must not block
  unrelated sync (technical spec 10.3)

Idempotency rests on `uuid`:

- a redelivery resolves to the stored operation, is not stored twice, and is not
  applied twice; an operation already carrying `applied_at` is not re-applied
- redelivery is resolved before the acceptance checks run, so an operation this
  node already accepted is not re-decided against node or device state that
  changed after it was accepted
- the same `uuid` arriving with different content is a replay rather than a
  redelivery and is refused; the stored operation is append-only and remains
  authoritative
- concurrent delivery of the same operation is resolved by the unique index on
  `uuid`, and the losing insert resolves to the stored winner instead of
  duplicating it
- an application is committed together with the row's `applied` mark, so there
  is no state in which local entity data changed but the operation still looks
  unapplied. Appliers must still be idempotent, because a retry after a failure
  runs against an entity a previous attempt may have partially reached

Applying an operation is per-entity behavior owned by the task that owns that
entity's sync, so the receive path dispatches on `entity_type` and
`operation_type` to a registered applier. An operation no applier claims is not
refused: it was authentic enough to store, so it is kept and marked `failed`
with a readable reason, and a node that receives an operation for an entity type
it does not yet understand can apply it after an upgrade.

Nothing is applied from `payload_json`:

- signatures cover the normalized operation fields only, so the payload is
  unauthenticated and can be changed in transit, or by the sending peer after
  signing, without breaking verification. An applier that read it would be
  writing local state from unauthenticated input on an operation that verified
- appliers are therefore handed the signed field projection and have no access
  to the payload at all, so this is a structural property rather than a
  convention an applier could forget
- `payload_json` is still stored, because the schema carries it and conflict
  review (section 14.2) shows local and remote values, but it is diagnostic and
  non-authoritative
- the consequence for operation vocabularies is that an operation's meaning must
  live in `operation_type` together with the entity it names. An operation that
  needs to carry a value expresses it as a command-style operation type rather
  than as payload data
- a redelivery whose payload does not match the stored copy is refused as a
  `uuid` conflict rather than absorbed quietly, because it has been changed in
  transit; the stored copy stands either way, since operations are append-only

Creating and queueing:

An operation created on this node is signed before it is inserted, because
`signature` and `hash` are append-only content, and is stored `pending`. Pending
is the queue: on-site queues operations in that state while there is no internet
and pushes them later (technical spec 10.2), so nothing about creating an
operation depends on the peer being reachable. `uuid` is minted at the origin,
which is what lets the same operation be recognized as itself on every node that
ever sees it.

Delivery state on the sending side:

- `pending` becomes `sent` only when the peer has confirmed it holds the
  operation, not when it was put on the wire. A response lost in flight would
  otherwise drop an operation nobody would resend, and redelivering an operation
  the peer already holds is safe, so erring towards resending is the cheap
  mistake
- an operation the peer refuses becomes `failed` with the peer's reason code and
  an incremented `retry_count`. It leaves the queue rather than being offered on
  every run forever, stays in the log, and can be retried deliberately once the
  reason is fixed
- only operations that originated on this node are offered to a peer. Operations
  received from a peer are stored, applied, and left alone, which is what keeps
  two nodes from bouncing the same operation back and forth
- an operation with a `target_node_id` is offered only to the node it names; an
  unaddressed operation goes to whichever peer this node syncs with
- Alpha 1 has exactly one central node and one active on-site node per event
  (technical spec 10.1), so delivery state fits on the operation row. A topology
  with several peers needs per-peer delivery records, because one `status`
  column cannot say "delivered to A but not to B"

### 13.4 `node_pairing_tokens`

Represents the one-time pairing tokens a central node creates so an on-site or
standalone node can pair with it (technical spec 7.3, 7.4).

Key fields:

- `id`
- `token_hash`
- `issued_by_node_id`
- `issued_by_user_id`, nullable
- `label`, nullable
- `expires_at`, nullable
- `used_at`, nullable
- `paired_node_id`, nullable
- `revoked_at`, nullable
- `created_at`
- `updated_at`

Rules:

- only the token hash is stored; the plaintext token is displayed once at issue
  time and is not recoverable afterwards
- tokens are issued by, and redeemed on, a central node only
- Alpha 1 does not require quick expiry, so `expires_at` is normally unset
- a token pairs one node once; used, revoked, and expired tokens are refused
- because tokens do not expire by default, God mode may revoke outstanding
  unused tokens; revocation is audited and never rewrites an already-used token,
  which is preserved pairing history

### 13.5 Node pairing endpoint

```text
POST /api/node-pairing
```

Node-to-node, not user-facing. The one-time pairing token is the only
credential, so the route carries no user session and is rate limited instead.

Request: `pairing_token`, `node_id`, `node_name`, `node_role` (`onsite` or
`standalone`), `public_key`.

Response: the central node identity (`id`, `node_name`, `node_role`,
`public_key`), the registered `paired_node`, `paired_at`, and `replayed`.

Node ids are global, not per-install. A node keeps the same `nodes.id` on every
install that knows it: the pairing node sends its own id and central adopts it,
and the pairing node adopts the id central returns. This is a requirement of
node sync rather than a convenience. A node operation names its origin and
target nodes by id inside the message the signature covers (section 13.3), so a
receiver that knew the sending node by a different local id could not resolve
the origin of any operation that node signed, and could not rewrite the id
without breaking verification. A submitted id that is already held by a
different node identity — a different name, different key material, or this
install's own node — is refused as `node_id_conflict`.

Rules:

- redemption registers the pairing node as a peer `nodes` record on central,
  under the pairing node's own id, and marks the token used
- replaying a used token with the same node id, node name, and public key
  returns the original pairing so a lost response can be recovered; replaying it
  with a different node identity is refused
- a node name already held with different key material, and a revoked peer
  node, are both refused
- the on-site node stores the returned central identity as node config values
  (`central_node_name`, `central_node_public_key`, `central_node_paired_url`,
  `central_node_paired_at`) so God mode shows pairing state with the same
  file/database/runtime source labels as the rest of node config
- pairing status is derived from the configured central URL against the URL
  that was paired, so changing the central node URL triggers a pairing recheck
  (technical spec 7.3)
- event mode refuses pairing over plain HTTP (technical spec 8.2)
- token issue, token revocation, and completed pairing are audited as node
  pairing/config changes (section 8)

### 13.6 Node sync exchange endpoint

```text
POST /api/node-sync
```

Node-to-node, not user-facing. There is no user session: the calling node signs
the exchange with its node private key and is verified against the public key
registered when the two nodes paired.

One request carries both directions. Node sync is bidirectional rather than
push-only (technical spec 10.1), and the on-site node initiates because central
has a routable address while an on-site node on an event network usually does
not. Making the response carry central's own queued operations is what keeps
sync bidirectional without central having to open a connection inwards; it is
not a statement about authority, since on-site is the authoritative node during
an active event window (technical spec 10.2).

Request: `source_node_id`, `sent_at`, `operations` (operation envelopes),
`acknowledged` (uuids the caller now holds), `refused` (operations the caller
will never accept, each with `uuid`, `reason_code`, and `detail`), and
`signature`.

Response: `node_id`, `received_at`, `results` (one per pushed operation),
`operations` (this node's queued operations for the caller), `acknowledged`, and
`refusals_recorded`.

An operation envelope is the normalized operation fields, `signature`, `hash`,
and `payload_json` (section 13.3). The delivery lifecycle columns do not travel.

Result outcomes:

```text
stored
duplicate
refused
```

A result is a delivery outcome, not an application outcome. It answers the only
question the sending node needs answered — does the peer hold this operation
now? — and deliberately does not report whether the peer applied it. A peer that
stored an operation but could not apply it keeps its own `failed` record and
retries locally; re-sending would be redelivery of an operation the peer already
holds.

Rules:

- the exchange signature covers the format marker `meridian.node-sync.v1`, a
  newline, and a JSON object of `source_node_id`, `sent_at`, and the `uuid`
  lists for `operations`, `acknowledged`, and `refused`, in that order. Operation
  contents are not covered, because each operation carries its own node
  signature over its own canonical payload and is verified separately; refusal
  reason text is not covered either, because it is stored as a readable failure
  reason rather than acted on
- authentication is required because the response hands operations back: an
  unauthenticated caller could otherwise pull this node's queue. Operation-level
  signatures alone cannot answer whether a caller may be given operations
- the calling node must be a `nodes` record that is not this install's own, is
  not revoked, and has completed pairing
- `sent_at` must be inside a two-sided clock window (default five minutes),
  which bounds replay of a captured exchange; a peer whose clock runs ahead is
  as unverifiable as one whose clock runs behind
- one refused operation does not end an exchange. The refusal is reported in
  `results` and the rest of the batch is processed, because unresolved problems
  must not block unrelated sync (technical spec 10.3)
- a malformed envelope refuses the whole exchange rather than one operation. A
  sending node only builds envelopes from rows it already holds, so an
  unparseable one is corruption or a hostile caller; nothing is stored, so the
  sender's retry is safe
- refused exchanges are audited as `node_sync.refused` with a stable reason code
  (section 14.1); refused operations inside an accepted exchange are audited by
  the receive path as `node_operation.rejected`
- an exchange carries at most one batch (default 100 operations). A run is a
  loop of exchanges, so a backlog built up during an outage drains over repeated
  exchanges, bounded per run so a scheduled sync cannot spin indefinitely
- acknowledgements and refusals produced by one exchange are carried into the
  next one by the same run, so nothing about that bookkeeping is persisted
  between runs. A run that stops early simply leaves the peer offering the
  unacknowledged operations again, and the receiver stores them idempotently
- a peer can only settle operations that originated on the receiving node. An
  acknowledgement or refusal naming an operation this node did not originate, or
  one that is no longer pending, changes nothing
- an unreachable peer changes no operation state at all: operations stay
  `pending` and are pushed on a later run (technical spec 10.2)
- event mode refuses sync over plain HTTP (technical spec 8.2), and a node whose
  central URL changed does not sync until pairing is confirmed again (technical
  spec 7.3)

Whether an authentic operation is allowed to change event-scoped state during an
active event window is event authority (technical spec 10.2), and disagreement
between local and remote state is the sync conflict queue (section 14.2).

Sync state is derived from `node_operations` and `audit_events` rather than
stored as a run status, so there is one source of truth. A stored status would
let a run that died mid-way look healthier than one that finished and reported a
problem. God mode reads it on the node configuration screen, and Electron health
surfaces the same signals on-site (technical spec 25.3). It reports:

- operations queued here, delivered to the peer, and refused by the peer
- operations received from the peer, applied, and stored but not applied
- the oldest queued operation, the last send, and the last receipt
- recent operation failures and recent refused exchanges

The two directions are counted apart because they fail for different reasons:
undelivered means this node cannot reach its peer, while unapplied means the peer
was reached and something local is wrong. Queued work is reported without alarm,
because an on-site node building a backlog during an outage is the system working
as designed; failures and refused exchanges are what ask for a human.

---

### 13.7 `system_config_overrides`

Node-local database overrides for catalogued environment variables (technical
spec 22A.3, 22A.5; SYS-005 through SYS-015). The catalogue itself is
`apps/server/.env.example`; this table stores only the overrides.

Key fields:

- `id`
- `node_id` (unique with `name`; overrides are node-local and never synced)
- `name` (environment-variable name)
- `type` (declared type at save time)
- `value_json` (JSON-encoded non-secret value; preserves `""`/`null`/`false`/`0`/`"0"` distinctions)
- `secret_value` (encrypted at rest; never readable back through any surface)
- `is_secret`
- `is_active`
- `change_reason`
- `created_by_user_id`
- `updated_by_user_id`
- `created_at`
- `updated_at`

Rules:

- never replicated through PowerSync; never carried by node sync (SYS-011, SYS-012)
- bootstrap-locked variables are refused at write and skipped at load (SYS-010)
- invalid rows are skipped at boot and reported through diagnostics (SYS-006, SYS-022)
- every write is audited with redacted values (SYS-015)

### 13.8 `node_health_reports`

Latest sanitized health report per known node (technical spec 22A.11; SYS-037
through SYS-040). One row per node, replaced on each verified delivery.

Key fields:

- `id`
- `node_id` (unique)
- `report_uuid`
- `overall_status`
- `node_name`
- `node_role`
- `meridian_version`
- `config_schema_version`
- `category_statuses_json`
- `summary_json` (numeric sync/disk/memory summaries only)
- `warnings_json` (sanitized `key`/`status`/`summary` lines)
- `generated_at`
- `received_at`

Reports never contain environment values, secrets, credentials, connection
strings, tokens, or operational/volunteer data (SYS-039).

### 13.9 Node health report endpoint

```text
POST /api/node-health-report
```

Node-to-node, no user session. The body is the report payload signed with the
reporting node's private key over the `meridian.node-health-report.v1`
canonical payload. The receiver verifies origin node, pairing status,
freshness inside the node-sync replay window, and the signature against the
public key learned at pairing; anything unverified is refused with a reason
code and audited (SYS-037, SYS-038). Response: `{"status": "stored",
"report_uuid": "..."}`.

## 14. Audit and Sync Conflicts

### 14.1 `audit_events`

Represents immutable system audit events.

Key fields:

- `id`
- `organization_id`, nullable
- `event_id`, nullable
- `department_id`, nullable
- `actor_user_id`, nullable for system jobs
- `actor_device_id`, nullable
- `actor_node_id`, nullable
- `action`
- `entity_type`
- `entity_id`
- `before_json`, nullable
- `after_json`, nullable
- `reason`, nullable
- `source_context`
- `signature_metadata_json`, nullable
- `created_at`

Storage and volume (ADR-0002):

- The table is indexed for the two audit surfaces that read it by scope:
  `(organization_id, created_at)`, `department_id`, `event_id`, and
  `actor_user_id`, alongside the original `created_at`, `(entity_type,
  entity_id)`, and `action`.
- How much an organization writes is configurable per organization, on five
  ordered verbosity levels with per-action overrides. The default is the most
  complete level, which is what was recorded before the setting existed;
  recording less is opt-in. A required floor —
  everything section 8 and requirements 2.4 oblige — is enforced in the write
  path and cannot be reached below by any level or override. An action the
  catalogue does not know is written at every level.
- How much an organization keeps is configurable per organization, by maximum
  rows, maximum estimated content size, and a retention window in days. Every
  limit is null by default, meaning unbounded. Reaching a limit archives the
  oldest rows to a JSON Lines file carrying every column, records the archival
  as an `audit.archived` entry, and only then removes them. Nothing else in the
  application may remove an audit row.
- On PostgreSQL the table is range-partitioned by month on `created_at`, on by
  default. The primary key is `(id, created_at)` because the partition key must
  appear in every unique constraint, and `created_at` is not-null because a
  partition key cannot be null. A default partition catches rows outside the
  created months, so an unexpected timestamp is never a write that fails.
- These controls are configured from God Mode only.

### 14.2 `sync_conflicts`

Represents operations that could not be safely applied.

Key fields:

- `id`
- `operation_id`
- `conflict_type`
- `entity_type`
- `entity_id`
- `local_value_json`
- `remote_value_json`
- `reason`
- `status`
- `reviewed_by_user_id`
- `reviewed_at`
- `resolution`
- `created_at`
- `updated_at`

Rules:

- conflict review is God-mode/Orchid-only for Alpha 1
- conflict resolution is audited
- severe data conflicts should trigger an Electron health warning
- Name Reference extraction, clicking, and search do not create Name Reference-specific audit records beyond existing source-record read/view/search audit behavior where applicable

---

## 15. Policy-Level Constraints and Deferments

### 15.1 No Free-Floating Operational Permissions

Meridian should avoid free-floating permissions outside organization, department, and team membership.

Direct user roles are reserved for god-mode/admin repair.

### 15.2 No Free-Floating Hours

Hours must always be tied to:

- event
- department
- shift
- staff
- actual start time
- actual end time

Setup, teardown, standby, emergency coverage, or unscheduled labor must be represented as shift work if it should count for hours/credits.

### 15.3 No Field Report Drafts or Edits

Field Reports are finalized at submit time.

Original Field Report title and body are immutable.

Only the original submitter may append.

Appends do not have titles and cannot alter the original title.

### 15.4 Online-Only Incidents

Incident creation is online-only for Alpha 1.

Incident cache is limited and restricted to IC users.

### 15.4A Name References Are Not Identity Data

Name References are operational text markers and derived search/display artifacts.

They must not be modeled as canonical people, aliases, identities, entities, suspects, volunteer profile links, notifications, or user mentions.

### 15.5 Policy/Procedure Alpha 1 Boundaries

Alpha 1 includes:

- policy documents
- procedure documents
- reusable fragments
- fragment references
- document versioning
- acknowledgment during signup/training
- Markdown/PDF export
- PowerSync of visible published documents/fragments

Alpha 1 excludes:

- policy/procedure packet assembly
- full-text document search
- team-scoped acknowledgment requirements
- offline acknowledgments
- rich formatting beyond Markdown
- nested fragments

### 15.6 Event Map and Placement Alpha 1 Boundaries

Alpha 1 includes:

- event maps enabled by default, `placement` and `topographic` types
- whole-map draft/published/archived lifecycle
- uploaded/imported map asset or prepared map package with lightweight metadata
- camps with name and location only
- lightweight map locations/features
- point/line/polygon-capable geometry, GeoJSON-compatible where appropriate, plus local placement coordinates
- event-level Placement department designation with organization default
- operations-window locking of published map geometry and camp/location records
- organizer/admin locked-map override with explicit reason
- optional IMS incident camp/location reference
- optional shift meeting and deployment map-location references
- offline sync of map packages/permitted camp/location data to permitted devices

Alpha 1 excludes:

- full GIS editor, drawing suite, automatic geocoding, public map builder
- a `hybrid` map type beyond linkable placement/topographic maps
- arbitrary dropped pins
- camps/map places in the global command palette
- structured map/location fields on Field Reports
- volunteer-submitted map corrections
- live GPS tracking, turn-by-turn routing, or real-time personnel icons
- per-camp lifecycle states, camp notes, descriptions, contacts, or affiliation fields
- multiple Placement departments per event
- georeferencing requirement for placement maps
- equipment location fields (equipment location is not yet modeled)

---

## 16. Initial Open Implementation Details

The following implementation details may be refined later without changing the core data model contract:

1. Exact PowerSync schema and sync rules.
2. Exact Laravel module folder structure.
3. Exact OpenAPI generation package.
4. Exact file storage abstraction and S3/MinIO transition plan.
5. Exact photo conversion pipeline.
6. Exact Markdown sanitizer/renderer libraries for Laravel and Vue/Capacitor.
7. Exact custom fragment token grammar and editor UI.
8. Exact snapshot strategy for acknowledged document versions.
9. Exact queue/job behavior for fragment-driven document version bumps.
10. Exact acknowledgment placement in signup and training screens.
11. Exact attendance reconciliation rules.
12. Exact sync conflict resolver UI.
13. Exact shared workstation session UI.
14. Exact IC incident dashboard UI.
15. Exact deployment bundle format.
16. Exact physical storage shape for the rebuildable Name Reference derived index.
17. Exact map asset/package storage, tiling, and topographic basemap package format.
18. Exact GeoJSON/geometry storage representation and local-coordinate encoding for placement maps.
19. Exact effective-permission-level role codes for the Placement department (mint placement-specific codes mirroring IC roles, or reuse `department_lead` plus map-management grants).

---

## 17. Implementation Rule

No layer may implement business rules independently.

The same domain service/action/command handler should be used by:

- API controllers
- Orchid screens
- PowerSync upload handling
- mobile/PWA operational actions
- Electron/shared workstation actions
- node operation application
- imports
- exports
- future integrations

This prevents the API, Orchid, offline clients, and node sync from drifting into separate interpretations of Meridian's rules.
