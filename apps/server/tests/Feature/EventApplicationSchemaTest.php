<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventApplication;
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
}
