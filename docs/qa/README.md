# Meridian QA Docs

Human QA scenarios live in this directory and use IDs like `QA-BOOT-01`.

Every `docs/qa/QA-*.md` file must include these sections:

- Purpose
- Requirements covered
- Environment
- Personas
- Setup data
- Steps
- Expected results
- Evidence to capture
- Failure notes

Run the local process checks before opening a PR:

```bash
scripts/process/check.sh
```

On Windows, open Git Bash in the repository and run this command there so the same POSIX script is used across Windows, Linux, and macOS. Do not use Windows PowerShell, `cmd.exe`, or the WSL `bash.exe` shim for this check.

## Alpha 1 Branding script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-BRAND-01-organization-and-department-branding.md`](QA-BRAND-01-organization-and-department-branding.md) | Organization palette/display name/logo replacement of Meridian identity with login, Orchid, and desktop preserved; blocking WCAG 2.1 AA validation with no auto-repair; department logo/accent/surface bounded to department-scoped surfaces; the organization-wide override switch; lettermark fallback; central authority and active-event freeze; audit; offline rendering; state legibility | M15A.14 |

## Alpha 1 God Mode console script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-GOD-01-console-orientation-docs-changelog.md`](QA-GOD-01-console-orientation-docs-changelog.md) | Meridian orientation summary and the God-Mode-is-repair-tooling boundary replacing framework welcome content; the read-only attention list across configuration readiness, organizational data gaps, and unresolved sync conflicts, with resolve links and an explicit all-clear; the in-console Documentation page serving only `docs/technician/` offline with title/heading filtering and documentation-versus-build version; the version-grouped, unfiltered Changelog rendering offline from the packaged baseline; central-node-only refresh with degradation, event-window skip, and credential redaction; removal of external framework documentation/changelog links and the framework version badge | M15B.12 |

| [`QA-GOD-02-console-visual-identity.md`](QA-GOD-02-console-visual-identity.md) | Meridian palette, typography, and spacing resolved from the shared design tokens in place of framework defaults; the Meridian logo in expanded and collapsed navigation and the Meridian favicon across console, authentication, and setup; a footer stating the repository's actual license, a 2026-to-present copyright range, and the Meridian build version with no framework license, version, credit, or link left anywhere; login, magic-link, sign-out, and node first-run setup brought onto the same identity; an active organization branding profile leaving the console unchanged; contrast and focus visibility across tables, forms, badges, and disabled states in both themes; the vendor view override inventory | M15C.10 |

The two God Mode scripts split by *content* and *appearance*: `QA-GOD-01`
covers what the console says, `QA-GOD-02` covers how it looks.

## Alpha 1 System Configuration and Diagnostics script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-SYS-01-system-configuration-and-diagnostics.md`](QA-SYS-01-system-configuration-and-diagnostics.md) | The `.env.example`-driven configuration catalogue with truthful source badges; node-local database overrides with typed validation, activation requirements, and audited changes; bootstrap-locked and managed variables refusing overrides; secret encryption, masking, and replace-only editing; granular `platform.system.*` permission enforcement; the diagnostics screen with required/optional checks and expected-offline handling; sanitized export and CLI exit-code gating; signed node health reports on central with staleness labelling | M13A.10 |

## Alpha 1 Briefing script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-BRF-01-briefing-notes-hub.md`](QA-BRF-01-briefing-notes-hub.md) | Notes create with author+Command visibility, Command add-to-Briefing (reference/link), hub display, shells, Orchid Note scaffold | M15.10 |

## Alpha 1 Field Report script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-FR-01-offline-field-report.md`](QA-FR-01-offline-field-report.md) | Offline Field Report submit with title/photos, reconnect/FRA, immutability, IC visibility, photo upload pending state, Name References, and `ic_lead`-only photo download | M9.9 |
| [`QA-FR-02-dictated-field-reports.md`](QA-FR-02-dictated-field-reports.md) | Taking a Field Report for another staff member: who may (`ic_operator` and `ic_lead` at this milestone; the Department Operator half of FR-015 waits on M18.10A, which owns the section 4.8A Operator capability set) and who may not however senior; author and submitter recorded separately and both visible; append and photo authority staying with the author; the staff selector scoped to the department the taker's console serves, refused server-side rather than only hidden | M18.24A |

