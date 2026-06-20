# Meridian Technical Specification

Draft: 0.2  
Scope: Alpha 1 technical architecture and implementation direction  
Status: Working draft  
Additive Update: Policies and Procedures technical architecture added.
Additive Update: Name References technical behavior added for IMS notes and Field Reports.

---

# 1. Purpose

Meridian is a general-purpose, configurable volunteer operations platform for organizations and events. It is designed for field reliability, offline-capable operations, and trusted on-site coordination.

Meridian supports organizations, events, departments, teams, staff, shifts, attendance, field reports, incidents, Name References in IMS text, credentials, permissions, policies, procedures, reusable governance fragments, policy/procedure acknowledgments, node sync, and administrative data repair.

The primary Alpha 1 goal is to prove that Meridian can operate reliably in a real event environment where internet connectivity may be limited, intermittent, or unavailable.

---

# 2. Product and Architecture Principles

## 2.1 Core principles

Meridian prioritizes:

1. Operational reliability.
2. Offline-capable field use.
3. Clear event authority during active event windows.
4. Append-only operational history where appropriate.
5. Strong auditability.
6. Secure device and node trust.
7. Practical deployment on inexpensive hardware.
8. Open-source Meridian source code.
9. Fast development toward a working MVP/Alpha 1.
10. Reusable governance content without duplicating common policy/procedure language.

## 2.2 Platform posture

Meridian should feel like a configurable platform, not a one-off app.

However, Alpha 1 should avoid excessive modularity or framework abstraction that slows down delivery. The architecture should be a modular monolith, not a plugin platform.

## 2.3 Open source posture

Meridian source code should be open source from the start.

PowerSync may be used as an external dependency and does not need to be shipped as part of Meridian’s source code. Meridian does not need to maintain a fully open-source alternate sync path for Alpha 1.

---

# 3. High-Level Architecture

Meridian is composed of four major runtime surfaces:

1. Server/admin application.
2. Mobile/field application.
3. Desktop on-site wrapper.
4. Node-to-node synchronization layer.

## 3.1 Server/admin application

The server/admin application is a Laravel modular monolith with:

- Laravel.
- PostgreSQL.
- Orchid admin panel.
- OpenAPI-described API.
- PowerSync service integration.
- Node sync API.
- Docker Compose deployment.

The Laravel server remains the canonical writer to PostgreSQL. Clients do not directly mutate canonical tables. Device writes are submitted through Laravel validation and acceptance flows.

## 3.2 Mobile/field application

The field application is:

- Vue.
- Capacitor from day one.
- Offline-capable.
- PowerSync-backed.
- Locally encrypted.
- Device-signing capable.
- Installable as a native-feeling application.

The installed app is required for reliable on-site/offline operation where DNS or browser-trusted HTTPS cannot be guaranteed.

## 3.3 Desktop on-site wrapper

The on-site laptop uses an Electron desktop wrapper.

The Electron wrapper:

- Wraps the local Meridian web UI.
- Is installable.
- Runs fullscreen/kiosk-style by default.
- Shows a health panel.
- Shows server/node/sync status.
- Auto-recovers if the local UI crashes.
- Does not start or stop Docker Compose.
- Does not include emergency export in Alpha 1.
- Does not need to block accidental close in Alpha 1.

## 3.4 Node-to-node sync

Meridian supports multiple node roles:

- `development`
- `standalone`
- `central`
- `onsite`

Central and on-site nodes synchronize using Meridian application-level operation sync, not raw database replication.

PowerSync is used for server-to-device synchronization. Meridian node sync is separate from PowerSync.

---

# 4. Repository and Package Topology

Meridian should live in a single monorepo.

Proposed structure:

```text
meridian/
  apps/
    server/        Laravel + Orchid + API
    mobile/        Vue + Capacitor
    desktop/       Electron wrapper
  packages/
    shared-types/
    openapi-client/
  deploy/
    docker/
    caddy/
    powersync/
    dns/
```

Each target should produce a built distribution artifact:

- Server Docker image / install bundle.
- Mobile app package.
- Electron desktop installer.
- Deployment configuration bundle.

OpenAPI should generate a TypeScript API client used by the Vue app, even though most operational data comes through PowerSync.

---

# 5. Server Stack

## 5.1 Core server

The server stack is:

- Laravel.
- PostgreSQL.
- Orchid.
- OpenAPI.
- Docker Compose.
- PowerSync service.
- Caddy or equivalent reverse proxy.
- DNS support for on-site deployments where Meridian controls DNS.

## 5.2 Modular monolith boundaries

Laravel modules should be organized by domain.

Initial modules:

```text
Organizations
Events
Departments
Teams
Staff
Users
Memberships
Roles
Permissions
Shifts
Attendance
FieldReports
Incidents
Credentials
PolicyDocuments
ProcedureDocuments
DocumentFragments
DocumentAcknowledgments
DocumentExports
Devices
SharedWorkstations
NodeConfig
NodeSync
Audit
SyncConflicts
Files
```

This should be a lightweight modular monolith using folders and namespaces, not a heavy plugin system.

Each domain module may own:

- Migrations.
- Models.
- Policies.
- Actions/services.
- API endpoints.
- Orchid screens.
- Tests.

Cross-module behavior should happen through explicit service/action classes rather than implicit event spaghetti.

A central audit service should be used by all modules.

OpenAPI endpoints should be grouped by module where practical.

---

# 6. Database and IDs

## 6.1 Database

Meridian uses PostgreSQL for central and on-site nodes.

SQLite may be used locally by PowerSync on devices, but the canonical server database is PostgreSQL.

## 6.2 IDs

All externally referenced records use UUIDs as primary IDs.

UUIDs are generated by the creating node or device before sync.

Offline field reports receive a device-generated UUID before reaching any server.

Attendance operations receive operation UUIDs generated on the device.

Central preserves original on-site UUIDs forever.

Records should store, where relevant:

- `origin_node_id`
- `origin_device_id`

## 6.3 Timestamps

Meridian stores operationally relevant timestamps.

Where applicable:

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

Each node should have a configured timezone.

Meridian does not need proactive clock drift detection in Alpha 1. However, if a submitted timestamp is obviously invalid, impossible, or outside a sanity window, the server should:

1. Accept the operation if otherwise valid.
2. Store the raw timestamp in the audit trail.
3. Use a corrected/interpreted event-local timestamp for operational display.
4. Mark the timestamp as suspect.

---

# 7. Node Model

## 7.1 Node roles

A Meridian install has a declared node role:

```text
development
standalone
central
onsite
```

A standalone node may later become a central node or an on-site node.

Changing node mode requires God mode and a restart.

An on-site node is bound to a single event. Changing the event binding on an on-site node is blocked once event data exists.

## 7.2 Node naming

Nodes should use domain-like names with periods and no spaces.

Examples:

```text
juplaya.2027.onsite
juplaya.central
gerlach.test
```

## 7.3 Node configuration

Node configuration is file-first, database-second.

File config provides boot defaults. Database overrides allow some settings to change on the fly. God mode must show whether each config value came from:

- file config
- database override
- runtime/default

Node config may include:

```text
node_name
node_role
node_public_key
node_private_key or key reference
central_node_url, optional
organization_id
event_id
PowerSync config
hostname/domain, optional
```

Private keys do not need a separate secret volume for Alpha 1.

Fresh standalone nodes generate a keypair immediately.

Connecting to central requires a one-time pairing token created by central. Pairing tokens do not need quick expiry for Alpha 1. Pairing does not need special audit treatment beyond normal config/audit behavior.

Changing the central node URL triggers a node-pairing recheck.

Changing hostname or certificate settings requires setup validation before event mode resumes.

## 7.4 Node setup

Docker Compose should not ask questions directly.

Docker Compose brings the system up. Setup happens through either:

```text
meridian setup
```

or a first-run web setup flow:

```text
https://localhost/setup
```

Setup should generate secrets if they are missing or still set to defaults, including:

- Laravel `APP_KEY`.
- Node keys.
- Service secrets.

Sample configs must contain fake values only.

---

# 8. Deployment and Networking

## 8.1 Deployment model

The same Docker Compose-based install should support:

- Central node.
- On-site node.
- Standalone node.
- Development node.

