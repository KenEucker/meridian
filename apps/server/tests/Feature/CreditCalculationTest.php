<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPolicy;
use App\Models\Department;
use App\Models\Event;
use App\Models\HoursWorked;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use App\Services\Credits\CreditCalculationException;
use App\Services\Credits\CreditCalculationResult;
use App\Services\Credits\CreditCalculationService;
use App\Services\Credits\CreditPolicyResolution;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Credit calculation (M13.5; CREDIT-001 through CREDIT-004; ORG-009, ORG-010,
 * SHIFT-010; HOURS-007, HOURS-008).
 */
class CreditCalculationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Credits are a claim about a moment: the hours were final when they were
     * calculated, and the entry froze then. The scenario runs on a fixed clock
     * so freeze timestamps are the ones the domain is supposed to write rather
     * than whatever the wall clock happened to say.
     */
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

    public function test_frozen_hours_are_credited_at_the_organization_default_policy(): void
    {
        $scenario = $this->scenario();
        $hours = $this->hours($scenario['shift'], $scenario['staff'], minutes: 240, frozenAt: '2026-07-14 12:00:00');

        $result = $this->calculate($scenario['event'], $scenario['organizer']);

        $this->assertSame(1, $result->createdCount());
        $this->assertSame(0, $result->unresolvedPolicyCount);
        $this->assertSame('4.00', $result->totalHours);
        $this->assertSame('6.00', $result->totalCredits);

        $entry = CreditLedgerEntry::query()->where('hours_worked_id', $hours->id)->firstOrFail();

        $this->assertSame('4.00', $entry->hours);
        $this->assertSame('6.00', $entry->credits);
        $this->assertSame($scenario['defaultPolicy']->id, $entry->credit_policy_id);
        $this->assertSame(CreditPolicyResolution::SOURCE_ORGANIZATION, $entry->calculation_basis['policy_source']);
        $this->assertSame(CreditLedgerEntry::ENTRY_TYPE_CALCULATED, $entry->entry_type);
        $this->assertSame($scenario['organizer']->id, $entry->created_by_user_id);

        $this->assertSame($scenario['event']->id, $entry->event_id);
        $this->assertSame($scenario['department']->id, $entry->department_id);
        $this->assertSame($scenario['shift']->id, $entry->shift_id);
        $this->assertSame($scenario['staff']->id, $entry->staff_id);
    }

    public function test_a_shift_specific_policy_wins_over_the_organization_default(): void
    {
        $scenario = $this->scenario();
        $shiftPolicy = CreditPolicy::factory()
            ->for($scenario['organization'])
            ->multiplier('2.000')
            ->create(['name' => 'Overnight Gate', 'shift_id' => $scenario['shift']->id]);
        $scenario['shift']->forceFill(['credit_policy_id' => $shiftPolicy->id])->save();

        $this->hours($scenario['shift'], $scenario['staff'], minutes: 240, frozenAt: '2026-07-14 12:00:00');

        $this->calculate($scenario['event'], $scenario['organizer']);

        $entry = CreditLedgerEntry::query()->firstOrFail();

        $this->assertSame($shiftPolicy->id, $entry->credit_policy_id);
        $this->assertSame('8.00', $entry->credits);
        $this->assertSame(CreditPolicyResolution::SOURCE_SHIFT, $entry->calculation_basis['policy_source']);
        $this->assertSame('2.000', $entry->calculation_basis['credit_multiplier']);
    }

    /**
     * The event-wide refusal is the point. An unfrozen record is one an
     * authorized attendance manager may still correct (HOURS-007), so crediting
     * the event around it would publish a total the grace period has not
     * finished producing.
     */
    public function test_calculation_is_refused_while_any_hours_record_is_still_open(): void
    {
        $scenario = $this->scenario();
        $this->hours($scenario['shift'], $scenario['staff'], minutes: 240, frozenAt: '2026-07-14 12:00:00');
        $this->hours($scenario['shift'], $this->staff($scenario['department'], 'Rio Vasquez'), minutes: 180, frozenAt: null);

        try {
            $this->calculate($scenario['event'], $scenario['organizer']);
            $this->fail('Calculation should refuse an event with an open hours record.');
        } catch (CreditCalculationException $exception) {
            $this->assertStringContainsString('correction grace period is open', $exception->getMessage());
        }

        $this->assertSame(0, CreditLedgerEntry::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'event_credits.calculated')->count());
    }

    public function test_entries_freeze_at_calculation_and_a_second_run_leaves_them_alone(): void
    {
        $scenario = $this->scenario();
        $hours = $this->hours($scenario['shift'], $scenario['staff'], minutes: 240, frozenAt: '2026-07-14 12:00:00');

        $first = $this->calculate($scenario['event'], $scenario['organizer']);
        $entry = CreditLedgerEntry::query()->firstOrFail();

        $this->assertTrue($entry->isFrozen());
        $this->assertSame(CreditLedgerEntry::STATUS_FROZEN, $entry->status);
        $this->assertTrue($entry->frozen_at->equalTo(Carbon::parse('2026-07-15 17:00:00 UTC')));
        $this->assertSame(1, $first->createdCount());
        $this->assertSame(0, $first->alreadyCalculatedCount);

        Carbon::setTestNow('2026-07-20 09:00:00 UTC');
        $second = $this->calculate($scenario['event'], $scenario['organizer']);

        $this->assertSame(0, $second->createdCount());
        $this->assertSame(1, $second->alreadyCalculatedCount);
        $this->assertSame(1, CreditLedgerEntry::query()->where('hours_worked_id', $hours->id)->count());

        $unchanged = CreditLedgerEntry::query()->firstOrFail();

        $this->assertSame($entry->id, $unchanged->id);
        $this->assertTrue($unchanged->frozen_at->equalTo(Carbon::parse('2026-07-15 17:00:00 UTC')));
        $this->assertSame('6.00', $unchanged->credits);
    }

    /**
     * A re-rated policy prices future work. It does not restate what an event
     * already paid, so a recalculation after the change finds the frozen entry
     * and leaves the original credits standing (CREDIT-004).
     */
    public function test_a_later_policy_rate_change_does_not_reprice_frozen_entries(): void
    {
        $scenario = $this->scenario();
        $this->hours($scenario['shift'], $scenario['staff'], minutes: 240, frozenAt: '2026-07-14 12:00:00');
        $this->calculate($scenario['event'], $scenario['organizer']);

        $scenario['defaultPolicy']->forceFill([
            'name' => 'Standard Credit (2027 rate)',
            'credit_multiplier' => '4.000',
        ])->save();

        $this->calculate($scenario['event'], $scenario['organizer']);

        $entry = CreditLedgerEntry::query()->firstOrFail();

        $this->assertSame('6.00', $entry->credits);
        $this->assertSame('1.500', $entry->calculation_basis['credit_multiplier']);
        $this->assertSame('Standard Credit', $entry->calculation_basis['credit_policy_name']);
    }

    public function test_a_frozen_entry_cannot_be_updated_or_deleted(): void
    {
        $scenario = $this->scenario();
        $this->hours($scenario['shift'], $scenario['staff'], minutes: 240, frozenAt: '2026-07-14 12:00:00');
        $this->calculate($scenario['event'], $scenario['organizer']);

        $entry = CreditLedgerEntry::query()->firstOrFail();

        try {
            $entry->forceFill(['credits' => '99.00'])->save();
            $this->fail('A frozen credit ledger entry should not be updatable.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot be updated', $exception->getMessage());
        }

        try {
            $entry->delete();
            $this->fail('A frozen credit ledger entry should not be deletable.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot be deleted', $exception->getMessage());
        }

        $this->assertDatabaseHas('credit_ledger_entries', ['id' => $entry->id, 'credits' => '6.00']);
    }

    /**
     * ORG-009 makes the organization default a configuration step, not a
     * guarantee. Until it is taken, the hours stay uncredited and the run says
     * how many are waiting rather than crediting them at an invented rate.
     */
    public function test_hours_with_no_resolvable_policy_produce_no_entry_and_are_reported(): void
    {
        $scenario = $this->scenario(withDefaultPolicy: false);
        $this->hours($scenario['shift'], $scenario['staff'], minutes: 240, frozenAt: '2026-07-14 12:00:00');

        $result = $this->calculate($scenario['event'], $scenario['organizer']);

        $this->assertSame(0, $result->createdCount());
        $this->assertSame(1, $result->unresolvedPolicyCount);
        $this->assertSame('0.00', $result->totalCredits);
        $this->assertSame(0, CreditLedgerEntry::query()->count());

        $audit = AuditEvent::query()->where('action', 'event_credits.calculated')->firstOrFail();
        $this->assertSame(1, $audit->after_json['hours_without_credit_policy']);
    }

    /**
     * Archiving withdraws a policy from future selection. It does not restate
     * the rate a shift was worked under, so a shift still pointing at an
     * archived policy is credited at that policy rather than dropping to the
     * organization default.
     */
    public function test_an_archived_shift_policy_still_governs_the_shift_that_points_at_it(): void
    {
        $scenario = $this->scenario();
        $archived = CreditPolicy::factory()
            ->for($scenario['organization'])
            ->multiplier('3.000')
            ->archived()
            ->create(['name' => 'Retired Hardship Rate']);
        $scenario['shift']->forceFill(['credit_policy_id' => $archived->id])->save();

        $this->hours($scenario['shift'], $scenario['staff'], minutes: 120, frozenAt: '2026-07-14 12:00:00');

        $this->calculate($scenario['event'], $scenario['organizer']);

        $entry = CreditLedgerEntry::query()->firstOrFail();

        $this->assertSame($archived->id, $entry->credit_policy_id);
        $this->assertSame('6.00', $entry->credits);
    }

    /**
     * Hours are rounded before the multiplier is applied so a reader can
     * multiply the two printed numbers and land on the printed result.
     */
    public function test_the_entry_reproduces_its_own_arithmetic_after_rounding(): void
    {
        $scenario = $this->scenario(multiplier: '1.250');
        $this->hours($scenario['shift'], $scenario['staff'], minutes: 250, frozenAt: '2026-07-14 12:00:00');

        $this->calculate($scenario['event'], $scenario['organizer']);

        $entry = CreditLedgerEntry::query()->firstOrFail();

        $this->assertSame('4.17', $entry->hours);
        $this->assertSame('5.21', $entry->credits);
        $this->assertSame(250, $entry->calculation_basis['minutes_worked']);
        $this->assertSame(
            round((float) $entry->hours * (float) $entry->calculation_basis['credit_multiplier'], 2),
            round((float) $entry->credits, 2),
        );
    }

    /**
     * A corrected record is credited at the corrected total, and the entry says
     * it was corrected, so a later dispute can tell an edited basis from the one
     * the clock produced (HOURS-007).
     */
    public function test_corrected_hours_are_credited_at_their_corrected_total(): void
    {
        $scenario = $this->scenario();
        $hours = $this->hours(
            $scenario['shift'],
            $scenario['staff'],
            minutes: 300,
            frozenAt: '2026-07-14 12:00:00',
            correctedAt: '2026-07-10 08:30:00',
        );

        $this->calculate($scenario['event'], $scenario['organizer']);

        $entry = CreditLedgerEntry::query()->firstOrFail();

        $this->assertSame('5.00', $entry->hours);
        $this->assertSame('7.50', $entry->credits);
        $this->assertSame('2026-07-10T08:30:00+00:00', $entry->calculation_basis['hours_corrected_at']);
        $this->assertSame(
            $hours->frozen_at->utc()->toIso8601String(),
            $entry->calculation_basis['hours_frozen_at'],
        );
    }

    public function test_the_calculation_basis_records_what_the_number_was_derived_from(): void
    {
        $scenario = $this->scenario();
        $hours = $this->hours($scenario['shift'], $scenario['staff'], minutes: 240, frozenAt: '2026-07-14 12:00:00');

        $this->calculate($scenario['event'], $scenario['organizer']);

        $basis = CreditLedgerEntry::query()->firstOrFail()->calculation_basis;

        $this->assertSame([
            'calculated_at',
            'credit_multiplier',
            'credit_policy_id',
            'credit_policy_name',
            'credits',
            'department_id',
            'hours',
            'hours_corrected_at',
            'hours_frozen_at',
            'hours_worked_id',
            'minutes_worked',
            'policy_source',
            'shift_id',
            'shift_title',
            'staff_id',
        ], $this->sortedKeys($basis));

        $this->assertSame((string) $hours->id, $basis['hours_worked_id']);
        $this->assertSame('Standard Credit', $basis['credit_policy_name']);
        $this->assertSame('1.500', $basis['credit_multiplier']);
        $this->assertSame('Gate Watch', $basis['shift_title']);
        $this->assertNull($basis['hours_corrected_at']);
        $this->assertSame('2026-07-15T17:00:00+00:00', $basis['calculated_at']);
    }

    public function test_the_run_is_audited_with_its_scope_and_totals(): void
    {
        $scenario = $this->scenario();
        $this->hours($scenario['shift'], $scenario['staff'], minutes: 240, frozenAt: '2026-07-14 12:00:00');
        $this->hours(
            $scenario['shift'],
            $this->staff($scenario['department'], 'Rio Vasquez'),
            minutes: 120,
            frozenAt: '2026-07-14 12:00:00',
        );

        $this->calculate($scenario['event'], $scenario['organizer']);

        $audit = AuditEvent::query()->where('action', 'event_credits.calculated')->firstOrFail();

        $this->assertSame($scenario['organizer']->id, $audit->actor_user_id);
        $this->assertSame($scenario['organization']->id, $audit->organization_id);
        $this->assertSame($scenario['event']->id, $audit->event_id);
        $this->assertSame(2, $audit->after_json['entries_created']);
        $this->assertSame(0, $audit->after_json['entries_already_calculated']);
        $this->assertSame(0, $audit->after_json['hours_without_credit_policy']);
        $this->assertSame('6.00', $audit->after_json['total_hours']);
        $this->assertSame('9.00', $audit->after_json['total_credits']);
        $this->assertSame(AuditEvent::SOURCE_SYSTEM, $audit->source_context);
    }

    public function test_an_event_with_no_recorded_hours_calculates_nothing(): void
    {
        $scenario = $this->scenario();

        $result = $this->calculate($scenario['event'], $scenario['organizer']);

        $this->assertSame(0, $result->createdCount());
        $this->assertSame('0.00', $result->totalCredits);
        $this->assertSame(0, CreditLedgerEntry::query()->count());
    }

    /**
     * Another event's frozen hours are not this event's credits, even when both
     * belong to the same organization and department.
     */
    public function test_calculation_is_scoped_to_its_own_event(): void
    {
        $scenario = $this->scenario();
        $otherEvent = Event::factory()->for($scenario['organization'])->create(['name' => 'Spring Work Weekend']);
        $otherShift = $this->shift($otherEvent, $scenario['department'], 'Perimeter Watch');

        $this->hours($scenario['shift'], $scenario['staff'], minutes: 240, frozenAt: '2026-07-14 12:00:00');
        $this->hours($otherShift, $scenario['staff'], minutes: 60, frozenAt: '2026-07-14 12:00:00');

        $result = $this->calculate($scenario['event'], $scenario['organizer']);

        $this->assertSame(1, $result->createdCount());
        $this->assertSame($scenario['event']->id, $result->entries->first()->event_id);
    }

    private function calculate(Event $event, User $actor): CreditCalculationResult
    {
        return app(CreditCalculationService::class)->calculateForEvent($event, $actor);
    }

    /**
     * @return array<string, mixed>
     */
    private function scenario(bool $withDefaultPolicy = true, string $multiplier = '1.500'): array
    {
        $organization = Organization::factory()->create(['name' => 'Signal Camp']);
        $event = Event::factory()->for($organization)->create(['name' => 'Emberfall 2026']);
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);

        $defaultPolicy = null;

        if ($withDefaultPolicy) {
            $defaultPolicy = CreditPolicy::factory()
                ->for($organization)
                ->multiplier($multiplier)
                ->create(['name' => 'Standard Credit']);

            $organization->forceFill(['default_credit_policy_id' => $defaultPolicy->id])->save();
        }

        return [
            'organization' => $organization->refresh(),
            'event' => $event,
            'department' => $department,
            'defaultPolicy' => $defaultPolicy,
            'shift' => $this->shift($event, $department, 'Gate Watch'),
            'staff' => $this->staff($department, 'Avery Nakamura'),
            'organizer' => User::factory()->create(),
        ];
    }

    private function shift(Event $event, Department $department, string $title): Shift
    {
        return Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $this->defaultTeam($department)->id,
            'title' => $title,
            'starts_at' => Carbon::parse('2026-07-01 08:00:00 UTC'),
            'ends_at' => Carbon::parse('2026-07-01 16:00:00 UTC'),
            'capacity' => null,
        ]);
    }

    private function defaultTeam(Department $department): Team
    {
        return Team::query()
            ->where('department_id', $department->id)
            ->where('is_default', true)
            ->firstOrFail();
    }

    private function staff(Department $department, string $legalName): Staff
    {
        return Staff::factory()->create(['legal_name' => $legalName]);
    }

    /**
     * A worked shift and the hours the checkout path creates from it, frozen or
     * still inside the correction grace period.
     */
    private function hours(
        Shift $shift,
        Staff $staff,
        int $minutes,
        ?string $frozenAt,
        ?string $correctedAt = null,
    ): HoursWorked {
        $startedAt = $shift->starts_at->copy();
        $endedAt = $startedAt->copy()->addMinutes($minutes);

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
            'current_state' => $correctedAt === null
                ? AttendanceRecord::STATE_CHECKED_OUT
                : AttendanceRecord::STATE_CORRECTED,
            'checked_in_at' => $startedAt,
            'checked_out_at' => $endedAt,
            'corrected_at' => $correctedAt === null ? null : Carbon::parse($correctedAt, 'UTC'),
        ]);

        return HoursWorked::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'attendance_record_id' => $record->id,
            'actual_started_at' => $startedAt,
            'actual_ended_at' => $endedAt,
            'minutes_worked' => $minutes,
            'server_corrected_at' => $correctedAt === null ? null : Carbon::parse($correctedAt, 'UTC'),
            'frozen_at' => $frozenAt === null ? null : Carbon::parse($frozenAt, 'UTC'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $basis
     * @return list<string>
     */
    private function sortedKeys(array $basis): array
    {
        $keys = array_keys($basis);
        sort($keys);

        return $keys;
    }
}
