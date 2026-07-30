# Meridian Technical Specification

Draft: 0.2  
Scope: Alpha 1 technical architecture and implementation direction  
Status: Working draft  
Additive Update: Policies and Procedures technical architecture added.
Additive Update: Name References technical behavior added for IMS notes and Field Reports.
Additive Update: Fixed UI modes and deployment-target build artifacts added.
Additive Update: The Briefing technical architecture added (Alpha 1 Notes + Command add-to-Briefing + hub shells; full types post–Alpha 1).

---

# 1. Purpose

Meridian is a general-purpose, configurable volunteer operations platform for organizations and events. It is designed for field reliability, offline-capable operations, and trusted on-site coordination.

Meridian supports organizations, events, departments, teams, staff, shifts, attendance, field reports, incidents, Name References in IMS text, credentials, permissions, policies, procedures, reusable governance fragments, policy/procedure acknowledgments, The Briefing (Command hub), node sync, and administrative data repair.

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

Meridian is composed of five major runtime layers:

1. Server/admin application.
2. Shared client application.
3. Mobile packaging wrapper.
4. Desktop on-site wrapper.
5. Node-to-node synchronization layer.

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

Laravel serves the Admin build of the shared Vue client at `/`. Orchid remains
scoped to `/admin` as God Mode and repair tooling.

## 3.2 Shared client application

The shared client application is one Vue codebase that produces three fixed
deployment artifacts:

| Deployment target | UI mode | Product name | Build artifact |
|---|---|---|---|
| Server-hosted web application | `admin` | Meridian Admin | `apps/client/dist/admin` |
| Capacitor mobile application | `field` | Meridian Field | `apps/client/dist/field` |
| Electron desktop/on-site application | `kiosk` | Meridian Kiosk | `apps/client/dist/kiosk` |

The shared client application is:

- Vue.
- Mobile-first.
- Offline-capable.
- PowerSync-backed.
- Locally encrypted.
- Device-signing capable.
- Built as the Admin artifact served by Laravel.
- Built as the Field artifact packaged by Capacitor for iOS and Android.
- Built as the Kiosk artifact packaged and locally served by Electron.

Field, Kiosk, and Admin product workflows live in this shared client. Platform
shells may expose platform capabilities through adapters, but they do not fork
product UI or derive UI mode at runtime.

UI mode is compile-time/package-time configuration. It must not be derived from
viewport width, pointer capability, device profile, network state, current user,
role, permission grants, authenticated state, trusted-workstation state, or user
preference.

## 3.3 Mobile packaging wrapper

The mobile app uses Capacitor to package the Field build of the shared Vue
client for iOS and Android. The installed app is required for reliable
on-site/offline operation where DNS or browser-trusted HTTPS cannot be
guaranteed.

The Capacitor wrapper must not expose a UI mode override. It always packages
Meridian Field.

## 3.4 Desktop on-site wrapper

The on-site laptop uses an Electron desktop wrapper that packages Meridian
Kiosk.

The Electron wrapper:

- Serves the packaged Kiosk build of the shared Vue client through a tiny local
  static server.
- Is installable.
- Runs fullscreen/kiosk-style by default.
- Shows a health panel.
- Shows server/node/sync status.
- Auto-recovers if the local UI crashes.
- Does not start or stop Docker Compose.
- Does not include emergency export in Alpha 1.
- Does not need to block accidental close in Alpha 1.
- Does not expose an external app URL override for normal operation.

## 3.5 UI modes and presentation profiles

UI mode controls the Meridian product shell, route availability posture,
navigation framing, and session assumptions for a deployment target.

Presentation profile is separate. Components and screens may still adapt to:

- narrow or wide viewport;
- touch-first or keyboard-first input;
- compact or roomy density;
- fullscreen or embedded presentation;
- light, dark, reduced-motion, or other accessibility preferences;
- table-first, card-first, or priority-feed layout needs.

Those adaptations must be named and implemented as presentation/input/layout
profiles rather than `field`, `kiosk`, or `admin` mode switches.

Auth works in every UI mode. Authorization remains policy-driven and server
enforced in every UI mode. UI mode never grants access by itself.

## 3.6 Node-to-node sync

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
    client/        Shared Vue client
    server/        Laravel + Orchid + API + client serving
    mobile/        Capacitor mobile packaging
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

- Server Docker image / install bundle with `apps/client/dist/admin`.
- Mobile app package with `apps/client/dist/field`.
- Electron desktop installer with `apps/client/dist/kiosk`.
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
Notes
BriefingNoteInclusions
AfterActionReports
BriefingDirections
ActionPlans
BriefingNotices
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
- Notes they authored, and Briefing-included Note presentations for events they can access.
- Relevant readiness/sync state.

Department Logistics / IC devices may additionally cache Command-visible Notes for events they can access (author+Command pool), per permission rules.

Permitted users/devices should additionally cache the event map package by default:

- Published placement maps and published topographic map packages they may view.
- Permitted camp/location records.
- Kiosk devices receive the event map offline by default when published and permitted.

Sensitive map layers/features must not sync to users/devices without permission. UI hiding is not sufficient.

Department Logistics users should additionally cache:

- Department-scoped searchable staff, equipment, and shift indexes for the
  current event/department.
- Department on-site/off-site presence state.
- Current, upcoming, and outgoing shift context for selected staff.
- Check-in/check-out/no-show state for those department shifts.
- Department equipment state and open checkouts they are permitted to manage.
- Future shift signups needed for the selected staff workspace.

Department Operations users should additionally cache:

- Current and upcoming shift assignments for their department.
- Current deployment/location assignment for those shifts.
- Active deployment/location options.
- Capability-authorized overview module payloads only; the Operations Center
  shell does not expand cache authority by itself.

Department Planning users should additionally cache:

- Identity-free plan-versus-actual aggregate rows by shift/team window.
- Aggregate fields only: capacity target, signed-up/assigned count, checked-in
  count, no-show count, unscheduled additions, planned hours, actual hours, and
  variance/status.
- Explicit data-freshness metadata.

Department leads should additionally cache:

- Department Overview selected-shift summaries, exceptions, checked-in staff,
  assignments, and compact equipment/deployment readiness identifiers.
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

## 9.5 Permission scoping of sync rules

The cache lists in section 9.3 describe what each role should receive. They are expectations, not the authorization boundary.

Sync rules are scoped by the user's effective roles, as defined in section 11A.7. A device does not receive records its user could not retrieve through the API, and a change to a user's effective roles changes what subsequently replicates to that user's devices.

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

## 11.4 API tokens

Meridian client applications authenticate to the API with a bearer token, not with a browser session cookie. The web client, the mobile Field application, and the desktop application all use the same mechanism, so that authentication does not depend on a client being served same-origin by the node it talks to.

Tokens are issued by Laravel Sanctum.

Token issuance paths:

- API magic link. A client posts an email address, the node issues a magic link or code, and the client completes verification through an API endpoint rather than through a browser redirect. On success the client receives a token.
- Provider handoff. Google and Discord login opens a system browser at the existing provider redirect. On completion the node returns the result to the requesting application through a registered callback or custom scheme. The provider exchange itself is not reimplemented in the client.
- Shared workstation login codes do not issue tokens. See section 13.

