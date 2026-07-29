<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\AuditEvent;
use App\Models\CreditLedgerEntry;
use App\Models\Event;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Credits\CreditCalculationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Generates and audits the credits earned export (M13.6; REPORT-005,
 * REPORT-006, REPORT-007, REPORT-010; CREDIT-005; technical spec 22.2).
 *
 * The export answers "what did this event credit, and how was each number
 * arrived at": one row per `credit_ledger_entries` row, carrying the credits
 * beside the hours, the rate, and the policy that set the rate, so CREDIT-005 is
 * satisfied in the file rather than by pointing a reader at the ledger.
 *
 * The basis columns are read from the entry's frozen `calculation_basis`, never
 * from the policy record it names. A policy may be renamed or re-rated after an
 * entry freezes (CREDIT-004), and this file has to keep showing the rate the
 * work was actually credited at; reading the live policy would silently restate
 * a finished event every time someone edited a multiplier. `policy_source` is
 * exported for the same reason the ledger stores it: a shift-specific policy and
 * an organization default that happen to match produce the same credits
 * (CREDIT-002, CREDIT-003), and only the source tells them apart. A basis
 * missing a key exports an empty cell rather than a substituted live value,
 * because a blank honestly says "the entry recorded no such basis".
 *
 * Rows come from the ledger and nothing else. Hours that resolved to no policy
 * are not credited at all ({@see CreditCalculationService} reports them as
 * `hours_without_credit_policy`), and a zero-credit row here would be
 * indistinguishable from a policy that genuinely credits nothing. What was
 * worked but not yet credited is the hours worked export's answer
 * ({@see HoursWorkedExportService}); conflating the two would leave it ambiguous
 * which rows in this file are owed.
 *
 * Every entry type is exported, not just `calculated`. The column is in the file
 * so the manual adjustment entries a later milestone adds appear the moment they
 * exist rather than being silently dropped by a filter written before they did.
 *
 * Sensitive fields are excluded by construction. No phone number, no emergency
 * contact, and no date of birth is a column, so an organizer export cannot carry
 * emergency contacts (REPORT-010). Contact details belong to
 * {@see StaffContactExportService}, which is the one export REPORT-009 lets carry
 * them, and only for a department the caller leads.
 */
final class CreditsEarnedExportService
{
    /**
     * Column order for the generated file. The header is part of the contract
     * the committed sample fixture pins.
     *
     * `hours`, `credit_multiplier`, and `credits` sit together on purpose: a
     * reader multiplies the first two and lands on the third without opening
     * the policy record (CREDIT-005).
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'event_name',
        'department',
        'team',
        'shift_title',
        'shift_starts_at',
        'shift_ends_at',
        'staff_legal_name',
        'staff_preferred_name',
        'staff_handle',
        'staff_email',
        'entry_type',
        'credit_status',
        'minutes_worked',
        'hours',
        'credit_policy_name',
        'credit_multiplier',
        'credits',
        'policy_source',
        'calculated_at',
        'hours_frozen_at',
        'hours_corrected_at',
    ];

    public function __construct(private readonly AuditService $audit) {}

    public function export(
        Event $event,
        ReportingExportScope $scope,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): ReportingExport {
        $exportedAt = now()->utc();
        $departmentIds = $scope->departmentFilter();
        $entries = $this->entriesInScope($event, $scope);

        $rows = $entries
            ->map(fn (CreditLedgerEntry $entry): array => $this->row($event, $entry))
            ->values()
            ->all();

        $export = new ReportingExport(
            contents: ReportingExportFile::csv(self::COLUMNS, $rows),
            filename: ReportingExportFile::filename('credits-earned', $event, $scope, $exportedAt->format('Ymd-His')),
            rowCount: count($rows),
            exportedAt: $exportedAt,
        );

        // Exports are sensitive reads: who pulled which credits, for which
        // event, how wide the scope was, and the two totals the file hands over
        // — the numbers a later dispute about what someone earned is actually
        // about (data/API section 8).
        $this->audit->recordForEntity(
            entity: $event,
            action: 'event_credits_earned.exported',
            actorUser: $actor,
            organizationId: (string) $event->organization_id,
            eventId: (string) $event->id,
            departmentId: count($departmentIds) === 1 ? $departmentIds[0] : null,
            after: [
                'format' => $export->format,
                'scope' => $scope->isEventWide() ? 'event' : 'department',
                'department_ids' => $departmentIds,
                'row_count' => $export->rowCount,
                'total_hours' => $this->total($entries, 'hours'),
                'total_credits' => $this->total($entries, 'credits'),
                'exported_at' => $export->exportedAt->toIso8601String(),
            ],
            sourceContext: $sourceContext,
        );

        return $export;
    }

    /**
     * Ledger entries the caller may see, in the order an operator reads a credit
     * statement: earliest shift first, then department, then staff member. The
     * final key is the entry id so two exports of an unchanged ledger produce
     * the same file.
     *
     * Scoping follows `credit_ledger_entries.department_id`, which the
     * calculation copies from the hours record it credited, so a department
     * export cannot pick up another department's credits through a renamed or
     * resnapshotted shift.
     *
     * @return Collection<int, CreditLedgerEntry>
     */
    private function entriesInScope(Event $event, ReportingExportScope $scope): Collection
    {
        $query = CreditLedgerEntry::query()
            ->where('event_id', $event->id)
            ->with(['department', 'staff', 'shift.department', 'shift.eligibleTeam']);

        $departmentIds = $scope->departmentFilter();

        if ($departmentIds !== []) {
            $query->whereIn('department_id', $departmentIds);
        }

        return $query
            ->get()
            ->sortBy(fn (CreditLedgerEntry $entry): string => implode('|', [
                $entry->shift?->starts_at?->utc()->toIso8601String() ?? '',
                Str::lower($this->departmentName($entry)),
                Str::lower((string) $entry->shift?->title),
                Str::lower((string) $entry->staff?->legal_name),
                (string) $entry->id,
            ]))
            ->values();
    }

