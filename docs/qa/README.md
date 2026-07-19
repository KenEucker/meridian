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
M10.9A delivers the identity-free Planning Table. The full
`QA-SLB-01-checkin-checkout-hours.md` script remains deferred to M10.11.

1. Start the shared client in development mode.
2. Open **Department overview** from the home surface.
3. Confirm compact event/department context, a switchable shift selector
   defaulting to Ranger Dirt Day Shift, and summary counts for assignments,
   checked in, on-site, and equipment out.
4. Confirm content order: exceptions first, then checked-in staff currently
   working, then full shift assignments, then compact equipment summary.
5. Switch to Ranger Dirt Swing Shift and confirm the overview updates to that
   shift without showing Logistics mutation controls.
6. Open **Logistics desk**. Confirm the search field is front and center and
   can find staff, equipment, and shifts for the current department.
7. Search for Vera Staff and open the staff workspace. Confirm presence
   controls, active/upcoming/outgoing shift context, and future signup list.
8. Mark Vera on-site if needed, then open Check in. Confirm the dialog defaults
   the timestamp to now and can hand off available equipment.
9. Confirm open equipment for a checked-in staff member can be returned as
   Returned, Missing, or Damaged with one action each.
10. Confirm provisions appear only as an extension placeholder with no fake data.
11. Open **Operations center**. Confirm the deployments module is present for
    Operations capability and can move a rostered staff member between
    deployments.
12. Confirm the incident overview module is absent unless IC capability is
    granted, and that opening Operations Center alone does not reveal incident
    content.
13. Open **Planning table**. Confirm rows are shift/team windows with capacity,
    signed-up/assigned, checked-in, no-show, unscheduled, planned hours, actual
    hours, and variance/status columns.
14. Confirm Planning Table shows no individual staff names, signup lists, or
    team-member lists.
