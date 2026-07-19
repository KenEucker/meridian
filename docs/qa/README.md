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

## Alpha 1 Field Report script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-FR-01-offline-field-report.md`](QA-FR-01-offline-field-report.md) | Offline Field Report submit with title/photos, reconnect/FRA, immutability, IC visibility, photo upload pending state, Name References, and `ic_lead`-only photo download | M9.9 |

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
