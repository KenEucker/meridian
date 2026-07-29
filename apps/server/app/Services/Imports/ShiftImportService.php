<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\Shift;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Shift\ShiftAdminException;
use App\Services\Shift\ShiftAdminService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CSV import for shifts (technical spec 22.2).
 *
 * A schedule is the one thing organizers reliably build in a spreadsheet before
 * it exists in any system, so this is the import that saves an afternoon of
 * typing. Rows name their event, department, and eligible team by slug and code
 * rather than by identifier, for the same reason the team import does: that is
 * what an operator can read off the sheet and check by eye.
 *
 * Writes go through {@see ShiftAdminService}, so an imported shift is validated
 * and audited by the same domain path as one created on the shift screen —
 * including the rules that end must follow start, that the eligible team must
 * belong to the shift's department, and that a started shift's times and team
 * are locked.
 *
 * A row is matched to an existing shift by event, department, eligible team,
 * title, and scheduled start together. Those five columns are the row's
 * identity: changing a title or a start time in the file creates a second shift
 * rather than renaming the first, because nothing else in the file distinguishes
 * "this shift, moved" from "another shift". What an import can correct in place
 * is everything else — the end time, capacity, the signup window, and the
 * schedule lock.
 *
 * Optional columns behave the way an operator would expect from a partial file:
 * a column the file does not carry at all leaves the existing value alone, while
 * a column that is present with an empty cell clears it. Training and waiver
 * requirements are never touched, because the file cannot express them; an
 * updated shift keeps the ones it already had.
 *
 * Nothing here cancels a shift. A shift missing from the file is left alone, and
 * a cancelled shift a row matches is reported rather than quietly revived.
 */
final class ShiftImportService
{
    public const COLUMN_ORGANIZATION_SLUG = 'organization_slug';

    public const COLUMN_EVENT_SLUG = 'event_slug';

    public const COLUMN_DEPARTMENT_CODE = 'department_code';

    public const COLUMN_TEAM_CODE = 'team_code';

    public const COLUMN_TITLE = 'title';

    public const COLUMN_STARTS_AT = 'starts_at';

    public const COLUMN_ENDS_AT = 'ends_at';

    public const COLUMN_CAPACITY = 'capacity';

    public const COLUMN_SIGNUP_OPENS_AT = 'signup_opens_at';

    public const COLUMN_SIGNUP_CLOSES_AT = 'signup_closes_at';

    public const COLUMN_SCHEDULE_LOCK_AT = 'schedule_lock_at';

