<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventApplicationDepartmentInterest;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventApplicationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_applications_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('event_applications'));

        foreach ([
            'id',
            'event_id',
            'organization_id',
            'staff_id',
            'applicant_email',
            'applicant_legal_name',
            'status',
            'submitted_at',
            'reviewed_at',
            'reviewed_by_user_id',
            'decision_reason',
            'withdrawn_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('event_applications', $column),
                "Missing column: {$column}",
            );
        }
    }

    public function test_event_application_ids_are_uuids(): void
    {
        $application = EventApplication::factory()->create();

        $this->assertTrue(Str::isUuid($application->id));
    }

    public function test_status_set_matches_app_003(): void
    {
        $this->assertSame([
            'submitted',
            'approved',
            'rejected',
            'deferred',
            'withdrawn',
            'auto_rejected_dns',
        ], EventApplication::statuses());

        $this->assertSame('Auto-rejected due to DNS', EventApplication::statusLabels()['auto_rejected_dns']);
    }

    public function test_application_belongs_to_event_and_organization_and_event_has_many_applications(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();

        $application = EventApplication::factory()
            ->create([
                'event_id' => $event->id,
                'organization_id' => $organization->id,
            ]);

        $application->refresh()->load(['event', 'organization']);
        $event->refresh()->load('applications');

        $this->assertTrue($application->event->is($event));
        $this->assertTrue($application->organization->is($organization));
        $this->assertCount(1, $event->applications);
        $this->assertTrue($event->applications->contains($application));
    }

    public function test_submitted_scope_filters_by_submitted_status(): void
    {
        $submitted = EventApplication::factory()->create();
        EventApplication::factory()->create([
            'status' => EventApplication::STATUS_WITHDRAWN,
        ]);

        $results = EventApplication::query()->submitted()->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($submitted));
        $this->assertTrue($submitted->isSubmitted());
    }

    public function test_event_department_assignments_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('event_department_assignments'));

        foreach ([
            'id',
            'event_id',
            'department_id',
            'created_at',
            'archived_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('event_department_assignments', $column),
                "Missing column: {$column}",
            );
        }
    }

    public function test_event_application_department_interests_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('event_application_department_interests'));

        foreach ([
            'id',
            'event_application_id',
            'department_id',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('event_application_department_interests', $column),
                "Missing column: {$column}",
            );
        }

        $this->assertFalse(Schema::hasColumn('event_application_department_interests', 'preference_order'));
    }

    public function test_department_interest_and_event_department_assignment_ids_are_uuids(): void
    {
        $assignment = EventDepartmentAssignment::factory()->create();
        $interest = EventApplicationDepartmentInterest::factory()->create();

        $this->assertTrue(Str::isUuid($assignment->id));
        $this->assertTrue(Str::isUuid($interest->id));
    }

    public function test_application_department_interest_relationships(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();
        EventDepartmentAssignment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
        ]);

        EventApplicationDepartmentInterest::factory()->create([
            'event_application_id' => $application->id,
            'department_id' => $department->id,
        ]);

        $application->refresh()->load('departmentInterests');
        $event->refresh()->load('departmentAssignments');
        $department->refresh()->load(['eventAssignments', 'applicationInterests']);

        $this->assertCount(1, $application->departmentInterests);
        $this->assertTrue($application->departmentInterests->first()->is($department));
        $this->assertCount(1, $event->departmentAssignments);
        $this->assertCount(1, $department->eventAssignments);
        $this->assertCount(1, $department->applicationInterests);
        $this->assertSame('No department preference', EventApplication::factory()->create()->departmentInterestDisplay());
        $this->assertSame($department->name, $application->departmentInterestDisplay());
    }
}
