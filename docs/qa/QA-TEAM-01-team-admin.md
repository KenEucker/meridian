# QA-TEAM-01: Team Admin Scaffold

## Purpose

Verify that an elevated Orchid user can view, create, edit, archive, and restore Meridian team records without relying on staff membership, permission grants, shifts, API, audit, or sync behavior that belongs to later Alpha 1 tasks.

## Requirements covered

- `TEAM-001`: Teams replace the earlier concept of roles.
- `TEAM-002`: Each department has a default team.
- `TEAM-003`: Departments may rename their default team.
- `TEAM-004`: Teams are persistent across events.
- `TEAM-005`: Teams may be archived.
- `TEAM-006`: Archived teams remain visible in historical records.
- Data/API spec section 10.6: `teams`
- Technical spec section 22.2: Alpha 1 Orchid screens
- UI implementation contract section 4: global UI rules
- UI implementation contract section 11.4: data table behavior
- Accessibility checklist sections 8, 9, 14, and 18

## Environment

- Development server environment
- Migrated database including `organizations`, `departments`, and `teams`
- Orchid admin route available at `/admin`
- Orchid user with `platform.index` and `platform.teams` permissions

## Personas

- Team administrator
- User without team administration permission

## Setup data

- Existing department named `Rangers`.
- Use a team name such as `Operators`.
- Use a team code such as `OPERATORS`.
- Use a team description such as `Radio operators and dispatch support.`

## Steps

1. Sign in to Orchid as the team administrator.
2. Open `/admin/teams`.
3. Confirm the Teams list loads and shows the department's automatically created default team.
4. Choose Add.
5. Select the `Rangers` department.
6. Enter the setup data values.
7. Save the team.
8. Open the saved team detail screen from the list.
9. Change the team name to `Operators QA Updated`.
10. Save the team.
11. Open the updated team detail screen again.
12. Archive the team.
13. Confirm the archived team remains visible in `/admin/teams` and is marked archived.
14. Open the archived team detail screen and restore it.
15. Sign out, then sign in as a user without `platform.teams`.
16. Attempt to open `/admin/teams`.

## Expected results

- The Teams list is available to the permitted Orchid user.
- The list shows team name, code, department, default-team state, and archived/active state.
- Add opens a create/detail scaffold with explicit Save and Cancel actions.
- Saving valid values creates the team and returns to the Teams list.
- Opening the saved team shows its existing values.
- Saving a valid edit updates the team.
- Duplicate team codes are rejected within the same department.
- The default team created with a department remains visible and cannot be archived from this screen.
- Archiving a non-default team sets archived state without deleting the row.
- Restoring an archived team returns it to active state.
- The user without `platform.teams` is denied access.
- No staff membership assignment, permission grant, shift eligibility, API, audit-service, import/export, or sync behavior appears in this scaffold.

## Evidence to capture

- Screenshot of `/admin/teams` showing the default team and saved team.
- Screenshot of the team detail screen showing Save and Cancel.
- Screenshot of an archived team still visible in the Teams list.
- Screenshot or notes showing the denied access result for the user without permission.

## Failure notes

- If a user without `platform.teams` can access the screen, stop testing and file a blocking permission issue.
- If team creation requires staff membership, shifts, permission grants, API, audit, or sync setup, stop testing and file a scope issue against M4.4.
- If archiving deletes the team row or hides it from historical/admin visibility, stop testing and file a history-preservation issue.