What the browser carries back to the application is a one-time exchange code, not the token. The client redeems that code, together with the PKCE verifier for the challenge it started the handoff with and its device identity, for the token. The return leg travels through a custom scheme on mobile and desktop, and another application on the same machine can register the same scheme, so the value in the URL must not itself be a credential and possession of it must not be sufficient to obtain one. The return address is resolved from node configuration per client target rather than from the request, and a client target with no configured return address does not offer provider login.

A provider handoff establishes no browser session. The browser performing the sign-in is not the application receiving the credential, and a Meridian session left open in a system browser is one nobody signs out of.

Every token is bound to a `devices` record. Token issuance requires a resolvable device identity, and a request that cannot supply one is refused rather than issued an unbound token. The device binding is what makes a token revocable as a unit of hardware rather than only as a unit of session.

God mode can list tokens by user and by device, revoke an individual token, and revoke every token issued to a device. Revocation is evaluated on the node at request time, so a revoked token stops authenticating on its next request without any client cooperation.

Tokens expire. Expiry is a node configuration value with a documented default. Token expiry is independent of the shared-workstation inactivity timeout in section 13.3; the two govern different things and neither substitutes for the other.

Token issuance, expiry, and revocation are audit-sensitive. Raw token values are never written to logs, audit entries, or exports. Audit entries reference the token by identifier and by bound device.

The shared-token `local.field` middleware used during Alpha 1 mobile Field QA is superseded by this mechanism and is removed. The seeded local Field fixture user remains as ordinary development seed data and is reached by logging in as that user.

Every operational endpoint accepts a bearer token or a shared-workstation session key, and nothing else. A Kiosk holds the second rather than the first (section 13.3), so both credentials reach the same routes and each one authorizes the user it resolves against the same policies; a workstation's pinned context grants no authority of its own. The routes that carry no credential at all are node-to-node exchange, which authenticates by node signature, the login paths themselves, and the organization branding profile a device resolves before it has a session.

A client holds its token durably, so an application that was signed in yesterday is signed in when it is reopened without coverage today. It disposes of the token when the person signs out, and drops it when the node refuses it — a revoked token or a revoked device arrives as a refused session refresh, and a client that kept the credential would go on presenting one the node has already withdrawn.

---

# 11A. Client Session and API Binding

## 11A.1 Purpose

This section defines how a Meridian client application establishes a session, learns what its user may do, resolves its operating context, submits writes, and retrieves files. It adds no domain behavior. It replaces the fixture data that Alpha 1 clients have carried in place of a server-driven session.

## 11A.2 Session resolution endpoint

The node exposes an authenticated endpoint that returns, for the calling user:

- the user's identity;
- the effective role codes the user holds, as resolved by the effective role resolver described in section 15;
- the permission capability codes those roles carry, as published by the permission catalog;
- the organizations, events, departments, and teams the user is associated with.

The endpoint returns role codes and capability codes. It does not return navigation decisions, screen lists, or menu structures. A client that needs to know whether to render a surface answers that question from the capabilities it holds. This keeps the permission catalog the single source of truth and prevents a second, divergent permission model from growing inside the response shape.

Capability codes are reported twice: as one flat list for the user, and against each role for the scope that role was resolved at. Authority in Meridian is scoped, so "may this user check equipment out" and "may this user check equipment out for this department" are different questions, and the client holds no copy of the role-to-capability mapping to tell them apart on its own. Both lists come from the catalog the server enforces from, so neither can drift from it.

Clients derive navigation and available actions from capabilities, applying the existing UI rules in the operating guide: unavailable actions are generally hidden, and permission-denied surfaces explain the required role to elevated users while stating only that access is restricted to default staff.

Client-side capability checks are presentation. Server-side authorization remains the enforcement boundary, and a client that fails to hide an action is still refused by the server.

## 11A.3 Context resolution

A client resolves organization and event context from the node it is connected to. When the node is locked to an event, that lock determines organization and event, in the same way the branding resolver and Kiosk workstation pinning already resolve locked context. The session response then narrows that context to the user's own departments and teams.

A connected client whose user belongs to more than one event, or to more than one organization, may switch to any other event or organization in which that user holds an association. Switching is a connected-only capability.

Switching is offered by a node that is not locked to an event. A node locked to one holds that event's records and no others, so it reports the lock rather than a switcher, and the client says why the choice is absent instead of appearing to have lost it. The session response carries both, so a client never has to infer either.

Where the node has no lock and the user is associated with exactly one event, that event is the context: there is nothing to choose between. Where more than one is available, the client asks, and names the chosen event when it resolves the session. Roles are resolved at whichever event that is, so event-scoped grants appear only where they apply.

A client without connectivity is locked to the context the node provides and does not offer switching.

Switching re-resolves permissions, navigation, branding, and cached context. Data from the previous context is not left visible.

## 11A.4 Offline permission cache

A client caches the most recent session response durably and uses it to establish navigation and permissions when the node is unreachable.

The cached response remains usable for the duration of the event the node is locked to. Once that event window has ended, or when the client holds no event context, the client requires a successful refresh before granting access. Tying staleness to the event window rather than to a fixed number of hours means a device does not lose its permissions partway through a multi-day event that has no connectivity.

A client operating from cache indicates that its permissions are cached and records when they were last refreshed.

On regaining connectivity the client refreshes and applies any reduction in permissions immediately. A permission that has been removed is removed on refresh, not on next login.

## 11A.5 Command outbox

Clients submit every command through one durable local queue rather than through per-feature queues.

The outbox replaces the separate field report and attendance queues that Alpha 1 carries. Both become callers of the shared queue.

Each queued command carries a client-generated idempotency key. The server treats a repeated key as the same command rather than as a new one, which is what makes replay after an interrupted sync safe.

The client shows which commands are queued, which have been accepted, and which have been rejected. A rejected command is surfaced, not silently discarded.

Commands this specification restricts to connected operation — incident creation in section 19, policy and procedure acknowledgments in section 21, event application submission, and map editing in section 21A — are refused at queue time rather than queued and rejected later. Queueing a command that can never succeed offline would misrepresent to the user that their work was captured.

## 11A.6 Authenticated downloads

A bearer token cannot be attached to a plain browser navigation, so authenticated file retrieval does not place credentials in a link.

An authenticated client requests a short-lived URL for the resource it wants, then navigates to that URL. This follows the pattern the field report photo endpoints already establish.

A short-lived URL expires, is scoped to the single resource it was issued for, and is subject to the same authorization as a direct request for that resource. Issuing the URL is an authorization decision; following it is not a second one.

This applies to reporting exports, generated incident PDFs, document exports, and attachments.

## 11A.7 Permission-scoped replication

Offline data replicated to a device is limited to what the device's user is permitted to read. A device does not receive records its user could not retrieve through the API.

The cache expectations in section 9.3 describe what a role *should* receive. This section states the boundary: sync rules are scoped by the user's effective roles, so a device cannot hold data its user has no capability to read. UI hiding is not sufficient, consistent with the existing rule for sensitive map layers.

A change to a user's effective roles changes what subsequently replicates to that user's devices.

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

A shared workstation is a special kind of trusted device and is the persistence
model for Kiosk pinned context.

Shared workstations are managed in God mode.

Example names:

```text
onsite-command-1
ic-desk-1
radio-desk-1
```

The on-site Electron machine is the first trusted shared workstation for Alpha 1.

Every trusted shared workstation used by Meridian Kiosk must be pinned to:

- one organization;
- one event;
- optionally one department.

