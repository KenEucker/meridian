<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShiftSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_shifts_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('shifts'));

        foreach ([
            'id',
            'event_id',
            'department_id',
            'eligible_team_id',
            'title',
            'department_name_snapshot',
            'team_name_snapshot',
            'starts_at',
            'ends_at',
            'capacity',
            'signup_opens_at',
            'signup_closes_at',
            'created_at',
            'updated_at',
            'cancelled_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('shifts', $column), "shifts.{$column} missing");
        }
    }

    public function test_shifts_table_defers_later_shift_fields_to_future_tasks(): void
    {
        foreach ([
            'schedule_lock_at',
            'credit_policy_id',
            'meeting_map_location_id',
        ] as $column) {
            $this->assertFalse(Schema::hasColumn('shifts', $column), "shifts.{$column} should be deferred");
        }

        $this->assertFalse(Schema::hasTable('shift_assignments'));
    }

    public function test_shift_belongs_to_event_department_and_eligible_team(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();
        $team = $department->defaultTeam;

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => 'Gate Lead',
        ]);

        $shift->refresh()->load('event', 'department', 'eligibleTeam');

        $this->assertTrue($shift->event->is($event));
        $this->assertTrue($shift->department->is($department));
        $this->assertTrue($shift->eligibleTeam->is($team));
        $this->assertSame('Gate Lead', $shift->title);
        $this->assertTrue($event->shifts->contains($shift));
        $this->assertTrue($department->shifts->contains($shift));
        $this->assertTrue($team->eligibleShifts->contains($shift));
    }

    public function test_shift_snapshots_department_and_team_names_on_create(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Ranger Ops']);
        $team = Team::factory()->for($department)->create(['name' => 'Perimeter']);

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'department_name_snapshot' => null,
            'team_name_snapshot' => null,
        ]);

        $this->assertSame('Ranger Ops', $shift->department_name_snapshot);
        $this->assertSame('Perimeter', $shift->team_name_snapshot);
    }

    public function test_shift_scheduled_time_helpers(): void
    {
        $startsAt = now()->addDays(3)->setTime(9, 0);
        $endsAt = $startsAt->copy()->addHours(6);

        $shift = Shift::factory()->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        $this->assertTrue($shift->starts_at->equalTo($startsAt));
        $this->assertTrue($shift->ends_at->equalTo($endsAt));
        $this->assertTrue($shift->covers($startsAt->copy()->addHours(2)));
        $this->assertFalse($shift->covers($startsAt->copy()->subHour()));
    }

    public function test_shift_capacity_may_be_null_or_defined(): void
    {
        $unlimited = Shift::factory()->create(['capacity' => null]);
        $limited = Shift::factory()->withCapacity(12)->create();

        $this->assertNull($unlimited->capacity);
        $this->assertFalse($unlimited->hasCapacityLimit());

        $this->assertSame(12, $limited->capacity);
        $this->assertTrue($limited->hasCapacityLimit());
    }

    public function test_active_scope_excludes_cancelled_shifts_without_deleting_them(): void
    {
        $active = Shift::factory()->create();
        $cancelled = Shift::factory()->cancelled()->create();

        $this->assertTrue(Shift::query()->active()->whereKey($active)->exists());
        $this->assertFalse(Shift::query()->active()->whereKey($cancelled)->exists());
        $this->assertTrue($cancelled->isCancelled());
        $this->assertDatabaseHas('shifts', ['id' => $cancelled->id]);
    }
}
