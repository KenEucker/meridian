<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\Training;
use App\Models\TrainingCompletion;
use App\Models\TrainingPrerequisite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TrainingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_trainings_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('trainings'));

        foreach ([
            'id',
            'organization_id',
            'department_id',
            'team_id',
            'event_id',
            'name',
            'description',
            'expires_after_days',
            'created_at',
            'updated_at',
            'archived_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('trainings', $column), "trainings.{$column} missing");
        }
    }

    public function test_training_prerequisites_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('training_prerequisites'));

        foreach ([
            'id',
            'training_id',
            'prerequisite_training_id',
            'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('training_prerequisites', $column), "training_prerequisites.{$column} missing");
        }

        $this->assertFalse(Schema::hasColumn('training_prerequisites', 'updated_at'));
    }

    public function test_training_completions_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('training_completions'));

        foreach ([
            'id',
            'training_id',
            'staff_id',
            'completed_at',
            'expires_at',
            'recorded_by_user_id',
            'origin_node_id',
            'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('training_completions', $column), "training_completions.{$column} missing");
        }

        $this->assertFalse(Schema::hasColumn('training_completions', 'updated_at'));
    }

    public function test_training_belongs_to_organization_and_optional_scopes(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $team = $department->defaultTeam;
        $event = Event::factory()->for($organization)->create();

        $training = Training::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'team_id' => $team->id,
            'event_id' => $event->id,
        ]);

        $training->refresh()->load('organization', 'department', 'team', 'event');

        $this->assertTrue($training->organization->is($organization));
        $this->assertTrue($training->department->is($department));
        $this->assertTrue($training->team->is($team));
        $this->assertTrue($training->event->is($event));
        $this->assertTrue($organization->trainings->contains($training));
    }

    public function test_training_scopes_are_nullable_for_organization_wide_one_off_trainings(): void
    {
        $training = Training::factory()->create([
            'department_id' => null,
            'team_id' => null,
            'event_id' => null,
            'expires_after_days' => null,
        ]);

        $this->assertNull($training->department_id);
        $this->assertNull($training->team_id);
        $this->assertNull($training->event_id);
        $this->assertFalse($training->isEventSpecific());
        $this->assertFalse($training->expires());
    }

    public function test_expiring_event_specific_training_helpers(): void
    {
        $event = Event::factory()->create();
        $training = Training::factory()->expiresAfterDays(365)->create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
        ]);

        $this->assertTrue($training->expires());
        $this->assertSame(365, $training->expires_after_days);
        $this->assertTrue($training->isEventSpecific());
    }

    public function test_training_has_prerequisites_and_completions_relationships(): void
    {
        $organization = Organization::factory()->create();
        $training = Training::factory()->for($organization)->create();
        $prerequisite = Training::factory()->for($organization)->create();

        TrainingPrerequisite::factory()->create([
            'training_id' => $training->id,
            'prerequisite_training_id' => $prerequisite->id,
        ]);

        TrainingCompletion::factory()->create([
            'training_id' => $training->id,
            'staff_id' => Staff::factory()->create()->id,
        ]);

        $training->refresh()->load('prerequisites', 'prerequisiteTrainings', 'completions');

        $this->assertCount(1, $training->prerequisites);
        $this->assertTrue($training->prerequisiteTrainings->contains($prerequisite));
        $this->assertCount(1, $training->completions);
    }

    public function test_active_scope_excludes_archived_trainings_without_deleting_them(): void
    {
        $active = Training::factory()->create();
        $archived = Training::factory()->archived()->create();

        $this->assertTrue(Training::query()->active()->whereKey($active)->exists());
        $this->assertFalse(Training::query()->active()->whereKey($archived)->exists());
        $this->assertTrue($archived->isArchived());
        $this->assertDatabaseHas('trainings', ['id' => $archived->id]);
    }
}
