<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventCredential;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Credential\CredentialEligibilityService;
use App\Services\Credential\CredentialRevocationException;
use App\Services\Credential\CredentialRevocationService;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CredentialRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_can_revoke_credential_and_remove_future_shifts(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        [$event, $staff, $organizerUser, $department] = $this->revocationScenario(
            grantRole: 'organizer',
            eventScoped: false,
        );

        $futureShift = $this->createShift($event, $department, '2026-06-10 09:00:00', '2026-06-10 17:00:00');
        $futureAssignment = ShiftAssignment::factory()->create([
            'shift_id' => $futureShift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
        ]);

        $credential = EventCredential::factory()->create([
            'event_id' => $event->id,
            'staff_id' => $staff->id,
            'status' => EventCredential::STATUS_ELIGIBLE,
        ]);

        $result = app(CredentialRevocationService::class)->revoke($event, $staff, $organizerUser);

        $this->assertTrue($result->isRevoked());
        $this->assertSame(CredentialRevocationService::REASON_MANUAL_REVOCATION, $result->status_reason);
        $this->assertNotNull($result->revoked_at);
        $this->assertSame($organizerUser->id, $result->changed_by_user_id);
        $this->assertNotNull($futureAssignment->fresh()->removed_at);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'event_credential.revoked',
            'entity_type' => EventCredential::class,
            'entity_id' => $credential->id,
            'actor_user_id' => $organizerUser->id,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'shift_assignment.removed_by_credential_revocation',
            'entity_type' => ShiftAssignment::class,
            'entity_id' => $futureAssignment->id,
        ]);
    }

    public function test_ic_lead_can_revoke_credential(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        [$event, $staff, $icLeadUser, $department] = $this->revocationScenario(
            grantRole: 'ic_lead',
            eventScoped: true,
        );

        ShiftAssignment::factory()->create([
            'shift_id' => $this->createShift($event, $department, '2026-06-10 09:00:00', '2026-06-10 17:00:00')->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
        ]);

        EventCredential::factory()->create([
            'event_id' => $event->id,
            'staff_id' => $staff->id,
            'status' => EventCredential::STATUS_ELIGIBLE,
        ]);

        $result = app(CredentialRevocationService::class)->revoke($event, $staff, $icLeadUser);

        $this->assertTrue($result->isRevoked());
    }

    public function test_department_lead_cannot_revoke_credential(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        [$event, $staff, $departmentLeadUser, $department] = $this->revocationScenario(
            grantRole: 'department_lead',
            eventScoped: false,
        );

        ShiftAssignment::factory()->create([
            'shift_id' => $this->createShift($event, $department, '2026-06-10 09:00:00', '2026-06-10 17:00:00')->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
        ]);

        EventCredential::factory()->create([
            'event_id' => $event->id,
            'staff_id' => $staff->id,
            'status' => EventCredential::STATUS_ELIGIBLE,
        ]);

        $this->expectException(CredentialRevocationException::class);
        $this->expectExceptionMessage('not authorized');

        app(CredentialRevocationService::class)->revoke($event, $staff, $departmentLeadUser);
    }

    public function test_ic_operator_cannot_revoke_credential(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        [$event, $staff, $icOperatorUser, $department] = $this->revocationScenario(
            grantRole: 'ic_operator',
            eventScoped: true,
        );

        ShiftAssignment::factory()->create([
            'shift_id' => $this->createShift($event, $department, '2026-06-10 09:00:00', '2026-06-10 17:00:00')->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
        ]);

        EventCredential::factory()->create([
            'event_id' => $event->id,
            'staff_id' => $staff->id,
            'status' => EventCredential::STATUS_ELIGIBLE,
        ]);

        $this->expectException(CredentialRevocationException::class);
        $this->expectExceptionMessage('not authorized');

        app(CredentialRevocationService::class)->revoke($event, $staff, $icOperatorUser);
    }

    public function test_preserves_completed_shifts(): void
    {
        Carbon::setTestNow('2026-06-15 18:00:00');

        [$event, $staff, $organizerUser, $department] = $this->revocationScenario(
            grantRole: 'organizer',
            eventScoped: false,
        );

        $completedShift = $this->createShift($event, $department, '2026-06-10 09:00:00', '2026-06-10 17:00:00');
        $completedAssignment = ShiftAssignment::factory()->create([
            'shift_id' => $completedShift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
        ]);

        $futureShift = $this->createShift($event, $department, '2026-06-20 09:00:00', '2026-06-20 17:00:00');
        $futureAssignment = ShiftAssignment::factory()->create([
            'shift_id' => $futureShift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
        ]);

        EventCredential::factory()->create([
            'event_id' => $event->id,
            'staff_id' => $staff->id,
            'status' => EventCredential::STATUS_ELIGIBLE,
        ]);

        app(CredentialRevocationService::class)->revoke($event, $staff, $organizerUser);

        $this->assertNull($completedAssignment->fresh()->removed_at);
        $this->assertNotNull($futureAssignment->fresh()->removed_at);
    }

    public function test_revoke_is_idempotent_for_already_revoked_credentials(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        [$event, $staff, $organizerUser, $department] = $this->revocationScenario(
            grantRole: 'organizer',
            eventScoped: false,
        );

        ShiftAssignment::factory()->create([
            'shift_id' => $this->createShift($event, $department, '2026-06-10 09:00:00', '2026-06-10 17:00:00')->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
        ]);

        $credential = EventCredential::factory()->revoked()->create([
            'event_id' => $event->id,
            'staff_id' => $staff->id,
        ]);
        $originalRevokedAt = $credential->revoked_at;

        $result = app(CredentialRevocationService::class)->revoke($event, $staff, $organizerUser);

        $this->assertSame($credential->id, $result->id);
        $this->assertSame($originalRevokedAt->toDateTimeString(), $result->revoked_at->toDateTimeString());
        $this->assertSame(0, AuditEvent::query()->where('action', 'event_credential.revoked')->count());
    }

    public function test_unscheduled_assignment_does_not_grant_credential_eligibility(): void
    {
        Carbon::setTestNow('2026-06-10 12:00:00');

        [$event, $staff, , $department] = $this->revocationScenario(
            grantRole: 'organizer',
            eventScoped: false,
            includeSubjectStaff: false,
        );

        $shift = $this->createShift($event, $department, '2026-06-10 09:00:00', '2026-06-10 17:00:00');

        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            'assigned_by_user_id' => User::factory()->create()->id,
            'created_at' => Carbon::parse('2026-06-10 10:00:00'),
            'updated_at' => Carbon::parse('2026-06-10 10:00:00'),
        ]);

        $evaluation = app(CredentialEligibilityService::class)->evaluate($event, $staff);

        $this->assertFalse($evaluation->eligible);
        $this->assertSame(
            CredentialEligibilityService::REASON_NO_SIGNED_UP_SHIFTS,
            $evaluation->blockReason,
        );
        $this->assertNull(app(CredentialEligibilityService::class)->recalculate($event, $staff));
    }

    public function test_planned_lead_assignment_before_shift_start_counts_for_credential(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        [$event, $staff, $user, $department] = $this->revocationScenario(
            grantRole: 'organizer',
            eventScoped: false,
            includeSubjectStaff: false,
        );

        $shift = $this->createShift($event, $department, '2026-06-10 09:00:00', '2026-06-10 17:00:00');

        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            'assigned_by_user_id' => $user->id,
            'created_at' => Carbon::parse('2026-06-01 12:00:00'),
            'updated_at' => Carbon::parse('2026-06-01 12:00:00'),
        ]);

        $credential = app(CredentialEligibilityService::class)->recalculate($event, $staff);

        $this->assertNotNull($credential);
        $this->assertTrue($credential->isEligible());
    }

    /**
     * @return array{0: Event, 1: Staff, 2: User, 3: Department}
     */
    private function revocationScenario(
        string $grantRole,
        bool $eventScoped,
        bool $includeSubjectStaff = true,
    ): array {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $team = Team::factory()->for($department)->create(['name' => 'Gate Team']);

        $actorStaff = Staff::factory()->create();
        $actorUser = User::factory()->create();
        $actorUser->staffProfiles()->attach($actorStaff->id);

        $actorDepartmentMembership = DepartmentMembership::factory()
            ->for($department)
            ->for($actorStaff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $actorStaff->id,
            'department_membership_id' => $actorDepartmentMembership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => $eventScoped ? $event->id : null,
            'permission_role_id' => PermissionRole::query()->where('code', $grantRole)->firstOrFail()->id,
        ]);

        if (! $includeSubjectStaff) {
            $subjectStaff = Staff::factory()->create(['date_of_birth' => '1990-01-15']);
            app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($subjectStaff, $department);

            StaffOrganizationStatus::factory()->create([
                'organization_id' => $organization->id,
                'staff_id' => $subjectStaff->id,
                'status' => StaffOrganizationStatus::STATUS_ACTIVE,
            ]);

            return [$event, $subjectStaff, $actorUser, $department];
        }

        $subjectStaff = Staff::factory()->create(['date_of_birth' => '1990-01-15']);
        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($subjectStaff, $department);

        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $subjectStaff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);

        return [$event, $subjectStaff, $actorUser, $department];
    }

    private function createShift(
        Event $event,
        Department $department,
        string $startsAt,
        string $endsAt,
    ): Shift {
        $department->loadMissing('defaultTeam');

        return Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'starts_at' => Carbon::parse($startsAt),
            'ends_at' => Carbon::parse($endsAt),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
