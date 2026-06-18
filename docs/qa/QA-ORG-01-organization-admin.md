# QA-ORG-01: Organization Admin Scaffold

## Purpose

Verify that an elevated Orchid user can view, create, and edit Meridian organization records without relying on event, department, team, staff, audit, API, or sync behavior that belongs to later Alpha 1 tasks.

## Requirements covered

- `ORG-001`: Meridian supports organizations that produce events and manage staff.
- `ORG-002`: Organizations define departments.
- Data/API spec section 10.1: `organizations`
- Technical spec section 5.2: modular monolith boundaries
- Technical spec section 22.2: Alpha 1 Orchid screens
- UI implementation contract section 4: global UI rules
- UI implementation contract section 11.4: data table behavior
- Accessibility checklist sections 8, 9, 14, and 18

## Environment

- Development server environment
- Migrated database including the `organizations` table
- Orchid admin route available at `/admin`
- Orchid user with `platform.index` and `platform.organizations` permissions

## Personas

- Organization administrator
- User without organization administration permission

## Setup data

- Use an organization name such as `Idaho Burners QA`.
- Use a slug such as `idaho-burners-qa`.
- Optional status threshold values: active-to-inactive `2`, prospective-to-inactive `1`.
- Optional calendar start: month `10`, day `3`.

## Steps

1. Sign in to Orchid as the organization administrator.
2. Open `/admin/organizations`.
3. Confirm the Organizations list loads.
4. Choose Add.
5. Enter the setup data values.
6. Save the organization.
7. Open the saved organization detail screen from the list.
8. Change the organization name to `Idaho Burners QA Updated`.
9. Save the organization.
10. Sign out, then sign in as a user without `platform.organizations`.
11. Attempt to open `/admin/organizations`.

## Expected results

- The Organizations list is available to the permitted Orchid user.
- The list shows organization name, slug, and calendar-year start when configured.
- Add opens a create/detail scaffold with explicit Save and Cancel actions.
- Saving valid values creates the organization and returns to the Organizations list.
- Opening the saved organization shows its existing values.
- Saving a valid edit updates the organization.
- Duplicate slugs are rejected.
- No destructive delete action is present.
- The user without `platform.organizations` is denied access.
- No event, department, team, staff, API, audit-service, import/export, or sync behavior appears in this scaffold.

## Evidence to capture

- Screenshot of `/admin/organizations` showing the saved organization.
- Screenshot of the organization detail screen showing Save and Cancel.
- Screenshot or notes showing the denied access result for the user without permission.

## Failure notes

- If a user without `platform.organizations` can access the screen, stop testing and file a blocking permission issue.
- If organization creation requires an event, department, team, or staff record, stop testing and file a scope issue against M4.1.
- If a destructive delete action is available, stop testing and file a history-preservation issue.
