# QA-TEAM-02: Department Self-Administration

## Purpose

Verify that a department lead or department administration user can maintain permitted department details and manage teams (including default-team rename and non-default archive/restore) from the normal Meridian Admin product UI and API path, without relying on Orchid/God Mode.

## Requirements covered

- `TEAM-001` through `TEAM-006`: Teams replace roles; default team; default rename; persistence across events; archive; archived visibility.
- `TEAM-008`: Department leads may assign staff with active department membership to teams they lead.
- `TEAM-009`: Team membership may grant system authority (`department.administer` via department-scoped grants; team-scoped lead visibility through `shift_lead`).
- `CLIENT-023`: administration surfaces act on server data, not bundled fixtures.
- Technical spec section 15.2: department-scoped `department_lead` and `department_administration`; team-scoped `shift_lead`.
- Technical spec section 22.1: Meridian Admin is the product admin surface; Orchid is God Mode/repair only.
- Data/API spec section 10.6: `departments`, `teams`
- Data/API spec sections 5.1 and 5.2: department team list reads and department/team self-admin commands
- Data/API spec section 7.2: department administration is not offline-writable work
- UI implementation contract section 12.4: `department.teams`
- Accessibility checklist sections 8, 9, 14, and 18

## Environment

- Development server environment with migrated database
- Shared Meridian Admin client (`apps/client` admin mode)
- Authenticated department lead or department administration user whose effective role grants `department.administer` through a team in the target department
- Permission catalog includes `department.administer` for `department_lead` and `department_administration`
- For API checks, authenticated requests against `/api/departments/{department}/teams`, `/api/departments/{department}/teams/{team}`, and `/api/commands/*-team` plus `update-department-details`, `assign-staff-to-team`, `remove-staff-from-team`, `select-team-lead`, and `remove-team-lead`

## Personas

- Department lead (or department administration) with `department.administer` for the target department
- Team lead (`shift_lead`) for one team in the target department
- Staff user without department administer authority
- Department lead for a different department (cross-department denial)

## Setup data

- Organization with an active department such as `Rangers` (code `RANGERS`) that already has its default team
- Use a new team name such as `Operators QA`
- Use a new team code such as `OPERATORS_QA`
- Use a team description such as `Radio operators and dispatch support.`
- Use a department detail rename such as `Rangers QA Updated`
- At least one team created directly on the server (seed, tinker, or Orchid) that the client has never been told about, to confirm the list is the node's
- At least two staff members with active membership in the department, one already assigned to a team and one not

## Steps

1. Sign in to Meridian Admin as the department lead (or department administration user).
2. Open the home surface and choose **Teams** under Department operations, or navigate to `/events/{eventId}/departments/{departmentId}/teams`.
3. Confirm the Teams list loads for the department and shows the server's teams — including the one created outside the client — with the default team marked Active / Default.
4. Set the status filter to **Archived**, then **All**, then **Active**, and confirm each selection shows the teams the server holds in that state.
5. In Department details, change the department name to `Rangers QA Updated` and save.
6. Confirm the page context shows the updated department name, and that the server has the new value.
7. Choose **Create team**.
8. Enter the setup data values and save.
9. Confirm the new team appears in the list with status Active and type Team (not Default), and that the server has the record.
10. Open the default team and rename it to `Rangers Default QA`, keeping it default; save.
11. Confirm the default team remains Default and cannot be archived from the list or edit screen.
12. Archive the non-default `Operators QA` team from the list or edit screen.
13. Filter or confirm the archived team remains visible and is marked Archived.
14. Restore the archived team and confirm it returns to Active.
15. Attempt to create a second team with the same code `OPERATORS_QA` and confirm the duplicate is rejected with the server's message naming the code field.
16. In Team staff, assign the unassigned department member to `Operators QA`, then designate that member as team lead.
17. Confirm the staff table marks the member as lead, then remove the lead designation and remove the member from the team, confirming the table follows the server each time.
18. Edit a team, and while the form is open archive the same team from a second session or from Orchid. Save the form and confirm the screen reports what the server answered rather than showing a stale success.
19. Navigate directly to a team edit URL for a team belonging to another department, and for a team id that does not exist.
20. Stop the node (or disconnect the device) and reload the Teams route, then attempt an archive.
21. Restart the node and reload to confirm the surface recovers.
22. Sign out, then sign in as a team lead (`shift_lead`) for one team in the same department.
23. Open the Admin route and confirm only led team details and staff assigned to that led team are visible, with no department details form, create-team action, or peer-team staff list.
24. Open the edit URL for the team this lead does lead, and confirm the page states that the team's details are not editable without department authority rather than offering a form.
25. Attempt create/archive API commands as the team lead and confirm they are denied.
26. Sign out, then sign in as a staff user without department administer or team-lead authority.
27. Attempt to open the Teams route and attempt create/archive API commands for the department.
28. As a lead for a different department, attempt create-team / update-department-details against the first department.

