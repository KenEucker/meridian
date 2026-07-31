# QA-ORG-03: Organizer Staff Intake and Lead Selection

## Purpose

Verify that organizers can add or invite staff, assign an initial department without using the public application path, and select/remove department leads from normal Meridian Admin.

## Requirements covered

- `ORG-001`, `ORG-015`, `ORG-016`
- `VOL-001` through `VOL-006`
- `TEAM-009`
- `CLIENT-023`: administration surfaces act on server data, not bundled fixtures.
- Requirements sections 3.1, 3.3, and 5.1 through 5.4
- Technical spec sections 15.1, 15.2, and 22.1
- Data/API spec sections 5.1, 5.2, 10.4, 10.6, and 10.7
- Data/API spec section 7.2: organization administration is not offline-writable work
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
- At least one archived department, to confirm intake only offers active ones
- Staff profile to add, with legal name and email
- Existing staff profile to select as department lead
- At least one staff member added directly on the server (seed, tinker, or Orchid) that the client has never been told about, to confirm the roster is the node's

## Steps

1. Sign in to Meridian Admin as the organizer.
2. Open Home and choose **Staff**, or navigate to `/organizer/staff`.
3. Confirm the roster loads for the organization and shows the server's staff, including the member created outside the client.
4. Confirm the lede above the roster names the organization the department switcher is currently in.
5. Open the initial-department selector and confirm it offers the organization's active departments and not the archived one.
6. Add a new staff member with legal name, email, invite enabled, and an initial department.
7. Confirm the new staff member appears in the staff list with invited state and the selected department, and that the server has the record.
8. Confirm the API created a staff profile, an organization status, a linked user when invite was enabled, a department membership, and a default-team membership.
9. Add a second staff member with no initial department and confirm the record is created without a department assignment.
10. Attempt to add the same staff member again and confirm the duplicate is refused with the server's message rather than a generic one.
11. Select an existing staff member as a lead for the department.
12. Confirm the staff row shows the department lead state.
13. Confirm the API created or reused a department lead team, granted `department_lead` to that team, and added the selected staff member to it.
14. Remove the department lead selection.
15. Confirm lead authority is removed by archiving the lead team membership, while the staff member remains in the department/default team.
16. Stop the node (or disconnect the device) and reload `/organizer/staff`, then attempt to add a staff member.
17. Restart the node and reload to confirm the surface recovers.
18. If the organizer holds standing in more than one organization, switch departments to one in the other organization and confirm the roster changes to that organization's staff.
19. Sign in as a non-organizer and attempt to open `/organizer/staff` and call the staff/lead commands.

## Expected results

- Every staff member shown is one the server holds for this organization, and a member created outside the client appears on the next load.
- The organization acted on is the one the selected department belongs to; switching to a department in another organization changes the roster.
- The initial-department selector offers the organization's active departments as the server reports them.
- Organizer can add/invite staff without submitting a public event application.
- Staff records preserve legal name and email; invite links a user login record.
- Adding a staff member with no initial department succeeds and creates no department assignment.
- Initial department assignment uses the department default team and preserves the invariant that department staff have at least one active team membership.
- Department lead selection uses team membership plus `team_grants`; no free-floating permission is created.
- Removing a department lead preserves history and does not remove ordinary department membership.
- After every write the roster is re-read from the server rather than patched in place.
- A refusal from the server is shown as the server worded it, and the surface does not act as though the change succeeded.
- With the node unreachable the surface reports that it could not be read and offers a retry path; it does not show an empty roster, and it does not queue the write for later (data/API 7.2).
- Organizer staff management does not grant IMS or Field Report visibility.
- Non-organizers receive restricted UI and/or HTTP 403, and the restricted screen does not call the endpoints.
- Successful actions write audit events for staff intake and department lead selection/removal.

## Evidence to capture

- Screenshot of `/organizer/staff` showing the added staff alongside the member created outside the client.
- Screenshot of the intake form showing the active-departments selector.
- Screenshot showing department lead selection and removal.
- Screenshot of the refusal on a duplicate staff member showing the server's message.
- Screenshot of the surface with the node unreachable.
- API or audit query output for `staff.created`, `department_lead.selected`, and `department_lead.removed`.
- Notes showing the non-organizer restricted state.

## Failure notes

- If the roster shows staff the server does not hold, or omits ones it does, stop testing and file a blocking CLIENT-023 issue.
- If the surface shows an empty roster rather than an error while the node is unreachable, file a CLIENT-023 issue: an organization with no staff and one that was not read must not look the same.
- If a write appears to succeed while the node is unreachable, stop testing and file an offline-scope issue against data/API 7.2.
- If staff intake is only possible through the public application path, file a blocking M11.14 issue.
- If the initial-department selector offers archived departments, file a CLIENT-023 issue.
- If department lead authority is granted directly to a user instead of through a team grant, file a TEAM-009 regression.
- If selecting a department lead grants incident or Field Report access by itself, file an ORG-015 permission issue.
- If adding staff to a department creates a department membership without an active team membership, file a VOL-006 regression.
- If organizer authority from another organization can mutate this organization's staff, stop testing and file a scoping issue.
