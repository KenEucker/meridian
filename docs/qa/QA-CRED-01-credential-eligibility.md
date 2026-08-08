# QA-CRED-01: Credential Eligibility

## Purpose

Verify Milestone 7 event credential eligibility and manual revocation through the domain services that power Alpha 1: a reviewer can confirm that a credential-counting shift signup creates an Eligible credential, later failures move the credential to Blocked with documented reasons, organizers and IC leads can revoke credentials, unauthorized actors cannot, future shifts are removed on revocation while completed shifts remain, revoked state is preserved through recalculation, and unscheduled assignments do not grant credential eligibility.

Section G additionally verifies the Milestone 13 credential eligibility export (M13.1): an organizer exports the whole event, a department lead exports only their own department, unauthorized actors are refused, sensitive contact fields never reach the file, and each successful export is audited.

Section H verifies the M18.5 credential administration surface, which is the product path to the revocation sections A through F reach through tinker: the list states what a revocation would remove and what it would preserve before it happens, the command is restricted to the same two authorities, and the recorded hours of a completed shift survive it unchanged — the half of CRED-013 that could not be checked until hours records existed.

Sections A through G use the documented Laravel domain-service tinker commands, which reach rules the surfaces do not expose. Section H needs a running client and a signed-in session. Run this script independently from `QA-SHIFT-01` after a fresh seed unless noted.

## Requirements covered

- `CRED-001`
- `CRED-002`
- `CRED-003`
- `CRED-004`
- `CRED-005`
- `CRED-006`
- `CRED-007`
- `CRED-008`
- `CRED-009`
- `CRED-010`
- `CRED-011`
- `CRED-012`
- `CRED-013`
- `CRED-014`
- `WAIVER-006`
- `REPORT-001`
- `REPORT-006`
- `REPORT-007`
- `REPORT-010`
- Requirements sections 3.16, 5.6, 5.12, and 7.14
- Data/API spec section 10.11
- Technical spec section 22.2 (CSV export)
- UI implementation contract 12.6 (`organizer.credentials`)
- Meridian Alpha 1 tasks M7.9, M7.10, M7.11, M13.1, M16.22, and M18.5

## Environment

- Fresh checkout or task branch with server dependencies installed.
- Laravel app migrated and seeded from the development scenario:
  ```bash
  cd apps/server
  php artisan migrate:fresh --seed
  ```
- Shell access from `apps/server` for tinker verification commands.
- A running client and the ability to sign in as the personas below for section H. Sections A through G need no UI at all.

## Personas

- Eligible staff subject: seeded Vera Staff (`vera.staff@northwood-collective.test`), active Rangers / Dirt member.
- Organizer revoker: seeded Olive Organizer (`olive.organizer@northwood-collective.test`).
- IC lead revoker: seeded Ingrid ICLead (`ingrid.iclead@northwood-collective.test`).
- Unauthorized department lead: seeded Dana Departmentlead (`dana.departmentlead@northwood-collective.test`).
- Unauthorized IC operator: seeded Omar ICOperator (`omar.icoperator@northwood-collective.test`).
- Lead assigner for planned/unscheduled cases: seeded Dana Departmentlead.

## Setup data

- Organization: `Northwood Collective` with slug `northwood-collective`.
- Event: `Emberfall 2026` with slug `emberfall-2026`.
- Department: `Rangers` with code `RANGERS`.
- Eligible team: `Dirt` with code `DIRT`.
- Create QA shifts and waivers with titles/names prefixed `QA CRED` so cleanup is obvious.
- Ensure Vera has a date of birth before age checks by setting it in the relevant step; do not leave the seeded event permanently age-gated after the age scenario unless restoring it.

## Steps

### A. Eligible credential after credential-counting signup