An on-site install can connect to a central node and sync with it.

An on-site node may also be configured standalone and later paired with central.

## 8.2 Secure connection policy

Meridian production/event mode must never use plain HTTP.

Connection policy:

```text
1. Browser/PWA access requires browser-trusted HTTPS.
2. Production/event mode never uses plain HTTP.
3. Preferred on-site deployment includes a Meridian-controlled router/AP/DNS path.
4. If network/DNS control is unavailable, the installed Capacitor app is the reliable client.
5. The installed app may trust a locally discovered on-site node through Meridian node fingerprint/cert pinning.
6. Direct IP/self-signed browser access is God-mode/emergency only, not normal staff workflow.
```

## 8.3 Preferred on-site networking model

Preferred event deployment includes a small Meridian-controlled network kit:

```text
Meridian server laptop
+ small travel router / Wi-Fi AP
+ optional Ethernet switch
```

The router/AP provides:

- Wi-Fi.
- DHCP.
- DNS.
- Local resolution for the event hostname.

Example:

```text
SSID: Meridian-Juplaya-2027
DNS: juplaya-2027-onsite.example.org → 10.10.0.2
```

The on-site node uses a public-domain HTTPS certificate provisioned before the event.

Example:

```text
https://juplaya-2027-onsite.example.org
```

During the event, local DNS resolves the public hostname to the on-site server’s LAN IP.

## 8.4 Fallback connection model

If Meridian does not control DNS/network hardware, browser/PWA access is not guaranteed.

The installed Capacitor app should support:

- Local discovery.
- mDNS/IP discovery where available.
- Node fingerprint trust.
- Certificate pinning or app-level trust where needed.

This fallback is the reliable path for on-site/offline operation without DNS control.

## 8.5 Local discovery

Local nodes should be discoverable.

On-site nodes should support `.local` mDNS names, but `.local` names are not sufficient for browser-trusted HTTPS unless the client trusts the certificate. Therefore `.local` discovery is primarily useful for the installed app and admin/debug flows.

## 8.6 HTTPS validation

Setup must fail closed if HTTPS validation fails in event mode.

PowerSync unavailability should also fail closed in event mode.

Client event mode must fail closed if local encryption or device signing is unavailable.

---

# 9. PowerSync and Device Sync

## 9.1 Chosen sync layer

PowerSync is the chosen device sync layer for Alpha 1.

PowerSync is an external dependency. Meridian does not need to ship PowerSync source code.

## 9.2 Sync responsibility

PowerSync handles:

```text
Meridian server ↔ user devices
```

Meridian node sync handles:

```text
central node ↔ on-site node
```

## 9.3 Client data model

The device should cache as much authorized data as possible.

Offline data may be stale, but stale authorized data is better than no data.

Regular staff should cache:

- Their own shifts.
- Their department/team info.
- Field report form.
- Basic event info.
- Their own submitted field reports.
- Published policies/procedures visible to them.
- Published fragments referenced by those visible documents.
- Their policy/procedure acknowledgment status.
- Relevant readiness/sync state.

Permitted users/devices should additionally cache the event map package by default:

- Published placement maps and published topographic map packages they may view.
- Permitted camp/location records.
- Kiosk devices receive the event map offline by default when published and permitted.

Sensitive map layers/features must not sync to users/devices without permission. UI hiding is not sufficient.

Shift leads should additionally cache:

- Assigned staff for teams/shifts they lead.
- Check-in/check-out/no-show state for those teams/shifts.
- Team roster.

Department leads should additionally cache:

- Department roster.
- Department schedule.
- Department attendance data.
- Draft, published, and archived policy/procedure documents and fragments they are allowed to maintain.

IC roles may cache:

- Last viewed limited incident data.
- Related field reports where permitted.
- Derived Name Reference tokens from cached Incident notes and related Field Reports, where permitted.

Incidents should not be greedily synced.

Any cached Name Reference tokens are derived from authorized source text and must be rebuildable from that text. PowerSync must not become the business-rule engine for Name Reference visibility or search authorization.

## 9.4 Offline write scope

Alpha 1 offline writes include:

- Field report creation.
- Field report photo attachment sync.
- Check-in.
- Check-out.
- Mark no-show.

Policy/procedure acknowledgments are not creatable offline in Alpha 1. Acknowledgments require server connection and are accepted through Laravel before they appear in synced state.

Event application submission, including optional department interest, is online-only in Alpha 1. It has no special offline or sync behavior beyond normal application submission records.

Incidents require server connection for creation.

Map editing (maps, camps, map locations, assets, publishing/archiving, and locked-data overrides) is not an offline write for MVP. Map packages and permitted camp/location data sync down to authorized devices read-only, and locked operations-window map data remains stable offline.

Field reports are finalized when submitted. There are no field report drafts.

Failed sync actions remain recoverable.

Normal users should see sync status unobtrusively. Advanced sync details are hidden behind advanced/debug/God mode.

---

# 10. Node-to-Node Sync

## 10.1 Sync model

Central and on-site sync is operation-based, not table-replication-based.

Node operations are append-only.

Receiver stores remote operations before applying them.

Operations are idempotent; receiving the same operation multiple times is safe.

Node sync is bidirectional, not push-only.

For Alpha 1:

- Exactly one central node.
- Exactly one active on-site node per event.
- Data model should allow multiple on-site nodes later.
- Later, one on-site node may act as “acting central” for a local on-site cluster.

## 10.2 Event authority

Before the event starts:

- Central prepares event data.
- Central prepares organization, department, and team governance content, including policies, procedures, fragments, and acknowledgment requirements.
- Central pushes event config/data to on-site.
- On-site may receive updates until the event starts.

During the active event window:

- The on-site primary node is authoritative for event-scoped records.
- Central is read-only for that event, except for data arriving from the on-site primary node.
- Edits not from the on-site primary node are refused for event-scoped records.
- Permission changes happen only on the on-site primary node.
- Policy/procedure document edits and fragment edits are blocked during the active event window.
- Fragment changes during an active event are disallowed to avoid silent document version bumps mid-event.
- Acknowledgments collected on-site during signup or training sync back to central.
- On-site pushes changes back to central continuously when internet exists.
- If internet disappears, on-site queues node operations and pushes later.

After the event closes:

- On-site should no longer edit event records.
- Post-event corrections happen on central.
- Post-event corrections may sync both ways as needed.

## 10.3 Conflict policy

Conflicts go to a sync conflict queue.

Conflicts are:

- Visible only in God mode.
- Grouped by entity type.
- Display both local and remote values.
- Resolved by choosing either “accept on-site” or “accept central.”
- Not manually corrected inside the conflict resolver.
- Audited when resolved.

Defaults:

- Active event window + event-scoped records: accept on-site.
- Central/global records: accept central.

Unresolved conflicts should not block unrelated sync.

Severe data conflicts should trigger an Electron health warning.

Incidents and attached field reports may require ordered sync.

## 10.4 Node operation schema

Alpha 1 node operations use normalized operation fields and may also include payload JSON.

Each operation should include:

```text
uuid
origin_node_id
target_node_id, optional
actor_user_id
actor_device_id, optional
operation_type
entity_type
entity_id
event_id, optional
created_at
sent_at
received_at
applied_at
status
signature
hash
payload_json, optional
failure_reason, optional
retry_count
```

Operation signatures cover normalized operation fields.

Operations are signed with the node private key.

Device-originated operations are signed by the originating device and then countersigned by the accepting node.

---

# 11. Authentication

## 11.1 Providers

Alpha 1 auth supports:

- Email magic link.
- Google OAuth.
- Discord OAuth.

There is no password login.

God mode may also use the same providers.

Every login resolves to a global user account by verified email.

Magic-link verification may create a user account only when the system setting for magic-link account creation is enabled. This setting defaults to enabled for Alpha 1 testing and development. When the setting is disabled, a magic link for an unknown email fails without creating a user account.

Google login requires verified email.

Discord login requires verified email.

If Discord and Google return the same verified email, they attach to the same Meridian user.

If an auth provider does not return a verified email, login fails.

OAuth provider linking happens only when connected to central/internet.