The pinned organization/event/department context is stored on the existing
`shared_workstations` model. Meridian must not introduce a separate trusted
device registration model for Kiosk context.

If Meridian Kiosk starts without a pinned organization and event, it enters
setup. It must not infer context from viewport, network, authenticated user,
last route, or cached event data.

Authorized organizers, lead organizers, and God Mode users may change pinned
Kiosk context from Kiosk setup/support surfaces. Context changes are audited.

## 13.2 Shared workstation login

Meridian Kiosk supports shared workstation login.

Known users can enter short login codes.

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

### Code generation authority

Login codes may be generated two ways.

God mode generates a code for any known user. This is the assisted-recovery and event-preparation path.

A user generates a code for themselves from a device on which they already hold a valid session. This is the on-site path: a staff member standing at a kiosk with no internet, no email delivery, and no technician available generates a code on their phone and types it into the workstation in front of them.

Self-service generation requires only that the generating device can reach the node that will accept the code. It does not require internet access, central node reachability, email delivery, or any other out-of-band channel. This is the reason the mechanism exists — it is the only login path that survives an on-site node with no route to central.

A self-service code is scoped to the generating user. A user cannot generate a code on behalf of someone else. God mode retains that ability.

Code generation is rate-limited per user and per node. Failed code entry is rate-limited per workstation.

Both generation paths produce the same kind of code and are subject to every constraint listed above. In particular, a self-service code still cannot create a trusted personal device session, and still expires at six weeks.

A successful code entry establishes a shared workstation session as described in section 13.3. It does not issue an API token under section 11.4.

## 13.3 Shared workstation session behavior

Shared workstation sessions time out after 5 minutes of inactivity or when the
user explicitly ends the session.

The 5 minutes are the node's. A session carries an inactivity deadline derived
from its last activity, every authenticated request slides it, and the node ends a
session it observes past that deadline. The Kiosk counts down against the deadline
it is given rather than against its own idea of when it last did something.

The Kiosk warns before the timeout and offers a way to continue. Continuing is
activity, so it slides the window rather than asking for anything to be re-entered.

Users must explicitly end their session before switching users. This is enforced
where switching happens: a workstation holding a live session offers no code entry
and cannot be navigated to one. It is deliberately not enforced as a node-side
refusal to start a second session — a Kiosk that crashed still holds a session it
can no longer present, and refusing would lock the workstation out for up to five
minutes at exactly the moment somebody needs it. Starting a session closes
whatever the workstation still had open and records that it did.

The active user is shown prominently at all times.

Permissions come entirely from the active user.

Pinned workstation context limits and frames the Kiosk shell; it does not grant
the active user any authority.

Shared workstation local data remains encrypted at rest.

When a session ends or times out, active session data is wiped. Unsaved form
state is abandoned. Saved local queued operations remain in the local queue and
sync when available.

If the Electron app restarts, the shared workstation session locks immediately.
This is a property of the credential rather than a behavior of the renderer: the
session key is held in memory only and is never written to disk, so a restarted
Kiosk has nothing left to present. Nothing resolved under a workstation session is
cached for the next start either — a session document on disk is how the user who
was signed in five minutes ago comes back on screen for whoever restarts the
machine.

A timeout lands on the safe-timeout surface rather than on the login screen,
because a timeout means nobody is watching and the screen must hold nobody's
records. An explicit end lands on the login screen, because the person who ended it
is standing there.

For MVP, shared workstation login is allowed only on the on-site server machine or on explicitly designated trusted shared workstations.

Kiosk switch, re-authentication, safe-timeout, and setup/support surfaces are
available in Meridian Kiosk. Meridian Admin may configure, review, and support
those Kiosk surfaces, but Admin mode must not become a quick switcher for its
own session.

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
department_logistics
department_operations
department_administration
department_planning
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
- `department_logistics`, `department_operations`, `department_administration`, and `department_planning` are department-scoped grants assigned through teams; team names are arbitrary.
- `ic_lead`, `ic_operator`, and `ic_viewer` are event/team scoped through the IC permission model.

Alpha 1 department operational capabilities are separated:

- `department_logistics` manages department presence, staff-mediated attendance, and equipment checkout/check-in through the Logistics Desk staff workspace.
- `department_operations` opens the Operations Center and manages current deployment/location assignment. The shell does not grant incident, equipment, or other module access.
- `department_planning` views identity-free Planning Table aggregates comparing plan versus actual by shift/team window.
- `department_administration` manages department/team administrative settings as permitted.
- Incident overview modules require separate event-scoped IC capability.
- Equipment overview modules require the relevant equipment visibility/manage capability.

Every permission decision should be explainable in the UI.

Example:

```text
You can mark no-show because your team has Department Logistics for this department.
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
- Manage The Briefing for the event (add Notes by reference or link, Directions, Action Plan, Notices, Final AAR).
- Create Notes and read Notes authored by others for the event (Command visibility).

### ic_operator

Can:

- View incidents.
- View all field reports for the event.
- Close incidents.
- Reopen incidents.
- Add incident notes.
- Link/unlink field reports from incidents.
- Manage The Briefing for the event (add Notes by reference or link, Directions, Action Plan, Notices, Final AAR).
- Create Notes and read Notes authored by others for the event (Command visibility).

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
title
body text
picture attachments
submitted_by
device_submitted_at
server_received_at
origin_device_id
origin_node_id
sync_status
```

Field report title is required plain text: outer whitespace trimmed, 1–200 characters after trimming, duplicates allowed within an event.

Field report body is a single unstructured text area. Field Reports have one title plus one body and do not gain categories/types or structured map/location fields in Alpha 1.

There are no field report categories/types in Alpha 1.

GPS collection is excluded from Alpha 1.

## 17.4 Immutability and appends

The original field report title and body never change after submission.

Field reports are immutable but can have append-only additions.

Only the original submitter can append to their own field report.

Elevated users can append only to their own field reports, not to other users’ field reports.

Appends do not have titles and cannot alter the original title.

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

When a field report is attached to an incident, the incident timeline copy includes the Field Report title, author, and body.

The original field report submitter is not shown that their field report has been attached to an incident.

## 17.7 Name References in Field Reports

Field Report body text may contain Name References. Field Report titles are not parsed for Name References.

A Name Reference starts with `@` and continues through letters, numbers, hyphens, and underscores until whitespace or punctuation.

Examples:

```text
@bucket.        -> bucket
@blue-hat       -> blue-hat
@blue_hat       -> blue_hat
@ranger bucket  -> ranger
```

Field Report body text and appends are parsed for Name References immediately after submission. Titles are not parsed. The original body remains the source of truth for Name References.

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

- Mark department staff on-site/off-site.
- Check-in.
- Check-out.
- Mark no-show.

Staff self check-in/out is excluded from Alpha 1.

Department Logistics can mark department staff on-site/off-site.

Department Logistics can check staff in/out after the staff member is marked on-site for that department.

Department Logistics can mark no-show.

Department leads may have broader attendance access for their department.

### Automatic no-show determination

No-show is determined automatically. A scheduled shift whose assigned staff member has not checked in by the end of the accepted sign-in window is recorded as a no-show without anyone marking it.

The accepted sign-in window extends before and after the scheduled shift start by 5% of the scheduled shift duration. A four-hour shift therefore accepts a sign-in from twelve minutes before its start to twelve minutes after.