    /**
     * @var list<string>
     */
    public const REQUIRED_COLUMNS = [
        self::COLUMN_ORGANIZATION_SLUG,
        self::COLUMN_EVENT_SLUG,
        self::COLUMN_DEPARTMENT_CODE,
        self::COLUMN_TEAM_CODE,
        self::COLUMN_TITLE,
        self::COLUMN_STARTS_AT,
        self::COLUMN_ENDS_AT,
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly ShiftAdminService $shifts,
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
            $title = $values[self::COLUMN_TITLE] ?? '';
            $startsAtCell = $values[self::COLUMN_STARTS_AT] ?? '';
            $endsAtCell = $values[self::COLUMN_ENDS_AT] ?? '';

            $identifier = $this->identifier($departmentCode, $title, $startsAtCell);

            if ($organizationSlug === '' || $eventSlug === '' || $departmentCode === '' || $teamCode === '') {
                $rows[] = ImportRow::skipped($number, $identifier, 'Missing organization slug, event slug, department code, or team code.');

                continue;
            }

            if ($title === '') {
                $rows[] = ImportRow::skipped($number, $identifier, 'Missing shift title.');

                continue;
            }

            if (mb_strlen($title) > 255) {
                $rows[] = ImportRow::skipped($number, $identifier, 'Title must be 255 characters or fewer.');

                continue;
            }

            $organization = $this->lookup->organization($organizationSlug);

            if ($organization === null) {
                $rows[] = ImportRow::skipped($number, $identifier, sprintf(
                    'No organization "%s".',
                    $organizationSlug,
                ));

                continue;
            }

            $event = $this->lookup->event($organization, $eventSlug);

            if ($event === null) {
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

            $team = $this->lookup->team($department, $teamCode);

            if ($team === null) {
                $rows[] = ImportRow::skipped($number, $identifier, sprintf(
                    'No team "%s" in department "%s".',
                    $teamCode,
                    $departmentCode,
                ));

                continue;
            }

            $startsAt = ImportMoment::parse($startsAtCell, $event);
            $endsAt = ImportMoment::parse($endsAtCell, $event);

            if ($startsAt === null || $endsAt === null) {
                $rows[] = ImportRow::skipped($number, $identifier, 'Start and end must both be readable dates and times.');

                continue;
            }

            // Optional columns are resolved into attributes only when the file
            // carries them, so a file without a capacity column leaves an
            // existing capacity alone rather than clearing it.
            $attributes = [
                'event_id' => (string) $event->id,
                'eligible_team_id' => (string) $team->id,
                'title' => $title,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ];

            if (array_key_exists(self::COLUMN_CAPACITY, $values)) {
                $capacityCell = $values[self::COLUMN_CAPACITY];

                if ($capacityCell !== '' && ! $this->isPositiveInteger($capacityCell)) {
                    $rows[] = ImportRow::skipped($number, $identifier, 'Capacity must be a whole number of 1 or more, or empty for no limit.');

                    continue;
                }

                $attributes['capacity'] = $capacityCell === '' ? null : (int) $capacityCell;
            }

            $windowFailure = null;

            foreach ([
                self::COLUMN_SIGNUP_OPENS_AT => 'signup_opens_at',
                self::COLUMN_SIGNUP_CLOSES_AT => 'signup_closes_at',
                self::COLUMN_SCHEDULE_LOCK_AT => 'schedule_lock_at',
            ] as $column => $attribute) {
                if (! array_key_exists($column, $values)) {
                    continue;
                }

                $cell = $values[$column];

                if ($cell === '') {
                    $attributes[$attribute] = null;

                    continue;
                }

                $moment = ImportMoment::parse($cell, $event);

                if ($moment === null) {
                    $windowFailure = sprintf('%s must be a readable date and time, or empty.', $column);

                    break;
                }

                $attributes[$attribute] = $moment;
            }

            if ($windowFailure !== null) {
                $rows[] = ImportRow::skipped($number, $identifier, $windowFailure);

                continue;
            }

            $key = implode('/', [
                (string) $event->id,
                (string) $department->id,
                (string) $team->id,
                Str::lower($title),
                $startsAt->toIso8601String(),
            ]);

            if (isset($seen[$key])) {
                $rows[] = ImportRow::skipped($number, $identifier, sprintf(
                    'Duplicate of row %d in this file.',
                    $seen[$key],
                ));

                continue;
            }

            $seen[$key] = $number;

            $rows[] = $this->applyRow(
                $number,
                $identifier,
                $event,
                $department,
                $team,
                $title,
                $startsAt,
                $attributes,
                $actor,
                $sourceContext,
            );
        }

        $result = new ImportResult($rows, $preview);

        $this->audit->record(
            action: 'shifts.imported',
            entityType: 'csv_import',
            entityId: (string) Str::uuid(),
            actorUser: $actor,
            after: $result->summary(),
            sourceContext: $sourceContext,
        );

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyRow(
        int $number,
        string $identifier,
        Event $event,
        Department $department,
        Team $team,
        string $title,
        Carbon $startsAt,
        array $attributes,
        User $actor,
        string $sourceContext,
    ): ImportRow {
        $existing = $this->existingShift($event, $department, $team, $title, $startsAt);

        // Columns the file left out fall back to what the shift already carries,
        // and to nothing at all when the shift is new. Training and waiver
        // requirements come along unchanged: the file cannot express them, and
        // an update that dropped them would silently remove a gate someone set
        // deliberately.
        $attributes += [
            'capacity' => $existing?->capacity,
            'signup_opens_at' => $existing?->signup_opens_at,
            'signup_closes_at' => $existing?->signup_closes_at,
            'schedule_lock_at' => $existing?->schedule_lock_at,
            'required_training_ids' => $this->requirementIds($existing, 'trainingRequirements', 'training_id'),
            'required_waiver_ids' => $this->requirementIds($existing, 'waiverRequirements', 'waiver_id'),
        ];

        try {
            if ($existing === null) {
                $this->shifts->create($department, $attributes, $actor, $sourceContext);

                return ImportRow::imported($number, $identifier);
            }

            if ($existing->isCancelled()) {
                return ImportRow::skipped($number, $identifier, 'This shift is cancelled. Restore it on the shift screen before importing changes.');
            }

            if ($this->matchesExisting($existing, $attributes)) {
                return ImportRow::skipped($number, $identifier, 'Already up to date.');
            }

            $this->shifts->update($existing, $attributes, $actor, $sourceContext);

            return ImportRow::updated($number, $identifier);
        } catch (ShiftAdminException $exception) {
            return ImportRow::skipped($number, $identifier, $exception->getMessage());
        }
    }

    private function existingShift(
        Event $event,
        Department $department,
        Team $team,
        string $title,
        Carbon $startsAt,
    ): ?Shift {
        return Shift::query()
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->where('eligible_team_id', $team->id)
            ->where('starts_at', $startsAt)
            ->whereRaw('lower(title) = ?', [Str::lower($title)])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function matchesExisting(Shift $existing, array $attributes): bool
    {
        return $existing->title === $attributes['title']
            && (string) $existing->eligible_team_id === (string) $attributes['eligible_team_id']
            && $existing->ends_at->equalTo($attributes['ends_at'])
            && $existing->capacity === $attributes['capacity']
            && $this->sameMoment($existing->signup_opens_at, $attributes['signup_opens_at'])
            && $this->sameMoment($existing->signup_closes_at, $attributes['signup_closes_at'])
            && $this->sameMoment($existing->schedule_lock_at, $attributes['schedule_lock_at']);
    }

    private function sameMoment(?Carbon $existing, ?Carbon $imported): bool
    {
        if ($existing === null || $imported === null) {
            return $existing === null && $imported === null;
        }

        return $existing->equalTo($imported);
    }

    /**
     * @return list<string>
     */
    private function requirementIds(?Shift $shift, string $relation, string $column): array
    {
        if ($shift === null) {
            return [];
        }

        return $shift->{$relation}()
            ->pluck($column)
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    private function isPositiveInteger(string $value): bool
    {
        return preg_match('/^\d+$/', $value) === 1 && (int) $value >= 1;
    }

    private function identifier(string $departmentCode, string $title, string $startsAt): string
    {
        $parts = array_filter([$departmentCode, $title, $startsAt], static fn (string $part): bool => $part !== '');

        return implode(' / ', $parts);
    }
}
