<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\User;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Status\StaffStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class StaffStatusDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_catalogs_match_canonical_organization_and_department_values(): void
    {
        $this->assertSame([
            'prospective',
            'active',
            'inactive',
            'emeritus',
            'retired',
            'do_not_staff',
        ], StaffOrganizationStatus::statuses());

        $this->assertSame([
            'prospective',
            'active',
            'inactive',
            'ineligible',
            'emeritus',
            'retired',
        ], DepartmentMembership::statuses());
    }

    public function test_organization_status_transition_updates_reason_actor_and_timestamp(): void
    {
        $statusRecord = StaffOrganizationStatus::factory()->create();
        $changedBy = User::factory()->create();
        $changedAt = now()->subHour();

        $updated = (new StaffStatusService)->transitionOrganizationStatus(
            $statusRecord,
            StaffOrganizationStatus::STATUS_EMERITUS,
            'Longstanding contributor.',
            $changedBy,
            $changedAt,
        );

        $this->assertSame(StaffOrganizationStatus::STATUS_EMERITUS, $updated->status);
        $this->assertSame('Longstanding contributor.', $updated->status_reason);
        $this->assertTrue($updated->statusChangedBy->is($changedBy));
        $this->assertSame($changedAt->toDateTimeString(), $updated->status_changed_at->toDateTimeString());
    }

    public function test_invalid_status_transitions_are_rejected(): void
    {
        $service = new StaffStatusService;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported organization staff status.');

        $service->transitionOrganizationStatus(
            StaffOrganizationStatus::factory()->create(),
            'suspended',
        );
    }

    public function test_active_department_work_prevents_organization_inactive_status(): void
    {
        $staff = Staff::factory()->create();
        $department = Department::factory()->create();
        StaffOrganizationStatus::factory()
            ->for($department->organization)
            ->for($staff)
            ->active()
            ->create();
        app(DepartmentMembershipService::class)->createWithTeams($staff, $department, [$department->defaultTeam]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Active department work prevents organization-level inactive status.');

        (new StaffStatusService)->transitionOrganizationStatus(
            $staff->organizationStatuses()->firstOrFail(),
            StaffOrganizationStatus::STATUS_INACTIVE,
            'No recent work.',
        );
    }

    public function test_department_assignment_activates_prospective_organization_status_when_no_trainings_exist(): void
    {
        $staff = Staff::factory()->create();
        $department = Department::factory()->create();
        $changedBy = User::factory()->create();
        $organizationStatus = StaffOrganizationStatus::factory()
            ->for($department->organization)
            ->for($staff)
            ->create([
                'status' => StaffOrganizationStatus::STATUS_PROSPECTIVE,
                'status_reason' => 'Approved application.',
                'status_changed_by_user_id' => null,
            ]);

        app(DepartmentMembershipService::class)->createWithTeams(
            $staff,
            $department,
            [$department->defaultTeam],
            DepartmentMembership::STATUS_ACTIVE,
            null,
            $changedBy,
        );

        $organizationStatus->refresh();

        $this->assertSame(StaffOrganizationStatus::STATUS_ACTIVE, $organizationStatus->status);
        $this->assertSame(
            'Activated after department assignment with no required trainings configured.',
            $organizationStatus->status_reason,
        );
        $this->assertSame($changedBy->id, $organizationStatus->status_changed_by_user_id);
    }

    public function test_department_ineligible_status_is_department_specific(): void
    {
        $staff = Staff::factory()->create();
        $firstDepartment = Department::factory()->create();
        $secondDepartment = Department::factory()
            ->for($firstDepartment->organization)
            ->create(['code' => 'SECOND']);
        $service = app(DepartmentMembershipService::class);
        $statusService = new StaffStatusService;

        $firstMembership = $service->createWithTeams($staff, $firstDepartment, [$firstDepartment->defaultTeam]);
        $secondMembership = $service->createWithTeams($staff, $secondDepartment, [$secondDepartment->defaultTeam]);

        $statusService->transitionDepartmentStatus(
            $firstMembership,
            DepartmentMembership::STATUS_INELIGIBLE,
            'Department-specific eligibility issue.',
        );

        $this->assertSame(DepartmentMembership::STATUS_INELIGIBLE, $firstMembership->refresh()->status);
        $this->assertSame(DepartmentMembership::STATUS_ACTIVE, $secondMembership->refresh()->status);
    }

    public function test_organization_do_not_staff_supersedes_department_status_and_blocks_access(): void
    {
        $staff = Staff::factory()->create();
        $department = Department::factory()->create();
        $organizationStatus = StaffOrganizationStatus::factory()
            ->for($department->organization)
            ->for($staff)
            ->active()
            ->create();
        $departmentMembership = app(DepartmentMembershipService::class)->createWithTeams(
            $staff,
            $department,
            [$department->defaultTeam],
        );
        $statusService = new StaffStatusService;

        $statusService->transitionOrganizationStatus(
            $organizationStatus,
            StaffOrganizationStatus::STATUS_DO_NOT_STAFF,
            'DNS by organizer review.',
        );

        $this->assertTrue($statusService->organizationStatusBlocksSystemAccess($organizationStatus->refresh()));
        $this->assertSame(
            StaffOrganizationStatus::STATUS_DO_NOT_STAFF,
            $statusService->effectiveDepartmentStatus($departmentMembership->refresh()),
        );
    }

    public function test_department_status_transition_rejects_unknown_status(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported department staff status.');

        (new StaffStatusService)->transitionDepartmentStatus(
            DepartmentMembership::factory()->create(),
            'paused',
        );
    }

    public function test_prospective_organization_status_expires_after_configured_threshold(): void
    {
        $organizationStatus = StaffOrganizationStatus::factory()->create([
            'status' => StaffOrganizationStatus::STATUS_PROSPECTIVE,
            'status_changed_at' => now()->subYears(2),
        ]);

        $updated = (new StaffStatusService)->expireProspectiveOrganizationStatusIfDue(
            $organizationStatus,
            now(),
        );

        $this->assertSame(StaffOrganizationStatus::STATUS_INACTIVE, $updated->status);
        $this->assertSame('Prospective status expired after configured threshold.', $updated->status_reason);
    }

    public function test_prospective_organization_status_does_not_expire_before_configured_threshold(): void
    {
        $organizationStatus = StaffOrganizationStatus::factory()->create([
            'status' => StaffOrganizationStatus::STATUS_PROSPECTIVE,
            'status_changed_at' => now()->subMonths(6),
        ]);

        $updated = (new StaffStatusService)->expireProspectiveOrganizationStatusIfDue(
            $organizationStatus,
            now(),
        );

        $this->assertSame(StaffOrganizationStatus::STATUS_PROSPECTIVE, $updated->status);
        $this->assertNull($updated->status_reason);
    }

    public function test_inactive_organization_status_is_allowed_after_department_work_is_no_longer_active(): void
    {
        $staff = Staff::factory()->create();
        $department = Department::factory()->create();
        $organizationStatus = StaffOrganizationStatus::factory()
            ->for($department->organization)
            ->for($staff)
            ->active()
            ->create();
        $departmentMembership = app(DepartmentMembershipService::class)->createWithTeams(
            $staff,
            $department,
            [$department->defaultTeam],
        );
        $extraTeam = Team::factory()->for($department)->create(['code' => 'EXTRA']);
        $departmentMembership->teamMemberships()->create([
            'team_id' => $extraTeam->id,
            'staff_id' => $staff->id,
            'membership_role' => 'member',
        ]);
        $departmentMembership->teamMemberships()->firstOrFail()->update(['archived_at' => now()]);

        (new StaffStatusService)->transitionDepartmentStatus(
            $departmentMembership,
            DepartmentMembership::STATUS_INACTIVE,
            'No current work.',
        );

        $updated = (new StaffStatusService)->transitionOrganizationStatus(
            $organizationStatus,
            StaffOrganizationStatus::STATUS_INACTIVE,
            'No active department work.',
        );

        $this->assertSame(StaffOrganizationStatus::STATUS_INACTIVE, $updated->status);
    }
}
