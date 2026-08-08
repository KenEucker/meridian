# QA-APP-01: Event Application Approval and Onboarding

## Purpose

Verify the Milestone 5 application/onboarding gate: a human can submit an event application, an organizer or Staff Coordinator can approve it at the organization level, the approved applicant can be assigned to an event-participating department, a department lead can assign the staff member to an operational team, and rescind rules allow rescind before operational team assignment while blocking rescind after team assignment.

This script complements `QA-APPLY-01-public-event-application.md`. Use the public form and Orchid review screens where they exist. Use the documented Laravel domain-service tinker commands for department assignment and team assignment until dedicated command/UI surfaces are added.

## Requirements covered

- `APP-001`
- `APP-002`
- `APP-003`
- `APP-005`
- `APP-006`
- `APP-007`
- `APP-008`
- `APP-009`
- `APP-010`
- `APP-011`
- `TEAM-008`
- `TEAM-009`
- Requirements sections 3.10, 5.2, 5.3, 5.4, and 6.3
- Technical spec sections 9.4, 15, 22, and 23
- Data/API spec sections 10.5 and 10.6
- UI implementation contract sections 12.1, 12.6, 12.10, 16, and 20
- Meridian Alpha 1 tasks M5.5, M5.7, M5.8, M5.9, and M5.10

## Environment

- Fresh checkout or task branch with server dependencies installed.
- Laravel app migrated and seeded from the development scenario:
  ```bash
  cd apps/server
  php artisan migrate:fresh --seed
  ```
- Development web server running.
- Orchid available at `/admin`.
- Browser access to the public application route.
- Shell access from `apps/server` for tinker verification commands.

## Personas

- Public applicant: unauthenticated visitor with the event application link.
- Organizer or Staff Coordinator: Orchid user with `platform.index` and `platform.applications` permissions.
- Department lead: seeded Dana Departmentlead or another user with department lead authority for the target department.
- Unauthorized user: signed-in user without application review, department assignment, or team assignment authority.

## Setup data

- Organization: `Northwood Collective` with slug `northwood-collective`.
- Event: `Emberfall 2026` with slug `emberfall-2026`.
- Department: `Rangers` with code `RANGERS`, assigned to the event through `event_department_assignments`.
- Operational team: `Dirt` with code `DIRT`, a non-default team in Rangers.
- Organizer reviewer: grant the seeded Olive Organizer user the required Orchid permissions before testing:
  ```bash
  php artisan tinker --execute='$user = App\Models\User::query()->where("email", "olive.organizer@northwood-collective.test")->firstOrFail(); $user->forceFill(["permissions" => array_merge($user->permissions ?? [], ["platform.index" => true, "platform.applications" => true])])->save();'
  ```
- Department lead assigner: `dana.departmentlead@northwood-collective.test`.
- Use unique applicant emails for each scenario, such as:
  - `qa.app.preteam@example.test`
  - `qa.app.defaultteam@example.test`
  - `qa.app.afterteam@example.test`

## Steps

### A. Submit and approve an event application