Determination writes an attendance operation through the existing append-only path in 20.1. It is a recorded operation with a timestamp, audited and synchronized like any other, not a value computed at read time. This is what lets a no-show survive on a device that later goes offline and lets the operation carry the node that produced it.

The node holding authority for the event performs the determination: the on-site primary node during the active event window, central otherwise. This follows the existing event-authority rule in section 10.2 and introduces no new authority concept.

Exclusions:

- cancelled shifts;
- shifts carrying an excused attendance record.

A check-in recorded after an automatic no-show supersedes it. The derived attendance state becomes checked-in and both operations remain in history, consistent with how attendance already handles corrections. A staff member who arrives very late is recorded as having arrived, and the record still shows the shift was missed at the window boundary.

Late arrival is this superseded case. A staff member checked in after the end of the accepted sign-in window arrived late. One threshold separates on time, late, and missed, with no gap or overlap.

The manual mark-no-show operation remains available to authorized attendance managers. Automatic determination covers the ordinary case; the manual operation stays for absences a lead needs to record deliberately.

## 20.3 Department Presence

On-site/off-site status is scoped by event, department, and staff member.

Only Department Logistics may mark eligible department staff on-site/off-site.

On-site status makes a staff member eligible for Logistics shift add/check-in.

Going off-site is blocked while the staff member is checked into a shift for the
department/event.

Going off-site is blocked while the staff member has checked-out equipment for
the department/event unless the equipment is returned or marked Missing/Damaged.

## 20.4 Shift selection

Check-in/check-out should select a shift.

If there is only one obvious/current shift, the UI should default to that shift.

Check-out creates hours for the selected shift.

## 20.5 Department operations workflows

Department Overview and Planning Table are department-scoped by default. Optional
team or date filters may narrow the view without changing authorization.

Shifts have exactly one team.

Department Overview is a lead situational-awareness surface for a selected shift.
Content order is exceptions, summary counts, checked-in staff, assignments, then
compact equipment/deployment/readiness summaries with drill-throughs.

Logistics Desk is staff-first. Department-scoped offline search finds staff,
equipment, and shifts. Selecting a staff member opens a workspace with presence,
active/upcoming/outgoing shift context, check-in/check-out dialog, equipment
handoff, and future signup or eligible shift-add actions.

Logistics can add an on-site eligible staff member who is not assigned to the
shift.

This does not create a separate exception record in Alpha 1.

No-show only applies after a shift has started.

Check-out normally requires prior check-in.

An authorized attendance manager can create a check-in and check-out at the same
time if necessary.

Offline check-out without known server-side check-in is accepted and reconciled later.

Overlapping check-ins are allowed but should warn.

Duplicate check-in is idempotent.

Attendance operations do not include optional notes in Alpha 1.

Operations Center always shows the deployment/location module for users with
Department Operations capability. Additional modules appear only when the actor
already holds the corresponding capability.

Operations may assign or change one current deployment/location per shift/staff
before or during the shift.

Planning Table shows identity-free plan-versus-actual aggregates by shift/team
window and must not expose individual staff identities, signup lists, or
team-member lists.

## 20.6 Attendance visibility

Staff see their own attendance state.

Department Logistics sees attendance for department shifts they are permitted to manage.

Department leads see attendance for their department.

Organizers do not automatically see all attendance.

God mode can directly edit attendance records.

Direct attendance edits do not require a reason/comment.

Direct attendance edits create before/after audit entries.

The UI shows corrected attendance as normal, with history available only to elevated users.

Duplicate/overlapping attendance warnings are visible to Department Logistics immediately.

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

The shared Vue client may render synced Markdown locally through PowerSync-backed data for offline reliability.

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
- Field Reports have one required title and one unstructured body and do not gain structured map/location fields (see section 17).

Arbitrary dropped pins are not supported for MVP. Camps and map locations are not added to the global command palette; map surfaces may provide their own scoped search/filter for permitted users.

## 21A.8 Sync and offline

Published placement maps, published topographic map packages, and permitted camp/location records are eligible for offline sync to authorized users/devices by default. Kiosk devices receive the event map offline by default when published and permitted; lead/IC devices receive map data according to permissions; non-permitted users do not receive hidden/sensitive camp/location data. Sensitive layers/features must not sync to users without permission. Locked operations-window map data remains stable offline, and surfaces should show stale/offline map status where relevant.

---

# 21B. The Briefing

## 21B.1 Purpose

The Briefing is an event-scoped Incident Command hub that shares Command-relevant operational information across departments.

It is implemented as a hub surface aggregating five domain modules:

```text
Notes
BriefingNoteInclusions
AfterActionReports
BriefingDirections
ActionPlans
BriefingNotices
```

The Briefing is not a single parent document table. Hub UI composes Briefing inclusions and the other types. Notes are a standalone module referenced by Briefing and AAR inclusion records.

## 21B.2 Permissions

Suggested capability codes:

```text
briefing.hub.view
notes.create
notes.view_own
notes.view_command
notes.add_to_briefing
notes.add_to_aar
briefing.aar.submission.manage
briefing.aar.submission.view_own
briefing.aar.submission.view_all
briefing.aar.final.manage
briefing.aar.final.view
briefing.directions.manage
briefing.directions.view
briefing.action_plan.manage
briefing.action_plan.view
briefing.notices.manage
briefing.notices.view
briefing.notices.dismiss
```

Authority mapping:

- `briefing.hub.view` — approved event staff
- `notes.create` — department_lead, team lead, `ic_lead`, `ic_operator`
- `notes.view_own` — Note author
- `notes.view_command` — `ic_lead`, `ic_operator`, `ic_viewer` (Command pool visibility before Briefing inclusion)
- `notes.add_to_briefing` / `notes.add_to_aar` — `ic_lead`, `ic_operator`
- Briefing-included Note presentations are readable per inclusion audience (`event_staff` or `department_leads_only`)
- `department_leads_only` audience = department leads for the event + Command + organizers; team leads excluded unless they also hold one of those roles
- `briefing.aar.submission.manage` / `view_own` — department_lead and team lead for own scope
- `briefing.aar.submission.view_all` / `briefing.aar.final.manage` / Directions / Action Plan / Notices manage — `ic_lead`, `ic_operator` (organizers may read Submission AARs and Final AAR per requirements)
- `briefing.aar.final.view` / Action Plan view / Notices view — approved event staff after publish rules

## 21B.3 Notes

Notes are a **standalone** event-scoped Markdown domain.

Rules:

- Created once; immutable thereafter (no update/append commands).
- Correction path is create a new Note.
- Creators: department leads, team leads, `ic_lead`, `ic_operator`.
- Pre-Briefing visibility: author + Command only.
- Distinct from IMS incident timeline notes and policy/procedure fragments.
- The Briefing and AARs do not own Notes; they store inclusion records.

### Inclusion modes (Briefing and AAR)

**Reference**

- Command authors a summary text for readers.
- Inclusion stores `note_id`, summary Markdown, and original-author credit.
- Readers can open/view the original Note.
- Used when Command restates/clarifies an idea while crediting the author.

**Link (verbatim)**

- Inclusion presents the Note body verbatim.
- Credit goes to the original author.
- Reads as Command communication attributed to that individual (including Command members).
- Stores `note_id` and may snapshot body at link time for stable Briefing display while retaining author credit and original Note identity.

Each Briefing inclusion also stores `audience`:

- `event_staff` (default) — approved event staff
- `department_leads_only` — department leads for the event, Command, and organizers (team leads excluded unless also dept lead/Command/organizer)

