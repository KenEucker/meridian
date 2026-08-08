# QA-SHIFT-01: Shift Signup Eligibility

## Purpose

Verify Milestone 7 shift signup eligibility through the domain services that power Alpha 1: a reviewer can configure training and waiver requirements on a shift, sign up an eligible staff member immediately, observe denials for missing training, missing waiver, department Ineligible status, full capacity, closed signup window, and schedule lock, receive advisory overlap warnings without a hard block, and confirm that an authorized department lead can assign overlapping shifts and remove staff after schedule lock.

Section H additionally verifies the Milestone 13 shift roster export (M13.2): an organizer exports the whole event, a department lead exports only their own department, unauthorized actors are refused, phone numbers and emergency contacts never reach the file, an unstaffed shift still appears, a removed staff member does not, and each successful export is audited.

Section J verifies the Milestone 18 staff shift board (M18.2, SHIFT-018): the surface a staff member browses shifts and signs up from, the two commands behind it, and the property that matters most about both — that the reason a shift says it will not take somebody is the reason the command refuses with.

Sections A through H are domain-service checks and need no signup screen. Section J is the screen, and needs a signed-in client.

## Requirements covered

- `SHIFT-005`
- `SHIFT-006`
- `SHIFT-007`
- `SHIFT-008`
- `SHIFT-009`
- `SHIFT-011`
- `SHIFT-012`
- `SHIFT-013`
- `SHIFT-014`
- `SHIFT-015`
- `SHIFT-016`
- `SHIFT-018`
- `TRAIN-008`
- `WAIVER-005`
- `REPORT-002`
- `REPORT-006`
- `REPORT-007`
- `REPORT-008`
- `REPORT-010`
- Requirements sections 3.11, 3.12, 5.5, 5.12, and 7.14
- Data/API spec section 10.9
- Technical spec section 22.2 (CSV export)
- Meridian Alpha 1 tasks M7.4 through M7.8, M7.11, M13.2, and M18.2

## Environment

- Fresh checkout or task branch with server dependencies installed.
- Laravel app migrated and seeded from the development scenario:
  ```bash
  cd apps/server
  php artisan migrate:fresh --seed
  ```
- Shell access from `apps/server` for tinker verification commands.
- Sections A through H need no staff-facing signup UI.
- Section J needs the client running against the seeded node with a signed-in
  device (`corepack pnpm run client:dev`, signed in per `QA-AUTH-01`).

## Personas

- Eligible staff: seeded Vera Staff (`vera.staff@northwood-collective.test`), active Rangers / Dirt member.
- Second eligible staff: seeded Sam Shiftlead (`sam.shiftlead@northwood-collective.test`), active Rangers / Dirt member.
- Department lead assigner/remover: seeded Dana Departmentlead (`dana.departmentlead@northwood-collective.test`).
- Do Not Staff subject: seeded Debbie DNS (`debbie.dns@northwood-collective.test`).
- Unauthorized assigner: seeded Vera Staff when used for lead assignment attempts.

## Setup data

- Organization: `Northwood Collective` with slug `northwood-collective`.
- Event: `Emberfall 2026` with slug `emberfall-2026`.
- Department: `Rangers` with code `RANGERS`.
- Eligible team: `Dirt` with code `DIRT`.
- Seeded development password is `password` when Orchid login is used for adjacent checks; this script is domain-service based.
- Create QA shifts, trainings, and waivers with the setup commands in the Steps section. Prefer titles prefixed with `QA SHIFT` so cleanup is obvious.

## Steps

### A. Configure a shift with training and waiver requirements

