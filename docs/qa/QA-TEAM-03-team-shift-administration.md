# QA-TEAM-03: Product Team and Shift Administration

## Purpose

Verify that department leads can designate individual team leads, that team leads can assign permitted staff to teams they lead, and that department/team leads can create and maintain shifts from the normal Meridian Admin product UI and API path with the documented eligibility and time-window rules, without relying on Orchid/God Mode.

Since M16.18 the shift surfaces read from the node rather than from bundled fixtures, so this script also checks that the schedule on screen is the one the node holds, that authority and the started-shift locks come from the node's answer, that a refusal is shown as the node worded it, and that an unreachable node is reported rather than shown as a department with nothing scheduled.

## Requirements covered

- `TEAM-008`: Team membership may grant shift eligibility.
- `TEAM-009`: Team membership may grant system authority (`shift_lead` applies only to memberships designated `membership_role = 'lead'` on a team holding an active team-scoped `shift_lead` grant).
- `TEAM-010`: Authority is granted only through team grants; no free-floating user-level grants.
- `SHIFT-001` through `SHIFT-010`: shifts belong to an event and department, scheduled start/end, displayed title/function, exactly one eligible team, optional required trainings/waivers, optional capacity, optional signup availability dates, optional schedule lock/cutoff.
- `CLIENT-023`: the shift surfaces act on server data, not bundled fixtures.
- `CLIENT-006`: what the shift surfaces offer follows the node's stated authority.
- Requirements sections 5.4 (team assignment) and 5.7 (shift operations context); requirements 7.7 shift requirements.
- Technical spec section 15.2: `shift_lead` is team-scoped; `department_lead`/`department_administration` are department-scoped.
- Data/API spec section 10.9 (`shifts`) and section 7.2 (shift administration is not offline-writable work).
- UI implementation contract section 12.4: `department.teams`, `department.shifts`, `department.shift-create`, `department.shift-edit` (edit restricted by time rules).
- Meridian Alpha 1 tasks M11.17 and M16.18.

## Documented time-window rules (enforced by `ShiftAdminService`)

- Scheduled end must be after scheduled start.
- Signup close must be after signup open when both are set.
- Capacity is null (no cap) or at least 1, and can never be set below current active assignments.
- Once a shift has started, its scheduled times and eligible team are locked and the shift cannot be cancelled or restored; title, capacity, signup window, and schedule lock stay editable.
- Cancelled shifts must be restored before editing; cancel/restore are soft transitions on `cancelled_at`.

## Environment

- Development server environment with migrated database and `DevelopmentScenarioSeeder` (or equivalent personas)
- Shared Meridian Admin client (`apps/client` admin mode) running against a reachable node
- For API checks, authenticated requests against `/api/departments/{department}/teams`, `/api/departments/{department}/shifts`, `/api/departments/{department}/shifts/{shift}`, and the commands `select-team-lead`, `remove-team-lead`, `assign-staff-to-team`, `remove-staff-from-team`, `create-shift`, `update-shift`, `cancel-shift`, `restore-shift`

## Personas

- Department lead (or department administration) with `department.administer` for the target department
- Designated team lead (`shift_lead` grant on the led team plus `membership_role = 'lead'`) for one non-default team
- Undesignated member of the same grant-bearing team (must have no lead authority)
- Staff user without any admin/lead authority
- Department lead for a different department (cross-department denial)

## Setup data

- Organization with an active department such as `Rangers` (code `RANGERS`) with default team plus a non-default team `Dirt` (code `DIRT`)
- An event belonging to the same organization
- An active department staff member not yet on `Dirt` (e.g. `Vera Staff`)
- A shift title such as `Dirt Patrol (QA)` with a future start/end window
- At least one shift created directly on the server (seed, tinker, or Orchid) that the client has never been told about, to confirm the list is the node's
- A shift whose start time has already passed, so the started-shift locks can be checked against the node's answer rather than the browser's clock
- Optional: one department training and one organization waiver for requirement selection

## Workflow composition

Workflow pages are hubs that contain several featuresets, not one page each:

- **Admin** (`department.teams`) contains department details, team management with staff assignment and lead designation, and the embedded document library.
- **Planning** (`department.planning`) contains the identity-free coverage table, the embedded shift administration featureset, and the embedded training featureset.
- Shifts, Documents, and Trainings keep their own routes for direct links, and staff without lead authority reach them from the **Staff** menu.