Directions, Action Plans (whole and/or sections), and Notices use the same audience values.

Alpha 1 implements Note create/list/detail, author+Command visibility, Command add-to-Briefing (reference and link) with audience mark, hub display of added Notes to permitted viewers, permissions, and Orchid list/detail scaffolding.

## 21B.4 After Action Reports

AARs are versioned Markdown documents with a fixed ICS section schema:

```text
command
operations
logistics
planning
admin
```

Kinds:

- `submission` — scoped to a department or team; authored by that lead
- `final` — one compiled event Final AAR

Lifecycle:

- Submission edit/submit/resubmit allowed through event end + 30 days
- IC may publish Final after event end
- At event end + 45 days, if no Final published, a job auto-assembles Final from latest submitted Submission versions and freezes it
- Frozen Final is read-only except organizer/god_mode repair

Note inclusion into AARs uses the same **reference** and **link** modes as The Briefing (post–Alpha 1 UI; model specified now).

Alpha 1 ships AAR hub shell only; full AAR domain is post–Alpha 1.

## 21B.5 Directions, Action Plans, and Notices

**Directions:** IC Markdown with optional entity deep links and dept/team targeting. Optional `audience` (`event_staff` | `department_leads_only`). Hub-only; no banners.

**Action Plans:** IC Markdown with optional dept/team sections. Whole plan and/or sections may set `audience`. During active event window, targeted sections may render banners on a fixed allowlist of screen IDs from the UI implementation contract; department-leads-only banners only for permitted viewers. Updates may emit Notices.

**Notices:** Short records with optional targeting, expiry, and `audience`. Hub list plus dismissible per-user alert state (`briefing_notice_dismissals`).

Alpha 1 ships hub shells only for these three types.

## 21B.6 Commands (full design)

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

`add-note-to-briefing` and `add-note-to-aar` accept `inclusion_mode` of `reference` or `link`. Reference mode requires Command summary text. Link mode includes the Note verbatim with author credit. `add-note-to-briefing` also accepts `audience` of `event_staff` (default) or `department_leads_only`.

Alpha 1 requires `create-note` and `add-note-to-briefing` (and related reads). Remaining commands are post–Alpha 1.

## 21B.7 Orchid / admin

Alpha 1 Orchid (God Mode) should include Note list/detail for repair visibility.

Post–Alpha 1 Orchid may include AAR, Direction, Action Plan, and Notice repair screens. Normal authoring belongs in product UI / Briefing hub, not Orchid.

## 21B.8 Sync and offline

Alpha 1:

- Sync Notes to author and Command devices (pre-inclusion visibility)
- Sync Briefing inclusion records and presented content for Notes added to The Briefing according to each inclusion’s audience
- Note create and add-to-Briefing require server connection in Alpha 1

Post–Alpha 1:

- Sync visible Directions, Action Plans, Notices, and published Final AARs
- Submission AARs sync to authors, IC, and organizers per visibility
- Notice dismissals are user-scoped sync rows
- Action Plan banners use synced Action Plan section payloads; they do not grant extra cache authority

## 21B.9 Audit

Audit Note create, add-note-to-briefing, add-note-to-aar, AAR submit/publish/auto-assemble/freeze, Direction/Action Plan/Notice mutations, and Notice dismissals.

---

# 21C. Insights

## 21C.1 Purpose and boundary

Insights are live compiled views of current authorized operational data. They answer whether operations are meeting expectations and where attention is needed, for Command, Planning, Logistics, organizers, department leads, and volunteers looking at their own numbers.

Insights are not Reports. A Report is a fixed, formal, historical, or submitted output. An Insight Sheet is a live view whose contents change as the underlying data changes. A PDF taken from a sheet is a snapshot of what one person was looking at; it does not convert the sheet into a Report and Meridian does not keep it.

Insights add no source of truth. They compile data the viewer is already authorized to read, from the domains that already own it. PostgreSQL and Laravel remain canonical. There is no analytics warehouse, no external business-intelligence system, no event stream, and no duplicated store.

## 21C.2 Metric registration

An Insight Metric type is defined in code by developers and registered as data. Registration carries the metric's identity, the domain it draws on, the capability required to view it, the configuration keys it accepts, and its fixed evaluation rules.

Organizations do not author metric logic. There are no organization-defined formulas and no query builder. Orchid may display and administer registration metadata, but cannot create metric behavior.

A registered metric renders through a contract: given an event, a resolved department scope, a filter selection, and a placement configuration, it returns values, a state, a presentation, and an optional action link. Anything a metric needs that this contract does not carry is a defect in the contract rather than a reason for a metric to reach around it.

Every metric declares the capability it requires. The framework refuses to render a metric whose capability the viewer lacks, so a new metric cannot leak a domain by forgetting to check.

## 21C.3 Sheets and placements

An Insight Sheet is owned by the organization and holds an ordered set of metric placements. Each placement references a registered metric type and carries its own configuration, so the same metric type behaves differently in two places on the same sheet.

A sheet declares which of its supported filters are offered to viewers. Filters apply to the whole sheet. Individual metrics carry no independent user-facing filters.

A viewer's filter selections are held for the session. They are not persisted across sessions and are not part of the sheet definition.

Sheet configuration is validated against metric registration. A placement referencing an unknown metric type, or carrying configuration keys that metric does not accept, is refused at write time rather than failing at render.

Sheet lifecycle uses the existing conventions: created, edited, and soft-archived. There is no publication workflow, approval chain, or version history, because nothing in the product needs one and inventing one would make a live view behave like a document.

## 21C.4 Scope resolution

A sheet reads one event at a time. Cross-event aggregation and comparison are out of scope.

Department scope resolves from the viewer's authorization and their selected department context. The same sheet renders organization-wide data for an organizer and a single department's data for a department lead, without either seeing a different sheet.

Authorized users may switch department context among departments they are authorized for, and the sheet re-renders.

## 21C.5 Authorization

Insights reuse the permission catalog, effective roles, and team grants described in section 15. There is no parallel permission system.

Capabilities:

- `insights.view` — read Insight Sheets and metrics the viewer is otherwise authorized for
- `insights.sheets.manage` — create, edit, order, archive organization Insight Sheets and their metric placements
- `insights.share_with_command` — share a sheet or a metric placement with Command, and remove that sharing

Resolution rules:

- organizers access Insights across departments for the selected event, subject to domain restrictions defined elsewhere;
- a department lead who is not also authorized through a team under the Organizers Department sees only the department being viewed, and may switch among departments they lead;
- Command — holders of `ic_lead`, `ic_operator`, or `ic_viewer` in the event's designated Incident Command Department — sees Insights for its own department plus content explicitly shared with it;
- Planning and Logistics see only their own department unless another permission independently grants more.

Holding `insights.sheets.manage` does not widen data access. A user may build a sheet whose metrics render less for them than for someone else, and may not configure a sheet to expose data they cannot read. Configuration authority and data authority are separate.

Enforcement is server-side, in policies, query handlers, API responses, Orchid, and sync rules. A hidden control is presentation, not enforcement.

## 21C.6 Restricted data

Insights never render personally identifiable information, individual volunteer names to leads or Command or Planning or Logistics or organizers, individual-level drilldown into another person, or anything that bypasses a source domain's restrictions.