Alpha 1 users may have one primary email address and one optional secondary email address. The secondary email is stored directly on the user record, must be globally unique across primary and secondary user emails, and must be verified by the same signed magic-link mechanism before it is usable for login matching.

Users may add or remove their own secondary email address. God mode may change a user's primary email address and is trusted to mark the new primary email as verified.

Google and Discord may authenticate against either the verified primary email or verified secondary email. A social provider may attach through a secondary email only when the already-authenticated user explicitly starts provider linking and that secondary email has already been verified.

Adding, verifying, or removing a secondary email address and changing a primary email address are audit-sensitive account events.

Organization membership determines what the user can see after login.

## 11.2 Offline authentication assumptions

Users are expected to authenticate before the event while internet is available.

Trusted sessions work offline for up to 6 weeks.

Normal no-internet login is unsupported for regular personal devices in Alpha 1.

The on-site node should cache enough verified identity/provider data from central before the event to validate returning users.

Auth/trust should be shared from central to on-site nodes so users remain logged in across central/on-site operation where appropriate.

## 11.3 Magic links

On-site magic links do not require email sending in Alpha 1.

God mode may generate/display short login URLs or codes.

These are for assisted recovery or shared workstation login, not normal personal device trust.

---

# 12. Devices, Trust, and Encryption

## 12.1 Device identity

Every app install/browser profile generates a durable `device_id`.

Device trust is per user/device pair.

One physical device can support multiple trusted users.

Switching users does not automatically wipe local event data.

Logout does not require network access.

“Wipe local data” works immediately from advanced settings.

God mode can eventually revoke trusted devices.

Device trust records should include:

```text
user_id
device_id
device_label
platform
first_trusted_at
last_seen_at
expires_at
trusted_node_fingerprint
device_public_key
```

## 12.2 Trust duration

Device sessions are trusted for 6 weeks.

Readiness does not expire automatically, but trust itself has a 6-week validity window.

## 12.3 Local encryption

Local data is encrypted.

Local encryption unlocks automatically for a valid trusted user/device session.

There is no separate Meridian PIN.

Biometric/PIN unlock is not required and should not be added.

The local encryption key is generated after successful login/trust.

Wiping local data destroys the local encryption key.

Encrypted data includes:

- Field reports.
- Field report photos.
- Incident cache.
- Shifts.
- Rosters.
- Event cache.
- Session/user data.
- Sync queue where practical.

Offline/event mode fails closed if encryption is unavailable.

Encryption status is part of readiness.

## 12.4 Device signatures

Each trusted device generates its own signing keypair.

Device public key is registered with the server during device trust setup.

Device-originated offline operations are signed by the device before sync.

The server verifies the device signature before accepting the operation.

The accepting node countersigns the operation with the node key.

Both signatures are retained in the audit trail.

Shared workstation sessions use the workstation device key plus active user session.

Device private keys live in OS secure storage where supported.

Browser/PWA mode supports device signatures only if secure key storage is available.

Event mode fails closed if device signing is unavailable.

---

# 13. Shared Workstations

## 13.1 Definition

A shared workstation is a special kind of trusted device.

Shared workstations are managed in God mode.

Example names:

```text
onsite-command-1
ic-desk-1
radio-desk-1
```

The on-site Electron machine is the first trusted shared workstation for Alpha 1.

## 13.2 Shared workstation login

Electron supports shared workstation login mode.

Known users can enter short login codes generated by God mode.

Login codes are:

- Scoped to one user.
- Scoped to one event.
- Usable only on trusted shared workstations.
- Unable to create trusted personal device sessions.
- Revocable by God mode.
- Human-typable.
- Rate-limited.
- Valid for 6 weeks.
- Audited when generated.
- Minimally audited when used.
- Not printable/exportable as event prep sheets.

Raw login codes should not be logged.

Failed login-code attempts are audited after a threshold.

## 13.3 Shared workstation session behavior

Shared workstation sessions last 12 hours or until the user explicitly ends the session.

Users must explicitly end their session before switching users.

The active user is shown prominently at all times.

Permissions come entirely from the active user.

Shared workstation local data remains encrypted at rest.

When a session ends, active session data is wiped.

If the Electron app restarts, the shared workstation session locks immediately.

For MVP, shared workstation login is allowed only on the on-site server machine or on explicitly designated trusted shared workstations.

---

# 14. Readiness

Readiness is tracked per user/device/event.

Readiness is advisory only.

Readiness is visible to the user.

Readiness is not visible to organizers.

Readiness does not expire automatically.

The app should avoid nagging users.

Readiness includes:

```text
logged in
device trusted
event selected
local cache complete
encryption active
last sync completed
trusted server known
device signing available
```

Users are encouraged, but not required, to prepare their devices before the event.

---

# 15. Permissions

## 15.1 Permission model

Meridian permissions are primarily based on team membership, with roles granted inside organization/event/department/team contexts.

Users may hold multiple roles at once.

Role scopes include:

```text
organization role
event role
department role
team role
shift role
```

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

## 15.2 Role scoping

- `god_mode` is global to a node.
- `organizer` is organization-scoped through the organization's configured Organizers Department.
- `lead_organizer` is organization-scoped through the configured Organizers Department and may be granted to any subset of that department, including every department member.
- `department_lead` is department-scoped.
- `shift_lead` is team-scoped, not shift-scoped.
- `ic_lead`, `ic_operator`, and `ic_viewer` are event/team scoped through the IC permission model.

Every permission decision should be explainable in the UI.

Example:

```text
You can mark no-show because you are a shift lead for this team.
```

Denied actions should show a reason when possible.

Permission changes are audit logged.

Permission changes sync between central and on-site nodes.

During the active event window, permission changes happen only on the on-site primary node.

## 15.3 Event map and Placement department permissions

Event map capabilities are expressed as permission capabilities and granted primarily through event organizers/admins and the event's designated Placement department. They follow the same general designation pattern as the Incident Command Department: a normal department is designated per event, and authority is event-scoped.

Suggested permission capabilities (module.action style):

```text
maps.view                 view published event maps
maps.view_camp_data       view camp/location map data
maps.view_sensitive       view sensitive map layers/locations
maps.manage_draft         create/edit draft maps before the operations window
maps.publish              publish/archive maps
camps.manage              manage camps/locations before the operations window
map_assets.manage         manage map assets/packages before the operations window
maps.override_locked      override locked map data after the operations window begins
```

Authority sources for map capabilities:

- Organizers/admins (organizer, lead_organizer, god_mode) may view, manage, publish/archive, and perform locked-map overrides per documented rules.
- The designated Placement department's lead(s) (department_lead scoped to the Placement department) may manage draft maps, manage camps/locations, manage map assets/packages, and publish/archive maps for that event before the operations window begins.
- Placement department members who are not leads receive `maps.view`/`maps.view_camp_data` per map permissions by default, and receive edit capabilities only when granted a map-management grant within the Placement department, using the same event/team grant mechanism as the IC permission model.
- If no Placement department is designated, map editing falls back to organizers/admins/map managers.

Scoping rules:

- The Placement designation applies only for the event where the department is designated and never makes a department globally special.
- The Placement designation does not grant IC or organizer authority, and IC/organizer authority does not grant map-management authority by itself.
- A department may be designated as both Placement and another special department for the same event, but each designation grants only its own authority.
- `maps.override_locked` is reserved for organizers/admins for MVP and is not part of the Placement department lead role.

Map capability decisions should be explainable in the UI, for example:

```text
You can edit this map because you lead the event's Placement department and the operations window has not started.
```

Sensitive map reads (for example incident, DNS, restricted-area, medical, staff-only, or security-sensitive locations) should follow existing sensitive-read audit principles where appropriate. Sensitive map layers/features must not sync to users/devices without permission; UI hiding is not sufficient.

The exact effective-permission-level role codes for the Placement department (whether to mint placement-specific role codes mirroring `ic_lead`/`ic_operator`/`ic_viewer`, or to reuse `department_lead` plus map-management grants) are left to the implementing milestone; see Open Questions.

---

# 16. Incident Command Permission Model

## 16.1 IC capability model

Incident Command is not a fixed department.

For each event, a department may be selected to function as Incident Command.

This is separate from the organization's persistent Organizers Department. The same department may be selected for both purposes, but organizer authority alone does not grant IC access.