1. Open `/northwood-collective/emberfall-2026/apply` as the public applicant.
2. Confirm the form is event-specific and shows the event context, legal name field, email field, and optional non-binding **Department interest** when participating departments exist.
3. Confirm there is no team selection, team interest, assignment, or membership control on the application form.
4. Submit the form using legal name `QA Before Team` and email `qa.app.preteam@example.test`. Select `Rangers` as a department interest if the control is visible.
5. Confirm the applicant sees the submitted confirmation page.
6. Sign in to Orchid as the organizer or Staff Coordinator and open **Operations > Applications**.
7. Confirm the application appears with canonical status **Submitted**, event context, organization context, applicant identity, submitted timestamp, and department interest labeled as interest, not assignment.
8. Open the application detail.
9. Confirm **Approve**, **Reject**, and **Defer** are visible to the organizer or Staff Coordinator.
10. Click **Approve** and confirm the action.
11. Confirm the application detail now shows canonical status **Approved**, reviewer, reviewed timestamp, decision reason, and a matched staff profile.
12. From `apps/server`, verify approval state:
    ```bash
    php artisan tinker --execute='$email = "qa.app.preteam@example.test"; $application = App\Models\EventApplication::query()->with(["staff.organizationStatuses", "reviewedBy", "departmentInterests"])->where("applicant_email", $email)->latest("submitted_at")->firstOrFail(); print(json_encode(["application_id" => $application->id, "status" => $application->status, "staff_id" => $application->staff_id, "reviewed_by" => $application->reviewedBy?->email, "organization_statuses" => $application->staff?->organizationStatuses->map(fn ($s) => ["organization_id" => $s->organization_id, "status" => $s->status, "changed_by" => $s->status_changed_by_user_id])->values()], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
13. Confirm the output shows application status `approved`, a non-null `staff_id`, and organization status `prospective` for the event organization.
14. Verify audit history for approval:
    ```bash
    php artisan tinker --execute='$email = "qa.app.preteam@example.test"; $application = App\Models\EventApplication::query()->where("applicant_email", $email)->latest("submitted_at")->firstOrFail(); App\Models\AuditEvent::query()->where("entity_id", $application->id)->orderBy("created_at")->get(["action", "actor_user_id", "organization_id", "event_id", "source_context", "reason"])->each(fn ($event) => print($event->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
    ```
15. Confirm at least `event_application.approved` exists and is attributable to the reviewing user.
16. Confirm approval did not create department membership, operational team membership, shift signup, training, waiver, credential, export, routing, or notification side effects.

### B. Assign the approved applicant to a department

17. Assign the approved applicant to the event-participating Rangers department using the domain service:
    ```bash
    php artisan tinker --execute='$email = "qa.app.preteam@example.test"; $application = App\Models\EventApplication::query()->where("applicant_email", $email)->latest("submitted_at")->firstOrFail(); $department = App\Models\Department::query()->where("code", "RANGERS")->firstOrFail(); $assigner = App\Models\User::query()->where("email", "olive.organizer@northwood-collective.test")->firstOrFail(); $membership = app(App\Services\Application\EventApplicationService::class)->assignToDepartment($application, $department, $assigner)->load(["department", "teamMemberships.team"]); print(json_encode(["department_membership_id" => $membership->id, "department" => $membership->department?->name, "status" => $membership->status, "teams" => $membership->teamMemberships->map(fn ($tm) => ["team" => $tm->team?->name, "is_default" => (bool) $tm->team?->is_default])->values()], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
18. Confirm the output shows one active department membership for Rangers.
19. Confirm the only team membership created by department assignment is the department default team.
20. Confirm the linked staff organization status is now `active` when no training requirement model exists:
    ```bash
    php artisan tinker --execute='$email = "qa.app.preteam@example.test"; $application = App\Models\EventApplication::query()->with("staff")->where("applicant_email", $email)->latest("submitted_at")->firstOrFail(); App\Models\StaffOrganizationStatus::query()->where("organization_id", $application->organization_id)->where("staff_id", $application->staff_id)->get(["status", "status_reason", "status_changed_by_user_id"])->each(fn ($status) => print($status->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
    ```
21. Verify audit history contains `department_membership.assigned_from_application` scoped to the organization, event, and department.
22. Confirm department assignment did not create an operational non-default team assignment, shift signup, training, waiver, credential, export, routing, or notification side effects.

### C. Rescind while only default-team department assignment exists

