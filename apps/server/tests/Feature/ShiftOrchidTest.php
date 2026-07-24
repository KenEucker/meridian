<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\Team;
use App\Models\Training;
use App\Models\User;
use App\Models\Waiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

/**
 * God Mode shift repair tooling (technical spec 22.1).
 */
class ShiftOrchidTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    public function test_orchid_shift_list_displays_shifts(): void
    {
        $shift = $this->shiftFor('Dirt Patrol');

        $response = $this->actingAs($this->shiftAdmin())->get(route('platform.shifts'));

        $response->assertOk();
        $response->assertSee('Shifts');
        $response->assertSee('Dirt Patrol');
        $response->assertSee($shift->department->name);
        $response->assertSee($shift->eligibleTeam->name);
    }

    public function test_orchid_shift_detail_displays_edit_scaffold(): void
    {
        $shift = $this->shiftFor('Gate Watch');

        $response = $this->actingAs($this->shiftAdmin())
            ->get(route('platform.shifts.edit', $shift));

        $response->assertOk();
        $response->assertSee('Edit Shift');
        $response->assertSee('Gate Watch');
        $response->assertSee('Cancel shift');
        $response->assertSee('Save');
    }

    public function test_orchid_shift_screen_requires_permission(): void
    {
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
            ],
        ]);

        $this->actingAs($user)->get(route('platform.shifts'))->assertForbidden();
    }

    public function test_orchid_shift_save_creates_shift_with_requirements(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $event = Event::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();
        $training = Training::factory()->create(['organization_id' => $organization->id]);
        $waiver = Waiver::factory()->create(['organization_id' => $organization->id]);

        $startsAt = Carbon::parse('2027-07-01 08:00:00');

        $response = $this->screen('platform.shifts.create')
            ->actingAs($this->shiftAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'shift' => [
                    'event_id' => $event->id,
                    'department_id' => $department->id,
                    'eligible_team_id' => $team->id,
                    'title' => 'Dirt Patrol',
                    'starts_at' => $startsAt->toDateTimeString(),
                    'ends_at' => $startsAt->copy()->addHours(8)->toDateTimeString(),
                    'capacity' => 6,
                    'required_training_ids' => [$training->id],
                    'required_waiver_ids' => [$waiver->id],
                ],
            ]);

        $response->assertRedirect(route('platform.shifts'));

        $this->assertDatabaseHas('shifts', [
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => 'Dirt Patrol',
            'capacity' => 6,
        ]);

        $shift = Shift::query()->where('title', 'Dirt Patrol')->firstOrFail();

        $this->assertDatabaseHas('shift_training_requirements', [
            'shift_id' => $shift->id,
            'training_id' => $training->id,
        ]);
        $this->assertDatabaseHas('shift_waiver_requirements', [
            'shift_id' => $shift->id,
            'waiver_id' => $waiver->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'shift.created',
            'entity_id' => $shift->id,
            'source_context' => 'orchid',
        ]);
    }

    public function test_orchid_shift_save_validates_structure(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $event = Event::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();
        $foreignTeam = Team::factory()->create();
        $foreignEvent = Event::factory()->create();
        $startsAt = Carbon::parse('2027-07-01 08:00:00');

        $base = [
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => 'Dirt Patrol',
            'starts_at' => $startsAt->toDateTimeString(),
            'ends_at' => $startsAt->copy()->addHours(8)->toDateTimeString(),
        ];

        $this->screen('platform.shifts.create')
            ->actingAs($this->shiftAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'shift' => [...$base, 'ends_at' => $startsAt->copy()->subHour()->toDateTimeString()],
            ])
            ->assertSessionHasErrors('shift.ends_at');

        $this->screen('platform.shifts.create')
            ->actingAs($this->shiftAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'shift' => [...$base, 'eligible_team_id' => $foreignTeam->id],
            ])
            ->assertSessionHasErrors('shift.eligible_team_id');

        $this->screen('platform.shifts.create')
            ->actingAs($this->shiftAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'shift' => [...$base, 'event_id' => $foreignEvent->id],
            ])
            ->assertSessionHasErrors('shift.event_id');

        $this->screen('platform.shifts.create')
            ->actingAs($this->shiftAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'shift' => [
                    ...$base,
                    'signup_opens_at' => $startsAt->copy()->subDay()->toDateTimeString(),
                    'signup_closes_at' => $startsAt->copy()->subDays(3)->toDateTimeString(),
                ],
            ])
            ->assertSessionHasErrors('shift.signup_closes_at');
    }

    public function test_orchid_shift_cancel_and_restore_preserves_record(): void
    {
        $shift = $this->shiftFor('Repairable Shift');

        $this->screen('platform.shifts.edit', ['shift' => $shift->id])
            ->actingAs($this->shiftAdmin())
            ->withoutFollowingRedirects()
            ->method('cancelShift')
            ->assertRedirect(route('platform.shifts'));

        $this->assertTrue($shift->refresh()->isCancelled());

        $this->screen('platform.shifts.edit', ['shift' => $shift->id])
            ->actingAs($this->shiftAdmin())
            ->withoutFollowingRedirects()
            ->method('restore')
            ->assertRedirect(route('platform.shifts'));

        $this->assertFalse($shift->refresh()->isCancelled());
    }

    public function test_orchid_repair_can_reschedule_a_started_shift(): void
    {
        // The product path locks a started shift's schedule; God Mode repair
        // deliberately does not, so a mis-scheduled shift stays fixable.
        $shift = $this->shiftFor('Started Shift', startsAt: Carbon::now()->subHours(2));
        $newStart = Carbon::now()->addDay()->startOfHour();

        $this->screen('platform.shifts.edit', ['shift' => $shift->id])
            ->actingAs($this->shiftAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'shift' => [
                    'event_id' => $shift->event_id,
                    'department_id' => $shift->department_id,
                    'eligible_team_id' => $shift->eligible_team_id,
                    'title' => 'Started Shift',
                    'starts_at' => $newStart->toDateTimeString(),
                    'ends_at' => $newStart->copy()->addHours(4)->toDateTimeString(),
                ],
            ])
            ->assertRedirect(route('platform.shifts'));

        $this->assertTrue($shift->refresh()->starts_at->equalTo($newStart));
    }

    private function shiftFor(string $title, ?Carbon $startsAt = null): Shift
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $event = Event::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();
        $startsAt ??= Carbon::now()->addWeek();

        return Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(8),
        ]);
    }

    private function shiftAdmin(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.shifts' => true,
            ],
        ]);
    }
}