1. From `apps/server`, create a Rangers / Dirt shift for the seeded event:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $department = App\Models\Department::query()->where("code", "RANGERS")->firstOrFail(); $team = App\Models\Team::query()->where("department_id", $department->id)->where("code", "DIRT")->firstOrFail(); $starts = now()->addWeek()->setTime(8, 0); $shift = App\Models\Shift::factory()->create(["event_id" => $event->id, "department_id" => $department->id, "eligible_team_id" => $team->id, "title" => "QA SHIFT Eligibility Morning", "starts_at" => $starts, "ends_at" => $starts->copy()->addHours(8), "capacity" => null, "signup_opens_at" => null, "signup_closes_at" => null, "schedule_lock_at" => null]); print(json_encode(["shift_id" => $shift->id, "title" => $shift->title, "starts_at" => (string) $shift->starts_at, "ends_at" => (string) $shift->ends_at], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
2. Record the printed `shift_id` as `$SHIFT_A`.
3. Create a required training and attach it to the shift:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); $training = App\Models\Training::factory()->for($shift->event->organization)->create(["name" => "QA SHIFT Ranger Safety"]); app(App\Services\Shift\ShiftRequirementService::class)->addTrainingRequirement($shift, $training); print(json_encode(["shift_id" => $shift->id, "training_id" => $training->id, "training_name" => $training->name], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
4. Create a required organization-scoped waiver and attach it to the shift:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); $organization = $shift->event->organization; $waiver = app(App\Services\Waiver\WaiverService::class)->create($organization, App\Models\Waiver::SCOPE_ORGANIZATION, $organization->id, "QA SHIFT Event Waiver"); app(App\Services\Shift\ShiftRequirementService::class)->addWaiverRequirement($shift, $waiver); print(json_encode(["shift_id" => $shift->id, "waiver_id" => $waiver->id, "waiver_name" => $waiver->name, "scope_type" => $waiver->scope_type], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
5. Confirm the shift now lists both requirements:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->with(["requiredTrainings", "requiredWaivers"])->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); print(json_encode(["trainings" => $shift->requiredTrainings->pluck("name"), "waivers" => $shift->requiredWaivers->pluck("name")], JSON_PRETTY_PRINT).PHP_EOL);'
    ```

### B. Observe eligibility denials before completions exist

6. Attempt self-signup as Vera before training/waiver completions exist:
    ```bash
    php artisan tinker --execute='try { $shift = App\Models\Shift::query()->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); $user = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $staff = $user->staffProfiles()->firstOrFail(); app(App\Services\Shift\ShiftSignupService::class)->signUp($shift, $staff, $user); print("UNEXPECTED_SUCCESS\n"); } catch (Throwable $e) { print($e->getMessage().PHP_EOL); }'
    ```
7. Confirm the denial message is `Required training must be complete before shift signup.`
8. Record Vera's training completion, then retry signup:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->with("requiredTrainings")->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); $user = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $staff = $user->staffProfiles()->firstOrFail(); $recorder = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); app(App\Services\Training\TrainingService::class)->recordCompletion($shift->requiredTrainings->first(), $staff, null, $recorder); try { app(App\Services\Shift\ShiftSignupService::class)->signUp($shift->refresh(), $staff, $user); print("UNEXPECTED_SUCCESS\n"); } catch (Throwable $e) { print($e->getMessage().PHP_EOL); }'
    ```
9. Confirm the denial message is now `Required waiver must be complete before shift signup.`

### C. Sign up an eligible staff member

10. Record Vera's waiver completion and sign up successfully:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->with("requiredWaivers")->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); $user = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $staff = $user->staffProfiles()->firstOrFail(); $recorder = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); app(App\Services\Waiver\WaiverService::class)->recordCompletion($shift->requiredWaivers->first(), $staff, null, $recorder); $outcome = app(App\Services\Shift\ShiftSignupService::class)->signUp($shift->refresh(), $staff, $user); print(json_encode(["assignment_id" => $outcome->assignment->id, "status" => $outcome->assignment->assignment_status, "assigned_by_user_id" => $outcome->assignment->assigned_by_user_id, "warning_count" => count($outcome->warnings)], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
11. Confirm the assignment status is `signed_up`, `assigned_by_user_id` is null, and warning count is `0`.
12. Verify the `shift_assignment.signed_up` audit event:
    ```bash
    php artisan tinker --execute='$assignment = App\Models\ShiftAssignment::query()->whereHas("shift", fn ($q) => $q->where("title", "QA SHIFT Eligibility Morning"))->whereHas("staff", fn ($q) => $q->where("email", "vera.staff@northwood-collective.test"))->latest("created_at")->firstOrFail(); App\Models\AuditEvent::query()->where("entity_id", $assignment->id)->where("action", "shift_assignment.signed_up")->get(["action", "actor_user_id", "organization_id", "event_id", "department_id", "source_context"])->each(fn ($event) => print($event->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
    ```
13. Confirm signup was immediate and did not require a separate approval step.

### D. Department Ineligible, Do Not Staff, and capacity denials

14. Withdraw Vera from the morning shift so later denial cases start clean:
    ```bash
    php artisan tinker --execute='$user = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $staff = $user->staffProfiles()->firstOrFail(); $assignment = App\Models\ShiftAssignment::query()->active()->where("staff_id", $staff->id)->whereHas("shift", fn ($q) => $q->where("title", "QA SHIFT Eligibility Morning"))->firstOrFail(); app(App\Services\Shift\ShiftRemovalService::class)->withdrawFromShift($assignment, $staff, $user); print("withdrawn\n");'
    ```
15. Temporarily mark Vera's Rangers membership Ineligible and retry signup:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); $user = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $staff = $user->staffProfiles()->firstOrFail(); $membership = App\Models\DepartmentMembership::query()->active()->where("staff_id", $staff->id)->where("department_id", $shift->department_id)->firstOrFail(); app(App\Services\Status\StaffStatusService::class)->transitionDepartmentStatus($membership, App\Models\DepartmentMembership::STATUS_INELIGIBLE, "QA SHIFT temporary ineligible"); try { app(App\Services\Shift\ShiftSignupService::class)->signUp($shift, $staff, $user); print("UNEXPECTED_SUCCESS\n"); } catch (Throwable $e) { print($e->getMessage().PHP_EOL); } app(App\Services\Status\StaffStatusService::class)->transitionDepartmentStatus($membership->refresh(), App\Models\DepartmentMembership::STATUS_ACTIVE, null);'
    ```
16. Confirm the denial message is `Ineligible department status prevents shift signup.` and Vera is restored to active department status.
17. Attempt signup as Debbie DNS:
    ```bash
    php artisan tinker --execute='try { $shift = App\Models\Shift::query()->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); $user = App\Models\User::query()->where("email", "debbie.dns@northwood-collective.test")->firstOrFail(); $staff = $user->staffProfiles()->firstOrFail(); app(App\Services\Shift\ShiftSignupService::class)->signUp($shift, $staff, $user); print("UNEXPECTED_SUCCESS\n"); } catch (Throwable $e) { print($e->getMessage().PHP_EOL); }'
    ```
18. Confirm the denial message is `Do Not Staff records cannot sign up for shifts.`
19. Set the morning shift capacity to `1`, sign up Sam to fill it, then attempt Vera signup:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->with(["requiredTrainings", "requiredWaivers"])->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); $shift->forceFill(["capacity" => 1])->save(); $samUser = App\Models\User::query()->where("email", "sam.shiftlead@northwood-collective.test")->firstOrFail(); $sam = $samUser->staffProfiles()->firstOrFail(); $recorder = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); foreach ($shift->requiredTrainings as $training) { app(App\Services\Training\TrainingService::class)->recordCompletion($training, $sam, null, $recorder); } foreach ($shift->requiredWaivers as $waiver) { app(App\Services\Waiver\WaiverService::class)->recordCompletion($waiver, $sam, null, $recorder); } $samOutcome = app(App\Services\Shift\ShiftSignupService::class)->signUp($shift->refresh(), $sam, $samUser); $veraUser = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $vera = $veraUser->staffProfiles()->firstOrFail(); try { app(App\Services\Shift\ShiftSignupService::class)->signUp($shift->refresh(), $vera, $veraUser); print("UNEXPECTED_SUCCESS\n"); } catch (Throwable $e) { print(json_encode(["sam_assignment_id" => $samOutcome->assignment->id, "capacity_denial" => $e->getMessage()], JSON_PRETTY_PRINT).PHP_EOL); }'
    ```
20. Confirm the denial message is `This shift is full and cannot accept additional signup.`

### E. Signup window and schedule lock

21. Clear capacity, withdraw Sam, and close the signup window in the past:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); $samUser = App\Models\User::query()->where("email", "sam.shiftlead@northwood-collective.test")->firstOrFail(); $sam = $samUser->staffProfiles()->firstOrFail(); $assignment = App\Models\ShiftAssignment::query()->active()->where("staff_id", $sam->id)->where("shift_id", $shift->id)->firstOrFail(); app(App\Services\Shift\ShiftRemovalService::class)->withdrawFromShift($assignment, $sam, $samUser); $shift->forceFill(["capacity" => null])->save(); app(App\Services\Shift\ShiftRequirementService::class)->setSignupWindow($shift->refresh(), now()->subDays(7), now()->subDay()); try { $veraUser = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $vera = $veraUser->staffProfiles()->firstOrFail(); app(App\Services\Shift\ShiftSignupService::class)->signUp($shift->refresh(), $vera, $veraUser); print("UNEXPECTED_SUCCESS\n"); } catch (Throwable $e) { print($e->getMessage().PHP_EOL); }'
    ```
22. Confirm the denial message is `Shift signup is not currently open.`
23. Clear the signup window, lock the schedule in the past, and retry Vera signup:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); app(App\Services\Shift\ShiftRequirementService::class)->setSignupWindow($shift, null, null); app(App\Services\Shift\ShiftRequirementService::class)->setScheduleLock($shift->refresh(), now()->subHour()); try { $veraUser = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $vera = $veraUser->staffProfiles()->firstOrFail(); app(App\Services\Shift\ShiftSignupService::class)->signUp($shift->refresh(), $vera, $veraUser); print("UNEXPECTED_SUCCESS\n"); } catch (Throwable $e) { print($e->getMessage().PHP_EOL); }'
    ```
24. Confirm the denial message is `The schedule is locked and cannot be changed.`
25. Clear the schedule lock and sign Vera back up for the morning shift:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); app(App\Services\Shift\ShiftRequirementService::class)->setScheduleLock($shift, null); $veraUser = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $vera = $veraUser->staffProfiles()->firstOrFail(); $outcome = app(App\Services\Shift\ShiftSignupService::class)->signUp($shift->refresh(), $vera, $veraUser); print(json_encode(["assignment_id" => $outcome->assignment->id, "status" => $outcome->assignment->assignment_status], JSON_PRETTY_PRINT).PHP_EOL);'
    ```

### F. Overlap warning and elevated lead assignment

26. Create an overlapping afternoon shift and attempt Vera self-signup:
    ```bash
    php artisan tinker --execute='$morning = App\Models\Shift::query()->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); $overlapStarts = $morning->starts_at->copy()->addHours(4); $overlap = App\Models\Shift::factory()->create(["event_id" => $morning->event_id, "department_id" => $morning->department_id, "eligible_team_id" => $morning->eligible_team_id, "title" => "QA SHIFT Overlap Afternoon", "starts_at" => $overlapStarts, "ends_at" => $overlapStarts->copy()->addHours(8), "capacity" => null, "signup_opens_at" => null, "signup_closes_at" => null, "schedule_lock_at" => null]); $training = $morning->requiredTrainings()->first(); $waiver = $morning->requiredWaivers()->first(); if ($training) { app(App\Services\Shift\ShiftRequirementService::class)->addTrainingRequirement($overlap, $training); } if ($waiver) { app(App\Services\Shift\ShiftRequirementService::class)->addWaiverRequirement($overlap, $waiver); } $veraUser = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $vera = $veraUser->staffProfiles()->firstOrFail(); $outcome = app(App\Services\Shift\ShiftSignupService::class)->signUp($overlap->refresh(), $vera, $veraUser); print(json_encode(["assignment_id" => $outcome->assignment->id, "status" => $outcome->assignment->assignment_status, "warning_count" => count($outcome->warnings), "warnings" => array_map(fn ($w) => $w->message(), $outcome->warnings)], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
27. Confirm signup still succeeds and at least one advisory overlap warning mentions `QA SHIFT Eligibility Morning`.
28. Withdraw Vera from the overlap shift, then have Dana assign Vera to that overlapping shift:
    ```bash
    php artisan tinker --execute='$overlap = App\Models\Shift::query()->where("title", "QA SHIFT Overlap Afternoon")->latest("created_at")->firstOrFail(); $veraUser = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $vera = $veraUser->staffProfiles()->firstOrFail(); $assignment = App\Models\ShiftAssignment::query()->active()->where("staff_id", $vera->id)->where("shift_id", $overlap->id)->firstOrFail(); app(App\Services\Shift\ShiftRemovalService::class)->withdrawFromShift($assignment, $vera, $veraUser); $dana = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); $outcome = app(App\Services\Shift\ShiftAssignmentService::class)->assignStaffToShift($overlap->refresh(), $vera, $dana); print(json_encode(["assignment_id" => $outcome->assignment->id, "status" => $outcome->assignment->assignment_status, "assigned_by_user_id" => $outcome->assignment->assigned_by_user_id, "warning_count" => count($outcome->warnings), "warnings" => array_map(fn ($w) => $w->message(), $outcome->warnings)], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
29. Confirm assignment status is `assigned`, `assigned_by_user_id` is Dana, and overlap warnings are advisory only.
30. Attempt the same lead assignment as Vera and confirm unauthorized denial:
    ```bash
    php artisan tinker --execute='try { $overlap = App\Models\Shift::query()->where("title", "QA SHIFT Overlap Afternoon")->latest("created_at")->firstOrFail(); $veraUser = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $vera = $veraUser->staffProfiles()->firstOrFail(); $samUser = App\Models\User::query()->where("email", "sam.shiftlead@northwood-collective.test")->firstOrFail(); $sam = $samUser->staffProfiles()->firstOrFail(); app(App\Services\Shift\ShiftAssignmentService::class)->assignStaffToShift($overlap, $sam, $veraUser); print("UNEXPECTED_SUCCESS\n"); } catch (Throwable $e) { print($e->getMessage().PHP_EOL); }'
    ```
31. Confirm the denial message is `You are not authorized to assign staff to this shift.`

### G. Lead removal after schedule lock

32. Lock the morning shift schedule and confirm Vera cannot self-withdraw:
    ```bash
    php artisan tinker --execute='$morning = App\Models\Shift::query()->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); app(App\Services\Shift\ShiftRequirementService::class)->setScheduleLock($morning, now()->subMinute()); $veraUser = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $vera = $veraUser->staffProfiles()->firstOrFail(); $assignment = App\Models\ShiftAssignment::query()->active()->where("staff_id", $vera->id)->where("shift_id", $morning->id)->firstOrFail(); try { app(App\Services\Shift\ShiftRemovalService::class)->withdrawFromShift($assignment, $vera, $veraUser); print("UNEXPECTED_SUCCESS\n"); } catch (Throwable $e) { print($e->getMessage().PHP_EOL); }'
    ```
33. Confirm the denial message is `The schedule is locked and cannot be changed.`
34. As Dana, remove Vera from the locked morning shift:
    ```bash
    php artisan tinker --execute='$morning = App\Models\Shift::query()->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); $vera = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $assignment = App\Models\ShiftAssignment::query()->active()->where("staff_id", $vera->id)->where("shift_id", $morning->id)->firstOrFail(); $dana = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); $removed = app(App\Services\Shift\ShiftRemovalService::class)->removeStaffFromShift($assignment, $dana); print(json_encode(["assignment_id" => $removed->id, "removed_at" => (string) $removed->removed_at], JSON_PRETTY_PRINT).PHP_EOL); App\Models\AuditEvent::query()->where("entity_id", $removed->id)->where("action", "shift_assignment.removed")->latest("created_at")->get(["action", "actor_user_id"])->each(fn ($event) => print($event->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
    ```
35. Confirm `removed_at` is set and a `shift_assignment.removed` audit event is attributed to Dana.

### H. Shift roster export (M13.2)

Run this section after sections A through G so the event carries a staffed shift, an unstaffed shift, a lead-assigned staff member, and a removed one.

36. Give Sam contact details the export must never carry, then export as Olive Organizer and save the file:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "sam.shiftlead@northwood-collective.test")->firstOrFail(); $staff->forceFill(["phone" => "+1-208-555-0100", "emergency_contact_name" => "Quinn Contact", "emergency_contact_phone" => "+1-208-555-0199"])->save(); $olive = App\Models\User::query()->where("email", "olive.organizer@northwood-collective.test")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($olive, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_SHIFT_ROSTER_EXPORT); $export = app(App\Services\Reporting\ShiftRosterExportService::class)->export($event, $scope, $olive); file_put_contents(storage_path("app/".$export->filename), $export->contents); print(json_encode(["event_wide" => $scope->organizationWide, "filename" => $export->filename, "row_count" => $export->rowCount, "saved_to" => storage_path("app/".$export->filename)], JSON_PRETTY_PRINT).PHP_EOL); print($export->contents);'
    ```
37. Confirm `event_wide` is true, the header row is `event_name,department,team,shift_title,shift_status,shift_starts_at,shift_ends_at,shift_capacity,assigned_staff_count,staff_legal_name,staff_preferred_name,staff_handle,staff_email,assignment_status,assignment_source`, and the file lists the QA shifts in schedule order with `Rangers` / `Dirt` as their department and team.
38. Confirm Sam appears on `QA SHIFT Overlap Afternoon` with `assignment_status` `assigned` and `assignment_source` `lead_assigned`, and that a self-signup row instead reads `signed_up` / `self_signup`.
39. Confirm the roster reflects what the shift domain recorded, not a recalculation:
    - `QA SHIFT Eligibility Morning` still appears after Dana removed Vera in section G, with `assigned_staff_count` `0` and empty staff columns; an unstaffed shift stays visible.
    - Vera has no row on that shift; a removed staff member is off the roster.
40. Confirm the saved file contains no phone number and no emergency contact:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "sam.shiftlead@northwood-collective.test")->firstOrFail(); $olive = App\Models\User::query()->where("email", "olive.organizer@northwood-collective.test")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($olive, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_SHIFT_ROSTER_EXPORT); $csv = app(App\Services\Reporting\ShiftRosterExportService::class)->export($event, $scope, $olive)->contents; print(json_encode(["phone_present" => str_contains($csv, (string) $staff->phone), "emergency_name_present" => str_contains($csv, (string) $staff->emergency_contact_name), "emergency_phone_present" => str_contains($csv, (string) $staff->emergency_contact_phone)], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
41. Confirm all three values are `false`.
42. Export as Dana Departmentlead and confirm the department-scoped file:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $dana = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); $rangers = App\Models\Department::query()->where("code", "RANGERS")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($dana, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_SHIFT_ROSTER_EXPORT); $export = app(App\Services\Reporting\ShiftRosterExportService::class)->export($event, $scope, $dana); print(json_encode(["event_wide" => $scope->organizationWide, "department_ids" => $scope->departmentIds, "rangers_id" => (string) $rangers->id, "filename" => $export->filename, "row_count" => $export->rowCount], JSON_PRETTY_PRINT).PHP_EOL); print($export->contents);'
    ```
43. Confirm `event_wide` is false, `department_ids` contains only the Rangers id, the filename carries `rangers`, and every exported row belongs to a Rangers shift.
44. Confirm an unauthorized actor resolves no export scope at all, including a staff member who is on the roster themselves:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $access = app(App\Services\Reporting\ReportingExportAccess::class); foreach (["vera.staff@northwood-collective.test", "omar.icoperator@northwood-collective.test"] as $email) { $user = App\Models\User::query()->where("email", $email)->firstOrFail(); print(json_encode([$email => $access->resolve($user, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_SHIFT_ROSTER_EXPORT) === null ? "denied" : "unexpected_scope"]).PHP_EOL); }'
    ```
45. Confirm both personas are `denied`; being on a roster is not authority to export it, and incident authority is not export authority.
46. Confirm each successful export was audited:
    ```bash
    php artisan tinker --execute='App\Models\AuditEvent::query()->where("action", "event_shift_roster.exported")->latest("created_at")->take(5)->get(["actor_user_id", "event_id", "department_id", "after_json"])->each(fn ($event) => print($event->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
    ```
47. Confirm one audit event per export with the acting user, the event, a `scope` of `event` or `department`, and a `row_count` matching the file.
48. Optional HTTP check when a browser session is available for Olive (log in with `QA-AUTH-01`): download `/api/events/{event id}/exports/shift-roster` and confirm the browser saves a `.csv` attachment. Add `?department_id={department id}` to narrow an organizer export to one department, and confirm a department id from another organization returns 404.

### J. Staff shift board and self-service commands (M18.2)

Run this section after sections A through G, which leave the seeded event with a
shift Vera is eligible for and one she is not.

49. Confirm the board and the command give the same answer for the same shift.
    Close the morning shift's signup window, then ask the board's evaluation and
    the command in turn:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); app(App\Services\Shift\ShiftRequirementService::class)->setSignupWindow($shift, now()->subDays(7), now()->subDay()); $user = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $staff = $user->staffProfiles()->firstOrFail(); $verdict = app(App\Services\Shift\ShiftSignupService::class)->evaluateSignup($shift->refresh(), $staff, $user); try { app(App\Services\Shift\ShiftSignupService::class)->signUp($shift->refresh(), $staff, $user); $refusal = "UNEXPECTED_SUCCESS"; } catch (App\Services\Shift\ShiftSignupException $e) { $refusal = $e->getMessage(); } print(json_encode(["board_eligible" => $verdict->eligible, "board_reason_code" => $verdict->reasonCode, "board_reason" => $verdict->message, "command_refusal" => $refusal], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
50. Confirm `board_eligible` is false and `board_reason` and `command_refusal`
    are the same sentence. A board that says something different from what the
    command does is the failure this surface exists to prevent.
51. Reopen the signup window before continuing:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); app(App\Services\Shift\ShiftRequirementService::class)->setSignupWindow($shift, null, null); print("signup window cleared\n");'
    ```
52. Sign in to the client as Vera and open **Shifts** from the Staff menu.
    Confirm the shift board lists the QA shifts for the departments Vera belongs
    to, each showing its window, department and team, and how many people are
    signed up.
53. Confirm a shift Vera may take shows **Open** with a **Sign up** control, and
    that a shift she may not shows **Unavailable** with the node's reason
    printed and **no** signup control at all — absent, not greyed out.
54. Press **Sign up** on the open shift. Confirm the row becomes **Signed up**,
    the signed-up count goes up by one, and a **Withdraw** control replaces
    **Sign up**.
55. Confirm the overlapping afternoon shift from section F still offers **Sign
    up** and shows the overlap sentence naming the shift it clashes with. Take
    it, and confirm the page reports the overlap alongside a signup that
    happened rather than instead of one.
56. Press **Withdraw** on one of them and confirm the row returns to **Open**
    and the count goes back down.
57. Lock the schedule on a shift Vera holds:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->where("title", "QA SHIFT Eligibility Morning")->latest("created_at")->firstOrFail(); app(App\Services\Shift\ShiftRequirementService::class)->setScheduleLock($shift, now()->subMinute()); print("locked\n");'
    ```
58. Reload the board. Confirm the shift still reads **Signed up** — being on a
    shift survives the cutoff — and that the **Withdraw** control is gone with
    the locked-schedule sentence in its place.
59. Put the device into airplane mode, or stop the node, and reload the board.
    Confirm the page states that signing up and withdrawing need a connection,
    the controls are closed, and nothing is queued for later: signup is not one
    of the Alpha 1 offline writes.
60. Confirm the audit trail carries the work done from the screen:
    ```bash
    php artisan tinker --execute='App\Models\AuditEvent::query()->whereIn("action", ["shift_assignment.signed_up", "shift_assignment.withdrawn"])->latest("created_at")->take(5)->get(["action", "actor_user_id", "event_id", "department_id", "source_context"])->each(fn ($event) => print($event->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
    ```
61. Confirm each signup and withdrawal from the board wrote an audit event
    attributed to Vera.

### I. Explicit non-goals for this script

62. Confirm this script did not require Orchid shift screens, public API clients, offline queues, or device sync operations.
63. Confirm unscheduled shift additions during live operations are out of scope here and belong to Milestone 10 / `QA-SLB-01`.
64. Confirm credential eligibility recalculation after signup/removal is verified in `QA-CRED-01-credential-eligibility.md`, not as a duplicate pass/fail gate in this script.
65. Confirm the shift roster export in section H is server-generated and online-only, that no product UI entry point is required for it yet, and that the remaining Alpha 1 exports and their consolidated script belong to M13.3 through M13.9 (`QA-EXPORT-01`).
66. Confirm lead removal from a surface is still out of scope here: section J covers a staff member's own signup and withdrawal, and the lead's removal control belongs to the department operations binding in M18.8.

## Expected results

- A shift can be configured with required trainings and required waivers.
- Eligible staff with current training and waiver completions can self-sign up immediately.
- Self-signup creates an active `signed_up` assignment with null `assigned_by_user_id` and a `shift_assignment.signed_up` audit event.
- Missing required training, missing required waiver, department Ineligible status, Do Not Staff organization status, full capacity, closed signup window, and schedule lock each deny self-signup with the documented messages and leave no successful assignment for the denied actor.
- Schedule overlaps warn by default and do not hard-block self-signup.
- Authorized department leads may assign overlapping shifts; unauthorized users cannot.
- Self-withdrawal is blocked after schedule lock; department-lead removal remains allowed and soft-removes the assignment.
- The shift roster export produces a CSV with the documented header, one row per staff member on a shift, and one row for a shift nobody is on.
- The roster reports assignments as recorded: self-signups and lead assignments are distinguished, removed staff are absent, and a cancelled shift is reported as cancelled rather than dropped.
- Organizers export the whole event; department leads export only their own department; staff on a roster and IC roles resolve no export scope.
- No phone number or emergency contact appears in the export.
- Every successful export writes one `event_shift_roster.exported` audit event naming the actor, event, scope, and row count.
- The shift board offers every shift in the staff member's departments for the event, states the node's reason for each one it will not take them, and offers no control at all on those.
- The board's reason and the command's refusal are the same sentence for the same shift.
- Signing up and withdrawing from the board change the row and the signed-up count on the next read, and each writes its own audit event.
- Overlap appears on the board as a warning beside a shift that is still offered, and again alongside a signup that succeeded.
- A shift someone holds still reads as signed up after the schedule locks, with withdrawal closed rather than the shift disappearing.
- Signup and withdrawal are refused while the device has no connection rather than queued.
- No Orchid signup surface, offline queue, or unscheduled operational add is required for this Alpha 1 QA gate.

## Evidence to capture

- Tinker output creating the QA shift and attaching training/waiver requirements.
- Denial messages for missing training and missing waiver.
- Successful eligible signup output with `signed_up` status and zero warnings.
- Audit evidence for `shift_assignment.signed_up`.
- Denial messages for Ineligible, Do Not Staff, capacity full, closed signup window, and schedule lock.
- Overlap self-signup output showing success plus advisory warning text.
- Lead overlap assignment output with `assigned` status and Dana as assigner.
- Unauthorized lead-assignment denial message.
- Schedule-lock self-withdrawal denial and successful lead removal with `shift_assignment.removed` audit evidence.
- The saved organizer roster export file, plus the department-scoped export output and its filename.
- Sensitive-field check output showing all three values false.
- Denied export-scope output for the staff and IC personas.
- Roster export audit event output.
- The board-versus-command comparison output from section J, showing the same sentence twice.
- Screenshots of the shift board: a shift offered, a shift refused with its reason and no control, an overlap warning, and a signed-up shift after the schedule locked.
- The offline state of the board with its controls closed.
- Audit output for signups and withdrawals made from the screen.

## Failure notes

- If eligible signup requires an approval queue or staged pending state, stop and file a blocking SHIFT-011 issue.
- If missing training or waiver allows signup, stop and file a blocking TRAIN-008 / WAIVER-005 issue.
- If department Ineligible or Do Not Staff allows signup, stop and file a status-enforcement issue.
- If a full shift accepts additional self-signup, stop and file a blocking SHIFT-012 issue.
- If overlap hard-blocks self-signup by default, stop and file a SHIFT-014 issue.
- If a department lead cannot assign an overlapping shift, stop and file a SHIFT-015 issue.
- If self-service changes remain allowed after schedule lock, or lead removal is blocked after lock, stop and file a SHIFT-009 / SHIFT-013 issue.
- If the shift board's reason for refusing a shift differs from the command's refusal, stop and file a blocking SHIFT-018 issue; two rule sets is the failure this surface is built to avoid.
- If a shift the board offers is refused by the command, or a shift it refuses is accepted, stop and file a blocking SHIFT-018 issue.
- If the board renders a disabled signup control instead of no control on a shift somebody may not take, file a CLIENT-005 issue.
- If a signup or withdrawal is queued while the device is offline rather than refused, stop and file a blocking CLIENT-018 / data/API 7.2 issue.
- If a shift somebody holds stops reading as signed up once the schedule locks, file a blocking SHIFT-018 issue: the cutoff closes withdrawal, not the fact of the assignment.
- If this script appears to require Orchid shift UI, public API clients, offline sync, or unscheduled operational adds, stop and report scope leakage; those surfaces are deferred.
- If the roster export carries a phone number or emergency contact, stop and file a blocking REPORT-008 / REPORT-010 issue.
- If a department lead export returns another department's shifts, or a plain staff member resolves an export scope, stop and file a blocking REPORT-006 / REPORT-007 issue.
- If an unstaffed shift is missing from the roster, or a removed staff member is still listed, stop and file a blocking REPORT-002 / SHIFT-013 issue.
- If a successful export writes no audit event, stop and file a blocking data/API section 8 issue.