23. Return to the approved application detail in Orchid.
24. Confirm **Rescind** is visible to the organizer or Staff Coordinator.
25. Click **Rescind** and confirm the action.
26. Confirm the application status becomes **Withdrawn**, `withdrawn_at` is set, reviewer metadata identifies the rescinding user, and the decision reason indicates rescind before team assignment.
27. From `apps/server`, verify rescind state:
    ```bash
    php artisan tinker --execute='$email = "qa.app.preteam@example.test"; $application = App\Models\EventApplication::query()->with("staff")->where("applicant_email", $email)->latest("submitted_at")->firstOrFail(); $departmentMemberships = App\Models\DepartmentMembership::query()->where("staff_id", $application->staff_id)->with(["department", "teamMemberships.team"])->get(); $orgStatuses = App\Models\StaffOrganizationStatus::query()->where("organization_id", $application->organization_id)->where("staff_id", $application->staff_id)->get(); print(json_encode(["application_status" => $application->status, "withdrawn_at" => (string) $application->withdrawn_at, "department_memberships" => $departmentMemberships->map(fn ($m) => ["department" => $m->department?->name, "status" => $m->status, "teams" => $m->teamMemberships->map(fn ($tm) => $tm->team?->name)->values()])->values(), "organization_statuses" => $orgStatuses->map(fn ($s) => ["status" => $s->status, "reason" => $s->status_reason])->values()], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
28. Confirm the linked staff organization status is `inactive`.
29. Confirm the default-only department membership is `inactive` and historical membership rows remain present.
30. Verify audit history contains `event_application.rescinded` and `department_membership.inactivated_from_application_rescind`.

### D. Assign an approved applicant to an operational team

31. Submit and approve a fresh application using legal name `QA After Team` and email `qa.app.afterteam@example.test`.
32. Assign the approved applicant to Rangers using the command from step 17, changing the email to `qa.app.afterteam@example.test`.
33. Assign the staff member to the non-default `Dirt` team as the department lead:
    ```bash
    php artisan tinker --execute='$email = "qa.app.afterteam@example.test"; $application = App\Models\EventApplication::query()->where("applicant_email", $email)->latest("submitted_at")->firstOrFail(); $staff = App\Models\Staff::query()->findOrFail($application->staff_id); $team = App\Models\Team::query()->where("code", "DIRT")->firstOrFail(); $assigner = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); $membership = app(App\Services\Membership\TeamMembershipService::class)->assignStaffToTeam($staff, $team, $assigner)->load("team"); print(json_encode(["team_membership_id" => $membership->id, "team" => $membership->team?->name, "membership_role" => $membership->membership_role, "archived_at" => $membership->archived_at], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
34. Confirm the output shows the `Dirt` team membership with role `member` and no `archived_at`.
35. Confirm the staff member now has both the default structural team and the non-default operational team in Rangers:
    ```bash
    php artisan tinker --execute='$email = "qa.app.afterteam@example.test"; $application = App\Models\EventApplication::query()->where("applicant_email", $email)->latest("submitted_at")->firstOrFail(); App\Models\TeamMembership::query()->with("team.department")->where("staff_id", $application->staff_id)->get()->each(fn ($membership) => print(json_encode(["team" => $membership->team?->name, "department" => $membership->team?->department?->name, "is_default" => (bool) $membership->team?->is_default, "archived_at" => $membership->archived_at], JSON_PRETTY_PRINT).PHP_EOL));'
    ```
36. Verify audit history contains `team_membership.assigned` scoped to the Rangers department and attributed to the department lead.
37. Confirm team assignment did not create shifts, training completions, waivers, credentials, exports, or notifications.

### E. Verify rescind is blocked after team assignment

