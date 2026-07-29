<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\HoursWorked;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Generates and audits the actual hours worked export (M13.4; REPORT-004,
 * REPORT-006, REPORT-007, REPORT-010; HOURS-001 through HOURS-008; technical
 * spec 22.2).
 *
 * The export answers "how much time was actually worked": one row per recorded
 * `hours_worked` record, carrying the scheduled window beside the actual one so
 * the distinction HOURS-001 draws is visible in the file rather than assumed by
 * the reader.
 *
 * Rows come from recorded hours and nothing else. HOURS-003 through HOURS-006
 * make hours inseparable from a shift and a department, and a shift nobody
 * worked produces no hours at all, so a no-show or an open check-in is absent
 * here by construction. Who was expected on a shift is the shift roster's
 * answer ({@see ShiftRosterExportService}); this file reports only what the
 * attendance domain actually recorded, which is what a credit basis and a
 * reimbursement total can be built on.
 *
 * Corrections and freezing are reported rather than hidden. A corrected record
 * carries the moment it was corrected (HOURS-007) and every row says whether the
 * grace period has closed on it (HOURS-008), so a reader can tell a final number
 * from one that may still move before credits are calculated (CREDIT-001).
 *
 * Sensitive fields are excluded by construction. No phone number, no emergency
 * contact, and no date of birth reaches the file, so an organizer export cannot
 * carry emergency contacts (REPORT-010). Contact details belong to
 * {@see StaffContactExportService}, which is the one export REPORT-009 lets
 * carry them, and only for a department the caller leads.
 */
final class HoursWorkedExportService
{
    /**
     * Column order for the generated file. The header is part of the contract
     * the committed sample fixture pins.
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
        'scheduled_minutes',
        'staff_legal_name',
        'staff_preferred_name',
        'staff_handle',
        'staff_email',
        'actual_started_at',
        'actual_ended_at',
        'minutes_worked',
        'hours_worked',
        'hours_status',
        'correction_state',
        'corrected_at',
        'frozen_at',
    ];

    /**
     * Whether the correction grace period has closed on the record
     * (HOURS-007, HOURS-008). An open record's total may still change; a frozen
     * one is the final basis credits are calculated from (CREDIT-001).
     */
    public const CORRECTION_STATE_OPEN = 'open';

    public const CORRECTION_STATE_FROZEN = 'frozen';

    public function __construct(private readonly AuditService $audit) {}

    public function export(
        Event $event,
        ReportingExportScope $scope,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): ReportingExport {
        $exportedAt = now()->utc();
        $departmentIds = $scope->departmentFilter();
        $hours = $this->hoursInScope($event, $scope);

        $rows = $hours
            ->map(fn (HoursWorked $record): array => $this->row($event, $record))
            ->values()
            ->all();

        $export = new ReportingExport(
            contents: ReportingExportFile::csv(self::COLUMNS, $rows),
            filename: ReportingExportFile::filename('hours-worked', $event, $scope, $exportedAt->format('Ymd-His')),
            rowCount: count($rows),
            exportedAt: $exportedAt,
        );

        // Exports are sensitive reads: who pulled which hours, for which event,
        // how wide the scope was, and how much time the file totals — the
        // number a later dispute is about (data/API section 8).
        $this->audit->recordForEntity(
            entity: $event,
            action: 'event_hours_worked.exported',
            actorUser: $actor,
            organizationId: (string) $event->organization_id,
            eventId: (string) $event->id,
            departmentId: count($departmentIds) === 1 ? $departmentIds[0] : null,
            after: [
                'format' => $export->format,
                'scope' => $scope->isEventWide() ? 'event' : 'department',
                'department_ids' => $departmentIds,
                'row_count' => $export->rowCount,
                'total_minutes_worked' => (int) $hours->sum('minutes_worked'),
                'exported_at' => $export->exportedAt->toIso8601String(),
            ],
            sourceContext: $sourceContext,
        );

        return $export;
    }