An aggregate covering fewer than 5 people is suppressed. A count of three in a small team is a name in a thin disguise. Suppression states that the value is withheld for privacy; it does not render as empty, zero, or a dash, because a silent gap invites the viewer to infer what was in it.

Metrics drawing on restricted domains are visible only to users authorized for those domains. Incident- and Field-Report-derived metrics are restricted to the designated Incident Command Department under the applicable IC permissions, per section 16.

A sheet the viewer cannot access is hidden, not rendered as an empty or inaccessible entry.

## 21C.7 Metric state

Metrics report whether conditions require attention and equally report when conditions are healthy. A metric that speaks only when something is wrong makes its silence ambiguous.

Semantic states a metric may carry:

- healthy — expectations are being met
- expected — within normal range, no attention needed
- attention — conditions warrant a look
- critical — conditions require action now
- incomplete — the metric could not compile from all the data it needs
- stale — compiled from data known to be behind
- offline — compiled on a device with no node reachable
- waiting to sync — local changes have not yet reached the node

The first four describe the operation. The last four describe the data. They are independent: a metric may be healthy and stale at once, and collapsing the two would tell a user their operation is fine when what is actually true is that nobody knows.

Thresholds are fixed system rules for MVP. Organization-configurable thresholds are out of scope.

States are not dismissed, acknowledged, assigned, or resolved. A metric continues to display after a problem is corrected, showing that expectations are now met. Insights add no task-management workflow.

A metric links to the operational surface where an authorized user can investigate or act. Following the link enters that surface under its own authorization; the link carries no authority of its own.

## 21C.8 Live data, synchronization, and offline

Insights recompile after each synchronized change to their underlying data. There is no separate refresh cycle to fall behind.

Insights compile on the device from data already synchronized there, under the permission-scoped sync rules in 11A.7. This is why offline Insights work at all, and also why they are bounded: a device compiles what it holds, and it holds only what its user may read.

Offline and partially synchronized Insights disclose what the system knows: stale, waiting to sync, incomplete, offline, and last synchronized time as applicable. The interface never presents incomplete or stale data as current and complete.

Insight evaluation does not circumvent sync rules, local data rules, upload validation, command handlers, or authorization policies. A metric that cannot compile within those boundaries reports incomplete rather than reaching past them.

Compiled results are not stored as canonical records. If a future metric proves too expensive to compile per view, a cache is a performance decision to be justified and specified then, not an architecture to adopt in advance.

## 21C.9 Sharing with Command

A department lead may share a whole sheet or a single metric placement with Command.

Sharing is ongoing or temporary. A temporary share stops applying when the event's operations window closes, and the sharing department may remove it earlier. Expiry is evaluated on read against the event window, so no scheduled job is involved.

Rules:

- shared content identifies its originating department wherever it appears;
- sharing grants no data Command is otherwise prohibited from seeing. Restricted metrics on a shared sheet stay restricted, so sheet-level sharing cannot leak a metric the sharer forgot was on the page;
- metric-level sharing applies to that placement, not to every use of the registered metric type;
- a shared item's action link preserves authorization; Command follows it as Command, not as the sharing department;
- sharing and unsharing are audited.

## 21C.10 PDF snapshots

An authorized viewer may save the current sheet view as a PDF. The browser generates it and the user downloads it.

The PDF represents what the viewer was looking at: visible metrics, selected filters, current states, freshness and synchronization disclosures, organization, event, department where applicable, sheet name, generation timestamp, generating user, and the originating department of shared content.

Meridian does not store the PDF, does not create a snapshot entity, and provides no snapshot history or retrieval.

PDF generation is not audited. The browser generates it from a view the user is already authorized to see, and a user can print any page they can read; an audit entry here would record only the cases where someone used the button, which would misrepresent itself as a record of who exported what. This deliberately differs from incident PDF export, which is server-generated and audited because the server produces the document.

The PDF carries the same suppression as the rendered view. It contains no personally identifiable information and no unauthorized individual data.

## 21C.11 Orchid

Orchid exposes registered metric definitions and their administrable registration metadata, organization Insight Sheets and their placements, sheet filter configuration, Command sharing configuration, and the audit records for sharing changes.

Orchid users cannot create metric code or formulas. Editing a sheet's configuration grants no data access.

Favorites and pins are personal view state and belong in the product interface, not in Orchid.

## 21C.12 Audit

Audit sheet creation and material configuration change, sheet sharing with Command, metric-placement sharing with Command, and unsharing.

Access to restricted incident- or Field-Report-derived Insights follows the existing sensitive-read auditing rules for those domains.

Audit payloads reference staff and users by identifier. They do not carry volunteer names or other personal data.

---

# 22. Admin, Orchid, and God Mode

## 22.1 Orchid purpose

Meridian Admin is the server-hosted Vue product UI for normal administrative,
organizer, department, staff, and operational workflows that are available from
the web deployment target.

Orchid is God Mode and repair tooling. It provides trusted data repair,
break-glass visibility, configuration override, sync conflict, and dangerous
administration screens. It is not the normal Admin product shell.

User-facing product workflows are separate from Orchid and live in the shared
Vue client.

## 22.2 Alpha 1 Orchid / God Mode screens

Alpha 1 Orchid / God Mode should include screens for:

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
Notes
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

God Mode users may directly repair:

- Users.
- Teams.
- Shifts.
- Incidents.
- Attendance.

Orchid / God Mode cannot directly edit finalized field report original body.

Orchid / God Mode does not provide field report append/redaction workflows in Alpha 1.

Orchid / God Mode does not allow attachment redaction/deletion in Alpha 1.

Dangerous God Mode actions require reason/comment.

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

## 22.5 God Mode landing screen

The console index screen is Meridian's own landing screen. It does not render
the administrative framework's welcome content and does not describe Meridian as
an installation of that framework (GOD-001, GOD-002).

The landing screen has two regions.

### 22.5.1 Orientation summary

A brief bulleted summary of how Meridian works end to end, covering
organizations and departments, events and the active event window, staff, teams,
shifts and eligibility, operations, attendance and hours, policies and
acknowledgments, incidents, and the central/on-site node model with its sync
authority rules (GOD-003).

The summary states that God Mode is repair and break-glass tooling and that
normal organizer, department, and staff workflows belong in Meridian Admin
(GOD-004, section 22.1).

### 22.5.2 Attention list

Items requiring attention are surfaced in three groups (GOD-005):

```text
Deployment and configuration readiness
Organizational data gaps
Unresolved sync conflicts
```

Deployment and configuration readiness reports node configuration completeness,
node role and pairing state, presence of required secrets, secure connection
policy status, and PowerSync connectivity (GOD-006, sections 25.3 and 26.2). The
secure connection policy and PowerSync signals are the same event-mode
fail-closed checks described in section 26.2; the landing screen reports them
rather than evaluating a second, separate policy.

Organizational data gaps report organizations without departments, organizations
without a configured Organizers Department, organizations and events without a
resolvable Incident Command Department, organizations without an active Lead
Organizer, and events without assigned departments (GOD-007). "Resolvable"
follows the existing effective-department resolution: the event override when
set, otherwise the organization default.

Unresolved sync conflicts report the outstanding conflict count and link to the
conflict queue described in section 10.3 (GOD-008).

Every attention item links to the screen where it can be resolved (GOD-009).

Checks are evaluated at view time and are read-only. Rendering the landing
screen must not create, update, or delete records, and must not repair the state
it reports (GOD-010).

