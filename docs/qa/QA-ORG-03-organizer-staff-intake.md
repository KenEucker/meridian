# QA-ORG-03: Organizer Staff Intake and Lead Selection

## Purpose

Verify that organizers can add or invite staff, assign an initial department without using the public application path, and select/remove department leads from normal Meridian Admin.

## Requirements covered

- `ORG-001`, `ORG-015`, `ORG-016`
- `VOL-001` through `VOL-006`
- `TEAM-009`
- Requirements sections 3.1, 3.3, and 5.1 through 5.4
- Technical spec sections 15.1, 15.2, and 22.1
- Data/API spec sections 5.1, 5.2, 10.4, 10.6, and 10.7
- UI implementation contract section 12.6: `organizer.staff`, `organizer.departments`

## Environment

- Development server environment with migrated database
- Shared Meridian Admin client (`apps/client` admin mode)
- Authenticated organizer or lead organizer whose effective role is granted through a team in the configured Organizers Department
- Permission catalog includes `organization.staff.manage`

## Personas

- Organizer or lead organizer
- Staff user without organizer authority
- Existing staff member who should become a department lead

## Setup data

- Organization with configured Organizers Department
- At least one non-archived department, such as Rangers
- Staff profile to add, with legal name and email
- Existing staff profile to select as department lead

## Steps

1. Sign in to Meridian Admin as the organizer.
2. Open Home and choose **Staff**, or navigate to `/organizer/staff`.
3. Add a new staff member with legal name, email, invite enabled, and an initial department.
4. Confirm the new staff member appears in the staff list with invited state and the selected department.
5. Confirm the API created a staff profile, an organization status, a linked user when invite was enabled, a department membership, and a default-team membership.
6. Select an existing staff member as a lead for the department.
7. Confirm the staff row shows the department lead state.
8. Confirm the API created or reused a department lead team, granted `department_lead` to that team, and added the selected staff member to it.
9. Remove the department lead selection.
10. Confirm lead authority is removed by archiving the lead team membership, while the staff member remains in the department/default team.
11. Sign in as a non-organizer and attempt to open `/organizer/staff` and call the staff/lead commands.

## Expected results

- Organizer can add/invite staff without submitting a public event application.
- Staff records preserve legal name and email; invite links a user login record.
- Initial department assignment uses the department default team and preserves the invariant that department staff have at least one active team membership.
- Department lead selection uses team membership plus `team_grants`; no free-floating permission is created.
- Removing a department lead preserves history and does not remove ordinary department membership.
- Organizer staff management does not grant IMS or Field Report visibility.
- Non-organizers receive restricted UI and/or HTTP 403.
- Successful actions write audit events for staff intake and department lead selection/removal.

## Evidence to capture

- Screenshot of `/organizer/staff` with added staff.
- Screenshot showing department lead selection and removal.
- API or audit query output for `staff.created`, `department_lead.selected`, and `department_lead.removed`.
- Notes showing the non-organizer restricted state.

## Failure notes

- If staff intake is only possible through the public application path, file a blocking M11.14 issue.
- If department lead authority is granted directly to a user instead of through a team grant, file a TEAM-009 regression.
- If selecting a department lead grants incident or Field Report access by itself, file an ORG-015 permission issue.
- If adding staff to a department creates a department membership without an active team membership, file a VOL-006 regression.