    /**
     * Recorded hours the caller may see, in the order an operator reads a
     * timesheet: earliest shift first, then department, then staff member. The
     * final key is the hours id so two exports of unchanged data produce the
     * same file.
     *
     * Scoping follows `hours_worked.department_id`, which HOURS-004 makes
     * required and which the checkout path fills from the shift's own
     * department, so a department export cannot pick up another department's
     * hours through a renamed or resnapshotted shift.
     *
     * @return Collection<int, HoursWorked>
     */
    private function hoursInScope(Event $event, ReportingExportScope $scope): Collection
    {
        $query = HoursWorked::query()
            ->where('event_id', $event->id)
            ->with(['department', 'staff', 'shift.department', 'shift.eligibleTeam']);

        $departmentIds = $scope->departmentFilter();

        if ($departmentIds !== []) {
            $query->whereIn('department_id', $departmentIds);
        }

        return $query
            ->get()
            ->sortBy(fn (HoursWorked $record): string => implode('|', [
                $record->shift?->starts_at?->utc()->toIso8601String() ?? '',
                Str::lower($this->departmentName($record)),
                Str::lower((string) $record->shift?->title),
                Str::lower((string) $record->staff?->legal_name),
                (string) $record->id,
            ]))
            ->values();
    }

    /**
     * @return array<string, string>
     */
    private function row(Event $event, HoursWorked $record): array
    {
        $shift = $record->shift;
        $staff = $record->staff;
        $minutesWorked = (int) $record->minutes_worked;

        return [
            'event_name' => (string) $event->name,
            'department' => $this->departmentName($record),
            'team' => (string) ($shift?->team_name_snapshot ?? $shift?->eligibleTeam?->name ?? ''),
            'shift_title' => (string) ($shift?->title ?? ''),
            'shift_starts_at' => $shift?->starts_at?->utc()->toIso8601String() ?? '',
            'shift_ends_at' => $shift?->ends_at?->utc()->toIso8601String() ?? '',
            'scheduled_minutes' => $this->scheduledMinutes($record),
            'staff_legal_name' => (string) ($staff?->legal_name ?? ''),
            'staff_preferred_name' => (string) ($staff?->preferred_name ?? ''),
            'staff_handle' => (string) ($staff?->handle ?? ''),
            'staff_email' => (string) ($staff?->email ?? ''),
            'actual_started_at' => $record->actual_started_at?->utc()->toIso8601String() ?? '',
            'actual_ended_at' => $record->actual_ended_at?->utc()->toIso8601String() ?? '',
            'minutes_worked' => (string) $minutesWorked,
            'hours_worked' => number_format($minutesWorked / 60, 2, '.', ''),
            'hours_status' => (string) $record->status,
            'correction_state' => $record->frozen_at === null
                ? self::CORRECTION_STATE_OPEN
                : self::CORRECTION_STATE_FROZEN,
            'corrected_at' => $record->server_corrected_at?->utc()->toIso8601String() ?? '',
            'frozen_at' => $record->frozen_at?->utc()->toIso8601String() ?? '',
        ];
    }

    /**
     * The shift's scheduled length, reported beside the actual minutes so the
     * two are comparable in one row without opening the schedule (HOURS-001).
     *
     * Scheduled minutes come from the shift and never from the hours record, so
     * a shift that ran long shows the difference rather than absorbing it.
     */
    private function scheduledMinutes(HoursWorked $record): string
    {
        $startsAt = $record->shift?->starts_at;
        $endsAt = $record->shift?->ends_at;

        if ($startsAt === null || $endsAt === null) {
            return '';
        }

        return (string) (int) $startsAt->diffInMinutes($endsAt);
    }

    /**
     * The shift's snapshot name comes first so a historical timesheet stays
     * readable after a department is renamed, with the hours record's own
     * department as the fallback HOURS-004 guarantees is there.
     */
    private function departmentName(HoursWorked $record): string
    {
        return (string) (
            $record->shift?->department_name_snapshot
            ?? $record->shift?->department?->name
            ?? $record->department?->name
            ?? ''
        );
    }
}
