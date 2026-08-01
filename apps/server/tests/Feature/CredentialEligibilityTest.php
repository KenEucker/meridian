<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventCredential;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Credential\CredentialEligibilityService;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Shift\ShiftRemovalService;
use App\Services\Shift\ShiftRequirementService;
use App\Services\Shift\ShiftSignupService;
use App\Services\Status\StaffStatusService;
use App\Services\Waiver\WaiverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CredentialEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_with_signed_up_shift_becomes_credential_eligible(): void
    {
        [$event, $staff, $user, $shift] = $this->credentialScenario();

        app(ShiftSignupService::class)->signUp($shift, $staff, $user);

        $credential = EventCredential::query()
            ->where('event_id', $event->id)
            ->where('staff_id', $staff->id)
            ->first();

        $this->assertNotNull($credential);
        $this->assertTrue($credential->isEligible());
        $this->assertNull($credential->status_reason);
    }

    public function test_removing_all_shifts_blocks_credential(): void
    {
        [$event, $staff, $user, $shift] = $this->credentialScenario();

        $outcome = app(ShiftSignupService::class)->signUp($shift, $staff, $user);
        $credential = EventCredential::query()
            ->where('event_id', $event->id)
            ->where('staff_id', $staff->id)
            ->firstOrFail();

        $this->assertTrue($credential->isEligible());

        app(ShiftRemovalService::class)->withdrawFromShift(
            $outcome->assignment->refresh(),
            $staff,
            $user,
        );

        $credential->refresh();

        $this->assertTrue($credential->isBlocked());
        $this->assertSame(
            CredentialEligibilityService::REASON_NO_SIGNED_UP_SHIFTS,
            $credential->status_reason,
        );
    }

    public function test_expired_required_waiver_blocks_credential(): void
    {
        [$event, $staff, $user, $shift] = $this->credentialScenario();
        $organization = $event->organization;
        $waiver = Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'expires_after_days' => 30,
        ]);
        app(ShiftRequirementService::class)->addWaiverRequirement($shift, $waiver);
        app(WaiverService::class)->recordCompletion($waiver, $staff);

        app(ShiftSignupService::class)->signUp($shift->refresh(), $staff, $user);

        $waiver->completions()
            ->where('staff_id', $staff->id)
            ->update(['expires_at' => Carbon::now()->subDay()]);

        $credential = app(CredentialEligibilityService::class)->recalculate($event, $staff);

        $this->assertNotNull($credential);
        $this->assertTrue($credential->isBlocked());
        $this->assertSame(
            CredentialEligibilityService::REASON_MISSING_REQUIRED_WAIVER,
            $credential->status_reason,
        );
    }

    public function test_organization_do_not_staff_status_blocks_credential(): void
    {
        [$event, $staff, $user, $shift] = $this->credentialScenario();

        app(ShiftSignupService::class)->signUp($shift, $staff, $user);

        StaffOrganizationStatus::query()
            ->where('organization_id', $event->organization_id)
            ->where('staff_id', $staff->id)
            ->firstOrFail()
            ->forceFill(['status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF])
            ->save();

        $credential = app(CredentialEligibilityService::class)->recalculate($event, $staff);

        $this->assertNotNull($credential);
        $this->assertTrue($credential->isBlocked());
        $this->assertSame(
            CredentialEligibilityService::REASON_ORGANIZATION_BLOCKING_STATUS,
            $credential->status_reason,
        );
    }

    public function test_department_ineligible_status_blocks_credential(): void
    {
        [$event, $staff, $user, $shift] = $this->credentialScenario();

        app(ShiftSignupService::class)->signUp($shift, $staff, $user);

        $departmentMembership = $staff->departmentMemberships()
            ->where('department_id', $shift->department_id)
            ->firstOrFail();

        app(StaffStatusService::class)->transitionDepartmentStatus(
            $departmentMembership,
            DepartmentMembership::STATUS_INELIGIBLE,
            'Test ineligible status.',
        );

        $credential = app(CredentialEligibilityService::class)->recalculate($event, $staff);

        $this->assertNotNull($credential);
        $this->assertTrue($credential->isBlocked());
        $this->assertSame(
            CredentialEligibilityService::REASON_DEPARTMENT_INELIGIBLE,
            $credential->status_reason,
        );
    }

    public function test_age_requirement_not_satisfied_blocks_credential_as_of_event_date(): void
    {
        [$event, $staff, $user, $shift] = $this->credentialScenario();
        $eventStartsAt = Carbon::parse('2026-09-01 09:00:00', 'America/Los_Angeles');
        $event->forceFill([
            'starts_at' => $eventStartsAt,
            'minimum_staff_age' => 18,
        ])->save();
        $staff->forceFill(['date_of_birth' => '2010-09-15'])->save();

        app(ShiftSignupService::class)->signUp($shift->refresh(), $staff->refresh(), $user);

        $credential = EventCredential::query()
            ->where('event_id', $event->id)
            ->where('staff_id', $staff->id)
            ->firstOrFail();

        $this->assertTrue($credential->isBlocked());
        $this->assertSame(
            CredentialEligibilityService::REASON_AGE_REQUIREMENT_NOT_SATISFIED,
            $credential->status_reason,
        );
    }

    public function test_age_requirement_blocks_when_date_of_birth_is_missing(): void
    {
        [$event, $staff, $user, $shift] = $this->credentialScenario();
        $event->forceFill(['minimum_staff_age' => 18])->save();
        $staff->forceFill(['date_of_birth' => null])->save();

        app(ShiftSignupService::class)->signUp($shift->refresh(), $staff->refresh(), $user);

        $credential = EventCredential::query()
            ->where('event_id', $event->id)
            ->where('staff_id', $staff->id)
            ->firstOrFail();

        $this->assertTrue($credential->isBlocked());
        $this->assertSame(
            CredentialEligibilityService::REASON_MISSING_DATE_OF_BIRTH,
            $credential->status_reason,
        );
    }

    public function test_revoked_credential_is_preserved_during_recalculation(): void
    {
        [$event, $staff, $shift] = $this->credentialScenario(returnUser: false);

        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        $credential = EventCredential::factory()->revoked()->create([
            'event_id' => $event->id,
            'staff_id' => $staff->id,
        ]);

        $result = app(CredentialEligibilityService::class)->recalculate($event, $staff);

        $this->assertSame($credential->id, $result?->id);
        $this->assertTrue($result?->isRevoked());
    }

    public function test_recalculate_returns_null_when_staff_never_had_event_shifts(): void
    {
        [$event, $staff] = $this->credentialScenario(returnUser: false);

        $result = app(CredentialEligibilityService::class)->recalculate($event, $staff);

        $this->assertNull($result);
        $this->assertDatabaseMissing('event_credentials', [
            'event_id' => $event->id,
            'staff_id' => $staff->id,
        ]);
    }

    public function test_evaluate_requires_at_least_one_signed_up_shift(): void
    {
        [$event, $staff] = $this->credentialScenario(returnUser: false);

        $evaluation = app(CredentialEligibilityService::class)->evaluate($event, $staff);

        $this->assertFalse($evaluation->eligible);
        $this->assertSame(
            CredentialEligibilityService::REASON_NO_SIGNED_UP_SHIFTS,
            $evaluation->blockReason,
        );
    }

    /**
     * @return array{0: Event, 1: Staff, 2?: User, 3: Shift}
     */
    private function credentialScenario(bool $returnUser = true): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $staff = Staff::factory()->create([
            'date_of_birth' => '1990-01-15',
        ]);
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);
        $department->load('defaultTeam');

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'title' => 'Gate Lead',
            'signup_opens_at' => null,
            'signup_closes_at' => null,
        ]);

        if ($returnUser) {
            return [$event, $staff, $user, $shift];
        }

        return [$event, $staff, $shift];
    }
}
