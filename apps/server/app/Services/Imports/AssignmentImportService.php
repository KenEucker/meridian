<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Shift\ShiftAssignmentException;
use App\Services\Shift\ShiftAssignmentOutcome;
use App\Services\Shift\ShiftAssignmentService;
use App\Services\Shift\ShiftSignupException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CSV import for shift assignments (technical spec 22.2).
 *
 * This is the companion to the shift import: once a schedule exists, the roster
 * that goes with it is usually the second spreadsheet. A row says who works
 * which shift — a staff email address, and the shift named the way the shift
 * import names it.
 *
 * Writes go through {@see ShiftAssignmentService}, so an imported assignment is
 * eligibility-checked, audited, and credential-recalculated exactly like one a
 * department lead makes. What the file cannot do is put someone on a shift they
 * may not work: Do Not Staff, department membership, eligible team membership,
 * Ineligible status, and required trainings and waivers all still refuse the
 * row, one row at a time and with the domain's own reason.
 *
 * The file also cannot say how someone got onto the shift. Every imported
 * assignment is recorded as lead-assigned by the operator running the import,
 * because that is what happened; a file claiming a staff member signed up for
 * themselves would falsify the one field the roster export reports on
 * (REPORT-002 `assignment_source`).
 *
 * Nothing here removes anyone. A staff member missing from the file keeps their
 * assignment, and a row matching an assignment that already exists is reported
 * as already assigned rather than duplicated.
 */
final class AssignmentImportService
{
    public const COLUMN_ORGANIZATION_SLUG = 'organization_slug';

    public const COLUMN_EVENT_SLUG = 'event_slug';

    public const COLUMN_DEPARTMENT_CODE = 'department_code';

    public const COLUMN_SHIFT_TITLE = 'shift_title';

    public const COLUMN_SHIFT_STARTS_AT = 'shift_starts_at';

    public const COLUMN_STAFF_EMAIL = 'staff_email';

    /**
     * Optional, and only needed when one department runs two shifts with the
     * same title and start for different teams. Without it such a row is
     * ambiguous, and an ambiguous row is skipped rather than guessed at.
     */
    public const COLUMN_TEAM_CODE = 'team_code';

    /**
     * @var list<string>
     */
    public const REQUIRED_COLUMNS = [
        self::COLUMN_ORGANIZATION_SLUG,
        self::COLUMN_EVENT_SLUG,
        self::COLUMN_DEPARTMENT_CODE,
        self::COLUMN_SHIFT_TITLE,
        self::COLUMN_SHIFT_STARTS_AT,
        self::COLUMN_STAFF_EMAIL,
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly ShiftAssignmentService $assignments,
        private readonly ImportLookup $lookup,
    ) {}