When every check passes, the landing screen states that explicitly instead of
rendering an empty region (GOD-011).

## 22.6 Technician documentation

Technician documentation is maintained in the repository under `docs/technician/`
and is written for node technicians and God Mode users rather than as
specification. It covers deployment, node setup and pairing, configuration and
config source resolution, data repair, sync conflict resolution, and break-glass
procedures (GOD-013).

The console serves this documentation from a Documentation page (GOD-012). The
page:

- renders documentation packaged with the deployment, requires no network
  access, and does not fetch from an external service (GOD-014);
- serves `docs/technician/` only. The requirements document, technical
  specification, data/API specification, UI documentation, QA scripts,
  architecture decision records, development plan, traceability matrix, and
  issue documents are not reachable from it (GOD-015);
- provides a document index, renders Markdown headings, lists, tables, and
  fenced code, and allows filtering documents by title and heading (GOD-016);
- displays the packaged technician documentation version alongside the running
  build version so a technician can tell whether the two match (GOD-017).

The packaged documentation version is the Meridian version the documentation was
packaged from. It is recorded when documentation is packaged and is compared
against `config('meridian.version')` at view time.

## 22.7 Changelog

The console provides a Changelog page describing Meridian releases (GOD-018).

Entries are derived from merged pull requests and carry pull request title,
body, number, merge date, and author. Entries are grouped under the Meridian
version in which each change shipped (GOD-019), which is the root
`package.json` version defined by the versioning strategy.

Every merged pull request appears. Changelog content is not filtered by
conventional-commit type or change category (GOD-020).

### 22.7.1 Packaged baseline

A release build step generates a changelog data file from repository history and
packages it with the deployment, so the page renders completely without network
access (GOD-021). The packaged file is the baseline and is always sufficient to
render the page.

### 22.7.2 Refresh

When the central node has network access and a configured source-repository
credential, the page refreshes from the source repository and merges newer
entries into the packaged baseline (GOD-022).

Refresh is never required for the page to render. Absent network, missing
credential, or a failed refresh degrades to the packaged baseline and displays
the time of the last successful refresh (GOD-023).

Only the central node performs refresh. On-site, standalone, and development
nodes render the packaged baseline (GOD-024).

Refresh does not block page rendering and is not performed during the active
event window (GOD-025), consistent with the event-window governance rules in
section 10.2.

Source-repository credentials are stored through the existing configuration
mechanism described in section 22.4, are read-only in scope, and are never
displayed in the console or written to logs (GOD-026).

## 22.8 Console external links and version display

Documentation and Changelog are console pages within Meridian. They are not
external links and do not open an external browser context (GOD-027).

Version information displayed in God Mode navigation is the Meridian build
version from section 26.3, not the administrative framework version (GOD-028).

---

# 22A. System Configuration and Diagnostics

## 22A.1 Purpose

Two God Mode console pages give a technician the truth about a Meridian node
(SYS-001 through SYS-041):

- **System Configuration** answers: which environment variables exist, what is
  each one's effective value, where did it come from, is a database override
  active, is overriding it safe, and what has to restart before a change is
  live.
- **System Diagnostics** answers: is this installation operating correctly -
  services, storage, queues, scheduler, sync, node identity, integrations, and
  security warnings.

The two stay separate. Diagnostics never displays configuration values
(SYS-028); node identity administration stays on Node Configuration (section
22.4), which the configuration catalogue links to rather than duplicates.

## 22A.2 Configuration catalogue

`apps/server/.env.example` is the catalogue (SYS-001). Structured metadata
lives in `@tag` comments directly above each variable:

```text
# @label Human label
# @type boolean|integer|float|string|json|url|duration|enum:a|b|c
# @config dotted.laravel.key[,second.key]
# @secret
# @required
# @bootstrap
# @managed <surface>
# @readonly
# @restart workers|deploy
```

`## Heading` lines open catalogue sections. The parsed catalogue is cached
keyed by build version and file mtime/size (SYS-023). A variable with no
`@config` mapping is unmapped and read-only - the system never guesses where a
value would land (SYS-004).

## 22A.3 Override storage

`system_config_overrides` stores node-scoped overrides: one row per node and
variable, carrying the declared type, a JSON-encoded non-secret value or an
encrypted secret value, an active flag, a change reason, and creator/updater
references. Overrides are deployment infrastructure: they are never replicated
through PowerSync, never carried by node-to-node sync, and never copied from
central to on-site nodes (SYS-011, SYS-012). The existing
`node_config_values` store remains the write path for node identity and
pairing state; the catalogue marks those variables managed and read-only here
(SYS-020).

## 22A.4 Activation classes

Every variable carries an activation class (SYS-008):

- **bootstrap** - needed before overrides can load (application key, primary
  database credentials, cache/session bootstrap stores, node signing key).
  Visible, never overridable (SYS-010).
- **request** - applied at boot; effective for new web requests and newly
  booted CLI/scheduler processes immediately, and for long-running queue
  workers after a worker restart.
- **workers** - consumed primarily by long-running workers; a worker restart
  is required before the change is fully active.
- **deploy** - consumed outside the PHP process; requires service restart or
  redeployment and is never applied to the runtime repository.

The configuration screen shows the stored override, the effective value in the
running process, and a pending-activation flag whenever the two differ.

## 22A.5 Value typing and secrets

Values are validated against the declared type on save and again at load
(SYS-006). Non-secret values are stored JSON-encoded so `""`, `null`, `false`,
`0`, and `"0"` survive storage distinctly (SYS-007). Secrets are stored only
in an encrypted column, masked on every surface, and replaceable but never
readable back (SYS-013, SYS-014). Secret changes require a change reason and
the separate secret-configuration permission (SYS-015, SYS-026).

## 22A.6 Boot application and precedence

A boot-time applier loads valid, active overrides for the local active node
and writes them into Laravel's configuration repository, giving the effective
precedence: database override, then process environment / `.env`, then Laravel
default (SYS-005). It runs once per boot, works over a cached configuration,
skips invalid rows with a sanitized log line, and fails soft when the table is
unreadable: the node continues on environment configuration and diagnostics
reports the failure as critical (SYS-022). Application code reads `config()`
everywhere; nothing performs per-setting database lookups.

## 22A.7 Configuration screen

`System Configuration` lists the catalogue with search and filters (SYS-016
through SYS-018) and truthful source badges: Database Override, Environment /
`.env` (the two cannot be reliably distinguished, so they are not), Laravel
Default, Missing, Invalid, Unmapped (SYS-017). The per-variable edit screen
shows the catalogue metadata, activation warnings, the override form for
editable variables, and the redacted audit history for holders of the audit
permission (SYS-019 through SYS-021).

## 22A.8 Diagnostics framework

Diagnostics are independent checks behind one contract (`DiagnosticCheck`:
key, label, category, required flag, run) returning healthy, warning,
critical, unknown, or not-applicable results with sanitized details and a
recommended action (SYS-029, SYS-030). The runner isolates failures, records
durations, and derives overall health consistently: required critical makes
the node critical; optional critical, any warning, or an unrunnable required
check degrades to warning; not-applicable is ignored (SYS-031). Checks are
read-only and non-destructive, clean up probe files, and report unknown
instead of pretending (SYS-032). Registered categories: application, security,
configuration overrides, database, cache, queue and scheduler heartbeat,
storage, wiring, PowerSync, node sync, node identity, integrations (SYS-033).
A scheduler-written heartbeat makes scheduler liveness measurable; queue
checks never claim worker liveness from a reachable connection.

