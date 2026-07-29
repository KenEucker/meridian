<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Models\AuditEvent;
use App\Models\CreditLedgerEntry;
use App\Models\Event;
use App\Models\HoursWorked;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns finalized hours into frozen credit ledger entries (M13.5; CREDIT-001
 * through CREDIT-004; data/API section 10.12).
 *
 * The gate is finalization, not the calendar. CREDIT-001 says credits are
 * calculated from finalized hours after the correction grace period, and
 * HOURS-008 makes `hours_worked.frozen_at` the moment that period closed on a
 * record. This service therefore refuses to run for an event that still holds
 * an unfrozen hours record: a partially frozen event is one where an authorized
 * attendance manager may still change a total (HOURS-007), and crediting it
 * would freeze a number that the domain has not finished producing. When
 * M18.14 adds the configured grace-period duration to the organization, that
 * date joins this check rather than replacing it — a run may not begin before
 * the configured period closes, and may not begin while any hours record is
 * still open either.
 *
 * Calculation and freeze are the same moment (CREDIT-004). The hours behind an
 * entry can no longer move when it is written, so there is nothing for a later
 * freeze step to wait for, and an entry written frozen cannot be repriced by a
 * policy edit that lands afterwards. Re-running the calculation is safe and
 * deliberately dull: existing entries are counted and left alone, and only
 * hours with no entry yet produce one.
 *
 * Hours with no resolvable policy are reported, not guessed. An organization
 * that has configured neither a shift policy nor a default (ORG-009) is not
 * running credits, and inventing a multiplier would be indistinguishable from a
 * configured one once frozen into the ledger.
 */
final class CreditCalculationService
{
    public function __construct(
        private readonly CreditPolicyResolver $policyResolver,
        private readonly AuditService $audit,
    ) {}

    /**
     * @throws CreditCalculationException
     */
    public function calculateForEvent(
        Event $event,
        ?User $actor = null,
        ?Carbon $calculatedAt = null,
        string $sourceContext = AuditEvent::SOURCE_SYSTEM,
    ): CreditCalculationResult {
        $calculatedAt ??= Carbon::now();

        return DB::transaction(function () use ($event, $actor, $calculatedAt, $sourceContext): CreditCalculationResult {
            $this->assertGracePeriodClosed($event);

            $records = $this->finalizedHours($event);
            $alreadyCalculated = $this->hoursIdsAlreadyCalculated($records);

            $entries = new Collection;
            $unresolvedPolicyCount = 0;

            foreach ($records as $record) {
                if ($alreadyCalculated->contains((string) $record->id)) {
                    continue;
                }

                $resolution = $record->shift === null
                    ? null
                    : $this->policyResolver->resolve($record->shift);

                if ($resolution === null) {
                    $unresolvedPolicyCount++;

                    continue;
                }

                $entries->push($this->writeEntry($record, $resolution, $actor, $calculatedAt));
            }

            $result = new CreditCalculationResult(
                entries: $entries,
                alreadyCalculatedCount: $alreadyCalculated->count(),
                unresolvedPolicyCount: $unresolvedPolicyCount,
                totalHours: $this->sum($entries, 'hours'),
                totalCredits: $this->sum($entries, 'credits'),
                calculatedAt: $calculatedAt,
            );

            // One audit entry per run, not per row. Each ledger entry is
            // already an immutable record carrying its own basis and author;
            // what the audit trail adds is who started a run, how much it
            // credited, and how much it could not credit (data/API section 8).
            $this->audit->recordForEntity(
                entity: $event,
                action: 'event_credits.calculated',
                actorUser: $actor,
                organizationId: (string) $event->organization_id,
                eventId: (string) $event->id,
                after: [
                    'calculated_at' => $calculatedAt->toIso8601String(),
                    'entries_created' => $result->createdCount(),
                    'entries_already_calculated' => $result->alreadyCalculatedCount,
                    'hours_without_credit_policy' => $result->unresolvedPolicyCount,
                    'total_hours' => $result->totalHours,
                    'total_credits' => $result->totalCredits,
                ],
                sourceContext: $sourceContext,
            );

            return $result;
        });
    }

    /**
     * @throws CreditCalculationException
     */
    private function assertGracePeriodClosed(Event $event): void
    {
        $openRecordCount = HoursWorked::query()
            ->where('event_id', $event->id)
            ->whereNull('frozen_at')
            ->count();

        if ($openRecordCount > 0) {
            throw CreditCalculationException::hoursNotFinalized($openRecordCount);
        }
    }

