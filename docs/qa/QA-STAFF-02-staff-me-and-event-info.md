# QA-STAFF-02: Staff Me Routing, Team Overview, and Event Information

## Purpose

Verify that Staff Me routes an ongoing event by role, that team leads land on a team-scoped Team Overview, and that Event Info renders the published policy/procedure documents the signed-in staff member is permitted to see for directions, arrival requirements, packing, food, housing, and event requirements, with explicit empty sections instead of placeholder prose.

## Requirements covered

- `POL-003`, `POL-006`, `POL-008` through `POL-012`: document scope, published visibility, and lead/organizer visibility.
- `POL-022`, `POL-040`, `POL-041`: rendered fragment text inline and latest fragment content.
- `TEAM-008` through `TEAM-010`: team lead designation and team-scoped staff visibility.
- Requirements section 5.7 Shift Operations: the staff member arriving for event work.
- Technical spec section 21.3: document states, scope, and visibility.
- Data/API spec section 11.4A: Event Info section selection and assembly rules.
- Data/API spec sections 5.1 and 5.2: `GET /api/events/{event}/info` and the `event_info_section` command field.
- UI implementation contract sections 7.0 (primary navigation menus), 11.5A (StaffPageShell and staff card lists), 12.3 (`staff.me`, `event.info`), and 12.4 (`team.overview`).
- Accessibility checklist sections 8, 9, 14, and 18.

## Environment

- Development server environment with migrated database
- Shared Meridian client (`apps/client`)
- Authenticated staff users with the roles listed under Personas
- For API checks, authenticated requests against `/api/events/{event}/info` and `/api/commands/create-*-document`, `/api/commands/update-*-document`, `/api/commands/publish-*-document`

## Personas

- Department lead for the test department
- Team lead for one non-default team, without department lead authority
- Staff member with no lead authority, in a different department
- Staff member with no standing in the event's organization

## Setup data

- One event in an organization with at least two departments and, in the test department, a default team plus one non-default team
- One organization-scoped published policy assigned to the `directions` section, for example `Getting To Signal Camp`
- One organization-scoped published procedure assigned to the `food` section
- One department-scoped published procedure assigned to the `packing` section for the test department
- One team-scoped published procedure assigned to the `housing` section for the non-default team
- One department-scoped **draft** policy assigned to the `arrival` section, left unpublished
- No document assigned to the `requirements` section

## Steps

1. Sign in as the department lead and open **Me** (`/staff/me`).
2. Confirm the event card states which surface it opens, then activate it.
3. Sign out and sign in as the team lead. Open **Me**, confirm the stated destination, and activate the event card.
4. On Team Overview, review the team shifts, the team roster with current attendance, and the drill-through links.
5. Edit the URL to a team in the same department that this persona does not lead, and reload.
6. Sign out and sign in as the staff member with no lead authority. Open **Me** and activate the event card.
7. On Event Info, read every section in order: directions, arrival requirements, what to bring, food, housing, event requirements.
8. Compare the packing and housing sections against the same page signed in as the department lead and as the team lead.
9. As the department lead, open the department document library, edit the `packing` procedure, change its Event Info section to **Not shown on Event Info**, and save.
10. Reload Event Info as a department member and confirm the packing section.
11. Restore the packing assignment, then publish the draft `arrival` policy and reload Event Info.
12. Sign out and, as the staff member with no standing in the event's organization, request `GET /api/events/{event}/info`.
13. On a phone or a 375px-wide viewport, sign in as the staff member with no lead authority and open the shell menu, then Documents, Shifts, Trainings, My Field Reports, and Event Info in turn.
14. Repeat step 13 as the department lead, comparing the same Documents, Shifts, and Trainings pages.

## Expected results

- Staff Me routes the department lead to Department Overview, the team lead to Team Overview for a team they lead, and the staff member without lead authority to Event Info. The card names the destination before it is activated.
- Team Overview shows only the selected team's shifts and roster, with staffing counts and current attendance; a department lead can switch between the department's teams, and a team lead sees only teams they lead.
- Requesting a team the persona may not open fails closed with the restricted message. It does not redirect to a team they may open, and no other team's roster is shown.
- Event Info renders the published document content itself, not a summary or a link-only list. Each rendered document shows its type, scope, and version.
- Sections are shown in the documented order, and within a section documents are ordered organization scope first, then department, then team, then by title.
- The `requirements` section states that no published document covers it yet. No section shows placeholder prose that reads like guidance.
- The `arrival` section stays empty while its document is a draft, including for the department lead who maintains it. It fills in after publish.
- The team-scoped `housing` document is visible to members of that team and absent for staff outside it; the department-scoped `packing` document is visible to department members and absent for staff in other departments.
- Clearing the Event Info section removes the document from Event Info while leaving it published and visible in the document library, and does not change the document version.
- The staff member with no standing in the event's organization receives HTTP 403 from `/api/events/{event}/info`.
- Every persona sees a single **Menu** rather than separate Staff and Workflows menus, listing Me and Event Info first, then the workflows they can reach. No Alpha 1 role reaches the ten-item split threshold: the department lead, the fullest role, reaches nine.
- Documents, Shifts, Trainings, My Field Reports, and Event Info render for the non-lead as a single narrow column of labelled cards. Nothing scrolls sideways at 375px, and every action, link, and disclosure control is at least 44px tall.
- The same Documents, Shifts, and Trainings pages render for the department lead as the wide workflow shell with the management table and its create/edit actions intact.

## Evidence to capture

- Screenshots of Staff Me for all three lead/staff personas showing the stated destination.
- Screenshot of Team Overview showing team shifts, roster, and attendance.
- Screenshot or notes of the failed-closed Team Overview for a team the persona does not lead.
- Screenshot of Event Info showing at least one filled section and one explicitly empty section.
- Screenshot of the document editor showing the Event Info section selector and the visibility panel.
- API response or notes for `GET /api/events/{event}/info` before and after publishing the `arrival` document.
- API or notes showing HTTP 403 for the staff member outside the organization.
- 375px-wide screenshots of the shell menu, one reader page, and the matching lead page.

## Failure notes

- If Event Info shows placeholder or sample text in place of a published document, stop testing and file a blocking M11.20 issue; staff cannot distinguish placeholder guidance from real guidance.
- If a draft or archived document appears on Event Info for anyone, stop testing and file a document-visibility issue.
- If a section shows a document scoped to a department or team the signed-in staff member does not belong to, stop testing and file a blocking visibility issue.
- If Team Overview renders a team the persona does not lead, or silently substitutes a team they do, stop testing and file a blocking authorization issue.
- If Staff Me routes a team lead to the Admin page or to Event Info instead of Team Overview, file a routing issue against UI contract section 12.3.
- If changing the Event Info assignment bumps the document version, file an issue against data/API spec section 11.4A; placement is not content.
- If a reader page scrolls sideways at 375px, or renders the lead table with columns hidden, file an issue against UI contract section 11.5A.
- If a lead loses the management table, its create/edit actions, or the wide shell on Documents, Shifts, or Trainings, stop testing and file a regression: the reader template must never replace a lead workspace.
