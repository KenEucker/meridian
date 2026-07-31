# QA-ORG-02: Organizer Department Administration

## Purpose

Verify that an organizer can create, edit, archive, restore, and list organization departments from the normal Meridian Admin product UI and API path, without relying on Orchid/God Mode.

## Requirements covered

- `ORG-002`: Organizations define departments.
- `CLIENT-023`: administration surfaces act on server data, not bundled fixtures.
- Technical spec section 15.2: organizer/lead_organizer organization scoping through the Organizers Department.
- Technical spec section 22.1: Meridian Admin is the product admin surface; Orchid is God Mode/repair only.
- Data/API spec section 10.6: `departments`
- Data/API spec sections 5.1 and 5.2: organization department list reads and department create/update/archive/restore commands
- Data/API spec section 7.2: organization administration is not offline-writable work
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
- At least one department created directly on the server (seed, tinker, or Orchid) that the client has never been told about, to confirm the list is the node's

## Steps

1. Sign in to Meridian Admin as the organizer.
2. Open the home surface and choose **Departments** under Organizer administration, or navigate to `/organizer/departments`.
3. Confirm the Departments list loads for the organization and shows the server's departments, including the one created outside the client.
4. Confirm the lede above the list names the organization the department switcher is currently in.
5. Set the status filter to **Archived**, then **Active**, and confirm the list changes to match the server's archived state each time.
6. Choose **Create department**.
7. Enter the setup data values and save.
8. Confirm the new department appears in the list with status Active, and that the server has the record.
9. Open the saved department and change the name to `Rangers QA Updated`.
10. Save the department and confirm the list shows the updated name.
11. Archive the department from the list or edit screen.
12. Filter or confirm the archived department remains visible and is marked Archived.
13. Restore the archived department and confirm it returns to Active.
14. Attempt to create a second department with the same code `RANGERS_QA` and confirm the duplicate is rejected with the server's message naming the code field.
15. Edit a department, and while the form is open archive the same department from a second session or from Orchid. Save the form and confirm the screen reports what the server answered rather than showing a stale success.
16. Navigate directly to `/organizer/departments/{id}` for a department belonging to another organization, and for an id that does not exist.
17. Stop the node (or disconnect the device) and reload `/organizer/departments`, then attempt an archive.
18. Restart the node and reload to confirm the surface recovers.
19. If the organizer holds standing in more than one organization, switch departments to one in the other organization and confirm the list changes to that organization's departments.
20. Sign out, then sign in as a staff user without organizer authority.
21. Attempt to open `/organizer/departments` and attempt create/archive API commands for the organization.

## Expected results

- Every department shown is one the server holds for this organization, and a department created outside the client appears on the next load.
- The organization acted on is the one the selected department belongs to; switching to a department in another organization changes the list.
- The status filter is applied by the server; each change re-reads the list.
- Organizer can list organization departments, including archived records.
- Create accepts name, code, and optional description; a default team is created automatically for the new department.
- Edit loads the department's current values from the server, not the row the list was showing.
- Edit updates identity fields without deleting history.
- Archive sets `archived_at`; restore clears it. No hard-delete action is available.
- Duplicate codes within the same organization are rejected, and the screen shows the server's message for the failing field rather than a generic one.
- A refusal from the server is shown as the server worded it, and the surface does not act as though the change succeeded.
- A department in another organization, or one that does not exist, reports "Department not found for this organization." rather than an empty form.
- With the node unreachable the surface reports that it could not be read and offers a retry path; it does not show an empty list, and it does not queue the write for later (data/API 7.2).
- Successful create/update/archive/restore actions produce audit events (`department.created`, `department.updated`, `department.archived`, `department.restored`).
- Staff without organizer authority cannot manage departments (restricted UI and/or HTTP 403), and the restricted screen does not call the endpoints.
- The workflow completes in Meridian Admin without opening Orchid.

## Evidence to capture

- Screenshot of `/organizer/departments` showing the created department alongside the department created outside the client.
- Screenshot of the create/edit form with Save and Archive/Restore actions.
- Screenshot or notes showing archived then restored status.
- Screenshot of the duplicate-code refusal showing the server's field message.
- Screenshot of the surface with the node unreachable.
- Screenshot or notes for the not-found state on a foreign or unknown department id.
- API or audit query/tinker output for `department.created` / `department.archived` / `department.restored`.
- Screenshot or notes showing denied access for the non-organizer user.

## Failure notes

- If the list shows departments the server does not hold, or omits ones it does, stop testing and file a blocking CLIENT-023 issue.
- If the surface shows an empty list rather than an error while the node is unreachable, file a CLIENT-023 issue: an empty organization and an unread one must not look the same.
- If a write appears to succeed while the node is unreachable, stop testing and file an offline-scope issue against data/API 7.2.
- If department administration is only available in Orchid, stop testing and file a blocking M11.12 product-path issue.
- If create does not attach a default team, stop testing and file a TEAM-002 regression.
- If archive hard-deletes the department or removes historical visibility, stop testing and file a history-preservation issue.
- If a non-organizer can create or archive departments, stop testing and file a blocking permission issue.
- If organizer authority from another organization can mutate this organization's departments, stop testing and file a scoping issue.
