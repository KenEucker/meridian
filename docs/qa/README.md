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

## Alpha 1 Department self-administration script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-TEAM-02-department-self-admin.md`](QA-TEAM-02-department-self-admin.md) | Department lead / department administration Meridian Admin department details and team create/edit/archive/restore (default rename; non-default archive) outside Orchid/God Mode | M11.13 |

## Alpha 1 Team and shift administration script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-TEAM-03-team-shift-administration.md`](QA-TEAM-03-team-shift-administration.md) | Department lead team-lead designation, team-lead staff assignment on led teams, and department/team lead shift create/maintain with documented eligibility and time-window rules outside Orchid/God Mode | M11.17 |

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