Examples:

- Organizers department.
- Producers department.
- Ranger department.
- Other event-specific department.

Within the selected IC department, individual teams may be granted IC roles.

IC access is therefore:

```text
event
+ selected IC department
+ team membership
+ team-granted IC role
```

Normal teams inside the IC department do not automatically receive IC visibility.

## 16.2 IC roles

Alpha 1 IC roles:

```text
ic_lead
ic_operator
ic_viewer
```

### ic_lead

Can:

- View incidents.
- View all field reports for the event.
- Download field report photos.
- Close incidents.
- Reopen incidents.
- Add incident notes.
- Link/unlink field reports from incidents.

### ic_operator

Can:

- View incidents.
- View all field reports for the event.
- Close incidents.
- Reopen incidents.
- Add incident notes.
- Link/unlink field reports from incidents.

Cannot:

- Download field report photos.

### ic_viewer

Can:

- View incidents.
- View all field reports for the event.

Cannot:

- Modify incidents.
- Add notes.
- Close/reopen incidents.
- Download field report photos.

---

# 17. Field Reports

## 17.1 Purpose

Field reports are low-friction, offline-capable reports created by users in the field.

Field reports are not incidents.

Incidents require server connection and are managed by IC roles.

Field reports can later be attached to incidents by IC roles.

## 17.2 Offline behavior

Field reports can be created offline.

There are no draft field reports.

Once submitted, a field report is finalized.

Offline-created field reports look submitted immediately to the user, even if still pending sync.

Failed field-report sync remains recoverable/exportable in advanced mode.

## 17.3 Alpha 1 schema

Alpha 1 field reports include:

```text
event
department/team context if available
body text
picture attachments
submitted_by
device_submitted_at
server_received_at
origin_device_id
origin_node_id
sync_status
```

Field report body is a single text area.

There are no field report categories/types in Alpha 1.

GPS collection is excluded from Alpha 1.

## 17.4 Immutability and appends

The original field report body never changes.

Field reports are immutable but can have append-only additions.

Only the original submitter can append to their own field report.

Elevated users can append only to their own field reports, not to other users’ field reports.

Each append includes:

```text
timestamp
author
body text
optional photos
source device
source node
```

Appends are immutable.

Appends are visible as a timeline under the original field report.

Appends share the same FRA number with timestamped entries.

## 17.5 Field report numbering

FRA numbers are event-specific.

The first server that receives the field report assigns the FRA number.

Offline-created reports show a temporary local number until synced.

The local temporary number is clearly replaced by the FRA number after sync.

Example:

```text
FRA-2027-000123
```

## 17.6 Field report visibility

Default visibility:

- Users see only their own field reports.
- Department leads see only their own field reports.
- Organizers see only their own field reports.
- Shift leads do not automatically see field reports from their shifts.
- IC roles can see all field reports for the event.
- God mode can see all field reports on the node.

Certain roles under the selected IC department may view all field reports if granted `ic_lead`, `ic_operator`, or `ic_viewer`.

Field reports attached to incidents become visible within that incident only to users who can view the incident.

The original field report submitter is not shown that their field report has been attached to an incident.

## 17.7 Name References in Field Reports

Field Report body text may contain Name References.

A Name Reference starts with `@` and continues through letters, numbers, hyphens, and underscores until whitespace or punctuation.

Examples:

```text
@bucket.        -> bucket
@blue-hat       -> blue-hat
@blue_hat       -> blue_hat
@ranger bucket  -> ranger
```

Field Reports are parsed for Name References immediately after submission. The original body remains the source of truth.

Name Reference extraction may update a rebuildable derived index for search, rendering/highlighting, incident summary chips, or permitted local/offline use.

The derived index is not a person, alias, identity, entity, suspect, or volunteer profile model.

Field Report authors may see highlighted Name References in their own submitted report text if the renderer supports it. Field Report entry must not provide autocomplete, context menus, suggestions of existing references, cross-record search expansion, linked incident visibility, or any additional permission because the author typed a Name Reference.

---

# 18. Field Report Photos and Attachments

## 18.1 Capture and source

Field report photos may come from:

- Device camera.
- Device files/gallery.

## 18.2 Local handling

Photos are stored locally encrypted until synced.

Photo sync happens separately from the field report text record, but photos remain attached to the field report.

A field report is considered locally submitted even if photos are still pending upload.

The server may receive the field report record before its photos.

## 18.3 Limits and processing

Alpha 1 limits:

```text
max photos per field report: 2 total
max compressed image dimensions: 2560 × 1900
max compressed file size: 5 MB
GIFs: unsupported
multi-image phone photos: converted to a single image
original full-resolution photos: not kept
```

All EXIF is stripped.

GPS EXIF is stripped.

Any image format a phone produces should be accepted where practical, then converted to Meridian’s supported stored format.

## 18.4 Immutability

Photos are immutable once attached.

Append-only additions may add photos later, but the total maximum remains 2 photos per field report.

Photo deletion/redaction is excluded from Alpha 1, including God-mode deletion/redaction.

## 18.5 Server storage

Alpha 1 stores uploaded photos on the server filesystem mounted as a Docker volume.

S3/MinIO-compatible storage should be supported from the start or soon after, but filesystem volume storage is acceptable for Alpha 1.

On-site and central nodes store their own file blobs.

Photos do not sync down to user devices.

Server UI previews images as server files.

Filenames should be plain text and include event and upload timestamp.

Example shape:

```text
EVENT-2027_FRA-2027-000123_2027-07-04T13-22-10Z_01.webp
```

No content-addressed filenames are required for Alpha 1.

Attachment records should include checksums.

File sync verifies checksums.

Failed photo sync is recoverable in advanced sync details.

## 18.6 Node sync and attachment visibility

Node-to-node sync includes field report photos in Alpha 1.

Metadata and blobs sync separately.

A field report is considered synced to central once the text metadata arrives.

Central may show:

```text
metadata received
attachments pending
```

On-site retries missing photo blobs with rate limits and eventual failure.

File sync failures are not shown in Electron health.

Central may display field reports before photos arrive.

Attachment downloads are available only to `ic_lead`.

Other permitted users may view according to visibility rules, but only `ic_lead` may download.

Image URLs are short-lived signed URLs, not public file paths.

---

# 18A. Staff Profile Pictures

## 18A.1 Ownership and eligibility

Staff profile pictures are staff-owned profile media.

Only the staff member may upload, replace, or remove the current picture on their own staff profile.

The upload control is available only after the staff member has `active` organization-level staff status in at least one organization.

Profile picture visibility follows staff profile visibility. A user who cannot view a staff profile cannot view that staff profile picture.

## 18A.2 Limits and processing

Alpha 1 staff profile picture limits:

```text
max current profile pictures per staff profile: 1
supported upload formats: JPEG, PNG, WebP
max original upload size: 10 MB
max stored image dimensions: 1024 x 1024
previous profile pictures after replace/remove: not preserved
```

Server-side processing resizes larger images down to fit within 1024 x 1024 while preserving aspect ratio.

EXIF metadata is stripped before storage.

## 18A.3 Storage and sync

Alpha 1 stores staff profile pictures using the same approved server file storage approach as other uploaded image blobs.

Profile picture metadata syncs separately from the image blob.

Staff profile picture blobs sync lazily as they are accessed rather than proactively syncing every profile picture to every node or device.

When metadata is present but the blob has not synced to the current node or device, the UI should show a placeholder or pending image state and retry blob fetch according to the file sync path.

Profile picture upload, replace, and remove actions are online-required for Alpha 1.

---

# 19. Incidents

## 19.1 Purpose

Incidents are server-connected operational records managed by Incident Command roles.

Incidents are not created from field reports automatically.

Field reports may be linked to incidents later by IC roles.

## 19.2 Offline behavior

Incident creation requires active server connection.

Incident creation is available only to elevated IC roles.

Regular staff see no incident UI.

Incidents should not be greedily synced to devices.

Elevated users may read limited cached incidents offline.

Alpha 1 incident cache rule:

```text
last 5 viewed incidents
```

Cached incidents flush on logout.

Cached incidents flush after 6 weeks.

Cached incidents are excluded from normal emergency exports.

Related field reports for cached incidents may be cached where permitted.

