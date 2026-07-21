# QA-TEAM-02: Department Self-Administration

## Purpose

Verify that a department lead or department administration user can maintain permitted department details and manage teams (including default-team rename and non-default archive/restore) from the normal Meridian Admin product UI and API path, without relying on Orchid/God Mode.

## Requirements covered

- `TEAM-001` through `TEAM-006`: Teams replace roles; default team; default rename; persistence across events; archive; archived visibility.
- `TEAM-009`: Team membership may grant system authority (`department.administer` via department-scoped grants).
- Technical spec section 15.2: department-scoped `department_lead` and `department_administration`.
- Technical spec section 22.1: Meridian Admin is the product admin surface; Orchid is God Mode/repair only.
- Data/API spec section 10.6: `departments`, `teams`
- Data/API spec sections 5.1 and 5.2: department team list reads and department/team self-admin commands
- UI implementation contract section 12.4: `department.teams`
- Accessibility checklist sections 8, 9, 14, and 18

## Environment

- Development server environment with migrated database
- Shared Meridian Admin client (`apps/client` admin mode)
- Authenticated department lead or department administration user whose effective role grants `department.administer` through a team in the target department
- Permission catalog includes `department.administer` for `department_lead` and `department_administration`
- For API checks, authenticated requests against `/api/departments/{department}/teams` and `/api/commands/*-team` plus `update-department-details`

## Personas

- Department lead (or department administration) with `department.administer` for the target department
- Staff user without department administer authority
- Department lead for a different department (cross-department denial)

## Setup data

- Organization with an active department such as `Rangers` (code `RANGERS`) that already has its default team
- Use a new team name such as `Operators QA`
- Use a new team code such as `OPERATORS_QA`
- Use a team description such as `Radio operators and dispatch support.`
- Use a department detail rename such as `Rangers QA Updated`

## Steps

1. Sign in to Meridian Admin as the department lead (or department administration user).
2. Open the home surface and choose **Teams** under Department operations, or navigate to `/events/{eventId}/departments/{departmentId}/teams`.
3. Confirm the Teams list loads for the department and shows the default team as Active / Default.
4. In Department details, change the department name to `Rangers QA Updated` and save.
5. Confirm the page context shows the updated department name.
6. Choose **Create team**.
7. Enter the setup data values and save.
8. Confirm the new team appears in the list with status Active and type Team (not Default).
9. Open the default team and rename it to `Rangers Default QA`, keeping it default; save.
10. Confirm the default team remains Default and cannot be archived from the list or edit screen.
11. Archive the non-default `Operators QA` team from the list or edit screen.
12. Filter or confirm the archived team remains visible and is marked Archived.
13. Restore the archived team and confirm it returns to Active.
14. Attempt to create a second team with the same code `OPERATORS_QA` and confirm the duplicate is rejected.
15. Sign out, then sign in as a staff user without department administer authority.
16. Attempt to open the Teams route and attempt create/archive API commands for the department.
17. As a lead for a different department, attempt create-team / update-department-details against the first department.

## Expected results

- Department lead / department administration can list department teams, including archived records.
- Department details update accepts name, code, and optional description for the administered department only.
- Create team accepts name, code, and optional description; new teams are non-default.
- Default team rename preserves `is_default` and `departments.default_team_id`.
- Archive sets `archived_at` for non-default teams only; restore clears it. No hard-delete action is available.
- Duplicate codes within the same department are rejected.
- Successful update/create/archive/restore actions produce audit events (`department.updated`, `team.created`, `team.updated`, `team.archived`, `team.restored`).
- Staff without administer authority and leads of other departments cannot manage this department (restricted UI and/or HTTP 403).
- The workflow completes in Meridian Admin without opening Orchid.
- Team membership assignment UI is not required for this script (deferred to M11.17).

## Evidence to capture

- Screenshot of `/events/.../teams` showing department details and the created team.
- Screenshot of the create/edit team form with Save and Archive/Restore actions.
- Screenshot or notes showing default team rename without archive control.
- Screenshot or notes showing archived then restored status.
- API or audit query/tinker output for `department.updated` / `team.created` / `team.archived` / `team.restored`.
- Screenshot or notes showing denied access for the non-admin and cross-department users.

## Failure notes

- If department/team administration is only available in Orchid, stop testing and file a blocking M11.13 product-path issue.
- If the default team can be archived from Meridian Admin, stop testing and file a TEAM-005 regression.
- If default rename clears `is_default` or `default_team_id`, stop testing and file a TEAM-003 regression.
- If archive hard-deletes the team or removes historical visibility, stop testing and file a history-preservation issue.
- If a non-admin or cross-department actor can create or archive teams, stop testing and file a blocking permission issue.