## Alpha 1 Incident management script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-INC-01-incident-management.md`](QA-INC-01-incident-management.md) | IC-only incident access, online create/edit with the full IMS current-field set, notes/strikes, Name Reference chips, linked incidents, Field Report attach/unlink, history, list/IC Field Report cross-links/filters, attachment-strike automated evidence, and IC-lead PDF print | M11.11 |
| [`QA-INC-02-incident-search-and-filters.md`](QA-INC-02-incident-search-and-filters.md) | Explicit incident list search across record/notes/attached Field Reports, state/priority/type/responder/started-window filters, operational-order heading sorts, paging, per-user saved filter presets, refused filter values, and IC-permission enforcement ahead of filtering | M11.19 |

## Alpha 1 Organizer department administration script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-ORG-02-organizer-department-admin.md`](QA-ORG-02-organizer-department-admin.md) | Organizer Meridian Admin create/edit/archive/restore/list for organization departments outside Orchid/God Mode | M11.12 |
| [`QA-ORG-03-organizer-staff-intake.md`](QA-ORG-03-organizer-staff-intake.md) | Organizer Meridian Admin add/invite staff, optional initial department assignment, and department lead selection/removal outside Orchid/God Mode | M11.14 |
| [`QA-ORG-04-context-and-organizer-surfaces.md`](QA-ORG-04-context-and-organizer-surfaces.md) | Entering a department space from the cached session, including with the device disconnected; one application read and decided on its own address, with department interest shown as interest and never edited; review authority proved to be organizer and Staff Coordinator only, with a department lead's decision refused by the node rather than by a hidden button; event administration outside the God Mode console — identity, published dates, the active event window, the duplicate-address and backwards-window refusals, and the ORG-006 Incident Command override offered only from the event's own participating departments with the ORG-005 inheritance stated; managing which departments participate in an event from the same surface, where a returning department is restored rather than assigned twice and a department holding the event's Incident Command (ORG-006) or Placement (PLACE-003) designation is refused removal in the node's own words; an event inside its active window naming the node that holds its other records while still offering the edit, because closing the window is the edit (M12.6 exempts the `events` row from event authority on purpose); audit review attributing every change with field names and no field values, and carrying no incident or Field Report history at any filter or page (ORG-015); and the God Mode audit trail beside it — node-wide, the same organization/department/team filter bar the other God Mode lists carry, the team filter narrowing by subject, the incident and node rows the product surface withholds, the recorded values on an entry, and no edit path anywhere | M18.29, M18.31, M18.34 (audit half) |

## Alpha 1 Department surfaces script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-DEPT-01-department-surfaces.md`](QA-DEPT-01-department-surfaces.md) | The department staff list reached by three standings and reaching three different amounts of the department — the whole of it for `department.administer` and `department.schedule.manage`, only the led teams for a designated team lead, a 403 for everybody else including an organizer; emergency contacts served to department leads for their own department and **absent from the payload as keys** rather than blank for everyone else (VOL-011, VOL-012); non-active memberships listed with their status; roster search and team filtering working with the device disconnected; the Planning Table still identity-free with its holder able to read the roster (SLB-019, SLB-020); deployment options created, renamed, archived, and restored at last — case-insensitive name uniqueness per event and department, an archive refused while staff are standing there with the count named and the control offered rather than disabled, a rename moving every current assignment at once, and the write refused on a node without event authority; credit review showing hours, rate, credits, policy, and policy source on every row, unmoved by renaming and re-rating the policy behind it (CREDIT-004, CREDIT-005), reporting what is worked and not yet credited, offering the department-scoped export and no way to calculate anything | M18.30 |

