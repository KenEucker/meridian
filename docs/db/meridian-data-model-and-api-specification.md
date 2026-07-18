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
- Orchid admin/god-mode interface
- OpenAPI-described Laravel API
- PowerSync device projections
- local SQLite client databases
- Vue/Capacitor mobile field app
- Electron on-site wrapper
- node-to-node synchronization
- export/reporting layers

This document is intentionally an implementation contract, not an exhaustive migration-by-migration schema. The goal is to define enough model shape for consistent development without over-specifying every database column before implementation.

---

## 2. Source Documents and Alignment Notes

This version aligns the data model with:

- Meridian Requirements Document, Draft v0.3
- Meridian Technical Specification, Draft v0.2

Important alignment changes from earlier data-model drafts:

1. Teams replace the earlier operational concept of department roles.
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

### 3.3 Orchid Responsibility

Orchid owns permission administration and trusted admin/god-mode data administration surfaces.

Orchid provides:

- permission catalog display
- role and grant administration
- team-based grant administration
- direct god-mode/admin assignment where allowed
- CRUD/admin screens for domain records
- sync conflict review in God mode
- audit log review
- policy/procedure authoring and preview screens
- dangerous repair/admin actions where required

Orchid does not bypass Laravel authorization, validation, audit, or domain rules.

All Orchid actions must call the same policies, services, and command/action classes used by the API and sync upload flows where practical.

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
GET /api/events
GET /api/events/{event}
GET /api/events/{event}/departments
GET /api/events/{event}/teams
GET /api/events/{event}/shifts
GET /api/events/{event}/field-reports
GET /api/events/{event}/incidents
GET /api/policy-documents
GET /api/procedure-documents
GET /api/document-fragments
GET /api/document-acknowledgments/me
```

All read APIs return permission-filtered resources.

Incident APIs must return data only to IC-authorized users.

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
POST /api/commands/set-current-deployment
POST /api/commands/checkout-equipment
POST /api/commands/return-equipment
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
POST /api/commands/export-document
POST /api/commands/designate-placement-department
POST /api/commands/publish-event-map
POST /api/commands/archive-event-map
POST /api/commands/override-locked-map-data
```

Fragments do not have Draft/Published/Archived states in Alpha 1, so fragment publish/archive commands are not part of the Alpha 1 command surface.

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
- equipment operations
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
ic_lead
ic_operator
ic_viewer
organizer
lead_organizer
god_mode
```

Permission decisions should be explainable in the UI.

Example:

```text
You can mark no-show because you are a shift lead for this team.
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
- readiness/sync state

Shift leads may additionally cache:

- assigned staff for teams/shifts they lead
- check-in/check-out/no-show state for those teams/shifts
- team roster

Department leads may additionally cache:

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

Policy/procedure acknowledgments are not creatable offline in Alpha 1.

Incident creation requires server connection.

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

### 7.5 Sync Conflicts

Conflicts go to a sync conflict queue.

For Alpha 1, conflicts are visible only in God mode/Orchid.

Conflicts should be grouped by entity type and resolved by choosing either:

- accept on-site
- accept central

Conflict resolution is audited.

Unresolved conflicts should not block unrelated sync.

---

## 8. Audit Model

Every meaningful change should be attributable to a user and timestamped.

Audit applies to:

- permission changes
- auth/device trust events
- shared workstation login code generation/use
- node pairing/config changes
- attendance direct edits
- dangerous Orchid actions
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
27. Devices and trust
28. Shared workstations
29. Nodes and node sync
30. Audit
31. Sync conflicts

---

## 10. Canonical Entity Map

### 10.1 Organizations

#### `organizations`

Represents an organization that manages staff.

Key fields:

- `id`
- `name`
- `slug`
- `organizers_department_id`
- `default_ic_department_id`
- `default_placement_department_id`
- `default_credit_policy_id`
- `active_inactive_threshold_years`
- `prospective_inactive_threshold_years`
- `calendar_year_start_month`
- `calendar_year_start_day`
- `created_at`
- `updated_at`
- `archived_at`