## Expected results

- Every team, staff row, and department detail shown is one the server holds for this department, and a team created outside the client appears on the next load.
- Department lead / department administration can list department teams, including archived records.
- The status filter never hides a change the server made: after any archive or restore the list reflects the server's state without a manual reload.
- Department details update accepts name, code, and optional description for the administered department only.
- Create team accepts name, code, and optional description; new teams are non-default.
- Default team rename preserves `is_default` and `departments.default_team_id`.
- Archive sets `archived_at` for non-default teams only; restore clears it. No hard-delete action is available.
- Duplicate codes within the same department are rejected, and the screen shows the server's message for the failing field rather than a generic one.
- Staff assignment, removal, lead designation, and lead removal act on the server's roster for the department, and each is followed by a re-read rather than a local edit of the table.
- A refusal from the server is shown as the server worded it, and the surface does not act as though the change succeeded.
- A team in another department, or one that does not exist, reports "Team not found for this department." rather than an empty form.
- With the node unreachable the surface reports that it could not be read and offers a retry path; it does not show an empty department, and it does not queue the write for later (data/API 7.2).
- Successful update/create/archive/restore actions produce audit events (`department.updated`, `team.created`, `team.updated`, `team.archived`, `team.restored`).
- Staff without administer authority and leads of other departments cannot manage this department (restricted UI and/or HTTP 403), and the restricted screen does not call the write commands.
- Team leads can read only led teams and assigned staff through the Admin route/API and cannot mutate department details or team lifecycle; opening a led team's edit URL states that plainly instead of offering a form.
- Staff-only department members do not see an Admin entry point, and direct Admin access fails closed.
- The workflow completes in Meridian Admin without opening Orchid.

## Evidence to capture

- Screenshot of `/events/.../teams` showing department details, the created team, and the team created outside the client.
- Screenshot of the create/edit team form with Save and Archive/Restore actions.
- Screenshot or notes showing default team rename without archive control.
- Screenshot or notes showing archived then restored status.
- Screenshot of the duplicate-code refusal showing the server's field message.
- Screenshot of the team staff table before and after assigning a member and designating them lead.
- Screenshot of the surface with the node unreachable.
- Screenshot or notes for the not-found state on a foreign or unknown team id.
- API or audit query/tinker output for `department.updated` / `team.created` / `team.archived` / `team.restored` / `team_membership.assigned`.
- Screenshot or notes showing denied access for the non-admin and cross-department users, and the team lead's non-editable team edit page.

## Failure notes

- If the list shows teams or staff the server does not hold, or omits ones it does, stop testing and file a blocking CLIENT-023 issue.
- If the surface shows an empty department rather than an error while the node is unreachable, file a CLIENT-023 issue: a department with no teams and one that could not be read must not look the same.
- If a write appears to succeed while the node is unreachable, stop testing and file an offline-scope issue against data/API 7.2.
- If department/team administration is only available in Orchid, stop testing and file a blocking M11.13 product-path issue.
- If the default team can be archived from Meridian Admin, stop testing and file a TEAM-005 regression.
- If default rename clears `is_default` or `default_team_id`, stop testing and file a TEAM-003 regression.
- If archive hard-deletes the team or removes historical visibility, stop testing and file a history-preservation issue.
- If a non-admin or cross-department actor can create or archive teams, stop testing and file a blocking permission issue.
