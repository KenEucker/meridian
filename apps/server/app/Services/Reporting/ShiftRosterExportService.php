<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Generates and audits the event shift roster export (M13.2; REPORT-002,
 * REPORT-006 through REPORT-008, REPORT-010; technical spec 22.2).
 *
 * The roster answers the operational question "who is on this shift": one row
 * per staff member on a shift, plus one row for a shift nobody is on yet, so an
 * unfilled shift is visible in the file instead of silently missing from it.
 *
 * Sensitive fields are excluded by construction. No phone number and no
 * emergency contact reaches the file, which is what REPORT-008 requires of
 * every shift roster export and what REPORT-010 requires of every organizer
 * export. Contact details belong to {@see StaffContactExportService}, which is
 * the one export REPORT-009 lets carry them, and only for a department the
 * caller leads.
 *
 * The roster reports assignments as the shift domain recorded them and never
 * re-decides eligibility: removed assignments are gone from the roster, and a
 * cancelled shift is reported as cancelled rather than dropped, so a printed
 * roster still explains why nobody is expected.
 */
final class ShiftRosterExportService implements ReportingExportGenerator
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
        'shift_status',
        'shift_starts_at',
        'shift_ends_at',
        'shift_capacity',
        'assigned_staff_count',
        'staff_legal_name',
        'staff_preferred_name',
        'staff_handle',
        'staff_email',
        'assignment_status',
        'assignment_source',
    ];

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * How the staff member reached the roster: their own signup, or a lead who
     * assigned them (SHIFT-011, SHIFT-015).
     */
    public const SOURCE_SELF_SIGNUP = 'self_signup';

    public const SOURCE_LEAD_ASSIGNED = 'lead_assigned';

    public function __construct(private readonly AuditService $audit) {}

    public function export(
        Event $event,
        ReportingExportScope $scope,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): ReportingExport {
        $exportedAt = now()->utc();
        $departmentIds = $scope->departmentFilter();
        $rows = [];

        foreach ($this->shiftsInScope($event, $scope) as $shift) {
            $assignments = $this->rosterFor($shift);

            if ($assignments->isEmpty()) {
                $rows[] = $this->row($event, $shift, $assignments->count(), null);

                continue;
            }

            foreach ($assignments as $assignment) {
                $rows[] = $this->row($event, $shift, $assignments->count(), $assignment);
            }
        }

        $export = new ReportingExport(
            contents: ReportingExportFile::csv(self::COLUMNS, $rows),
            filename: ReportingExportFile::filename('shift-roster', $event, $scope, $exportedAt->format('Ymd-His')),
            rowCount: count($rows),
            exportedAt: $exportedAt,
        );

        // Exports are sensitive reads: who pulled which roster, for which
        // event, and how wide the scope was (data/API section 8).
        $this->audit->recordForEntity(
            entity: $event,
            action: 'event_shift_roster.exported',
            actorUser: $actor,
            organizationId: (string) $event->organization_id,
            eventId: (string) $event->id,
            departmentId: count($departmentIds) === 1 ? $departmentIds[0] : null,
            after: [
                'format' => $export->format,
                'scope' => $scope->isEventWide() ? 'event' : 'department',
                'department_ids' => $departmentIds,
                'row_count' => $export->rowCount,
                'exported_at' => $export->exportedAt->toIso8601String(),
            ],
            sourceContext: $sourceContext,
        );

        return $export;
    }

    /**
     * Shifts the caller may see, in the order an operator reads a schedule:
     * earliest first, then department, then title. The final key is the shift
     * id so two exports of unchanged data produce the same file.
     *
     * Cancelled shifts stay in the roster and carry their status; a shift that
     * was called off is operational truth a roster reader needs.
     *
     * @return Collection<int, Shift>
     */
    private function shiftsInScope(Event $event, ReportingExportScope $scope): Collection
    {
        $query = Shift::query()
            ->where('event_id', $event->id)
            ->with(['department', 'eligibleTeam']);

        $departmentIds = $scope->departmentFilter();

        if ($departmentIds !== []) {
            $query->whereIn('department_id', $departmentIds);
        }

        return $query
            ->get()
            ->sortBy(fn (Shift $shift): string => implode('|', [
                $shift->starts_at?->utc()->toIso8601String() ?? '',
                Str::lower($this->departmentName($shift)),
                Str::lower((string) $shift->title),
                (string) $shift->id,
            ]))
            ->values();
    }

    /**
     * The staff currently on the shift, ordered by legal name.
     *
     * Only active assignments count: a staff member removed from the shift, or
     * removed by credential revocation (CRED-012), is off the roster.
     *
     * @return Collection<int, ShiftAssignment>
     */
    private function rosterFor(Shift $shift): Collection
    {
        return $shift->assignments()
            ->active()
            ->with('staff')
            ->get()
            ->sortBy(fn (ShiftAssignment $assignment): string => Str::lower(
                (string) $assignment->staff?->legal_name,
            ).'|'.$assignment->staff_id)
            ->values();
    }

    /**
     * @return array<string, string>
     */
    private function row(Event $event, Shift $shift, int $assignedCount, ?ShiftAssignment $assignment): array
    {
        $staff = $assignment?->staff;

        return [
            'event_name' => (string) $event->name,
            'department' => $this->departmentName($shift),
            'team' => $this->teamName($shift),
            'shift_title' => (string) $shift->title,
            'shift_status' => $shift->isCancelled() ? self::STATUS_CANCELLED : self::STATUS_SCHEDULED,
            'shift_starts_at' => $shift->starts_at?->utc()->toIso8601String() ?? '',
            'shift_ends_at' => $shift->ends_at?->utc()->toIso8601String() ?? '',
            'shift_capacity' => $shift->capacity === null ? '' : (string) $shift->capacity,
            'assigned_staff_count' => (string) $assignedCount,
            'staff_legal_name' => (string) ($staff?->legal_name ?? ''),
            'staff_preferred_name' => (string) ($staff?->preferred_name ?? ''),
            'staff_handle' => (string) ($staff?->handle ?? ''),
            'staff_email' => (string) ($staff?->email ?? ''),
            'assignment_status' => (string) ($assignment?->assignment_status ?? ''),
            'assignment_source' => $this->assignmentSource($assignment),
        ];
    }

    /**
     * Snapshot names come first so a historical roster stays readable after a
     * department or team is renamed.
     */
    private function departmentName(Shift $shift): string
    {
        return (string) ($shift->department_name_snapshot ?? $shift->department?->name ?? '');
    }

    private function teamName(Shift $shift): string
    {
        return (string) ($shift->team_name_snapshot ?? $shift->eligibleTeam?->name ?? '');
    }

    private function assignmentSource(?ShiftAssignment $assignment): string
    {
        if ($assignment === null) {
            return '';
        }

        return $assignment->isSelfSignup() ? self::SOURCE_SELF_SIGNUP : self::SOURCE_LEAD_ASSIGNED;
    }
}