38. Return to the `qa.app.afterteam@example.test` application detail in Orchid.
39. Confirm **Rescind** is visible to the organizer or Staff Coordinator because the application is approved.
40. Click **Rescind** and confirm the action.
41. Confirm the action is blocked with a warning equivalent to "Applications cannot be rescinded after team assignment."
42. Verify state remained unchanged:
    ```bash
    php artisan tinker --execute='$email = "qa.app.afterteam@example.test"; $application = App\Models\EventApplication::query()->with("staff.organizationStatuses")->where("applicant_email", $email)->latest("submitted_at")->firstOrFail(); print(json_encode(["application_status" => $application->status, "withdrawn_at" => $application->withdrawn_at, "organization_statuses" => $application->staff?->organizationStatuses->map(fn ($s) => ["status" => $s->status, "reason" => $s->status_reason])->values()], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
43. Confirm the application remains `approved`, `withdrawn_at` remains null, and the organization status remains active.
44. Confirm no `event_application.rescinded` audit event was added for the blocked attempt.
45. Confirm the default and non-default team membership history remains intact.

### F. Permission and validation checks

46. As a user without `platform.applications`, attempt to open the Orchid application list and detail. Confirm access is denied.
47. As a department lead without organizer/Staff Coordinator review permission, confirm application review actions such as **Approve**, **Reject**, **Defer**, and **Rescind** are not available.
48. Attempt department assignment with an unauthorized user through the domain service. Confirm it is rejected and no membership is created.
49. Attempt department assignment to an archived department, a non-participating department, or a department from another organization. Confirm it is rejected and no membership is created.
50. Attempt team assignment with an unauthorized user or to a team in another department. Confirm it is rejected and no team membership is created.
51. Attempt duplicate department assignment and duplicate active team assignment. Confirm each is rejected without additional rows.
52. If the applicant email is changed to a known organization-level DNS email before assignment, confirm department and team assignment are rejected.

### G. Online-only and accessibility review

53. Confirm application submission is online-only in Alpha 1. There is no offline queue, local-only submitted state, device sync operation, or conflict-resolution path for applications.
54. Confirm assignment and rescind checks are server-side/admin-side behavior in this milestone and do not create offline device operations.
55. Review the public form and Orchid review detail by keyboard only. Confirm reachable controls, visible focus, visible labels, canonical status labels, and non-color-only state communication.
56. Confirm destructive or high-impact actions such as Approve and Rescind require confirmation.

## Expected results

- Application submission remains event-specific and does not create department/team membership or access by itself.
- Approval occurs at the organization level and changes a Submitted application to Approved.
- Approval links or creates the staff profile and creates or ensures Prospective organization-level staff status.
- Approval records reviewer metadata, decision reason, and audit history.
- Department assignment occurs only after approval.
- Department assignment creates active department membership with only the structural default team.
- Department assignment promotes organization status to Active when no training requirement model exists.
- Team assignment by a department lead adds a non-default operational team membership in the department.
- Team membership may grant shift eligibility or system authority later, but this script does not expect shift, credential, training, waiver, or export behavior.
- Rescind before operational team assignment changes the application to Withdrawn, sets `withdrawn_at`, inactivates the staff organization status, inactivates default-only department membership, and preserves history.
- Rescind after non-default team assignment is blocked and leaves application, status, membership, and audit history unchanged.
- Unauthorized users cannot review applications, assign departments, assign teams, or rescind approvals.
- Invalid department/team assignment targets are rejected without partial state changes.
- Application submission and onboarding admin actions have no special offline/sync behavior in Alpha 1.
- Accessibility review notes cover keyboard operation, visible focus, labels, canonical status names, and confirmation for high-impact actions.

## Evidence to capture

- Screenshot of the public application form with event context and optional Department interest.
- Screenshot of the submitted confirmation page.
- Screenshot of Orchid Applications list showing the Submitted application.
- Screenshot of application detail before approval with Approve, Reject, and Defer visible to the permitted reviewer.
- Screenshot of application detail after approval showing Approved status, reviewer metadata, and matched staff.
- Tinker output showing the approved application, linked staff profile, and Prospective organization status.
- Tinker output or query evidence showing `event_application.approved` audit history.
- Tinker output showing department assignment created active Rangers membership with only the default team.
- Tinker output or query evidence showing `department_membership.assigned_from_application`.
- Screenshot and query output showing successful rescind before operational team assignment.
- Tinker output showing organization status and department membership became inactive after rescind.
- Tinker output showing assignment to the Dirt team and both default and non-default team memberships.
- Tinker output or query evidence showing `team_membership.assigned`.
- Screenshot or notes showing rescind blocked after non-default team assignment.
- Query evidence that no rescind audit event was created for the blocked after-team-assignment attempt.
- Permission-denied evidence for users without application review, department assignment, or team assignment authority.
- Notes from keyboard/accessibility review.

## Failure notes

- If approval does not create or ensure Prospective organization-level staff status, stop and file a blocking onboarding issue.
- If application submission or approval creates department/team membership before assignment, stop and file a scope leakage issue.
- If department assignment can happen before approval, stop and file a blocking APP-007 issue.
- If department assignment creates a non-default operational team membership, stop and file a team-assignment boundary issue.
- If department assignment fails to create default-team structural membership, stop and file a department membership invariant issue.
- If team assignment works for a user who is not a department lead for that department, stop and file a permission issue.
- If team assignment creates shifts, training completions, waivers, credentials, exports, or notifications, stop and file a scope leakage issue.
- If rescind before team assignment does not make the person Inactive, stop and file a blocking APP-009 issue.
- If rescind deletes membership or status history instead of preserving rows with changed state, stop and file an operational-history issue.
- If rescind succeeds after non-default team assignment or archived non-default team assignment history, stop and file a blocking APP-010 issue.
- If a blocked rescind changes status, `withdrawn_at`, memberships, or audit history, stop and file a state-integrity issue.
- If application submission works offline or queues a local application operation in Alpha 1, stop and file a scope issue because applications are online-only for this milestone.
