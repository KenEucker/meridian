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
| [`QA-GOD-01-console-orientation-docs-changelog.md`](QA-GOD-01-console-orientation-docs-changelog.md) | Meridian orientation summary and the God-Mode-is-repair-tooling boundary replacing framework welcome content; the read-only attention list across configuration readiness, organizational data gaps, and unresolved sync conflicts, with resolve links and an explicit all-clear; the in-console Documentation page serving only `docs/operator/` offline with title/heading filtering and documentation-versus-build version; the version-grouped, unfiltered Changelog rendering offline from the packaged baseline; central-node-only refresh with degradation, event-window skip, and credential redaction; removal of external framework documentation/changelog links and the framework version badge | M15B.12 |

| [`QA-GOD-02-console-visual-identity.md`](QA-GOD-02-console-visual-identity.md) | Meridian palette, typography, and spacing resolved from the shared design tokens in place of framework defaults; the Meridian logo in expanded and collapsed navigation and the Meridian favicon across console, authentication, and setup; a footer stating the repository's actual license, a 2026-to-present copyright range, and the Meridian build version with no framework license, version, credit, or link left anywhere; login, magic-link, sign-out, and node first-run setup brought onto the same identity; an active organization branding profile leaving the console unchanged; contrast and focus visibility across tables, forms, badges, and disabled states in both themes; the vendor view override inventory | M15C.10 |

The two God Mode scripts split by *content* and *appearance*: `QA-GOD-01`
covers what the console says, `QA-GOD-02` covers how it looks.

## Alpha 1 Briefing script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-BRF-01-briefing-notes-hub.md`](QA-BRF-01-briefing-notes-hub.md) | Notes create with author+Command visibility, Command add-to-Briefing (reference/link), hub display, shells, Orchid Note scaffold | M15.10 |

## Alpha 1 Field Report script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-FR-01-offline-field-report.md`](QA-FR-01-offline-field-report.md) | Offline Field Report submit with title/photos, reconnect/FRA, immutability, IC visibility, photo upload pending state, Name References, and `ic_lead`-only photo download | M9.9 |

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

## Alpha 1 God Mode import script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-IMPORT-01-users-teams-import.md`](QA-IMPORT-01-users-teams-import.md) | Orchid / God Mode CSV import for users, teams, shifts, and assignments: upload or paste, preview that writes nothing, per-row created/updated/skipped outcomes, match-on-re-run instead of duplicate, whole-file rejection for a missing required column, event-timezone shift times, preserved shift requirements, lead-equivalent assignment eligibility, no permissions or deletions from a file, and audit history | M13.7, M13.8 |

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
| [`QA-SLB-01-checkin-checkout-hours.md`](QA-SLB-01-checkin-checkout-hours.md) | Staff-mediated on-site/check-in/check-out, actual hours creation, no-show, offline queued attendance writes, hours correction, freeze, audit/history, and self-service non-goals | M10.11 |

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