## Alpha 1 Kiosk surfaces script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-KIOSK-01-kiosk-surfaces.md`](QA-KIOSK-01-kiosk-surfaces.md) | The six screens in UI contract 12.8 and the rule that decides whether any of them are reachable: a workstation with no pinned organization and event puts its Kiosk in setup, and stays there with a client session naming a perfectly good event on the same machine, because UI-020 rules out inferring context from the authenticated user, cached event data, the network, the viewport, and the last route alike; the first pin made from God Mode, where a department that does not work the chosen event is refused by name, and the ordinary move made by an organizer from the Kiosk itself on `organization.events.manage`, both audited, both ending the live session because it was signed in to the previous context; the desk's shift board recording check-in, check-out, and no-show against the workstation's own scope, queuing with the node stopped and syncing once, and offering no control at all to somebody the node grants no attendance authority; a handover that states the queued count and what is abandoned before it ends the session, with code entry unreachable while one is live; and re-authentication confirming the signed-in user with a fresh login code, refusing a valid code belonging to anybody else, and handing nothing over when it does | M16.9, M18.28, M18.32 |

## Alpha 1 Command palette script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-NAV-01-command-palette.md`](QA-NAV-01-command-palette.md) | `Ctrl+K`, `Cmd+K`, and `/` only outside a field, with the slash typed as a character inside the desk's search box and a Field Report body; the palette's offer compared entry for entry against the same persona's Home, in both directions, across a department lead, a member holding no roles, and an organizer; IMS entries following Incident Command standing rather than organizer seniority; no camp and no operational map location at any query (MAP-018); the Kiosk's three states — unpinned, pinned but locked, signed in — offering nothing from a client session on the same machine, and never the session end the session bar owns; and the keyboard path from opening to dismissal with focus returned to the control that opened it | M18.33 |

## Alpha 1 Product document authoring script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-POL-02-product-document-authoring-sharing.md`](QA-POL-02-product-document-authoring-sharing.md) | Normal Meridian Admin policy/procedure/fragment authoring, preview, publish/archive, visibility review, and permitted export/share entry points outside Orchid/God Mode | M11.15 |

## Alpha 1 Product training management script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-TRAIN-01-training-management.md`](QA-TRAIN-01-training-management.md) | Department/organizer Meridian Admin training create/edit, prerequisite/expiration setup, scheduled-attendance signup/roster, trainer/lead completion recording, and completion spreadsheet import outside Orchid/God Mode | M11.16 |

## Alpha 1 Equipment inventory script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-EQUIP-01-equipment-inventory-setup.md`](QA-EQUIP-01-equipment-inventory-setup.md) | Department logistics/administration Meridian Admin equipment inventory create/edit/archive/restore and bulk CSV import before operations, feeding Logistics checkout/check-in, outside Orchid/God Mode | M11.18 |
| [`QA-EQUIP-02-pooled-tracked-equipment-and-lookup.md`](QA-EQUIP-02-pooled-tracked-equipment-and-lookup.md) | The tracking kind in inventory setup and import with pooled rows matched by name on a re-run; the two checkout presentations replacing the unit-by-unit checkbox list; scanner-driven lookup with ambiguous, unmatched, and out-of-scope values reported identically; shift-versus-event assignment scope with overdue and unknown derived rather than stored; partial pooled returns and audited write-offs; lookup against cached inventory with no node | M18.24, M18.24B, M18.24C, M18.24D |

## Alpha 1 God Mode import script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-IMPORT-01-users-teams-import.md`](QA-IMPORT-01-users-teams-import.md) | Orchid / God Mode CSV import for users, teams, shifts, and assignments: upload or paste, preview that writes nothing, per-row created/updated/skipped outcomes, match-on-re-run instead of duplicate, whole-file rejection for a missing required column, event-timezone shift times, preserved shift requirements, lead-equivalent assignment eligibility, no permissions or deletions from a file, and audit history | M13.7, M13.8 |

## Alpha 1 Reporting export script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-EXPORT-01-alpha-1-exports.md`](QA-EXPORT-01-alpha-1-exports.md) | All five Alpha 1 exports in one sitting — credential eligibility, shift roster, staff contacts, hours worked, and credits earned: organizer event-wide and department-role department scope, narrowing that cannot widen, refusals for incident-only and unroled actors, the documented column set of each file, phone/emergency-contact/date-of-birth exclusions, emergency contacts only for a caller who leads every exported department, a frozen credits basis that a later policy rename does not restate, and one audit entry per export | M13.9 |