Derived Name Reference tokens for cached incident notes and related Field Reports may be cached where permitted, but they remain rebuildable display/search artifacts and must not broaden offline visibility.

## 19.3 Incident visibility

Incidents are visible only to IC roles.

Not visible to:

- Regular staff.
- Shift leads outside IC.
- Department leads outside IC.
- Organizers unless their department/team is functioning as IC and they hold an IC role.

Only teams granted IC roles get IC visibility.

## 19.4 Incident creation

Standalone incidents are allowed.

Incidents are not created from field reports.

Incident numbers are system-generated, event-specific, and chronological.

Incident numbers are assigned by the on-site primary node during the event.

Example:

```text
INC-2027-000042
```

## 19.5 Incident fields

Alpha 1 incident fields are fixed.

There is no separate summary field.

Incident title is user-generated and editable.

Incident body/history is append-only and accepts inline notes.

Incident history/update body is a single text area per update.

An incident may optionally reference a known camp or map location. The reference is optional, is never required to create an incident, and does not replace the existing free-text location/summary behavior. When an incident references a camp, the IMS view may display useful camp location details to permitted IC roles. Incident map/location visibility follows existing IMS permissions, and map references do not introduce arbitrary dropped pins.

## 19.6 Incident statuses

Internal statuses:

```text
open
on_scene
monitoring
on_hold
closed
```

Default status is `open`.

`on_scene` means someone has accepted/responded to the incident.

Status labels may become configurable later, but internal status names remain fixed.

## 19.7 Incident edits and timeline

Incident notes/history entries are append-only.

Incident body/history is never edited directly.

Incident title edits are allowed and create timeline entries.

Example:

```text
title changed from "..." to "..."
```

Status changes create timeline entries.

Status changes may include an optional note.

Closing an incident requires a note stored as:

```text
reason: ...
```

Reopening a closed incident is allowed. Reopening does not require a reason, but the UI should present the option to provide one. If provided, it is stored as:

```text
reason: ...
```

Closed incidents can be reopened.

## 19.8 Field report links

Incident field-report links are append-only.

IC leads and IC operators can link and unlink field reports from incidents.

Link/unlink activity appears on the incident timeline only.

Link/unlink activity does not appear to the original field report submitter.

## 19.9 Name References on incidents

Incident notes may contain Name References.

Incident-level Name Reference chips include references extracted from:

- the incident's own notes;
- Field Reports attached to the incident.

Name Reference chips should appear near existing incident tags or metadata areas where appropriate and should be visually distinct from `#tags`.

Clicking a Name Reference runs a normal permission-filtered search for the reference text without the `@` prefix. It does not open a dedicated Name Reference profile/detail page and does not link to a volunteer profile.

## 19.10 IMS Name Reference search

Normal IMS search should find permitted records that include a searched name/reference string with or without the `@` operator when supported by the search implementation.

Search is case-insensitive.

Searching or clicking a Name Reference must not grant access to records the user could not otherwise view.

Meridian must not create a separate global Name Reference search surface for Alpha 1. Command palette search may include permitted IMS records for IC roles where it already supports IMS records, but it must not create a Name Reference profile/detail system.

---

# 20. Attendance

## 20.1 Offline behavior

Attendance operations can be created offline.

Attendance operations are append-only.

Attendance has a derived current state.

Potential derived states:

```text
scheduled
checked_in
checked_out
no_show
excused
corrected
```

## 20.2 Supported Alpha 1 operations

Alpha 1 supports:

- Check-in.
- Check-out.
- Mark no-show.

Staff self check-in/out is excluded from Alpha 1.

Shift leads can check staff in/out.

Shift leads can mark no-show.

Department leads may have broader attendance access for their department.

## 20.3 Shift selection

Check-in/check-out does not always require selecting a shift.

The UI should provide the option to select a shift.

If there is only one obvious/current shift, the UI should default to that shift.

Check-out can happen after a shift and may not need to be attached to a shift.

## 20.4 Shift lead workflows

Shift leads see rosters for teams/shifts they lead with action buttons.

Shift leads can check in a staff member who is not assigned to the shift.

This does not create a separate exception record in Alpha 1.

No-show only applies after a shift has started.

Check-out normally requires prior check-in.

A shift lead or department lead can create a check-in and check-out at the same time if necessary.

Offline check-out without known server-side check-in is accepted and reconciled later.

Overlapping check-ins are allowed but should warn.

Duplicate check-in is idempotent.

Attendance operations do not include optional notes in Alpha 1.

## 20.5 Attendance visibility

Staff see their own attendance state.

Shift leads see attendance for teams/shifts they lead.

Department leads see attendance for their department.

Organizers do not automatically see all attendance.

God mode can directly edit attendance records.

Direct attendance edits do not require a reason/comment.

Direct attendance edits create before/after audit entries.

The UI shows corrected attendance as normal, with history available only to elevated users.

Duplicate/overlapping attendance warnings are visible to shift leads immediately.

Attendance conflicts from offline devices go to the sync conflict queue.

---


# 21. Policies, Procedures, and Fragments

## 21.1 Purpose

Meridian supports governance documents used for human-readable policies and procedures.

Policy and procedure documents are authored as Markdown and may include references to reusable text fragments.

Examples include:

- Organization behavioral agreements.
- Department participation policies.
- Team mission statements.
- Shift lead procedures.
- Radio checkout procedures.
- Incident escalation procedures.

Policies and procedures are separate product and domain types because they may diverge after MVP.

## 21.2 Laravel modules

Policies and Procedures are implemented as separate Laravel domain modules.

Alpha 1 modules include:

```text
PolicyDocuments
ProcedureDocuments
DocumentFragments
DocumentAcknowledgments
DocumentExports
```

Policy documents and procedure documents should use separate domain modules and separate persistence models/tables rather than one shared document table.

Fragments are shared by both policy and procedure documents.

Acknowledgments use a shared acknowledgment table/model that can reference either a policy document or a procedure document.

## 21.3 Document states and scope

Policy and procedure document states are:

```text
draft
published
archived
```

There is no separate `active` state.

Published documents are visible according to scope.

Draft and archived documents are synced only to users who are allowed to edit or maintain them.

Document scopes are:

```text
organization
department
team
```

Organization-scoped documents are maintained by organizers.

Department-scoped documents are maintained by department leads.

Team-scoped documents are maintained by team leads.

Organizers cannot edit department documents merely by being organizers.

Department leads and team leads can see policies/procedures within their department according to their leadership scope.

Organizers can see all published policy/procedure documents across the organization, including department- and team-scoped documents, but this visibility does not grant edit or publish authority for those scopes.

Draft and archived department/team documents sync only to maintainers for the relevant scope unless another explicit permission grants access.

Policy/procedure documents are not generally public-facing before login except as part of staff signup.

## 21.4 Document versioning

Policy and procedure documents use a two-part version number:

```text
document_revision.fragment_revision
```

The first number represents document changes.

The second number represents fragment-driven changes.

The first published version is:

```text
1.00
```

If a referenced fragment changes, the document version is bumped automatically:

```text
1.01
```

Document changes increment the document revision. The fragment revision counter is scoped to the current document revision and should reset to `00` when the document revision increments.

Documents store the two version components separately as integers.

Document and fragment versions are monotonically increasing.

When a fragment changes, every published document that references it receives an automatic fragment-revision bump.

Fragment-driven document version bumps should be performed by background jobs using Laravel’s available queue/job infrastructure.

Background jobs may be introduced for this feature even if most of Alpha 1 avoids heavy background processing.

If a document version has associated acknowledgments, Meridian must preserve enough rendered/source state to prove what document version was acknowledged.

Rendered Markdown snapshots are required only for document versions that have associated acknowledgments.

## 21.5 Fragment model

A fragment is a reusable named Markdown text object.

Fragments are scoped to:

```text
organization
department
team
```

Fragments do not have draft/published/archived lifecycle states in Alpha 1.

Fragments have an auto-incrementing version that increments when the fragment changes.

Fragments are Markdown only.

Fragments may not contain references to other fragments.

Nested fragments are prohibited.

Fragment references always resolve to the latest fragment text.

Documents cannot pin old fragment versions for display.