1. Create a Rangers / Dirt shift for credential signup:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $department = App\Models\Department::query()->where("code", "RANGERS")->firstOrFail(); $team = App\Models\Team::query()->where("department_id", $department->id)->where("code", "DIRT")->firstOrFail(); $starts = now()->addDays(10)->setTime(9, 0); $shift = App\Models\Shift::factory()->create(["event_id" => $event->id, "department_id" => $department->id, "eligible_team_id" => $team->id, "title" => "QA CRED Morning Gate", "starts_at" => $starts, "ends_at" => $starts->copy()->addHours(8), "capacity" => null, "signup_opens_at" => null, "signup_closes_at" => null, "schedule_lock_at" => null]); print(json_encode(["shift_id" => $shift->id, "event_id" => $event->id, "starts_at" => (string) $shift->starts_at], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
2. Sign Vera up and inspect the resulting credential:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->where("title", "QA CRED Morning Gate")->latest("created_at")->firstOrFail(); $user = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $staff = $user->staffProfiles()->firstOrFail(); $outcome = app(App\Services\Shift\ShiftSignupService::class)->signUp($shift, $staff, $user); $credential = App\Models\EventCredential::query()->where("event_id", $shift->event_id)->where("staff_id", $staff->id)->first(); print(json_encode(["assignment_id" => $outcome->assignment->id, "assignment_status" => $outcome->assignment->assignment_status, "credential_id" => $credential?->id, "status" => $credential?->status, "status_reason" => $credential?->status_reason, "revoked_at" => $credential?->revoked_at], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
3. Confirm one credential exists for the event/staff pair with status `eligible` and null `status_reason`.
4. Confirm recalculation does not create a second credential row:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); app(App\Services\Credential\CredentialEligibilityService::class)->recalculate($event, $staff); print(App\Models\EventCredential::query()->where("event_id", $event->id)->where("staff_id", $staff->id)->count().PHP_EOL);'
    ```
5. Confirm the count remains `1`.

### B. Removing all shifts blocks credential eligibility

6. Withdraw Vera from the morning shift and re-check credential state:
    ```bash
    php artisan tinker --execute='$user = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $staff = $user->staffProfiles()->firstOrFail(); $assignment = App\Models\ShiftAssignment::query()->active()->where("staff_id", $staff->id)->whereHas("shift", fn ($q) => $q->where("title", "QA CRED Morning Gate"))->firstOrFail(); app(App\Services\Shift\ShiftRemovalService::class)->withdrawFromShift($assignment, $staff, $user); $credential = App\Models\EventCredential::query()->where("staff_id", $staff->id)->whereHas("event", fn ($q) => $q->where("slug", "emberfall-2026"))->firstOrFail(); print(json_encode(["status" => $credential->status, "status_reason" => $credential->status_reason], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
7. Confirm status is `blocked` with reason `no_signed_up_shifts`.
8. Sign Vera back up so later blocking scenarios start from Eligible:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->where("title", "QA CRED Morning Gate")->latest("created_at")->firstOrFail(); $user = App\Models\User::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $staff = $user->staffProfiles()->firstOrFail(); app(App\Services\Shift\ShiftSignupService::class)->signUp($shift, $staff, $user); $credential = App\Models\EventCredential::query()->where("event_id", $shift->event_id)->where("staff_id", $staff->id)->firstOrFail(); print(json_encode(["status" => $credential->status, "status_reason" => $credential->status_reason], JSON_PRETTY_PRINT).PHP_EOL);'
    ```

### C. Expired required waiver blocks credential

9. Attach an expiring required waiver, complete it, then expire the completion and recalculate:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->where("title", "QA CRED Morning Gate")->latest("created_at")->firstOrFail(); $organization = $shift->event->organization; $staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $recorder = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); $waiver = app(App\Services\Waiver\WaiverService::class)->create($organization, App\Models\Waiver::SCOPE_ORGANIZATION, $organization->id, "QA CRED Expiring Waiver", null, 30); app(App\Services\Shift\ShiftRequirementService::class)->addWaiverRequirement($shift, $waiver); app(App\Services\Waiver\WaiverService::class)->recordCompletion($waiver, $staff, null, $recorder); $waiver->completions()->where("staff_id", $staff->id)->update(["expires_at" => now()->subDay()]); $credential = app(App\Services\Credential\CredentialEligibilityService::class)->recalculate($shift->event, $staff); print(json_encode(["status" => $credential?->status, "status_reason" => $credential?->status_reason], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
10. Confirm status is `blocked` with reason `missing_required_waiver`.
11. Remove the waiver requirement and recalculate so later steps are not stuck on the expired waiver:
    ```bash
    php artisan tinker --execute='$shift = App\Models\Shift::query()->where("title", "QA CRED Morning Gate")->latest("created_at")->firstOrFail(); $waiver = App\Models\Waiver::query()->where("name", "QA CRED Expiring Waiver")->firstOrFail(); App\Models\ShiftWaiverRequirement::query()->where("shift_id", $shift->id)->where("waiver_id", $waiver->id)->delete(); $staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $credential = app(App\Services\Credential\CredentialEligibilityService::class)->recalculate($shift->event, $staff); print(json_encode(["status" => $credential?->status, "status_reason" => $credential?->status_reason], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
12. Confirm status returns to `eligible`.

### D. Age, organization DNS, and department Ineligible blocking

13. Set an event minimum age Vera does not satisfy, then recalculate:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $originalAge = $event->minimum_staff_age; $originalDob = $staff->date_of_birth; $event->forceFill(["starts_at" => $event->starts_at ?? now()->addMonths(2), "minimum_staff_age" => 21])->save(); $staff->forceFill(["date_of_birth" => now()->subYears(18)->toDateString()])->save(); $credential = app(App\Services\Credential\CredentialEligibilityService::class)->recalculate($event->refresh(), $staff->refresh()); print(json_encode(["status" => $credential?->status, "status_reason" => $credential?->status_reason], JSON_PRETTY_PRINT).PHP_EOL); $event->forceFill(["minimum_staff_age" => $originalAge])->save(); $staff->forceFill(["date_of_birth" => $originalDob])->save(); app(App\Services\Credential\CredentialEligibilityService::class)->recalculate($event->refresh(), $staff->refresh());'
    ```
14. Confirm the temporary blocked reason is `age_requirement_not_satisfied`.
15. Clear date of birth while a minimum age remains configured and confirm missing-DOB blocking, then restore:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $originalAge = $event->minimum_staff_age; $originalDob = $staff->date_of_birth; $event->forceFill(["minimum_staff_age" => 18])->save(); $staff->forceFill(["date_of_birth" => null])->save(); $credential = app(App\Services\Credential\CredentialEligibilityService::class)->recalculate($event->refresh(), $staff->refresh()); print(json_encode(["status" => $credential?->status, "status_reason" => $credential?->status_reason], JSON_PRETTY_PRINT).PHP_EOL); $event->forceFill(["minimum_staff_age" => $originalAge])->save(); $staff->forceFill(["date_of_birth" => $originalDob ?? now()->subYears(30)->toDateString()])->save(); $restored = app(App\Services\Credential\CredentialEligibilityService::class)->recalculate($event->refresh(), $staff->refresh()); print(json_encode(["restored_status" => $restored?->status, "restored_reason" => $restored?->status_reason], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
16. Confirm the temporary blocked reason is `missing_date_of_birth` and restored status is `eligible`.
17. Temporarily set Vera to organization Do Not Staff, recalculate, then restore Active:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $changer = App\Models\User::query()->where("email", "olive.organizer@northwood-collective.test")->firstOrFail(); $orgStatus = App\Models\StaffOrganizationStatus::query()->where("organization_id", $event->organization_id)->where("staff_id", $staff->id)->firstOrFail(); app(App\Services\Status\StaffStatusService::class)->transitionOrganizationStatus($orgStatus, App\Models\StaffOrganizationStatus::STATUS_DO_NOT_STAFF, "QA CRED temporary DNS", $changer); $blocked = app(App\Services\Credential\CredentialEligibilityService::class)->recalculate($event, $staff); print(json_encode(["status" => $blocked?->status, "status_reason" => $blocked?->status_reason], JSON_PRETTY_PRINT).PHP_EOL); app(App\Services\Status\StaffStatusService::class)->transitionOrganizationStatus($orgStatus->refresh(), App\Models\StaffOrganizationStatus::STATUS_ACTIVE, "QA CRED restore active", $changer); $restored = app(App\Services\Credential\CredentialEligibilityService::class)->recalculate($event, $staff); print(json_encode(["restored_status" => $restored?->status, "restored_reason" => $restored?->status_reason], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
18. Confirm temporary reason `organization_blocking_status`, then restored `eligible`.
19. Temporarily mark Vera's Rangers membership Ineligible, recalculate, then restore Active:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $shift = App\Models\Shift::query()->where("title", "QA CRED Morning Gate")->latest("created_at")->firstOrFail(); $membership = App\Models\DepartmentMembership::query()->active()->where("staff_id", $staff->id)->where("department_id", $shift->department_id)->firstOrFail(); app(App\Services\Status\StaffStatusService::class)->transitionDepartmentStatus($membership, App\Models\DepartmentMembership::STATUS_INELIGIBLE, "QA CRED temporary ineligible"); $blocked = app(App\Services\Credential\CredentialEligibilityService::class)->recalculate($event, $staff); print(json_encode(["status" => $blocked?->status, "status_reason" => $blocked?->status_reason], JSON_PRETTY_PRINT).PHP_EOL); app(App\Services\Status\StaffStatusService::class)->transitionDepartmentStatus($membership->refresh(), App\Models\DepartmentMembership::STATUS_ACTIVE, null); $restored = app(App\Services\Credential\CredentialEligibilityService::class)->recalculate($event, $staff); print(json_encode(["restored_status" => $restored?->status, "restored_reason" => $restored?->status_reason], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
20. Confirm temporary reason `department_ineligible`, then restored `eligible`.

### E. Manual revocation authorization and future-shift removal

21. Create a completed past shift assignment and a future shift assignment for Vera:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $department = App\Models\Department::query()->where("code", "RANGERS")->firstOrFail(); $team = App\Models\Team::query()->where("department_id", $department->id)->where("code", "DIRT")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $completed = App\Models\Shift::factory()->create(["event_id" => $event->id, "department_id" => $department->id, "eligible_team_id" => $team->id, "title" => "QA CRED Completed Past", "starts_at" => now()->subDays(3)->setTime(9, 0), "ends_at" => now()->subDays(3)->setTime(17, 0)]); $future = App\Models\Shift::factory()->create(["event_id" => $event->id, "department_id" => $department->id, "eligible_team_id" => $team->id, "title" => "QA CRED Future Afternoon", "starts_at" => now()->addDays(14)->setTime(13, 0), "ends_at" => now()->addDays(14)->setTime(21, 0)]); $completedAssignment = App\Models\ShiftAssignment::factory()->create(["shift_id" => $completed->id, "staff_id" => $staff->id, "assignment_status" => App\Models\ShiftAssignment::STATUS_SIGNED_UP, "assigned_by_user_id" => null, "removed_at" => null]); $futureAssignment = App\Models\ShiftAssignment::factory()->create(["shift_id" => $future->id, "staff_id" => $staff->id, "assignment_status" => App\Models\ShiftAssignment::STATUS_SIGNED_UP, "assigned_by_user_id" => null, "removed_at" => null]); print(json_encode(["completed_assignment_id" => $completedAssignment->id, "future_assignment_id" => $futureAssignment->id], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
22. Attempt revocation as Dana and confirm unauthorized denial:
    ```bash
    php artisan tinker --execute='try { $event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $dana = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); app(App\Services\Credential\CredentialRevocationService::class)->revoke($event, $staff, $dana, "QA should fail"); print("UNEXPECTED_SUCCESS\n"); } catch (Throwable $e) { print($e->getMessage().PHP_EOL); }'
    ```
23. Confirm the denial message is `You are not authorized to revoke event credentials.`
24. Attempt revocation as Omar ICOperator and confirm the same unauthorized denial:
    ```bash
    php artisan tinker --execute='try { $event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $omar = App\Models\User::query()->where("email", "omar.icoperator@northwood-collective.test")->firstOrFail(); app(App\Services\Credential\CredentialRevocationService::class)->revoke($event, $staff, $omar, "QA should fail"); print("UNEXPECTED_SUCCESS\n"); } catch (Throwable $e) { print($e->getMessage().PHP_EOL); }'
    ```
25. Revoke as Olive Organizer:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $olive = App\Models\User::query()->where("email", "olive.organizer@northwood-collective.test")->firstOrFail(); $credential = app(App\Services\Credential\CredentialRevocationService::class)->revoke($event, $staff, $olive, "QA CRED organizer revocation"); $completed = App\Models\ShiftAssignment::query()->whereHas("shift", fn ($q) => $q->where("title", "QA CRED Completed Past"))->where("staff_id", $staff->id)->firstOrFail(); $future = App\Models\ShiftAssignment::query()->whereHas("shift", fn ($q) => $q->where("title", "QA CRED Future Afternoon"))->where("staff_id", $staff->id)->firstOrFail(); $morning = App\Models\ShiftAssignment::query()->whereHas("shift", fn ($q) => $q->where("title", "QA CRED Morning Gate"))->where("staff_id", $staff->id)->first(); print(json_encode(["credential_status" => $credential->status, "status_reason" => $credential->status_reason, "revoked_at" => (string) $credential->revoked_at, "changed_by" => $credential->changed_by_user_id, "completed_removed_at" => $completed->removed_at, "future_removed_at" => (string) $future->removed_at, "morning_removed_at" => $morning?->removed_at], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
26. Confirm credential status is `revoked`, reason `manual_revocation`, `revoked_at` is set, completed assignment `removed_at` remains null, and future/active non-completed assignments have `removed_at` set.
27. Verify audit history for revocation and future-shift removal:
    ```bash
    php artisan tinker --execute='$staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $credential = App\Models\EventCredential::query()->where("staff_id", $staff->id)->whereHas("event", fn ($q) => $q->where("slug", "emberfall-2026"))->firstOrFail(); App\Models\AuditEvent::query()->where("entity_id", $credential->id)->where("action", "event_credential.revoked")->latest("created_at")->get(["action", "actor_user_id", "reason"])->each(fn ($event) => print($event->toJson(JSON_PRETTY_PRINT).PHP_EOL)); App\Models\AuditEvent::query()->where("action", "shift_assignment.removed_by_credential_revocation")->latest("created_at")->take(5)->get(["action", "entity_id", "actor_user_id"])->each(fn ($event) => print($event->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
    ```
28. Confirm at least one `event_credential.revoked` event attributed to Olive and one or more `shift_assignment.removed_by_credential_revocation` events.
29. Recalculate after revocation and confirm revoked state is preserved:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $credential = app(App\Services\Credential\CredentialEligibilityService::class)->recalculate($event, $staff); print(json_encode(["status" => $credential?->status, "status_reason" => $credential?->status_reason, "revoked_at" => (string) $credential?->revoked_at], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
30. Confirm status remains `revoked` and is not auto-moved back to eligible/blocked.

### F. IC lead revocation path and unscheduled work

31. Fresh-seed is recommended before this section if Vera is already revoked from section E. If continuing without a fresh seed, create a second staff/shift scenario using Sam instead:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $department = App\Models\Department::query()->where("code", "RANGERS")->firstOrFail(); $team = App\Models\Team::query()->where("department_id", $department->id)->where("code", "DIRT")->firstOrFail(); $samUser = App\Models\User::query()->where("email", "sam.shiftlead@northwood-collective.test")->firstOrFail(); $sam = $samUser->staffProfiles()->firstOrFail(); $starts = now()->addDays(12)->setTime(10, 0); $shift = App\Models\Shift::factory()->create(["event_id" => $event->id, "department_id" => $department->id, "eligible_team_id" => $team->id, "title" => "QA CRED Sam Future", "starts_at" => $starts, "ends_at" => $starts->copy()->addHours(8)]); $outcome = app(App\Services\Shift\ShiftSignupService::class)->signUp($shift, $sam, $samUser); $ingrid = App\Models\User::query()->where("email", "ingrid.iclead@northwood-collective.test")->firstOrFail(); $credential = app(App\Services\Credential\CredentialRevocationService::class)->revoke($event, $sam, $ingrid, "QA CRED IC lead revocation"); print(json_encode(["assignment_id" => $outcome->assignment->id, "credential_status" => $credential->status, "changed_by" => $credential->changed_by_user_id, "assignment_removed_at" => (string) $outcome->assignment->fresh()->removed_at], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
32. Confirm Ingrid can revoke and the future Sam assignment is removed.
33. Verify unscheduled lead assignment after shift start does not grant credential eligibility. Use a fresh staff member without prior credential-counting assignments:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $department = App\Models\Department::query()->where("code", "RANGERS")->firstOrFail(); $team = App\Models\Team::query()->where("department_id", $department->id)->where("code", "DIRT")->firstOrFail(); $staff = App\Models\Staff::factory()->create(["legal_name" => "QA CRED Unscheduled", "email" => "qa.cred.unscheduled@example.test", "date_of_birth" => "1990-01-15"]); App\Models\StaffOrganizationStatus::factory()->create(["organization_id" => $event->organization_id, "staff_id" => $staff->id, "status" => App\Models\StaffOrganizationStatus::STATUS_ACTIVE]); app(App\Services\Membership\DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department); $shift = App\Models\Shift::factory()->create(["event_id" => $event->id, "department_id" => $department->id, "eligible_team_id" => $team->id, "title" => "QA CRED Unscheduled Live", "starts_at" => now()->subHours(2), "ends_at" => now()->addHours(6)]); $dana = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); $assignment = App\Models\ShiftAssignment::factory()->create(["shift_id" => $shift->id, "staff_id" => $staff->id, "assignment_status" => App\Models\ShiftAssignment::STATUS_ASSIGNED, "assigned_by_user_id" => $dana->id, "created_at" => now()->subHour(), "updated_at" => now()->subHour()]); $evaluation = app(App\Services\Credential\CredentialEligibilityService::class)->evaluate($event, $staff); $recalculated = app(App\Services\Credential\CredentialEligibilityService::class)->recalculate($event, $staff); print(json_encode(["assignment_status" => $assignment->assignment_status, "counts" => app(App\Services\Credential\CredentialEligibilityService::class)->countsTowardCredentialEligibility($assignment), "eligible" => $evaluation->eligible, "block_reason" => $evaluation->blockReason, "recalculated_is_null" => $recalculated === null], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
34. Confirm `counts` is false, evaluation is not eligible with reason `no_signed_up_shifts`, and `recalculated_is_null` is true (no credential created).
35. Verify a planned lead assignment created before shift start does count:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $department = App\Models\Department::query()->where("code", "RANGERS")->firstOrFail(); $team = App\Models\Team::query()->where("department_id", $department->id)->where("code", "DIRT")->firstOrFail(); $staff = App\Models\Staff::factory()->create(["legal_name" => "QA CRED Planned", "email" => "qa.cred.planned@example.test", "date_of_birth" => "1990-01-15"]); App\Models\StaffOrganizationStatus::factory()->create(["organization_id" => $event->organization_id, "staff_id" => $staff->id, "status" => App\Models\StaffOrganizationStatus::STATUS_ACTIVE]); app(App\Services\Membership\DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department); $starts = now()->addDays(5)->setTime(9, 0); $shift = App\Models\Shift::factory()->create(["event_id" => $event->id, "department_id" => $department->id, "eligible_team_id" => $team->id, "title" => "QA CRED Planned Future", "starts_at" => $starts, "ends_at" => $starts->copy()->addHours(8)]); $dana = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); $assignment = App\Models\ShiftAssignment::factory()->create(["shift_id" => $shift->id, "staff_id" => $staff->id, "assignment_status" => App\Models\ShiftAssignment::STATUS_ASSIGNED, "assigned_by_user_id" => $dana->id, "created_at" => now(), "updated_at" => now()]); $credential = app(App\Services\Credential\CredentialEligibilityService::class)->recalculate($event, $staff); print(json_encode(["counts" => app(App\Services\Credential\CredentialEligibilityService::class)->countsTowardCredentialEligibility($assignment), "status" => $credential?->status, "status_reason" => $credential?->status_reason], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
36. Confirm `counts` is true and credential status is `eligible`.

### G. Credential eligibility export (M13.1)

Run this section after section A so at least one Eligible credential exists. A fresh seed followed by section A is enough.

37. Give Vera contact details the export must never carry, then export as Olive Organizer and save the file:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $staff->forceFill(["phone" => "+1-208-555-0100", "emergency_contact_name" => "Quinn Contact", "emergency_contact_phone" => "+1-208-555-0199"])->save(); $olive = App\Models\User::query()->where("email", "olive.organizer@northwood-collective.test")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($olive, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT); $export = app(App\Services\Reporting\CredentialEligibilityExportService::class)->export($event, $scope, $olive); file_put_contents(storage_path("app/".$export->filename), $export->contents); print(json_encode(["event_wide" => $scope->organizationWide, "filename" => $export->filename, "row_count" => $export->rowCount, "saved_to" => storage_path("app/".$export->filename)], JSON_PRETTY_PRINT).PHP_EOL); print($export->contents);'
    ```
38. Confirm `event_wide` is true, the header row is `event_name,staff_legal_name,staff_preferred_name,staff_handle,staff_email,departments,credential_status,status_reason,status_reason_label,credential_shift_count,credential_updated_at,revoked_at`, and Vera appears with status `eligible`, department `Rangers`, and a credential shift count of at least 1.
39. Confirm the saved file contains no phone number, emergency contact, or date of birth:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $olive = App\Models\User::query()->where("email", "olive.organizer@northwood-collective.test")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($olive, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT); $csv = app(App\Services\Reporting\CredentialEligibilityExportService::class)->export($event, $scope, $olive)->contents; print(json_encode(["phone_present" => str_contains($csv, (string) $staff->phone), "emergency_name_present" => str_contains($csv, (string) $staff->emergency_contact_name), "emergency_phone_present" => str_contains($csv, (string) $staff->emergency_contact_phone), "date_of_birth_present" => $staff->date_of_birth !== null && str_contains($csv, $staff->date_of_birth->format("Y-m-d"))], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
40. Confirm all four values are `false`.
41. Export as Dana Departmentlead and confirm the department-scoped file:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $dana = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($dana, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT); $rangers = App\Models\Department::query()->where("code", "RANGERS")->firstOrFail(); $export = app(App\Services\Reporting\CredentialEligibilityExportService::class)->export($event, $scope, $dana); print(json_encode(["event_wide" => $scope->organizationWide, "department_ids" => $scope->departmentIds, "rangers_id" => (string) $rangers->id, "filename" => $export->filename, "row_count" => $export->rowCount], JSON_PRETTY_PRINT).PHP_EOL); print($export->contents);'
    ```
42. Confirm `event_wide` is false, `department_ids` contains only the Rangers id, the filename carries `rangers`, and every exported row belongs to a Rangers member.
43. Confirm an unauthorized actor resolves no export scope at all:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $access = app(App\Services\Reporting\ReportingExportAccess::class); foreach (["omar.icoperator@northwood-collective.test", "ivy.icviewer@northwood-collective.test"] as $email) { $user = App\Models\User::query()->where("email", $email)->firstOrFail(); print(json_encode([$email => $access->resolve($user, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT) === null ? "denied" : "unexpected_scope"]).PHP_EOL); }'
    ```
44. Confirm both IC personas are `denied`; incident authority is not export authority.
45. Confirm each successful export was audited:
    ```bash
    php artisan tinker --execute='App\Models\AuditEvent::query()->where("action", "event_credential_eligibility.exported")->latest("created_at")->take(5)->get(["actor_user_id", "event_id", "department_id", "after_json"])->each(fn ($event) => print($event->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
    ```
46. Confirm one audit event per export with the acting user, the event, a `scope` of `event` or `department`, and a `row_count` matching the file.
47. Optional HTTP check when a browser session is available for Olive (log in with `QA-AUTH-01`): download `/api/events/{event id}/exports/credential-eligibility` and confirm the browser saves a `.csv` attachment. Add `?department_id={department id}` to narrow an organizer export to one department, and confirm a department id from another organization returns 404.
48. Optional product surface check (M16.22), when a client is running and Olive can sign in to it: open Credentials from the home directory's Organization pages, confirm the page names the event, states that an organizer exports every department while a department role exports its own, and lists the excluded fields and the columns before anything is generated. Press `Export CSV` and confirm the file that saves is the same file section G produced. Sign in as Ira Ineligible and confirm no Credentials entry appears anywhere in navigation, and that opening `/organizer/credentials` directly states which authorities the page's two featuresets require rather than offering either. Take the device offline as Olive and confirm the export button is disabled with a stated reason rather than queueing.

### H. Credential administration through the product path (M18.5)

Run this section after a fresh seed. It repeats section E's decision through the surface an organizer actually has, and adds the one thing section E could not check: that recorded hours survive it.

49. Give Vera one completed shift with recorded hours and two future shifts, so the surface has something to preserve and something to remove:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $department = App\Models\Department::query()->where("code", "RANGERS")->firstOrFail(); $team = App\Models\Team::query()->where("department_id", $department->id)->where("code", "DIRT")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $completed = App\Models\Shift::factory()->create(["event_id" => $event->id, "department_id" => $department->id, "eligible_team_id" => $team->id, "title" => "QA CRED M18.5 Completed", "starts_at" => now()->subDays(2)->setTime(9, 0), "ends_at" => now()->subDays(2)->setTime(17, 0)]); App\Models\ShiftAssignment::factory()->create(["shift_id" => $completed->id, "staff_id" => $staff->id, "assignment_status" => App\Models\ShiftAssignment::STATUS_SIGNED_UP, "assigned_by_user_id" => null, "removed_at" => null]); $hours = App\Models\HoursWorked::factory()->create(["shift_id" => $completed->id, "staff_id" => $staff->id, "actual_started_at" => now()->subDays(2)->setTime(9, 2), "actual_ended_at" => now()->subDays(2)->setTime(17, 0), "minutes_worked" => 478]); foreach ([10, 12] as $offset) { $starts = now()->addDays($offset)->setTime(13, 0); $shift = App\Models\Shift::factory()->create(["event_id" => $event->id, "department_id" => $department->id, "eligible_team_id" => $team->id, "title" => "QA CRED M18.5 Future ".$offset, "starts_at" => $starts, "ends_at" => $starts->copy()->addHours(8)]); App\Models\ShiftAssignment::factory()->create(["shift_id" => $shift->id, "staff_id" => $staff->id, "assignment_status" => App\Models\ShiftAssignment::STATUS_SIGNED_UP, "assigned_by_user_id" => null, "removed_at" => null]); } app(App\Services\Credential\CredentialEligibilityService::class)->recalculate($event, $staff); print(json_encode(["hours_worked_id" => $hours->id, "minutes_worked" => $hours->minutes_worked], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
50. Sign in to the client as Olive Organizer and open Credentials from the home directory's Organization pages. Confirm the page carries both featuresets: the credential list and the eligibility export.
51. Find Vera in the list. Confirm her row states `2 upcoming shifts would be removed` and `1 completed shift and 7 hr 58 min recorded stay on the record` before anything is pressed. This is CRED-012 and CRED-013 stated as the decision rather than discovered afterwards.
52. Type part of another staff member's name into the filter and confirm the list narrows without the page reloading or the node being asked again; clear it.
53. Press `Revoke credential` on Vera's row, type `QA CRED M18.5 product path` as the reason, and confirm. Confirm her row becomes `Revoked` with reason `Manual revocation`, reports `No upcoming shifts to remove`, still reports the completed shift and the recorded hours, and offers no control to revoke again.
54. Confirm the recorded hours record is untouched, which is the half of CRED-013 no earlier script could check:
    ```bash
    php artisan tinker --execute='$staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $hours = App\Models\HoursWorked::query()->where("staff_id", $staff->id)->whereHas("shift", fn ($q) => $q->where("title", "QA CRED M18.5 Completed"))->firstOrFail(); $completed = App\Models\ShiftAssignment::query()->where("staff_id", $staff->id)->whereHas("shift", fn ($q) => $q->where("title", "QA CRED M18.5 Completed"))->firstOrFail(); $future = App\Models\ShiftAssignment::query()->where("staff_id", $staff->id)->whereHas("shift", fn ($q) => $q->where("title", "like", "QA CRED M18.5 Future%"))->get(); print(json_encode(["minutes_worked" => $hours->minutes_worked, "status" => $hours->status, "actual_started_at" => (string) $hours->actual_started_at, "actual_ended_at" => (string) $hours->actual_ended_at, "completed_removed_at" => $completed->removed_at, "future_removed" => $future->every(fn ($a) => $a->removed_at !== null)], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
55. Confirm `minutes_worked` is still 478, `status` is `recorded`, both actual times are unchanged, `completed_removed_at` is null, and `future_removed` is true.
56. Confirm the audit entry carries the typed reason:
    ```bash
    php artisan tinker --execute='App\Models\AuditEvent::query()->where("action", "event_credential.revoked")->latest("created_at")->take(1)->get(["actor_user_id", "reason", "source_context"])->each(fn ($event) => print($event->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
    ```
57. Sign in as Dana Departmentlead and open Credentials. Confirm the page offers the eligibility export and no credential list, and that `GET /api/events/{event id}/credentials` returns 403 with `Only organizers and Incident Command leads may administer event credentials.` A department lead reads the same records as a file (REPORT-007) and administers none of them.
58. Sign in as Ingrid ICLead and open Credentials. Confirm the opposite shape: the credential list is present and no export is offered.
59. Sign in as Omar ICOperator and confirm no Credentials entry appears in navigation at all, and that `POST /api/commands/revoke-credential` for Vera returns 403.
60. As Olive, take the device offline and confirm the revoke controls are disabled with a stated reason rather than queueing the decision.

### I. Explicit non-goals for this script

61. Confirm this script did not require Orchid credential screens, public API clients, offline queues, or device sync operations.
62. Confirm physical credential issuance is out of scope.
63. Confirm shift signup eligibility denials themselves are covered by `QA-SHIFT-01-shift-signup-eligibility.md`.
64. Confirm the credential eligibility export in section G is server-generated and online-only, that it and the credential administration of section H are separate authorities on one page (`organizer.credentials`, M16.22 and M18.5), and that the remaining Alpha 1 exports and their consolidated script belong to M13.2 through M13.9 (`QA-EXPORT-01`).

## Expected results

- A credential-counting self-signup creates at most one event credential with status `eligible`.
- Removing all credential-counting shifts moves an existing credential to `blocked` with reason `no_signed_up_shifts`.
- Expired required waivers block credential eligibility with reason `missing_required_waiver`.
- Age requirements and missing date of birth block eligibility with the documented reasons.
- Organization Do Not Staff and department Ineligible block eligibility with the documented reasons.
- Manual revocation is limited to organizers and IC leads; department leads and IC operators are denied.
- Revocation sets `revoked` / `manual_revocation` / `revoked_at`, removes future non-completed assignments, preserves completed assignments, writes audit events, and is not undone by automatic recalculation.
- Unscheduled lead assignments created after shift start do not grant credential eligibility; planned lead assignments before shift start do.
- The credential eligibility export produces a CSV with the documented header, reports recorded credential status and reason without recalculating it, and counts only credential-counting shifts.
- Organizers export the whole event; department leads export only their own department; IC roles resolve no export scope.
- No phone number, emergency contact, or date of birth appears in the export.
- Every successful export writes one `event_credential_eligibility.exported` audit event naming the actor, event, scope, and row count.
- The `organizer.credentials` entry point states the scope and the excluded fields before generating, downloads through a short-lived scoped link, is absent for a user holding no export capability, and refuses rather than queues while the device is offline.
- The credential list on that same page states what a revocation would remove and what it would preserve before it is pressed, keeps revoked rows visible with no control to revoke them again, and filters what it already holds without asking the node again.
- Revoking through the product path leaves the `hours_worked` record byte-for-byte as it was — minutes, status, and both actual times — and removes only the assignments on shifts that have not ended.
- The two featuresets answer to separate authorities on one page: an organizer holds both, a department lead sees the export alone, an Incident Command lead sees the list alone, and an IC operator reaches neither.
- No physical credential issuance, Orchid/API UI, or offline sync is required for this Alpha 1 QA gate.

## Evidence to capture

- Tinker output showing Eligible credential after signup and a single credential row after recalculation.
- Blocked credential after withdrawal with reason `no_signed_up_shifts`.
- Blocked credential after expired waiver with reason `missing_required_waiver`.
- Temporary age and missing-DOB block outputs, plus restored Eligible evidence.
- Temporary DNS and department Ineligible block outputs, plus restored Eligible evidence.
- Unauthorized revocation denial messages for Dana and Omar.
- Organizer revocation output showing revoked credential, preserved completed assignment, removed future assignment, and related audit events.
- Recalculation output proving revoked state is preserved.
- IC lead revocation evidence for Sam.
- Unscheduled vs planned assignment counting evidence.
- The credential list showing Vera's row before revocation, with the upcoming and preserved counts on it.
- The same row after revocation, and the tinker output proving the hours record is unchanged.
- The audit entry carrying the reason typed into the surface.
- The three role shapes of the Credentials page: organizer, department lead, and Incident Command lead.
- The saved organizer export file, plus the department-scoped export output and its filename.
- If the optional surface check ran: the Credentials page showing its scope and exclusions, and the file it downloaded.
- Sensitive-field check output showing all four values false.
- Denied export-scope output for the IC personas.
- Export audit event output.

## Failure notes

- If signup does not create an Eligible credential when requirements are satisfied, stop and file a blocking CRED-004 / CRED-009 issue.
- If more than one credential row exists for the same event/staff pair, stop and file a blocking CRED-003 issue.
- If removing all shifts leaves the credential Eligible, stop and file a blocking CRED-010 issue.
- If an expired required waiver leaves the credential Eligible, stop and file a blocking WAIVER-006 / CRED-005 issue.
- If DNS or department Ineligible leaves the credential Eligible, stop and file a CRED-007 / CRED-008 issue.
- If Dana or Omar can revoke, stop and file a blocking CRED-011 issue.
- If revocation deletes completed assignments or fails to remove future assignments, stop and file a CRED-012 / CRED-013 issue.
- If revoking through the product path changes an `hours_worked` record in any way, stop and file a blocking CRED-013 issue; recorded hours are what a person is paid, credited, and remembered by.
- If a department lead can read the credential list, or an Incident Command lead cannot, stop and file a blocking CRED-011 issue.
- If recalculation moves a revoked credential back to eligible/blocked, stop and file a CRED-009 issue.
- If an unscheduled after-start assignment grants credential eligibility, stop and file a blocking CRED-014 issue.
- If this script appears to require Orchid credential UI, physical badge issuance, offline sync, or hours-record checks beyond completed assignments, stop and report scope leakage.
- If the export carries a phone number, emergency contact, or date of birth, stop and file a blocking REPORT-008 / REPORT-010 issue.
- If a department lead export returns another department's staff, or an IC persona resolves an export scope, stop and file a blocking REPORT-006 / REPORT-007 issue.
- If a successful export writes no audit event, stop and file a blocking data/API section 8 issue.