    /**
     * Frozen hours for the event, earliest shift first, then department, then
     * staff member, then record id. A run over unchanged data therefore writes
     * entries in the same order every time, which keeps a re-run diffable
     * against the first one.
     *
     * @return Collection<int, HoursWorked>
     */
    private function finalizedHours(Event $event): Collection
    {
        return HoursWorked::query()
            ->where('event_id', $event->id)
            ->whereNotNull('frozen_at')
            ->with(['shift.event.organization', 'shift.creditPolicy', 'department', 'staff'])
            ->get()
            ->sortBy(fn (HoursWorked $record): string => implode('|', [
                $record->shift?->starts_at?->utc()->toIso8601String() ?? '',
                Str::lower((string) $record->department?->name),
                Str::lower((string) $record->staff?->legal_name),
                (string) $record->id,
            ]))
            ->values();
    }

    /**
     * Hours ids that already carry a calculated entry. Read once for the whole
     * run so a second calculation costs one query rather than one per record.
     *
     * @param  Collection<int, HoursWorked>  $records
     * @return Collection<int, string>
     */
    private function hoursIdsAlreadyCalculated(Collection $records): Collection
    {
        if ($records->isEmpty()) {
            return new Collection;
        }

        return CreditLedgerEntry::query()
            ->calculated()
            ->whereIn('hours_worked_id', $records->pluck('id')->all())
            ->pluck('hours_worked_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values();
    }

    private function writeEntry(
        HoursWorked $record,
        CreditPolicyResolution $resolution,
        ?User $actor,
        Carbon $calculatedAt,
    ): CreditLedgerEntry {
        $minutesWorked = (int) $record->minutes_worked;
        $multiplier = (float) $resolution->policy->credit_multiplier;

        // Hours are rounded before the multiplier is applied so the two numbers
        // on the row reproduce each other: a reader can multiply the printed
        // hours by the printed rate and get the printed credits, without
        // knowing that a hidden third decimal was dropped somewhere.
        $hours = round($minutesWorked / 60, 2);
        $credits = round($hours * $multiplier, 2);

        $entry = CreditLedgerEntry::query()->create([
            'event_id' => $record->event_id,
            'department_id' => $record->department_id,
            'shift_id' => $record->shift_id,
            'staff_id' => $record->staff_id,
            'hours_worked_id' => $record->id,
            'credit_policy_id' => $resolution->policy->id,
            'entry_type' => CreditLedgerEntry::ENTRY_TYPE_CALCULATED,
            'hours' => $this->decimal($hours),
            'credits' => $this->decimal($credits),
            'status' => CreditLedgerEntry::STATUS_FROZEN,
            'calculation_basis' => $this->calculationBasis($record, $resolution, $minutesWorked, $hours, $credits, $calculatedAt),
            'created_by_user_id' => $actor?->getKey(),
            'frozen_at' => $calculatedAt,
        ]);

        return $entry->refresh();
    }

    /**
     * Everything the number was derived from, stored on the entry itself
     * (CREDIT-005).
     *
     * The policy's name and multiplier are copied rather than referenced,
     * because a policy may be renamed or re-rated after this entry freezes and
     * the export has to keep showing the rate the work was actually credited
     * at. The hours record's own freeze and correction timestamps travel with
     * it so a reader can see that the basis was final before it was used
     * (CREDIT-001) and whether it had been corrected first (HOURS-007).
     *
     * @return array<string, mixed>
     */
    private function calculationBasis(
        HoursWorked $record,
        CreditPolicyResolution $resolution,
        int $minutesWorked,
        float $hours,
        float $credits,
        Carbon $calculatedAt,
    ): array {
        return [
            'policy_source' => $resolution->source,
            'credit_policy_id' => (string) $resolution->policy->id,
            'credit_policy_name' => (string) $resolution->policy->name,
            'credit_multiplier' => (string) $resolution->policy->credit_multiplier,
            'minutes_worked' => $minutesWorked,
            'hours' => $this->decimal($hours),
            'credits' => $this->decimal($credits),
            'hours_worked_id' => (string) $record->id,
            'hours_frozen_at' => $record->frozen_at?->utc()->toIso8601String(),
            'hours_corrected_at' => $record->server_corrected_at?->utc()->toIso8601String(),
            'shift_id' => (string) $record->shift_id,
            'shift_title' => (string) ($record->shift?->title ?? ''),
            'department_id' => (string) $record->department_id,
            'staff_id' => (string) $record->staff_id,
            'calculated_at' => $calculatedAt->utc()->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, CreditLedgerEntry>  $entries
     */
    private function sum(Collection $entries, string $column): string
    {
        return $this->decimal(round((float) $entries->sum(
            fn (CreditLedgerEntry $entry): float => (float) $entry->{$column},
        ), 2));
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