When a published fragment changes, published documents that reference it update automatically through the document fragment-revision bump process.

Documents do not require review or republication before updated fragment text appears.

## 21.6 Fragment reference syntax

Documents store fragment references using a custom Markdown token.

Example:

```md
All staff agree to the following organization expectations:

{{fragment:org-behavioral-agreement}}
```

Fragment references should use UUIDs internally.

Editors should display human-friendly fragment names.

When editing, the UI shows fragment references and the currently referenced fragment version.

When viewing, the UI renders referenced fragment text inline as document text.

Fragment references are validated before publish so broken references cannot be published.

## 21.7 Markdown and rendering

Policy documents, procedure documents, and fragments are authored as Markdown.

Laravel APIs should send Markdown/source content as plain text. Rendering happens where the document is rendered.

The Vue/Capacitor app may render synced Markdown locally through PowerSync-backed data for offline reliability.

Laravel may render Markdown server-side for previews, PDF export, Markdown export with resolved fragments, and other server-generated artifacts.

The supported Markdown subset is intentionally boring:

- headings
- paragraphs
- lists
- links
- emphasis
- blockquotes
- code blocks

Raw HTML is disallowed.

Markdown must be sanitized before rendering.

Fragment Markdown must be sanitized independently before inclusion.

## 21.8 PowerSync behavior

Policies/procedures and fragments should use PowerSync for offline reliability.

Published documents visible to the active user are synced to the device.

Published fragments referenced by synced documents are synced to the device.

Draft and archived documents/fragments are excluded from normal device sync unless the user is a lead or maintainer allowed to edit that content.

The mobile app includes a Policies & Procedures area.

Visible documents are searchable by title only.

Full-text search within document or fragment bodies is not included.

The app shows document scope and version subtly, such as near the bottom of the document view.

The app does not need to show a special notice that the document includes automatically updated fragments.

Staff can see acknowledgment status for required documents.

## 21.9 Acknowledgments

Policy/procedure acknowledgments occur only during staff signup or training for Alpha 1.

Acknowledgments are scoped to organization and department for Alpha 1.

Acknowledgments are not modeled as direct shift signup gates.

Acknowledgments are not modeled as direct credential eligibility gates.

Acknowledgments are not creatable offline in Alpha 1.

Acknowledgment creation requires server connection and is accepted through Laravel.

Acknowledgment records store:

```text
user_id
document_type
document_id
document_version
scope_type
scope_id
acknowledged_at
accepted_by_node_id
```

Acknowledgment records store only the document ID and document version, not the entire rendered text, unless the associated document version requires a preserved snapshot due to acknowledgment history.

Acknowledgments are audit events as well as acknowledgment records.

## 21.10 Node sync behavior

Policies, procedures, fragments, versions, and acknowledgments sync between central and on-site nodes.

Before event start, central is authoritative for organization, department, and team governance content.

During the active event window, policy/procedure edits and fragment edits are blocked.

Acknowledgments collected on-site during signup or training sync back to central.

Fragment changes during active event mode are disallowed.

## 21.11 Orchid admin behavior

Orchid includes CRUD screens for:

```text
Policy documents
Procedure documents
Document fragments
Document acknowledgments
```

Orchid does not require separate screens for document versions or fragment versions in Alpha 1.

Orchid supports rendered preview with fragments inline.

Fragment screens show which documents reference a fragment before editing.

When a fragment edit affects published documents, Orchid warns:

```text
Editing this fragment will bump versions for N published documents.
```

Dangerous document actions require reason/comment.

## 21.12 Exports

Markdown export returns document Markdown with fragment references resolved inline.

PDF export renders document content with fragment text inline.

Exports include:

- document type
- document title
- document version
- scope
- export timestamp

Exports are generated server-side by Laravel.

Export/print events are audited.

Policy/procedure packet assembly is post-Alpha 1.

When implemented later, packet assembly should be stored as an ordered list of document IDs.

## 21.13 Search

Alpha 1 supports title search only.

Meridian does not need full-text search within policy/procedure documents or fragments.

PostgreSQL full-text search is not required for this feature in Alpha 1.

## 21.14 Alpha 1 vertical slice

Alpha 1 includes the Policies and Procedures feature.

The minimum vertical slice is:

1. Create a fragment.
2. Create a policy document.
3. Create a procedure document.
4. Reference a fragment from a policy/procedure document.
5. Publish a document.
6. View a rendered document with fragment text inline.
7. Acknowledge a required document during signup or training.
8. Export a document as Markdown with fragments resolved inline.
9. Export a document as PDF with fragments rendered inline.
10. Sync published visible documents and referenced fragments to the on-site node.
11. Sync visible published documents and fragments to devices through PowerSync.
12. Sync acknowledgments from on-site to central.

Policy/procedure packets are post-Alpha 1.

Full-text search is not included.

---

# 21A. Event Geography and Maps

## 21A.1 Purpose

Event Geography & Maps is an event-scoped operational feature for showing what is where for an event and for referencing operational locations from other workflows. It is enabled by default for events.

It supports both real-world/topographic maps and 2D top-down placement maps, and is intentionally simple for MVP. It is primarily an operational feature for department leads, the Placement department, organizers/map managers, kiosk operators, and IC roles where relevant. It is not a public volunteer navigation feature for MVP.

This feature must not become a full GIS, CAD, dispatch, public navigation, or live-tracking system. GPS collection from staff devices remains excluded (see section 28); optional geospatial coordinates here come from prepared assets or manual placement, not from tracking staff.

## 21A.2 Entities and modules

Map data lives in a Laravel module that owns event maps, map assets/packages, optional map layers, camps, and non-camp map locations. Canonical schema lives in PostgreSQL; PowerSync projects permitted data to devices but is not the business-rule engine.

Core concepts:

- Event map: event-scoped, typed `placement` or `topographic`, with a whole-map lifecycle of `draft`, `published`, `archived`.
- Map asset/package: an uploaded/imported image/SVG/PDF-derived placement asset or a prepared topo package referenced by a map.
- Map layer (optional): groups features for toggling and for sensitive-layer permission control.
- Camp: event-scoped entity with `name` and `location` only for MVP.
- Map location/feature: lightweight non-camp operational location with a type such as `department_hq`, `gate`, `road`, `landmark`, `deployment_location`, `service_location`, `restricted_area`, `parking`, or `other`.

## 21A.3 Map types and coordinate systems

- `placement` maps use a local coordinate plane suitable for top-down site plans. Placement-map camps/locations are not required to have GPS coordinates.
- `topographic` maps use real-world geospatial coordinates or prepared map packages/assets, treated as offline-capable map packages rather than assumed-online basemaps.

Geometry should be capable of representing points, lines, and polygons over time, using GeoJSON-compatible concepts where appropriate while allowing local/non-geographic placement coordinates. Placement and topographic maps should be designed to be linkable/georeferenced over time, but georeferencing is not required for MVP, and a separate `hybrid` type is not introduced.

## 21A.4 MVP creation approach

For MVP, a map is created from an uploaded/imported map asset or prepared map package, with lightweight map metadata, and camp/location records placed on top. There is no full GIS editor, no complex drawing suite, no automatic geocoding, and no public map builder. Map assets follow existing Meridian file-storage and offline principles.

## 21A.5 Lifecycle, publishing, and operations-window locking

Only the whole map has `draft`/`published`/`archived` state; individual camps/locations do not have separate lifecycle states. Only `published` maps are visible to permitted operational users; `draft`/`archived` maps are limited to users with map edit/admin permissions.

Before the event operations window begins, authorized map editors may create/update maps, camps, and map locations. When the event enters its operations window (the existing event operations window concept), published map geometry, camp records, and map location records are locked against normal editing so incidents, deployments, kiosk views, and lead workflows do not change underneath active operations. After the operations window begins, corrections to locked data require an organizer/admin override with an explicit reason, or a post-event update path.

## 21A.6 Command-style writes

Business-rule map mutations use command-style writes:

```text
POST /api/commands/publish-event-map
POST /api/commands/archive-event-map
POST /api/commands/designate-placement-department
POST /api/commands/override-locked-map-data
```

Command handlers enforce authorization, operations-window locking, and the rule that the designated Placement department is assigned to the event. Map publishing, archiving, Placement department designation, sensitive map reads/exports, and locked-data overrides are audited (see section 23).

