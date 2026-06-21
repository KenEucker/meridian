<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\Training;
use App\Models\TrainingCompletion;
use App\Models\TrainingPrerequisite;
use App\Models\User;
use App\Services\Training\TrainingPrerequisiteException;
use App\Services\Training\TrainingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TrainingDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_prerequisite_creates_relationship(): void
    {
        $organization = Organization::factory()->create();
        $training = Training::factory()->for($organization)->create();
        $prerequisite = Training::factory()->for($organization)->create();

        $record = (new TrainingService)->addPrerequisite($training, $prerequisite);

        $this->assertSame((string) $training->id, (string) $record->training_id);
        $this->assertSame((string) $prerequisite->id, (string) $record->prerequisite_training_id);
        $this->assertTrue($training->refresh()->prerequisiteTrainings->contains($prerequisite));
    }

    public function test_add_prerequisite_rejects_self_reference(): void
    {
        $training = Training::factory()->create();

        $this->expectException(TrainingPrerequisiteException::class);
        $this->expectExceptionMessage('A training cannot be a prerequisite of itself.');

        (new TrainingService)->addPrerequisite($training, $training);
    }

    public function test_add_prerequisite_rejects_cross_organization_prerequisite(): void
    {
        $training = Training::factory()->create();
        $prerequisite = Training::factory()->create();

        $this->expectException(TrainingPrerequisiteException::class);
        $this->expectExceptionMessage('A prerequisite training must belong to the same organization.');

        (new TrainingService)->addPrerequisite($training, $prerequisite);
    }

    public function test_add_prerequisite_rejects_duplicate(): void
    {
        $organization = Organization::factory()->create();
        $training = Training::factory()->for($organization)->create();
        $prerequisite = Training::factory()->for($organization)->create();
        $service = new TrainingService;

        $service->addPrerequisite($training, $prerequisite);

        $this->expectException(TrainingPrerequisiteException::class);
        $this->expectExceptionMessage('This prerequisite relationship already exists.');

        $service->addPrerequisite($training, $prerequisite);
    }

    public function test_add_prerequisite_rejects_direct_cycle(): void
    {
        $organization = Organization::factory()->create();
        $a = Training::factory()->for($organization)->create();
        $b = Training::factory()->for($organization)->create();
        $service = new TrainingService;

        $service->addPrerequisite($a, $b);

        $this->expectException(TrainingPrerequisiteException::class);
        $this->expectExceptionMessage('Adding this prerequisite would create a prerequisite cycle.');

        $service->addPrerequisite($b, $a);
    }

    public function test_add_prerequisite_rejects_transitive_cycle(): void
    {
        $organization = Organization::factory()->create();
        $a = Training::factory()->for($organization)->create();
        $b = Training::factory()->for($organization)->create();
        $c = Training::factory()->for($organization)->create();
        $service = new TrainingService;

        $service->addPrerequisite($a, $b);
        $service->addPrerequisite($b, $c);

        $this->expectException(TrainingPrerequisiteException::class);
        $this->expectExceptionMessage('Adding this prerequisite would create a prerequisite cycle.');

        $service->addPrerequisite($c, $a);
    }

    public function test_prerequisite_pairs_are_unique_at_the_database_level(): void
    {
        $organization = Organization::factory()->create();
        $training = Training::factory()->for($organization)->create();
        $prerequisite = Training::factory()->for($organization)->create();

        TrainingPrerequisite::factory()->create([
            'training_id' => $training->id,
            'prerequisite_training_id' => $prerequisite->id,
        ]);

        $this->expectException(QueryException::class);

        TrainingPrerequisite::factory()->create([
            'training_id' => $training->id,
            'prerequisite_training_id' => $prerequisite->id,
        ]);
    }

    public function test_record_completion_derives_expiration_from_training_window(): void
    {
        $training = Training::factory()->expiresAfterDays(30)->create();
        $staff = Staff::factory()->create();
        $recorder = User::factory()->create();
        $node = Node::factory()->create();
        $completedAt = Carbon::parse('2026-01-01 10:00:00');

        $completion = (new TrainingService)->recordCompletion(
            $training,
            $staff,
            $completedAt,
            $recorder,
            $node,
        );

        $this->assertSame((string) $training->id, (string) $completion->training_id);
        $this->assertSame((string) $staff->id, (string) $completion->staff_id);
        $this->assertTrue($completion->completed_at->equalTo($completedAt));
        $this->assertNotNull($completion->expires_at);
        $this->assertTrue($completion->expires_at->equalTo($completedAt->copy()->addDays(30)));
        $this->assertSame((string) $recorder->id, (string) $completion->recorded_by_user_id);
        $this->assertSame((string) $node->id, (string) $completion->origin_node_id);
    }

    public function test_record_completion_without_expiry_window_has_no_expiration(): void
    {
        $training = Training::factory()->create(['expires_after_days' => null]);
        $staff = Staff::factory()->create();

        $completion = (new TrainingService)->recordCompletion($training, $staff);

        $this->assertNull($completion->expires_at);
        $this->assertNull($completion->recorded_by_user_id);
        $this->assertNull($completion->origin_node_id);
        $this->assertFalse($completion->isExpiredAt());
    }

    public function test_current_scope_and_expiry_helper_reflect_expiration(): void
    {
        $training = Training::factory()->create();
        $staff = Staff::factory()->create();

        $current = TrainingCompletion::factory()->for($training)->for($staff)->create([
            'expires_at' => Carbon::now()->addDay(),
        ]);
        $lapsed = TrainingCompletion::factory()->for($training)->for($staff)->expired()->create();

        $this->assertTrue(TrainingCompletion::query()->current()->whereKey($current)->exists());
        $this->assertFalse(TrainingCompletion::query()->current()->whereKey($lapsed)->exists());
        $this->assertFalse($current->isExpiredAt());
        $this->assertTrue($lapsed->isExpiredAt());
    }
}
