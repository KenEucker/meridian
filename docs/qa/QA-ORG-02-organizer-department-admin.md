# QA-ORG-02: Organizer Department Administration

## Purpose

Verify that an organizer can create, edit, archive, restore, and list organization departments from the normal Meridian Admin product UI and API path, without relying on Orchid/God Mode.

## Requirements covered

- `ORG-002`: Organizations define departments.
- Technical spec section 15.2: organizer/lead_organizer organization scoping through the Organizers Department.
- Technical spec section 22.1: Meridian Admin is the product admin surface; Orchid is God Mode/repair only.
- Data/API spec section 10.6: `departments`
- Data/API spec sections 5.1 and 5.2: organization department list reads and department create/update/archive/restore commands
- UI implementation contract section 12.6: `organizer.departments`
- Accessibility checklist sections 8, 9, 14, and 18

## Environment

- Development server environment with migrated database
- Shared Meridian Admin client (`apps/client` admin mode)
- Authenticated organizer or lead organizer whose effective role is granted through a team in the organization's configured Organizers Department
- Permission catalog includes `organization.departments.manage` for organizer and lead_organizer roles
- For API checks, authenticated requests against `/api/organizations/{organization}/departments` and `/api/commands/*-department`

## Personas

- Organizer (or lead organizer) with `organization.departments.manage`
- Staff user without organizer authority

## Setup data

- Organization with a configured Organizers Department and an organizer team grant for the test organizer
- Use a department name such as `Rangers QA`
- Use a department code such as `RANGERS_QA`
- Use a description such as `Field operations and volunteer support.`

## Steps

1. Sign in to Meridian Admin as the organizer.
2. Open the home surface and choose **Departments** under Organizer administration, or navigate to `/organizer/departments`.
3. Confirm the Departments list loads for the organization and shows active departments.
4. Choose **Create department**.
5. Enter the setup data values and save.
6. Confirm the new department appears in the list with status Active.
7. Open the saved department and change the name to `Rangers QA Updated`.
8. Save the department and confirm the list shows the updated name.
9. Archive the department from the list or edit screen.
10. Filter or confirm the archived department remains visible and is marked Archived.
11. Restore the archived department and confirm it returns to Active.
12. Attempt to create a second department with the same code `RANGERS_QA` and confirm the duplicate is rejected.
13. Sign out, then sign in as a staff user without organizer authority.
14. Attempt to open `/organizer/departments` and attempt create/archive API commands for the organization.

## Expected results

- Organizer can list organization departments, including archived records.
- Create accepts name, code, and optional description; a default team is created automatically for the new department.
- Edit updates identity fields without deleting history.
- Archive sets `archived_at`; restore clears it. No hard-delete action is available.
- Duplicate codes within the same organization are rejected.
- Successful create/update/archive/restore actions produce audit events (`department.created`, `department.updated`, `department.archived`, `department.restored`).
- Staff without organizer authority cannot manage departments (restricted UI and/or HTTP 403).
- The workflow completes in Meridian Admin without opening Orchid.

## Evidence to capture

- Screenshot of `/organizer/departments` showing the created department.
- Screenshot of the create/edit form with Save and Archive/Restore actions.
- Screenshot or notes showing archived then restored status.
- API or audit query/tinker output for `department.created` / `department.archived` / `department.restored`.
- Screenshot or notes showing denied access for the non-organizer user.

## Failure notes

- If department administration is only available in Orchid, stop testing and file a blocking M11.12 product-path issue.
- If create does not attach a default team, stop testing and file a TEAM-002 regression.
- If archive hard-deletes the department or removes historical visibility, stop testing and file a history-preservation issue.
- If a non-organizer can create or archive departments, stop testing and file a blocking permission issue.
- If organizer authority from another organization can mutate this organization's departments, stop testing and file a scoping issue.