## 21A.7 Operational references

Operational records may optionally reference map locations without requiring them:

- Incidents may optionally reference a camp or map location (see section 19); references never replace incident free-text location/summary and never become required.
- Shift meeting/check-in locations, deployment locations, department HQ locations, and equipment/storage locations (where equipment locations are already modeled) may reference an operational map location.
- Field Reports do not gain structured map/location fields and remain a single text body only (see section 17).

Arbitrary dropped pins are not supported for MVP. Camps and map locations are not added to the global command palette; map surfaces may provide their own scoped search/filter for permitted users.

## 21A.8 Sync and offline

Published placement maps, published topographic map packages, and permitted camp/location records are eligible for offline sync to authorized users/devices by default. Kiosk devices receive the event map offline by default when published and permitted; lead/IC devices receive map data according to permissions; non-permitted users do not receive hidden/sensitive camp/location data. Sensitive layers/features must not sync to users without permission. Locked operations-window map data remains stable offline, and surfaces should show stale/offline map status where relevant.

---

# 22. Admin, Orchid, and God Mode

## 22.1 Orchid purpose

Orchid provides the trusted admin/god-mode data administration interface.

The user-facing operational workflows are separate from Orchid.

## 22.2 Alpha 1 Orchid screens

Alpha 1 Orchid should include screens for:

```text
Organizations
Events
Departments
Teams
Users
Memberships
Roles/permissions
Shifts
Attendance
Field reports
Field report attachments
Incidents
Policy documents
Procedure documents
Document fragments
Document acknowledgments
Event maps
Map assets/packages
Camps
Map locations
Placement department designation (on the Event screen)
Devices
Shared workstations
Node config
Node pairing
Node sync status
Audit log
Sync conflicts
```

List screens should have search/filtering in Alpha 1.

CSV import/export is included in Alpha 1.

Spreadsheet import/export should focus first on:

- Users.
- Teams.
- Shifts.
- Assignments.

## 22.3 God mode

God mode is node-global.

God mode users may directly edit:

- Users.
- Teams.
- Shifts.
- Incidents.
- Attendance.

Orchid cannot directly edit finalized field report original body.

Orchid does not provide field report append/redaction workflows in Alpha 1.

Orchid does not allow attachment redaction/deletion in Alpha 1.

Dangerous Orchid actions require reason/comment.

Policy/procedure and fragment screens must support rendered preview, title search/filtering, and clear scope display.

Fragment screens must show which documents reference a fragment before editing.

Fragment edit screens must warn when editing the fragment will bump versions for published referencing documents.

Alpha 1 does not require separate Orchid CRUD screens for document versions or fragment versions.

Policy/procedure packet assembly is post-Alpha 1.

All direct edits create audit entries where appropriate.

Attendance direct edits create before/after audit entries.

## 22.4 God mode and config

God mode can edit database config overrides.

God mode can see config source:

```text
file config
database override
runtime/default
```

God mode manages:

- Node configuration.
- Trusted shared workstations.
- Login codes.
- Sync conflicts.
- Device revocation eventually.
- Node pairing.
- Dangerous admin operations.

---

# 23. Audit Log

Every meaningful change should be attributable to a user and timestamped.

Audit entries should capture:

- Actor user.
- Actor device, if applicable.
- Actor node.
- Action.
- Entity type.
- Entity ID.
- Before/after values where relevant.
- Timestamp.
- Reason/comment where required.
- Source context.
- Signature metadata where relevant.

Audit applies to:

- Permission changes.
- Auth/device trust events.
- Shared workstation login code generation/use.
- Node pairing/config changes.
- Attendance direct edits.
- Dangerous Orchid actions.
- Sync conflict resolution.
- Incident status/title/link changes.
- Node operation acceptance/rejection.
- Failed sync thresholds.
- Policy/procedure publish, archive, and document version changes.
- Fragment edits and fragment version changes.
- Policy/procedure acknowledgments.
- Policy/procedure export and print events.
- Event map publishing and archiving.
- Placement department designation changes.
- Locked-map data overrides.
- Sensitive map reads/exports, where existing sensitive-read audit principles apply.

Automatic document version bumps caused by fragment changes do not need separate audit entries beyond the audited fragment edit and resulting document version metadata.

Field reports preserve immutable original body and append-only additions.

Name Reference extraction, clicking, and searching do not require Name Reference-specific audit events. Existing read/view/search audit behavior applies where the relevant source record or surface already requires it.

---

# 24. Forms and Configuration

## 24.1 Alpha 1 fixed forms

Field report form fields are fixed for Alpha 1.

Incident form fields are fixed for Alpha 1.

Field report body is a single text area.

Incident history/update body is a single text area.

Photo attachment limits are fixed globally for Alpha 1.

Policy/procedure document structure is fixed to Markdown text plus fragment reference tokens.

Configurable form structure is excluded from Alpha 1.

## 24.2 Later configuration

Organizations may later configure:

- Field report form text/help labels.
- Incident status labels.

Internal status names remain fixed even if labels become configurable.

---

# 25. Electron Wrapper

## 25.1 Purpose

Electron provides the on-site command-center shell.

It wraps the local Meridian web UI.

It does not own server process management in Alpha 1.

## 25.2 Distribution

Electron should be distributed as an installable app for the on-site laptop.

It should default to fullscreen/kiosk mode.

It should hide browser chrome and navigation.

It should auto-reopen/recover if the local UI crashes.

It does not need to prevent accidental close in Alpha 1.

## 25.3 Health panel

Electron should show:

```text
local node name
node role
event name
sync status
PowerSync status
connected devices
local discovery status
certificate/HTTPS status
server version
expected app version
```

Node sync failures appear in Electron health.

Severe data sync conflicts appear in Electron health.

File sync failures do not appear in Electron health.

Electron warns if the local server version does not match the expected app version.

---

# 26. Packaging, Releases, and Environments

## 26.1 Environments

Alpha 1 environments:

```text
development
standalone
central
onsite
```

## 26.2 Production/event safeguards

Production/event modes:

- Disable dev auth completely.
- Refuse to boot with default secrets.
- Generate APP_KEY, node keys, and service secrets if missing or default.
- Use sample configs with fake values only.
- Run database migrations automatically, with backup warnings first.
- Fail closed if HTTPS validation fails in event mode.
- Fail closed if PowerSync is unavailable in event mode.
- Fail closed if local encryption or device signing is unavailable on the client.

Config schema version mismatches do not block startup in Alpha 1.

## 26.3 Versioning

Alpha 1 produces versioned builds.

Version metadata includes:

- Docker image tags.
- Mobile app version.
- Electron app version.
- Config schema version.

Node pairing rejects incompatible major versions.

Client apps warn when server version is incompatible.

Electron warns if local server version does not match the expected app version.

---

# 27. Alpha 1 Scope

Alpha 1 includes:

```text
Laravel + Orchid + PostgreSQL
Docker Compose deployment
PowerSync
Vue + Capacitor field app
Electron desktop wrapper
real auth
device trust
device signatures
node signatures
local encryption
readiness checklist
offline field reports
field report photo attachments
check-in/check-out/no-show
online-only incidents
IC roles
policy documents
procedure documents
reusable document fragments
policy/procedure acknowledgments during signup or training
policy/procedure Markdown and PDF export
event maps enabled by default (placement and topographic)
camps and lightweight map locations
event-level Placement department designation with organization default
operations-window map locking
optional IMS incident camp/location reference
offline map package/data sync to permitted devices
central/on-site node pairing
bidirectional node sync
sync conflict queue
audit log
CSV/spreadsheet import/export
versioned builds
deployment config bundles
```

## 27.1 Alpha 1 acceptance target

Alpha 1 should prove:

