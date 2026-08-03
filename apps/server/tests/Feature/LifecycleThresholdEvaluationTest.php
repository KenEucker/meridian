<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\HoursWorked;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Services\Status\LifecycleThresholdEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The scheduled lifecycle threshold evaluator (M18.15; ORG-019; STAT-009
 * through STAT-011).
 */
class LifecycleThresholdEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_prospective_staff_become_inactive_after_the_configured_threshold(): void
    {
        Carbon::setTestNow('2026-08-03 12:00:00 UTC');

        $organization = $this->organization(prospectiveYears: 1);
        $due = $this->statusRecord($organization, StaffOrganizationStatus::STATUS_PROSPECTIVE, '2025-06-01');
        $notYet = $this->statusRecord($organization, StaffOrganizationStatus::STATUS_PROSPECTIVE, '2025-09-01');

        $result = app(LifecycleThresholdEvaluationService::class)->evaluate();

        $this->assertSame(1, $result['prospective_expired']);
        $this->assertSame(StaffOrganizationStatus::STATUS_INACTIVE, $due->refresh()->status);
        $this->assertSame(StaffOrganizationStatus::STATUS_PROSPECTIVE, $notYet->refresh()->status);

        // Through the audited status path (ORG-019): the transition carries a
        // reason and an audit entry the same as one a person performs.
        $this->assertStringContainsString('threshold', (string) $due->status_reason);
        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'staff_organization_status.changed')
                ->where('entity_id', $due->id)
                ->where('source_context', AuditEvent::SOURCE_SYSTEM)
                ->exists(),
        );
    }

    public function test_active_staff_become_inactive_only_after_their_last_recorded_work(): void
    {
        Carbon::setTestNow('2026-08-03 12:00:00 UTC');

        $organization = $this->organization(activeYears: 2);

        // Status turned Active long ago, but they worked recently: the
        // threshold counts from the work, and they stay Active.
        $working = $this->statusRecord($organization, StaffOrganizationStatus::STATUS_ACTIVE, '2022-01-01');
        $this->recordedHours($organization, $working->staff, '2026-01-10');

        // Status turned Active long ago and the last recorded work is older
        // than the threshold: they lapse.
        $lapsed = $this->statusRecord($organization, StaffOrganizationStatus::STATUS_ACTIVE, '2022-01-01');
        $this->recordedHours($organization, $lapsed->staff, '2023-05-01');

        $result = app(LifecycleThresholdEvaluationService::class)->evaluate();

        $this->assertSame(1, $result['active_expired']);
        $this->assertSame(StaffOrganizationStatus::STATUS_ACTIVE, $working->refresh()->status);
        $this->assertSame(StaffOrganizationStatus::STATUS_INACTIVE, $lapsed->refresh()->status);
    }

    public function test_active_department_work_prevents_organization_inactivity(): void
    {
        // STAT-009, honored by skipping rather than by erroring.
        Carbon::setTestNow('2026-08-03 12:00:00 UTC');

        $organization = $this->organization(activeYears: 1);
        $record = $this->statusRecord($organization, StaffOrganizationStatus::STATUS_ACTIVE, '2020-01-01');

        $department = Department::factory()->for($organization)->create();
        DepartmentMembership::factory()
            ->for($department)
            ->for($record->staff)
            ->create(['status' => DepartmentMembership::STATUS_ACTIVE]);

        $result = app(LifecycleThresholdEvaluationService::class)->evaluate();

        $this->assertSame(0, $result['active_expired']);
        $this->assertSame(1, $result['skipped_for_department_work']);
        $this->assertSame(StaffOrganizationStatus::STATUS_ACTIVE, $record->refresh()->status);
    }

    public function test_evaluation_is_idempotent(): void
    {
        Carbon::setTestNow('2026-08-03 12:00:00 UTC');

        $organization = $this->organization(prospectiveYears: 1);
        $record = $this->statusRecord($organization, StaffOrganizationStatus::STATUS_PROSPECTIVE, '2024-01-01');

        $service = app(LifecycleThresholdEvaluationService::class);

        $first = $service->evaluate();
        $second = $service->evaluate();

        $this->assertSame(1, $first['prospective_expired']);
        $this->assertSame(0, $second['prospective_expired']);
        $this->assertSame(StaffOrganizationStatus::STATUS_INACTIVE, $record->refresh()->status);

        // One transition, one audit entry: the second run had nothing to say.
        $this->assertSame(
            1,
            AuditEvent::query()
                ->where('action', 'staff_organization_status.changed')
                ->where('entity_id', $record->id)
                ->count(),
        );
    }

    public function test_an_organization_without_thresholds_evaluates_nothing(): void
    {
        Carbon::setTestNow('2026-08-03 12:00:00 UTC');

        $organization = $this->organization();
        $record = $this->statusRecord($organization, StaffOrganizationStatus::STATUS_PROSPECTIVE, '2015-01-01');

        $result = app(LifecycleThresholdEvaluationService::class)->evaluate();

        $this->assertSame(0, $result['prospective_expired']);
        // Null means the organization has not adopted the rule, however old
        // the record is.
        $this->assertSame(StaffOrganizationStatus::STATUS_PROSPECTIVE, $record->refresh()->status);
    }

    public function test_the_scheduled_command_reports_what_it_applied(): void
    {
        Carbon::setTestNow('2026-08-03 12:00:00 UTC');

        $organization = $this->organization(prospectiveYears: 1);
        $this->statusRecord($organization, StaffOrganizationStatus::STATUS_PROSPECTIVE, '2024-01-01');

        $this->artisan('meridian:evaluate-lifecycle-thresholds')
            ->expectsOutputToContain('1 prospective expired')
            ->assertSuccessful();
    }

    private function organization(?int $prospectiveYears = null, ?int $activeYears = null): Organization
    {
        return Organization::factory()->create([
            'prospective_inactive_threshold_years' => $prospectiveYears,
            'active_inactive_threshold_years' => $activeYears,
        ]);
    }

    private function statusRecord(
        Organization $organization,
        string $status,
        string $changedAt,
    ): StaffOrganizationStatus {
        return StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => Staff::factory()->create()->id,
            'status' => $status,
            'status_changed_at' => Carbon::parse($changedAt.' 00:00:00 UTC'),
        ]);
    }

    private function recordedHours(Organization $organization, Staff $staff, string $endedAt): void
    {
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();
        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);

        HoursWorked::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'actual_started_at' => Carbon::parse($endedAt.' 08:00:00 UTC')->subHours(6),
            'actual_ended_at' => Carbon::parse($endedAt.' 08:00:00 UTC'),
        ]);
    }
}