## Steps

1. Sign in to Meridian Admin as the department lead and open `/events/{eventId}/departments/{departmentId}/admin`.
2. In **Team staff**, choose a department staff member and the `Dirt` team, then **Assign to team**. Confirm the member appears with role Staff.
3. Use **Make team lead** on that member. Confirm the row shows role Team lead.
4. Via API or tinker, confirm the member's `team_memberships.membership_role` is `lead` and an active `shift_lead` team grant exists on `Dirt`, and audit events `team_membership.assigned` and `team_lead.selected` were recorded.
5. Sign in as the newly designated team lead. Confirm the Admin page shows the led `Dirt` team and its staff, and `/api/departments/{department}/teams` returns `access.led_team_ids` containing `Dirt`.
6. Sign in as an undesignated member of `Dirt` and confirm the Admin route fails closed (no lead view, HTTP 403 on the teams read).
7. As the team lead, assign another permitted department staff member to `Dirt` and confirm success; attempt to assign staff to a peer team you do not lead and confirm denial (403).
8. As the team lead, remove the staff member added in step 7 and confirm the membership is archived (not deleted) with a `team_membership.removed` audit event.
9. As the department lead, attempt to remove a staff member from the department default team and confirm rejection.
10. Open **Planning** and confirm the page shows the coverage table plus embedded **Shifts** and **Trainings** sections. In the Shifts section (or at `/events/{eventId}/departments/{departmentId}/shifts`), choose **Create shift**, fill title `Dirt Patrol (QA)`, eligible team `Dirt`, a future start/end, capacity, signup window, schedule lock, and any required training/waiver; save.
10a. Confirm the shift created outside the client is in the list without any client-side setup, and that the eligible-team field offered only active teams — an archived team must not be selectable.
10b. With the browser network panel open, confirm the page issues one `GET /api/departments/{department}/shifts` on load, that changing the **Status** filter issues a new read carrying `?status=`, and that each create, edit, cancel, and restore is followed by a re-read.
11. Confirm the shift appears in the list with schedule, capacity, and Scheduled status, and `shift.created` was audited.
12. Attempt to create a shift whose end precedes its start, whose signup close precedes signup open, and whose capacity is 0. Confirm each is rejected with the server's own message and that no shift is created.
13. Edit the shift: change title and capacity; save; confirm `shift.updated` audit with before/after.
14. Open the shift whose start time has already passed. Confirm the page states that it has started, that the start, end, and eligible team inputs are disabled, and that title/capacity edits still save; attempt to cancel it and confirm the Cancel control is absent and the command is rejected.
15. Cancel a future shift from the list; confirm Cancelled status and that editing is rejected until restored; restore it and confirm it returns to Scheduled.
15a. Stop the node (or disconnect the device) and reload the Shifts route, then attempt a create and a cancel. Confirm the surface says it could not read the schedule and offers a retry, that nothing is queued for later, and that restarting the node and retrying recovers the surface.
16. Sign in as the team lead; confirm the Shifts list shows only led-team shifts, that the create form offers only teams they lead, create a shift for the led team, and confirm creating/editing shifts for a peer team is denied (403).
17. Sign in as a department member with no lead authority. Confirm a **Staff** menu appears listing Documents, Shifts, Trainings, and My Field Reports, that Shifts shows a read-only card list of the active shifts their teams are eligible for with no create/edit/cancel controls, that a cancelled shift for one of those teams is absent, that a shift for a team they do not belong to is absent, and that `GET /api/departments/{department}/shifts/{shift}` and the shift commands return 403.
17A. Sign in as someone with department membership but no team membership in the department; confirm the Shifts route shows the node's own 403 sentence and the shifts read returns 403.
17B. In Orchid (God Mode), open **Shifts**, confirm the visible organization/department/team filter bar narrows the list, and confirm the Teams screen offers organization and department filters but no team filter.
18. As a lead of a different department, attempt `create-shift` / `update-shift` / `select-team-lead` against the first department and confirm denial.
19. As the department lead, use **Remove lead** on the designated team lead; confirm the membership returns to member role, `team_lead.removed` is audited, and the former lead loses the led-team Admin/shift views.

## Expected results

