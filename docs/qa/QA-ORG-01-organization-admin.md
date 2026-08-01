# QA-ORG-01: Organization Admin Scaffold

## Purpose

Verify that an elevated Orchid user can view, create, and edit Meridian organization records in Orchid, including the Alpha 1 Incident Command department designation controls that currently live in the Orchid repair/admin surface.

## Requirements covered

- `ORG-001`: Meridian supports organizations that produce events and manage staff.
- `ORG-002`: Organizations define departments.
- `ORG-005`: Organizations can define a default Incident Command department.
- `ORG-006`: Events can override the organization Incident Command department.
- `INC-002`: Incident Command access starts from the event Incident Command department designation.
- Data/API spec section 10.1: `organizations`
- Data/API spec section 10.2: `events`
- Technical spec section 16.1: IC capability model
- Technical spec section 5.2: modular monolith boundaries
- Technical spec section 22.2: Alpha 1 Orchid screens
- UI implementation contract section 4: global UI rules
- UI implementation contract section 11.4: data table behavior
- Accessibility checklist sections 8, 9, 14, and 18

## Environment

- Development server environment
- Migrated database including the `organizations`, `events`, `departments`, and `event_department_assignments` tables
- Orchid admin route available at `/admin`
- Orchid user with `platform.index` and `platform.organizations` permissions
- For the event override checks, the same Orchid user also has `platform.events` permission

## Personas

- Organization administrator
- User without organization administration permission

## Setup data

- Use an organization name such as `Northwood Collective QA`.
- Use a slug such as `northwood-collective-qa`.
- Optional status threshold values: active-to-inactive `2`, prospective-to-inactive `1`.
- Optional calendar start: month `10`, day `3`.
- Create or reuse an active department in the organization, such as `Rangers QA`.
- Create or reuse an event in the organization and assign `Rangers QA` as an active participating department.
- Create or reuse one department that is not an eligible event participant, such as an archived department, an unassigned department, or a department in another organization.

## Steps

1. Sign in to Orchid as the organization administrator.
2. Open `/admin/organizations`.
3. Confirm the Organizations list loads.
4. Choose Add.
5. Enter the setup data values.
6. Save the organization.
7. Open the saved organization detail screen from the list.
8. Change the organization name to `Northwood Collective QA Updated`.
9. Save the organization.
10. Select `Rangers QA` as the default Incident Command department and save.
11. Reopen the organization detail screen and confirm `Rangers QA` remains selected.
12. Attempt to select the ineligible department as the default Incident Command department.
13. Open the event detail screen for the setup event.
14. Confirm the Incident Command department control offers `Use organization default` and active participating departments only.
15. Select `Rangers QA` as the event Incident Command department override and save.
16. Reopen the event detail screen and confirm `Rangers QA` remains selected.
17. Attempt to select the ineligible department as the event Incident Command department override.
18. Review audit history for the organization and event designation changes.
19. Sign out, then sign in as a user without `platform.organizations`.
20. Attempt to open `/admin/organizations`.

## Expected results

- The Organizations list is available to the permitted Orchid user.
- The list shows organization name, slug, and calendar-year start when configured.
- Add opens a create/detail scaffold with explicit Save and Cancel actions.
- Saving valid values creates the organization and returns to the Organizations list.
- Opening the saved organization shows its existing values.
- Saving a valid edit updates the organization.
- Duplicate slugs are rejected.
- Organization default Incident Command department selection accepts only active departments in the same organization.
- Event Incident Command override selection accepts only active same-organization departments with active event participation.
- Clearing the event override leaves the event using the organization default by effective fallback.
- Successful Incident Command designation changes create audit events with before and after department IDs.
- Repeating the same Incident Command designation does not create a duplicate audit event.
- No destructive delete action is present.
- The user without `platform.organizations` is denied access.
- No team grants, incident records, Field Report visibility changes, API, import/export, or sync behavior appears in this scaffold.

## Evidence to capture

- Screenshot of `/admin/organizations` showing the saved organization.
- Screenshot of the organization detail screen showing Save and Cancel.
- Screenshot of the organization detail screen with the default Incident Command department selector.
- Screenshot of the event detail screen with the Incident Command department override selector.
- Query or tinker output showing `organization.default_ic_department_changed` and `event.ic_department_changed` audit events.
- Screenshot or notes showing the denied access result for the user without permission.

## Failure notes

- If a user without `platform.organizations` can access the screen, stop testing and file a blocking permission issue.
- If organization creation requires an event, department, team, or staff record, stop testing and file a scope issue against M4.1.
- If an event override can select an inactive, cross-organization, or non-participating department, stop testing and file a blocking M11.1 validation issue.
- If changing the Incident Command designation grants IC roles, exposes incident records, changes Field Report visibility, or affects sync projections, stop testing and file a scope issue because those behaviors belong to later M11 tasks.
- If a destructive delete action is available, stop testing and file a history-preservation issue.