The individual Milestone 13 scripts verify one export beside the domain that
produces it (`QA-CRED-01` section G, `QA-SHIFT-01`, `QA-STAFF-01`, `QA-SLB-01`
section H); `QA-EXPORT-01` is the consolidated script that answers the milestone
QA gate.

## Alpha 1 Applicant portal script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-APPLY-02-applicant-portal.md`](QA-APPLY-02-applicant-portal.md) | Applicant self-service for somebody holding no account: a signed link requested from both public application surfaces and mailed only where there is something to show, an identical response for known and unknown addresses, a tampered or expired link refused, the applicant's own applications with scope, submission date, and status, applicant-only withdrawal of a still-Submitted application, a Do Not Staff auto-rejection absent rather than labelled, the bounded portal session and its closing, the per-address and per-client request limits, and audit of issuance and withdrawal | M18.22 |

The review side of the same records — organizer and Staff Coordinator approval,
rejection, deferral, and rescind — stays in `QA-APPLY-01`. This script is the
applicant's half, and it is the only one where the person under test has no
Meridian account at all.

## Alpha 1 Public marketing surface script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-PUBLIC-02-marketing-surface-and-organization-interest.md`](QA-PUBLIC-02-marketing-surface-and-organization-interest.md) | The public marketing surface at the client root: Meridian identity with no organization branding profile resolved, the home directory still rendered there for a client holding a session, the organization interest form and the inquiry it creates, no organization/user/staff record created by it, God Mode review with no create-organization path, the per-address and per-client submission limits, the hidden-field and form-token traps with no challenge presented, the submission/discard/review audit trail, and the on-site and event-locked nodes that serve none of it | M18.23 |

The landing page itself — the feature tour, the Northwood screenshots, and the
three platform offerings (PUBLIC-007 through PUBLIC-009) — is Milestone 20's,
and its coverage arrives with it as `QA-PUBLIC-01`. That ID is left free here
because M20.6 names the file it belongs to; this script is numbered second
because the surface it covers was built first.

## Alpha 1 Department self-administration script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-TEAM-02-department-self-admin.md`](QA-TEAM-02-department-self-admin.md) | Department lead / department administration Meridian Admin department details and team create/edit/archive/restore (default rename; non-default archive) outside Orchid/God Mode | M11.13 |

## Alpha 1 Team and shift administration script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-TEAM-03-team-shift-administration.md`](QA-TEAM-03-team-shift-administration.md) | Department lead team-lead designation, team-lead staff assignment on led teams, and department/team lead shift create/maintain with documented eligibility and time-window rules outside Orchid/God Mode | M11.17 |

## Alpha 1 Staff Me and event information script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-STAFF-02-staff-me-and-event-info.md`](QA-STAFF-02-staff-me-and-event-info.md) | Staff Me role-aware event routing, the team-lead Team Overview handoff and its fail-closed behavior, Event Info assembled from visible published documents with explicit empty sections instead of placeholders, the combined Staff/Workflows shell menu, and the mobile-first staff page template for reader views | M11.20 |

## Alpha 1 Client session and API wiring script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-CLIENT-01-session-and-api-wiring.md`](QA-CLIENT-01-session-and-api-wiring.md) | The whole Milestone 16 client path in one sitting: a client holding nothing sent to sign in, `GET /api/me` establishing the session and carrying codes rather than navigation, five shells derived from five session responses, the server still refusing what the client hid, cached permissions bounded by the event window with reduction on refresh, boot from the cache with the node stopped, association-bounded context switching that is absent offline and under a node lock, one command outbox with client-generated idempotency keys and connected-only refusals, downloads through short-lived scoped URLs, a revoked token seen from the client, and every bound surface reading the node instead of a fixture | M16.23 |

The credential half of Milestone 16 — token issuance, device binding, God Mode
revocation, and shared-workstation login codes and sessions — stays in
`QA-AUTH-01`, which `QA-CLIENT-01` signs in through rather than repeating.
`QA-CLIENT-01` used to turn `VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION` off
first, because a populated shell proved nothing about session wiring while a
development session fixture could fill one. The flag is gone at M18.9 and a
populated shell is now a finding rather than a setup mistake.