- Department leads designate and remove individual team leads from the product UI; designation sets `membership_role = 'lead'` and ensures a team-scoped `shift_lead` grant; removal preserves team membership.
- Only designated lead memberships resolve `shift_lead`; undesignated members of the same team have no lead authority.
- Team leads assign/remove permitted staff only on teams they lead; department leads manage all department teams; default-team membership cannot be removed from this surface.
- Removals archive memberships (`archived_at`) and never hard-delete.
- Shift create/edit enforces SHIFT-001 through SHIFT-009 field rules and the documented time-window rules above.
- Shift list reads are scoped: department administer sees all department shifts; team leads see only led-team shifts; a department member with team membership sees the active shifts their teams are eligible for, read-only; someone with no team membership in the department is denied. Shift detail reads stay lead-only.
- Every shift, team name, status, and assignment count on screen is one the node holds for this department, and a shift created outside the client appears on the next load.
- The whole page comes from one read: which shell it wears, the eligible-team options, the training and waiver checkboxes, and the rows are the same response, and the status filter goes to the node rather than narrowing what came back.
- Whether the page offers to create, edit, cancel, or restore follows the authority the node stated — for the surface as a whole and for each row — rather than a role or team list the client decided for itself.
- The started-shift locks follow the node's answer about the shift, not the browser's clock: the disabled inputs and the missing Cancel control match the refusals the commands would give.
- A refusal from the node is shown as the node worded it — backwards schedule, backwards signup window, capacity below assignments, editing a cancelled shift, cancelling a started one — and nothing on the page moves as though the change had been accepted.
- With the node unreachable the surface reports that the schedule could not be read and offers a retry path; it does not show a department with nothing scheduled, and it does not queue the create or cancel for later (data/API 7.2).
- All mutations produce audit events (`team_lead.selected`, `team_lead.removed`, `team_membership.assigned`, `team_membership.removed`, `shift.created`, `shift.updated`, `shift.cancelled`, `shift.restored`).
- Workflow pages compose featuresets: Admin embeds the document library, Planning embeds shift and training administration.
- Non-lead department members reach Documents, Shifts, Trainings, and My Field Reports from the Staff menu, and their Shifts view is the node's read-only answer for the teams they belong to, with no option lists behind it.
- Orchid list screens expose a visible organization/department/team filter bar, department and team options narrow to the selected organization, and no screen offers a filter for its own level.
- The product workflow completes in Meridian Admin without opening Orchid; Orchid shift editing exists only as repair tooling and may override the started-shift locks.

## Evidence to capture

- Screenshots of the Team staff section before/after lead designation and staff assignment.
- Screenshot of the Shifts list with Scheduled/Started/Cancelled rows and of the create/edit form with locked schedule inputs on a started shift.
- API or tinker output for `membership_role`, the `shift_lead` grant, and the audit events listed above.
- Screenshot or notes showing denied access for the undesignated member, the member with no team membership, and the cross-department lead.
- Screenshot of the member's read-only shift cards, showing no create, edit, or cancel controls.
- Network-panel notes showing one read on load, a filtered read carrying `?status=`, and one re-read after each write.
- Screenshot of the surface with the node unreachable, including the retry path.

## Failure notes

- If team lead designation or shift administration is only available in Orchid, stop testing and file a blocking M11.17 product-path issue.
- If an undesignated member of a grant-bearing team holds lead authority, stop testing and file a TEAM-009/M11.17 designation regression.
- If a started shift's schedule, eligible team, or cancellation can be changed, stop testing and file a time-window regression.
- If staff removal hard-deletes memberships or removes historical visibility, stop testing and file a history-preservation issue.
- If a non-lead or cross-department actor can manage staff or shifts, stop testing and file a blocking permission issue.
- If the shift list shows shifts the node does not hold, or omits shifts it does, stop testing and file a blocking CLIENT-023 issue.
- If the surface shows a department with nothing scheduled rather than an error while the node is unreachable, file a CLIENT-023 issue: a department with no shifts and one that could not be read must not look the same.
- If a create, edit, cancel, or restore appears to succeed while the node is unreachable, stop testing and file an offline-scope issue against data/API 7.2.
- If a refused write is reported in the client's own wording instead of the node's, file a CLIENT-006 issue.
- If a member's shift list shows a team they do not belong to, or offers any control that would be refused, file a blocking CLIENT-006 issue.
