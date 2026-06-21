<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\Training;
use App\Models\Waiver;
use App\Services\Shift\ShiftRequirementException;
use App\Services\Shift\ShiftRequirementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShiftDomainTest extends TestCase
{
    use RefreshDatabase;

    private function shiftRequirementService(): ShiftRequirementService
    {
        return new ShiftRequirementService;
    }

    public function test_shift_training_requirement_tables_have_documented_fields(): void
    {
        $this->assertTrue(Schema::hasTable('shift_training_requirements'));
        $this->assertTrue(Schema::hasTable('shift_waiver_requirements'));

        foreach ([
            'id',
            'shift_id',
            'training_id',
            'created_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('shift_training_requirements', $column),
                "shift_training_requirements.{$column} missing",
            );
        }

        foreach ([
            'id',
            'shift_id',
            'waiver_id',
            'created_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('shift_waiver_requirements', $column),
                "shift_waiver_requirements.{$column} missing",
            );
        }
    }

    public function test_add_training_requirement_creates_relationship(): void
    {
        $organization = Organization::factory()->create();
        $shift = Shift::factory()->create([
            'event_id' => Event::factory()->for($organization)->create()->id,
        ]);
        $training = Training::factory()->for($organization)->create();

        $record = $this->shiftRequirementService()->addTrainingRequirement($shift, $training);

        $this->assertSame((string) $shift->id, (string) $record->shift_id);
        $this->assertSame((string) $training->id, (string) $record->training_id);
        $this->assertTrue($shift->refresh()->requiredTrainings->contains($training));
    }

    public function test_add_training_requirement_rejects_cross_organization_training(): void
    {
        $shift = Shift::factory()->create();
        $training = Training::factory()->create();

        $this->expectException(ShiftRequirementException::class);
        $this->expectExceptionMessage('The requirement must belong to the same organization as the shift event.');

        $this->shiftRequirementService()->addTrainingRequirement($shift, $training);
    }

    public function test_add_training_requirement_rejects_duplicate(): void
    {
        $organization = Organization::factory()->create();
        $shift = Shift::factory()->create([
            'event_id' => Event::factory()->for($organization)->create()->id,
        ]);
        $training = Training::factory()->for($organization)->create();
        $service = $this->shiftRequirementService();

        $service->addTrainingRequirement($shift, $training);

        $this->expectException(ShiftRequirementException::class);
        $this->expectExceptionMessage('This training requirement already exists for the shift.');

        $service->addTrainingRequirement($shift, $training);
    }

    public function test_add_waiver_requirement_creates_relationship(): void
    {
        $organization = Organization::factory()->create();
        $shift = Shift::factory()->create([
            'event_id' => Event::factory()->for($organization)->create()->id,
        ]);
        $waiver = Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);

        $record = $this->shiftRequirementService()->addWaiverRequirement($shift, $waiver);

        $this->assertSame((string) $shift->id, (string) $record->shift_id);
        $this->assertSame((string) $waiver->id, (string) $record->waiver_id);
        $this->assertTrue($shift->refresh()->requiredWaivers->contains($waiver));
    }

    public function test_add_waiver_requirement_rejects_cross_organization_waiver(): void
    {
        $shift = Shift::factory()->create();
        $organization = Organization::factory()->create();
        $waiver = Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);

        $this->expectException(ShiftRequirementException::class);
        $this->expectExceptionMessage('The requirement must belong to the same organization as the shift event.');

        $this->shiftRequirementService()->addWaiverRequirement($shift, $waiver);
    }

    public function test_add_waiver_requirement_rejects_duplicate(): void
    {
        $organization = Organization::factory()->create();
        $shift = Shift::factory()->create([
            'event_id' => Event::factory()->for($organization)->create()->id,
        ]);
        $waiver = Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
        $service = $this->shiftRequirementService();

        $service->addWaiverRequirement($shift, $waiver);

        $this->expectException(ShiftRequirementException::class);
        $this->expectExceptionMessage('This waiver requirement already exists for the shift.');

        $service->addWaiverRequirement($shift, $waiver);
    }

    public function test_set_signup_window_persists_availability_dates(): void
    {
        $opensAt = now()->addDays(2)->setTime(9, 0);
        $closesAt = $opensAt->copy()->addDays(5);

        $shift = Shift::factory()->create();
        $updated = $this->shiftRequirementService()->setSignupWindow($shift, $opensAt, $closesAt);

        $this->assertTrue($updated->signup_opens_at->equalTo($opensAt));
        $this->assertTrue($updated->signup_closes_at->equalTo($closesAt));
        $this->assertTrue($updated->hasSignupWindow());
        $this->assertTrue($updated->isSignupOpenAt($opensAt->copy()->addDay()));
        $this->assertFalse($updated->isSignupOpenAt($opensAt->copy()->subMinute()));
        $this->assertFalse($updated->isSignupOpenAt($closesAt->copy()->addMinute()));
    }

    public function test_set_signup_window_rejects_close_before_open(): void
    {
        $shift = Shift::factory()->create();
        $opensAt = now()->addDays(3);
        $closesAt = $opensAt->copy()->subHour();

        $this->expectException(ShiftRequirementException::class);
        $this->expectExceptionMessage('Signup close must be after signup open when both dates are configured.');

        $this->shiftRequirementService()->setSignupWindow($shift, $opensAt, $closesAt);
    }

    public function test_signup_is_open_when_no_window_is_configured(): void
    {
        $shift = Shift::factory()->create([
            'signup_opens_at' => null,
            'signup_closes_at' => null,
        ]);

        $this->assertFalse($shift->hasSignupWindow());
        $this->assertTrue($shift->isSignupOpenAt(Carbon::now()));
    }

    public function test_signup_window_may_be_open_ended_on_either_side(): void
    {
        $opensAt = now()->addDay();

        $opensOnly = Shift::factory()->create([
            'signup_opens_at' => $opensAt,
            'signup_closes_at' => null,
        ]);

        $closesOnly = Shift::factory()->create([
            'signup_opens_at' => null,
            'signup_closes_at' => now()->addWeek(),
        ]);

        $this->assertFalse($opensOnly->isSignupOpenAt($opensAt->copy()->subMinute()));
        $this->assertTrue($opensOnly->isSignupOpenAt($opensAt->copy()->addMinute()));

        $this->assertTrue($closesOnly->isSignupOpenAt(now()));
        $this->assertFalse($closesOnly->isSignupOpenAt(now()->addWeeks(2)));
    }
}