## 22A.9 Diagnostics screen

`System Diagnostics` shows overall health, per-status counts, and check cards
with filters by status, category, and required/optional. Checks run at page
load and on explicit refresh; there is no background polling. An intentionally
offline on-site node holding a sync backlog is presented as expected offline
operation, not failure (SYS-036).

## 22A.10 Sanitized export

Authorized users export a JSON bundle: build/version info, node identity,
check results, and per-variable configuration metadata restricted to source
and status - never values, and nothing at all about secret contents (SYS-034,
SYS-035). Automated tests seed known secrets and assert the export never
contains them.

## 22A.11 Node health reports

Every ten minutes each node builds a sanitized health summary from its
diagnostics run, stores it locally, and - when paired - delivers it to central
signed with the node private key (`meridian.node-health-report.v1` canonical
payload). Central verifies origin, pairing, freshness (the node sync replay
window), and signature against the pairing public key; anything unverified is
refused and audited, mirroring the sync exchange (SYS-037, SYS-038). Reports
carry a whitelist only: identity, versions, statuses, numeric sync/disk/memory
summaries, and warning lines (SYS-039). The Node Health screen shows the
latest report per node with staleness labelling (SYS-040) and works offline
from the node's own local report.

## 22A.12 CLI

```text
meridian:diagnostics [--json]   # exits non-zero on required critical (SYS-041)
meridian:config:list [--json]   # catalogue with sources; secrets masked
meridian:config:validate        # exits non-zero on invalid overrides/missing required
meridian:health-report          # build/store/deliver this node's health report
```

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
- Dangerous God Mode actions.
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

Field reports preserve immutable original title and body and append-only additions.

Name Reference extraction, clicking, and searching do not require Name Reference-specific audit events. Existing read/view/search audit behavior applies where the relevant source record or surface already requires it.

---

# 24. Forms and Configuration

## 24.1 Alpha 1 fixed forms

Field report form fields are fixed for Alpha 1.

Incident form fields are fixed for Alpha 1.

Field report title is a required single-line text field.

Field report body is a single unstructured text area.

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

Electron provides the Meridian Kiosk on-site command-center shell.

It wraps the packaged Kiosk build of the shared Vue client.

It does not own server process management in Alpha 1.

## 25.2 Distribution

Electron should be distributed as an installable app for the on-site laptop.

It should default to fullscreen/kiosk presentation.

It should hide browser chrome and navigation.

It should auto-reopen/recover if the local UI crashes.

It does not need to prevent accidental close in Alpha 1.

Electron serves `apps/client/dist/kiosk` through its local static server by
default. It may override the local server/API URL and health URL for
environment-specific connectivity, but it must not expose a generic
`APP_URL`-style override that swaps Meridian Kiosk for an externally served
client.

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
- UI mode.
- Deployment target.
- Client bundle version.
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
Shared Vue client
Capacitor mobile wrapper
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
The Briefing hub
immutable Notes (create/list/detail; author+Command visibility)
Command add-note-to-briefing (reference or link)
Briefing hub display of added Notes to approved event staff
Briefing hub shells for AAR, Directions, Action Plan, and Notices
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
14. Department Logistics can mark staff on-site, check staff in/out, and mark no-show.
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
29. An authorized lead or IC user can create an immutable Note; the author and Command can read it; other event staff cannot until Command adds it to The Briefing; edit/append is rejected.
30. Command can add a Note to The Briefing by reference or link with event-staff or department-leads-only audience; permitted viewers then see it in the hub; hub shells remain for AAR, Directions, Action Plan, and Notices.

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
full Submission/Final AAR workflows
Directions deep-link authoring and targeting UI
Action Plan banners and Notice alert delivery
editable or appendable Notes
broad event-staff visibility of Notes not added to The Briefing
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
28. The Briefing hub, immutable Notes, and Command add-to-Briefing (Alpha slice)
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
19. Exact Markdown sanitizer/renderer libraries for Laravel and the shared Vue client.
20. Exact custom fragment token grammar and editor UI.
21. Exact snapshot strategy for acknowledged policy/procedure versions.
22. Exact background job behavior for fragment-driven document version bumps.
23. Exact acknowledgement flow placement in signup and training screens.
24. Post-Alpha 1 multi-on-site-node architecture.
25. Post-Alpha 1 backups and restore workflows.
26. Exact effective-permission-level role codes for the Placement department (mint placement-specific codes mirroring IC roles, or reuse `department_lead` plus map-management grants). Resolved map behavior: Placement leads may publish/archive maps; non-lead Placement members get view by default and edit only via map-management grant; locked-map overrides are organizer/admin-only.
27. Exact map asset/package storage, tiling, and topographic basemap package format.
28. Exact GeoJSON/geometry storage representation and local-coordinate encoding for placement maps.
29. Exact Action Plan banner screen-ID allowlist and banner component placement rules.
30. Exact Direction deep-link entity allowlist and resolver UX.
31. Exact Final AAR auto-assembly merge formatting from Submission AARs.
32. Whether offline Note create / add-to-Briefing is required post–Alpha 1.
33. Exact calculation for the extended shift presence Insight Metric: grace period, approved-extension behavior, deployment behavior, and breakdown. Designed with the metric.
34. Whether the equipment domain needs states the equipment-not-returned metric implies but does not model — overdue, lost, and unknown status are named as presentation distinctions but only `available`, `checked_out`, `returned`, `missing`, and `damaged` exist.
35. Whether event-assigned equipment needs an explicit assignment scope. The equipment-not-returned metric distinguishes shift-assigned from event-assigned equipment, and the current model does not record which a checkout is.
36. Whether any Insight Metric proves expensive enough to justify a compiled-result cache, and if so its invalidation rules.

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

## The Briefing

An event-scoped Incident Command hub surface aggregating Command-added Notes, After Action Reports, Directions, Action Plans, and Notices for cross-department Command communication.

## Note

A standalone immutable event-scoped Markdown record created by department leads, team leads, or IC. Readable by author and Command until Command adds it to The Briefing. Briefing/AAR may include a Note by reference (Command summary + view original) or link (verbatim with author credit).

## After Action Report

A versioned ICS-sectioned debrief document. Submission AARs are lead-authored; the Final AAR is IC-published or auto-assembled at day 45.

## Direction

An IC-authored Briefing instruction that may deep-link into Meridian entities and target departments or teams, without page banners.

## Action Plan

An IC-authored event plan with optional department/team sections that may banner on allowlisted surfaces during the active event window.

## Notice

A short Briefing alert that may be manual or spawned from Action Plan updates, shown in the hub and as dismissible per-user alerts.

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

## Insight Metric

A reusable, developer-defined unit of compiled operational information, registered as data. It carries values, a state, a presentation, and an optional link to the surface where an authorized user can act. It compiles current authorized data and stores no result.

## Insight Sheet

An organization-owned page holding an ordered set of Insight Metric placements, read against one event at a time and rendered according to the viewer's authorization and department context.

## Metric placement

One appearance of a registered Insight Metric on a sheet, carrying its own configuration. The same metric type may be placed on many sheets and more than once on one sheet.

## Report

A fixed, formal, historical, or submitted output. Distinct from an Insight. A PDF taken from an Insight Sheet is a transient snapshot, not a Report.
