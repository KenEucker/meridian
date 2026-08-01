<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverCompletion;
use App\Services\Documents\DocumentScopeValidator;
use App\Services\Waiver\WaiverScopeException;
use App\Services\Waiver\WaiverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WaiverDomainTest extends TestCase
{
    use RefreshDatabase;

    private function waiverService(): WaiverService
    {
        return new WaiverService(app(DocumentScopeValidator::class));
    }

    public function test_create_waiver_at_organization_scope(): void
    {
        $organization = Organization::factory()->create();

        $waiver = $this->waiverService()->create(
            $organization,
            Waiver::SCOPE_ORGANIZATION,
            (string) $organization->id,
            'General Liability Waiver',
        );

        $this->assertSame((string) $organization->id, (string) $waiver->organization_id);
        $this->assertSame(Waiver::SCOPE_ORGANIZATION, $waiver->scope_type);
        $this->assertSame((string) $organization->id, (string) $waiver->scope_id);
        $this->assertSame('General Liability Waiver', $waiver->name);
    }

    public function test_create_waiver_at_department_and_team_scope(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();
        $service = $this->waiverService();

        $departmentWaiver = $service->create(
            $organization,
            Waiver::SCOPE_DEPARTMENT,
            (string) $department->id,
            'Department Safety Acknowledgment',
        );

        $teamWaiver = $service->create(
            $organization,
            Waiver::SCOPE_TEAM,
            (string) $team->id,
            'Team Equipment Acknowledgment',
        );

        $this->assertSame(Waiver::SCOPE_DEPARTMENT, $departmentWaiver->scope_type);
        $this->assertSame((string) $department->id, (string) $departmentWaiver->scope_id);
        $this->assertSame(Waiver::SCOPE_TEAM, $teamWaiver->scope_type);
        $this->assertSame((string) $team->id, (string) $teamWaiver->scope_id);
    }

    public function test_create_waiver_rejects_unsupported_scope_type(): void
    {
        $organization = Organization::factory()->create();

        $this->expectException(WaiverScopeException::class);
        $this->expectExceptionMessage('Waivers may only be assigned at organization, department, or team level.');

        $this->waiverService()->create(
            $organization,
            'event',
            '00000000-0000-4000-8000-000000000001',
            'Invalid Event Waiver',
        );
    }

    public function test_create_waiver_rejects_scope_target_outside_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherDepartment = Department::factory()->create();

        $this->expectException(WaiverScopeException::class);
        $this->expectExceptionMessage('The scope target must belong to the waiver organization and scope type.');

        $this->waiverService()->create(
            $organization,
            Waiver::SCOPE_DEPARTMENT,
            (string) $otherDepartment->id,
            'Cross-organization Waiver',
        );
    }

    public function test_record_completion_derives_expiration_from_waiver_window(): void
    {
        $waiver = Waiver::factory()->expiresAfterDays(30)->create();
        $staff = Staff::factory()->create();
        $recorder = User::factory()->create();
        $completedAt = Carbon::parse('2026-01-01 10:00:00');

        $completion = $this->waiverService()->recordCompletion(
            $waiver,
            $staff,
            $completedAt,
            $recorder,
        );

        $this->assertSame((string) $waiver->id, (string) $completion->waiver_id);
        $this->assertSame((string) $staff->id, (string) $completion->staff_id);
        $this->assertTrue($completion->completed_at->equalTo($completedAt));
        $this->assertNotNull($completion->expires_at);
        $this->assertTrue($completion->expires_at->equalTo($completedAt->copy()->addDays(30)));
        $this->assertSame((string) $recorder->id, (string) $completion->recorded_by_user_id);
    }

    public function test_record_completion_without_expiry_window_has_no_expiration(): void
    {
        $waiver = Waiver::factory()->create(['expires_after_days' => null]);
        $staff = Staff::factory()->create();

        $completion = $this->waiverService()->recordCompletion(
            $waiver,
            $staff,
        );

        $this->assertNull($completion->expires_at);
        $this->assertNull($completion->recorded_by_user_id);
        $this->assertFalse($completion->isExpiredAt());
    }

    public function test_completion_tracking_reflects_complete_and_incomplete_state(): void
    {
        $waiver = Waiver::factory()->create();
        $staff = Staff::factory()->create();
        $service = $this->waiverService();

        $this->assertFalse($service->isCompleteFor($waiver, $staff));
        $this->assertFalse($waiver->isCompleteFor($staff));

        $service->recordCompletion($waiver, $staff);

        $this->assertTrue($service->isCompleteFor($waiver, $staff));
        $this->assertTrue($waiver->refresh()->isCompleteFor($staff));
    }

    public function test_current_scope_and_expiry_helper_reflect_expiration(): void
    {
        $waiver = Waiver::factory()->create();
        $staff = Staff::factory()->create();

        $current = WaiverCompletion::factory()->for($waiver)->for($staff)->create([
            'expires_at' => Carbon::now()->addDay(),
        ]);
        $lapsed = WaiverCompletion::factory()->for($waiver)->for($staff)->expired()->create();

        $this->assertTrue(WaiverCompletion::query()->current()->whereKey($current)->exists());
        $this->assertFalse(WaiverCompletion::query()->current()->whereKey($lapsed)->exists());
        $this->assertFalse($current->isExpiredAt());
        $this->assertTrue($lapsed->isExpiredAt());
        $this->assertTrue($waiver->refresh()->isCompleteFor($staff));
    }

    public function test_lapsed_completion_makes_waiver_incomplete(): void
    {
        $waiver = Waiver::factory()->create();
        $staff = Staff::factory()->create();

        WaiverCompletion::factory()->for($waiver)->for($staff)->expired()->create();

        $this->assertFalse($waiver->isCompleteFor($staff));
    }
}