Relationships:

- has many events
- has many departments
- belongs to Organizers Department
- has many staff through staff organization status records
- has many policy documents
- has many procedure documents
- has many document fragments
- has many credit policies

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
- `active_event_window_starts_at`
- `active_event_window_ends_at`
- `created_at`
- `updated_at`
- `archived_at`

Relationships:

- belongs to organization
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
- `created_at`
- `updated_at`
- `archived_at`

Relationships:

- belongs to organization
- has many teams
- has many department memberships
- has many shifts
- may be assigned to many events
- may be selected as the event IC department
- may own policy/procedure documents and fragments

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
- `created_at`
- `updated_at`
- `archived_at`

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
- `created_at`
- `updated_at`
- `archived_at`

#### `training_prerequisites`

Represents prerequisite relationships between trainings.

Key fields:

- `id`
- `training_id`
- `prerequisite_training_id`
- `created_at`

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
- `created_at`
- `updated_at`
- `archived_at`

Rules:

- Meridian tracks completion as complete/incomplete
- signed document contents are not stored in MVP

#### `waiver_completions`

Represents waiver completion.

Key fields:

- `id`
- `waiver_id`
- `staff_id`
- `completed_at`
- `expires_at`
- `recorded_by_user_id`
- `created_at`

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
- `credit_policy_id`, nullable
- `meeting_map_location_id`, nullable
- `created_at`
- `updated_at`
- `cancelled_at`

Rules:

- shifts belong to an event and department
- shifts define eligible team membership
- a shift may have its own displayed title/function
- a shift does not require membership in multiple teams
- required trainings and waivers must be enforced for scheduled and unscheduled additions
- overlap warnings are shown by default rather than hard-blocking
- elevated leads may assign overlapping shifts
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
- duplicate check-in is idempotent
- overlapping check-ins are allowed but warn
- offline check-out without known server-side check-in is accepted and reconciled later
- attendance conflicts go to the sync conflict queue

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
- shift/department leads may correct hours during the correction grace period
- hours freeze after the grace period
- staff do not self-report hours in MVP

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
- credential revocation is restricted to organizers and IC department leads
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
- `asset_tag`
- `serial_number`
- `status`
- `created_at`
- `updated_at`
- `archived_at`

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
- equipment does not need to be tied to a shift for MVP
- department-to-department allotments and full custody chains are out of scope

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
- when a Field Report is attached to an incident, content is copied into incident notes as `Field Report: <title>` followed by the body
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
- incident timestamps are not retroactively changed
- incident title edits create timeline entries
- incident body/history is append-only
- closing requires a note/reason
- reopening is allowed
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
- when a Field Report is attached, Field Report content is copied into incident notes as `Field Report: <title>` followed by the body
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

Represents configurable incident type labels.

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

### 11.3 `procedure_documents`

Represents a Markdown governance document describing how operational work should be performed.

Key fields mirror `policy_documents`:

- `id`
- `organization_id`
- `scope_type`
- `scope_id`
- `title`
- `slug`
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
- acknowledgments do not need to be automatically re-required when a document or included fragment changes
- acknowledgments are audit events as well as acknowledgment records

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

Represents a special trusted device intended for multiple users.

Key fields:

- `id`
- `device_id`
- `event_id`
- `name`
- `trusted`
- `created_at`
- `revoked_at`

### 12.4 `shared_workstation_login_codes`

Represents human-typable login codes generated by God mode.

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

---

## 13. Nodes and Node Operations

### 13.1 `nodes`

Represents a Meridian server node.

Key fields:

- `id`
- `node_name`
- `node_role`
- `public_key`
- `organization_id`, nullable
- `event_id`, nullable for central/standalone
- `central_node_url`, nullable
- `created_at`
- `updated_at`
- `revoked_at`

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

---

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
