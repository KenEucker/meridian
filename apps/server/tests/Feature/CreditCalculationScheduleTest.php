<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPolicy;
use App\Models\Department;
use App\Models\Event;
use App\Models\HoursWorked;
use App\Models\Node;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The scheduled credit calculation run (M18.16; CREDIT-001, CREDIT-004).
 *
 * The command visits only events holding frozen hours no calculated entry
 * covers, defers to the calculation service's own gates, and is quiet — in
 * writes and in audit — when there is nothing to do.
 */
class CreditCalculationScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-15 17:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_run_credits_a_settled_event_without_anyone_asking(): void
    {
        $scenario = $this->settledEvent();

        $this->artisan('meridian:calculate-event-credits')->assertExitCode(0);

        $entry = CreditLedgerEntry::query()->firstOrFail();
        $this->assertSame('6.00', $entry->credits);
        // Nobody pressed a button: the entry and the audit carry the system
        // source, not an actor.
        $this->assertNull($entry->created_by_user_id);

        $audit = AuditEvent::query()->where('action', 'event_credits.calculated')->firstOrFail();
        $this->assertSame(AuditEvent::SOURCE_SYSTEM, $audit->source_context);
        $this->assertSame($scenario['event']->id, $audit->entity_id);
    }

    public function test_a_second_run_writes_nothing_and_audits_nothing(): void
    {
        $this->settledEvent();

        Artisan::call('meridian:calculate-event-credits');
        Artisan::call('meridian:calculate-event-credits');

        // Idempotent in effect and in record: once every frozen hours row
        // carries its entry, the event matches no work query and the second
        // run does not so much as re-audit it.
        $this->assertSame(1, CreditLedgerEntry::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'event_credits.calculated')->count());
    }

    public function test_an_event_still_inside_its_grace_period_is_skipped_not_failed(): void
    {
        // Ends 10 Jul; the 14-day default holds the window open until 24 Jul,
        // past the fixed clock.
        $this->settledEvent(endsAt: '2026-07-10 16:00:00');

        $this->artisan('meridian:calculate-event-credits')->assertExitCode(0);

        $this->assertSame(0, CreditLedgerEntry::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'event_credits.calculated')->count());
    }

    public function test_an_onsite_node_refuses_quietly(): void
    {
        $this->settledEvent();

        Node::factory()->create([
            'node_role' => Node::ROLE_ONSITE,
            'is_local' => true,
        ]);

        $this->artisan('meridian:calculate-event-credits')->assertExitCode(0);

        $this->assertSame(0, CreditLedgerEntry::query()->count());
    }

    /**
     * An organization with a default policy and one event whose only hours
     * record froze inside a grace period that has since closed.
     *
     * @return array<string, mixed>
     */
    private function settledEvent(string $endsAt = '2026-07-01 16:00:00'): array
    {
        $organization = Organization::factory()->create();
        $policy = CreditPolicy::factory()
            ->for($organization)
            ->multiplier('1.500')
            ->create(['name' => 'Standard Credit']);
        $organization->forceFill(['default_credit_policy_id' => $policy->id])->save();

        $department = Department::factory()->for($organization)->create();
        $event = Event::factory()->for($organization)->create([
            'starts_at' => Carbon::parse('2026-06-28 09:00:00 UTC'),
            'ends_at' => Carbon::parse($endsAt, 'UTC'),
        ]);

        $team = Team::query()->where('department_id', $department->id)->where('is_default', true)->first()
            ?? Team::factory()->for($department)->create(['is_default' => true]);

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => 'Gate Watch',
            'starts_at' => Carbon::parse('2026-07-01 08:00:00 UTC'),
            'ends_at' => Carbon::parse('2026-07-01 16:00:00 UTC'),
            'capacity' => null,
        ]);

        $staff = Staff::factory()->create();
        $startedAt = $shift->starts_at->copy();
        $endedAt = $startedAt->copy()->addMinutes(240);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
        ]);

        $record = AttendanceRecord::factory()->create([
            'shift_id' => $shift->id,
            'shift_assignment_id' => $assignment->id,
            'staff_id' => $staff->id,
            'current_state' => AttendanceRecord::STATE_CHECKED_OUT,
            'checked_in_at' => $startedAt,
            'checked_out_at' => $endedAt,
        ]);

        HoursWorked::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'attendance_record_id' => $record->id,
            'actual_started_at' => $startedAt,
            'actual_ended_at' => $endedAt,
            'minutes_worked' => 240,
            'frozen_at' => Carbon::parse('2026-07-14 12:00:00', 'UTC'),
        ]);

        return ['organization' => $organization, 'event' => $event, 'shift' => $shift];
    }
}