    /**
     * @return array<string, string>
     */
    private function row(Event $event, CreditLedgerEntry $entry): array
    {
        $shift = $entry->shift;
        $staff = $entry->staff;

        return [
            'event_name' => (string) $event->name,
            'department' => $this->departmentName($entry),
            'team' => (string) ($shift?->team_name_snapshot ?? $shift?->eligibleTeam?->name ?? ''),
            'shift_title' => (string) ($shift?->title ?? ''),
            'shift_starts_at' => $shift?->starts_at?->utc()->toIso8601String() ?? '',
            'shift_ends_at' => $shift?->ends_at?->utc()->toIso8601String() ?? '',
            'staff_legal_name' => (string) ($staff?->legal_name ?? ''),
            'staff_preferred_name' => (string) ($staff?->preferred_name ?? ''),
            'staff_handle' => (string) ($staff?->handle ?? ''),
            'staff_email' => (string) ($staff?->email ?? ''),
            'entry_type' => (string) $entry->entry_type,
            'credit_status' => (string) $entry->status,
            'minutes_worked' => $this->basis($entry, 'minutes_worked'),
            // The ledger's own columns, not the basis copies of them: the basis
            // exists so the arithmetic can be re-checked against what the entry
            // pays, which only works if the two are reported from their own
            // sources.
            'hours' => (string) $entry->hours,
            'credit_policy_name' => $this->basis($entry, 'credit_policy_name'),
            'credit_multiplier' => $this->basis($entry, 'credit_multiplier'),
            'credits' => (string) $entry->credits,
            'policy_source' => $this->basis($entry, 'policy_source'),
            'calculated_at' => $this->basis($entry, 'calculated_at'),
            // The hours record's own moments, carried onto the entry when it was
            // written: the basis was final before it was used (CREDIT-001), and
            // a reader can see whether it had been corrected first (HOURS-007).
            'hours_frozen_at' => $this->basis($entry, 'hours_frozen_at'),
            'hours_corrected_at' => $this->basis($entry, 'hours_corrected_at'),
        ];
    }

    /**
     * One value out of the entry's frozen calculation basis (CREDIT-005).
     *
     * A missing or null key exports an empty cell. The basis is the record of
     * what an entry was calculated from, so substituting a live value for one
     * it never stored would report a rate the work was not credited at.
     */
    private function basis(CreditLedgerEntry $entry, string $key): string
    {
        $basis = $entry->calculation_basis;

        if (! is_array($basis)) {
            return '';
        }

        $value = $basis[$key] ?? null;

        return $value === null ? '' : (string) $value;
    }

    /**
     * The shift's snapshot name comes first so a historical credit statement
     * stays readable after a department is renamed, with the entry's own
     * department as the fallback the ledger guarantees is there.
     */
    private function departmentName(CreditLedgerEntry $entry): string
    {
        return (string) (
            $entry->shift?->department_name_snapshot
            ?? $entry->shift?->department?->name
            ?? $entry->department?->name
            ?? ''
        );
    }

    /**
     * @param  Collection<int, CreditLedgerEntry>  $entries
     */
    private function total(Collection $entries, string $column): string
    {
        $total = round((float) $entries->sum(
            fn (CreditLedgerEntry $entry): float => (float) $entry->{$column},
        ), 2);

        return number_format($total, 2, '.', '');
    }
}
