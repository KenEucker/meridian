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

## Alpha 1 IMS list/detail and timeline smoke

M11.5 adds the first restricted IMS incident list/detail read surfaces. M11.6
adds append-only operational timeline notes. M11.6A adds incident Name Reference
chips and chip-driven permission-filtered search. M11.7 adds the online-only
incident create/edit autosave surface for IC operators/leads. M11.7A adds
priority, incident types, and involved Rangers/responders to create/edit. M11.7B
adds linked incidents. M11.8 adds Field Report attach/unlink from incidents.
The full `QA-INC-01` incident-management script remains owned by M11.11.

1. Seed or create an event with a configured Incident Command department,
   an `ic_viewer`, `ic_operator`, `ic_lead`, an organizer without IC role, a
   department lead outside IC, a revoked IC grant, and at least two incidents.
2. As `ic_viewer`, open `/ims/incidents` in the shared client and confirm the
   list shows only that event's incidents with IMS number, title, state,
   priority text, location, and last update.
3. Open an incident detail and confirm the current state, event/IC department
   context, location, creator, and initial timeline entry are visible without
   Edit, Save, note, status-change, field-report-link, attachment, or PDF
   actions.
4. Repeat the detail check as `ic_operator` and `ic_lead`, add a plain-text
   operational note containing at least one `@name` marker, and confirm the new
   note appears in the timeline with the actor and timestamp.
5. As `ic_operator` or `ic_lead`, open `/ims/incidents/create`. Confirm the
   blank autosave form shows no IMS number until the first valid title autosaves,
   then assigns an IMS number and moves to the edit route.
6. Edit title, state, started timestamp, and free-text location fields on an
   open incident and on a closed incident. After M11.7A, also edit priority,
   incident types, and involved Rangers/responders. After M11.7B, link and
   unlink another same-event incident. Confirm duplicate, self, and cross-event
   links are rejected; already-linked incident candidates are hidden; candidates
   with shared `#tags` appear before location-only matches; autosave status is
   visible; failed validation is visible for a blank title; status does not
   block editing; field/link edits appear in history/audit; and incident notes
   remain append-only. After M11.8, repeat the already-linked exclusion,
   shared-tag-first, newest-added ordering check for Field Report attachment
   candidates. For both linked Incident and Field Report add controls, confirm
   search can find same-event records linked to other incidents when they are
   not already linked to the current incident. Attach one Field Report, confirm
   it appears in current state and as a copied timeline note headed
   `Field Report: <title>` with the Field Report author listed, then unlink it
   and confirm the copied note is struck while removal history remains visible.
   Confirm the Incident list links to IC Field Reports, the IC Field Reports
   list links back to Incidents, both list pages have Home links, table headings
   sort, Incident state/priority filters work, Closed incidents are filtered out
   by default but can be included, and IC Field Report state/priority filters
   follow related incidents. Confirm the IC Field Reports link-status filter can
   show linked and not-linked reports.
7. Simulate offline/no-network state on the create/edit screen and confirm
   incident mutation is blocked without queued-offline language while the typed
   form state remains on the screen.
8. Repeat create/edit as `ic_viewer`, organizer-only, department lead outside IC,
   wrong-event IC grant, revoked IC grant, unauthenticated user, and normal staff.
   Confirm no create/edit form or mutation action is exposed.
9. Confirm Name Reference chips appear near incident metadata, are visually
   distinct from any `#tag` treatment, and clicking a chip returns to the
   incident list with normal permission-filtered search results for the
   reference text without opening a Name Reference profile/detail page.
10. Confirm blank timeline notes are rejected, the original timeline entries
   remain unchanged, the incident last-updated timestamp advances after a valid
   note, and an `incident.note_appended` audit row is created.
11. Repeat as `ic_viewer` and confirm the timeline is visible but the add-note
   form/command is unavailable.
12. Repeat as organizer-only, department lead outside IC, wrong-event IC grant,
   revoked IC grant, unauthenticated user, and normal staff. Confirm no incident
   row, detail content, Name Reference chip/search result, or note mutation is
   exposed and restricted access messaging appears.
13. Request an incident detail, update, or append-note command under the wrong
   event URL and confirm the server fails closed.
14. Confirm permitted detail views create `incident.viewed` audit rows, while
   denied reads do not create incident-view audit rows.

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