1. A central node can configure an event.
2. An on-site node can pair with central.
3. Event data can sync to the on-site node before the event.
4. A user can authenticate and trust a device.
5. The device can cache authorized event data.
6. Local encryption and device signing are active.
7. The user can create a field report offline with photos.
8. The field report appears submitted immediately on the device.
9. Name References in the submitted Field Report body are preserved as source text and may be highlighted without adding autocomplete, suggestions, notifications, or permissions.
10. The device reconnects and syncs the field report to the on-site node.
11. The on-site node accepts and countersigns the operation.
12. The on-site node syncs the field report metadata and photos to central when internet is available.
13. The field report is visible in Orchid according to permission rules.
14. A shift lead can check staff in/out and mark no-show.
15. IC roles can create and manage incidents online.
16. Permitted IC users can see incident-level Name Reference chips derived from incident notes and attached Field Reports, and clicking a chip runs normal permission-filtered search.
17. A lead can create a fragment, reference it in a policy/procedure document, publish the document, and preview it with fragment text inline.
18. A user can view visible published policies/procedures offline from synced PowerSync data.
19. A user can acknowledge a required policy/procedure document during signup or training while connected to the server.
20. The acknowledgment stores document ID and document version.
21. A fragment edit automatically bumps the fragment-revision component of published referencing documents.
22. Markdown and PDF exports render fragment text inline and include document version/export timestamp.
23. Sync conflicts appear in God mode and do not block unrelated sync.
24. Electron displays node health and sync status.
25. An event has maps enabled by default and can designate zero or one Placement department that is assigned to the event.
26. An authorized user can create/import a simple placement map, add camps with name/location, and publish the map before the operations window.
27. A published map is visible to permitted lead/IC/kiosk users, camp names are not public to all volunteers, and the kiosk dashboard includes the map by default when published and permitted.
28. Map geometry, camps, and locations lock when the event operations window begins, and the map package/permitted data syncs offline to permitted devices.

Alpha 1 does not need to prove app-store distribution.

Alpha 1 does not need to work on an actual phone to be considered initially complete, though mobile validation remains important.

---

# 28. Alpha 1 Exclusions

Alpha 1 excludes:

```text
SMS
push notifications
native app-store distribution
staff self check-in/out
GPS collection
configurable form structure
automatic organizer visibility into all attendance
automatic organizer visibility into all incidents
God-mode field report body edits
field report attachment deletion/redaction
photo sync down to user devices
field report categories/types
field report drafts
policy/procedure acknowledgments while offline
policy/procedure packet assembly
full-text search within policy/procedure documents or fragments
incident creation while offline
Name Reference autocomplete, notifications, alias merging, profile/detail pages, volunteer profile links, user mentions, and canonical person/entity records
generic plugin system
full GIS editor, drawing suite, automatic geocoding, or public map builder
hybrid map type beyond linkable placement/topographic maps
arbitrary dropped pins
camps/map places in the global command palette
structured map/location fields on Field Reports
volunteer-submitted map corrections
live volunteer GPS tracking, turn-by-turn routing, or real-time personnel icons
per-camp lifecycle states separate from the whole map
multiple Placement departments per event
full multi-on-site-node implementation
USB/server snapshot restore workflows
automatic backups to USB/second disk
app-store distribution
biometric/PIN local unlock
password login
```

---

# 29. Implementation Order

Alpha 1 should be built in ordered slices even though the milestone is end-to-end.

Recommended order:

```text
1. Monorepo scaffold
2. Laravel + PostgreSQL + Orchid
3. Docker Compose deploy path
4. Electron wrapper shell
5. Auth providers
6. Node config and setup flow
7. Node keys, pairing, and roles
8. PowerSync service
9. Vue + Capacitor app shell
10. Device trust
11. Device signing
12. Local encryption
13. Readiness checklist
14. Policy/procedure and fragment modules
15. Policy/procedure PowerSync rules
16. Policy/procedure acknowledgments and exports
17. Offline field report text
18. Field report photo attachments
19. Check-in/check-out/no-show
20. Incidents online-only
21. IC permission model
22. Central/on-site bidirectional node sync
23. Sync conflict queue
24. HTTPS/cert/discovery validation
25. Audit log hardening
26. CSV/spreadsheet import/export
27. Build/distribution packages
```

Electron comes early because the on-site laptop experience is part of Alpha 1.

Incidents come after field reports and attendance.

Local encryption and device signing must be implemented before offline event-mode writes are allowed.

Fake/dev auth is allowed only in development mode, never Alpha 1 production/event mode.

---

# 30. Open Questions / Future Decisions

The following areas may need later detail:

1. Exact PowerSync schema and sync rules.
2. Exact Laravel module folder structure.
3. Exact OpenAPI generation package.
4. Exact file storage abstraction and S3/MinIO transition plan.
5. Exact crypto implementation for Capacitor secure storage.
6. Exact browser/PWA limitations for secure key storage.
7. Exact local discovery implementation.
8. Exact Caddy/DNS configuration for on-site router/AP deployments.
9. Exact spreadsheet import formats.
10. Exact audit log table schema.
11. Exact node operation payload JSON strategy.
12. Exact conflict resolver UI.
13. Exact readiness UI.
14. Exact shared workstation session UI.
15. Exact IC incident dashboard UI.
16. Exact attendance reconciliation rules.
17. Exact photo conversion pipeline.
18. Exact deployment bundle format.
19. Exact Markdown sanitizer/renderer libraries for Laravel and Vue/Capacitor.
20. Exact custom fragment token grammar and editor UI.
21. Exact snapshot strategy for acknowledged policy/procedure versions.
22. Exact background job behavior for fragment-driven document version bumps.
23. Exact acknowledgement flow placement in signup and training screens.
24. Post-Alpha 1 multi-on-site-node architecture.
25. Post-Alpha 1 backups and restore workflows.
26. Exact effective-permission-level role codes for the Placement department (mint placement-specific codes mirroring IC roles, or reuse `department_lead` plus map-management grants). Resolved map behavior: Placement leads may publish/archive maps; non-lead Placement members get view by default and edit only via map-management grant; locked-map overrides are organizer/admin-only.
27. Exact map asset/package storage, tiling, and topographic basemap package format.
28. Exact GeoJSON/geometry storage representation and local-coordinate encoding for placement maps.

---

# 31. Glossary

## Central node

A Meridian server that acts as the main organizational/event coordination node outside the event field environment.

## On-site node

A Meridian server deployed at an event. During the active event window, it is authoritative for event-scoped operational records.

## Standalone node

A Meridian server that is not currently paired with central and is operating independently.

## Acting central

A future multi-on-site-node concept where one on-site node coordinates other on-site nodes locally.

## God mode

A node-global elevated permission mode for trusted administrators who can perform dangerous actions, repair data, manage node config, and resolve sync conflicts.

## Field report

An immutable, low-friction report submitted by a user. It may be created offline. It is not an incident.

## Incident

An IC-managed operational record requiring server connection. Incidents are visible only to IC roles.

## IC department

An event-selected department that functions as Incident Command for that event.

## IC role

A team-granted incident command role within the selected IC department, such as `ic_lead`, `ic_operator`, or `ic_viewer`.

## Event map

An event-scoped map record, typed `placement` or `topographic`, with a whole-map lifecycle of draft/published/archived, used to show what is where and to reference operational locations.

## Camp

An event-scoped operational/location record with a name and a location for MVP; not a generic map feature and not a child of the Placement department.

## Map location

A lightweight non-camp operational location on an event map, such as a department HQ, gate, or deployment location.

## Placement department

A department designated for a specific event as responsible for event geography, placement, camp/location records, and published map data, following the same per-event designation pattern as the IC department.

## Shared workstation

A trusted device intended for multiple users, such as the on-site Electron command center or an IC desk workstation.

## Device trust

A trusted relationship between a user and a specific device, valid for 6 weeks.

## Device signature

A cryptographic signature created by a trusted device for device-originated operations.

## Node signature

A cryptographic signature created by a Meridian node for accepted or synced operations.

## Policy document

A Markdown governance document describing expectations, rules, agreements, or organization/department/team policy. It may reference reusable fragments.

## Procedure document

A Markdown governance document describing operational procedures. It may reference reusable fragments.

## Document fragment

A reusable named Markdown text object referenced by policy and procedure documents. Fragments are versioned and cannot contain nested fragment references.

## Policy/procedure acknowledgment

A record that a user acknowledged a specific policy or procedure document version during signup or training.

## PowerSync

The external sync dependency used for server-to-device local database synchronization.

## Node sync

Meridian’s application-level operation sync between central and on-site nodes.