    /**
     * @param  bool  $preview  Run the import and roll it back, reporting what a real run would do.
     *
     * @throws ImportException when the file itself cannot be read.
     */
    public function import(
        string $csv,
        User $actor,
        bool $preview = false,
        string $sourceContext = AuditEvent::SOURCE_ORCHID,
    ): ImportResult {
        $records = CsvImportReader::read($csv, self::REQUIRED_COLUMNS);

        if (! $preview) {
            return $this->apply($records, $actor, false, $sourceContext);
        }

        // The preview is the real import rolled back, so it cannot report
        // something different from what importing would do.
        DB::beginTransaction();

        try {
            return $this->apply($records, $actor, true, $sourceContext);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  list<array{row: int, values: array<string, string>}>  $records
     */
    private function apply(
        array $records,
        User $actor,
        bool $preview,
        string $sourceContext,
    ): ImportResult {
        $rows = [];
        $seen = [];

        foreach ($records as $record) {
            $number = $record['row'];
            $values = $record['values'];

            $organizationSlug = $values[self::COLUMN_ORGANIZATION_SLUG] ?? '';
            $eventSlug = $values[self::COLUMN_EVENT_SLUG] ?? '';
            $departmentCode = $values[self::COLUMN_DEPARTMENT_CODE] ?? '';
            $teamCode = $values[self::COLUMN_TEAM_CODE] ?? '';
            $shiftTitle = $values[self::COLUMN_SHIFT_TITLE] ?? '';
            $startsAtCell = $values[self::COLUMN_SHIFT_STARTS_AT] ?? '';
            $email = Str::lower($values[self::COLUMN_STAFF_EMAIL] ?? '');

            $identifier = $this->identifier($email, $shiftTitle, $startsAtCell);

            if ($organizationSlug === '' || $eventSlug === '' || $departmentCode === '') {
                $rows[] = ImportRow::skipped($number, $identifier, 'Missing organization slug, event slug, or department code.');

                continue;
            }

            if ($shiftTitle === '' || $startsAtCell === '') {
                $rows[] = ImportRow::skipped($number, $identifier, 'Missing shift title or shift start.');

                continue;
            }

            if ($email === '') {
                $rows[] = ImportRow::skipped($number, $identifier, 'Missing staff email address.');

                continue;
            }

            $organization = $this->lookup->organization($organizationSlug);
            $event = $organization === null ? null : $this->lookup->event($organization, $eventSlug);

            if ($organization === null || $event === null) {
                $rows[] = ImportRow::skipped($number, $identifier, sprintf(
                    'No event "%s" in organization "%s".',
                    $eventSlug,
                    $organizationSlug,
                ));

                continue;
            }

            $department = $this->lookup->department($organization, $departmentCode);

            if ($department === null) {
                $rows[] = ImportRow::skipped($number, $identifier, sprintf(
                    'No department "%s" in organization "%s".',
                    $departmentCode,
                    $organizationSlug,
                ));

                continue;
            }

            $team = $teamCode === '' ? null : $this->lookup->team($department, $teamCode);

            if ($teamCode !== '' && $team === null) {
                $rows[] = ImportRow::skipped($number, $identifier, sprintf(
                    'No team "%s" in department "%s".',
                    $teamCode,
                    $departmentCode,
                ));

                continue;
            }

            $startsAt = ImportMoment::parse($startsAtCell, $event);

            if ($startsAt === null) {
                $rows[] = ImportRow::skipped($number, $identifier, 'Shift start must be a readable date and time.');

                continue;
            }

            $shifts = $this->matchingShifts($event, $department, $team, $shiftTitle, $startsAt);

            if ($shifts === []) {
                $rows[] = ImportRow::skipped($number, $identifier, sprintf(
                    'No shift "%s" starting %s in department "%s".',
                    $shiftTitle,
                    $startsAtCell,
                    $departmentCode,
                ));

                continue;
            }

            if (count($shifts) > 1) {
                $rows[] = ImportRow::skipped($number, $identifier, sprintf(
                    'That title and start matches %d shifts in this department. Add a team_code column to name one.',
                    count($shifts),
                ));

                continue;
            }

            $shift = $shifts[0];
            $staff = $this->lookup->staff($email);

            if ($staff === null) {
                $rows[] = ImportRow::skipped($number, $identifier, sprintf(
                    'No staff member with email "%s".',
                    $email,
                ));

                continue;
            }

            $key = (string) $shift->id.'/'.(string) $staff->id;

            if (isset($seen[$key])) {
                $rows[] = ImportRow::skipped($number, $identifier, sprintf(
                    'Duplicate of row %d in this file.',
                    $seen[$key],
                ));

                continue;
            }

            $seen[$key] = $number;

            $rows[] = $this->applyRow($number, $identifier, $shift, $staff, $actor);
        }

        $result = new ImportResult($rows, $preview);

        $this->audit->record(
            action: 'shift_assignments.imported',
            entityType: 'csv_import',
            entityId: (string) Str::uuid(),
            actorUser: $actor,
            after: $result->summary(),
            sourceContext: $sourceContext,
        );

        return $result;
    }

    private function applyRow(
        int $number,
        string $identifier,
        Shift $shift,
        Staff $staff,
        User $actor,
    ): ImportRow {
        try {
            $outcome = $this->assignments->assignStaffToShiftFromImport($shift, $staff, $actor);
        } catch (ShiftAssignmentException|ShiftSignupException $exception) {
            return ImportRow::skipped($number, $identifier, $exception->getMessage());
        }

        // A double-booking is a decision a lead is allowed to make, so the row
        // is written; reporting the overlap is how the operator finds out they
        // made it in a spreadsheet.
        return ImportRow::imported($number, $identifier, $this->overlapNote($outcome));
    }

    private function overlapNote(ShiftAssignmentOutcome $outcome): ?string
    {
        if ($outcome->warnings === []) {
            return null;
        }

        return implode(' ', array_map(
            static fn ($warning): string => $warning->message(),
            $outcome->warnings,
        ));
    }

    /**
     * @return list<Shift>
     */
    private function matchingShifts(
        Event $event,
        Department $department,
        ?Team $team,
        string $title,
        Carbon $startsAt,
    ): array {
        $query = Shift::query()
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->where('starts_at', $startsAt)
            ->whereRaw('lower(title) = ?', [Str::lower($title)]);

        if ($team !== null) {
            $query->where('eligible_team_id', $team->id);
        }

        return $query->get()->all();
    }

    private function identifier(string $email, string $shiftTitle, string $startsAt): string
    {
        $parts = array_filter([$email, $shiftTitle, $startsAt], static fn (string $part): bool => $part !== '');

        return implode(' / ', $parts);
    }
}