## Alpha 1 Node sync script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-SYNC-01-onsite-central-sync.md`](QA-SYNC-01-onsite-central-sync.md) | On-site/central pairing, signed append-only node operations, outage queueing, bidirectional sync drain, active-event authority, governance freeze, conflict queue/resolution, God-mode sync health, and Electron sync-health observation | M12.11 |

M11.5 through M11.10 deliver the restricted IMS list/detail, timeline notes,
Name Reference chips, online create/edit autosave, priority/types/responders,
linked incidents, Field Report attach/unlink, attachment strike, and IC-lead
PDF print surfaces covered by `QA-INC-01`. Richer incident search/filter UI
remains with M11.19. The shared-client IMS screens still use a development
local fixture for human UI steps; multi-role HTTP/audit boundaries are retained
as automated evidence inside that script.

## Alpha 1 Department operations UX smoke

M10.1B resets the four department operations surfaces around field workflows.
M10.1 delivers Department Overview. M10.9B delivers Logistics Desk search and
staff workspace. M10.8 delivers the capability-composed Operations Center.
M10.9A delivers the identity-free Planning Table. M10.11 delivers the full
attendance, check-out, and hours QA script.

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-SLB-01-checkin-checkout-hours.md`](QA-SLB-01-checkin-checkout-hours.md) | Staff-mediated on-site/check-in/check-out, actual hours creation, no-show, offline queued attendance writes, department-scoped offline search over the desk's stored index, hours correction, freeze, audit/history, and self-service non-goals | M10.11 |

1. Start the shared client in development mode.
2. Confirm the shared shell header shows the Meridian wordmark image, with the
   UI mode shown only as Admin, Field, or Kiosk rather than repeating Meridian
   as visible text.
3. Confirm the home surface separates primary Department operations links from
   secondary supporting tools.
4. Open **Department overview** from the home surface.
5. Confirm compact event/department context, a switchable shift selector
   defaulting to Ranger Dirt Day Shift, and summary counts for assignments,
   checked in, on-site, and equipment out.
6. Confirm content order: exceptions first, then checked-in staff currently
   working, then full shift assignments, then compact equipment summary.
7. Switch to Ranger Dirt Swing Shift and confirm the overview updates to that
   shift without showing Logistics mutation controls.
8. Open **Logistics desk**. Confirm the search field is front and center and the
   page shows a department/event offline search-cache state for staff,
   equipment, and shifts.
9. Search for Ranger Dirt Swing Shift. Confirm the shift result opens a
   department-scoped search context with buttons for matching staff workspaces,
   without showing another department's staff.
10. Search for Radio 12. Confirm a checked-out equipment result opens the holder
   staff workspace, while available equipment stays a cache result until a staff
   member is selected.
11. Search for Vera Staff and open the staff workspace. Confirm presence
   controls, active/upcoming/outgoing shift context, and future signup list.
12. Mark Vera on-site if needed, then open Check in. Confirm the dialog defaults
   the timestamp to now and can hand off available equipment.
13. After Vera is checked in, open Check out equipment from the staff workspace
   and confirm multiple available radios, such as Radio 13 and Radio 14, can be
   selected and checked out together.
14. Confirm open equipment for a checked-in staff member can be returned as
   Returned, Missing, or Damaged with one action each.
15. Confirm provisions appear only as an extension placeholder with no fake data.
16. Open **Operations center**. Confirm the deployments module is present for
    Operations capability and can move a rostered staff member between
    deployments.
17. Confirm the Field Reports module and shortcuts appear only for a user who
    already has Field Report permission; Operations Center access alone must not
    reveal Field Report shortcuts.
18. Confirm the incident overview module is absent unless IC capability is
    granted, and that opening Operations Center alone does not reveal incident
    content.
19. Open **Planning table**. Confirm rows are shift/team windows with capacity,
    signed-up/assigned, checked-in, no-show, unscheduled, planned hours, actual
    hours, and variance/status columns.
20. Confirm Planning Table shows no individual staff names, signup lists, or
    team-member lists.
