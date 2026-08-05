<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Teams\TeamAdminException;
use App\Services\Teams\TeamAdminService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Spreadsheet and CSV import for teams (technical spec 22.2).
 *
 * Every row names its owning department by organization slug and department
 * code rather than by identifier, because that is what an operator can read off
 * a spreadsheet; department codes are unique per organization, so the pair
 * resolves to exactly one department or the row is skipped.
 *
 * Writes go through {@see TeamAdminService}, so an imported team is created and
 * audited by the same domain path as one created on the team screen. Rows match
 * existing teams on department and team code, so re-running a file updates
 * instead of duplicating. Nothing here archives or deletes a team: a team
 * missing from the file is left alone, because a spreadsheet is not a statement
 * about what should stop existing.
 */
final class TeamImportService
{
    public const COLUMN_ORGANIZATION_SLUG = 'organization_slug';

    public const COLUMN_DEPARTMENT_CODE = 'department_code';

    public const COLUMN_NAME = 'name';

    public const COLUMN_CODE = 'code';

    public const COLUMN_DESCRIPTION = 'description';

    /**
     * @var list<string>
     */
    public const REQUIRED_COLUMNS = [
        self::COLUMN_ORGANIZATION_SLUG,
        self::COLUMN_DEPARTMENT_CODE,
        self::COLUMN_NAME,
        self::COLUMN_CODE,
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly TeamAdminService $teams,
        private readonly ImportLookup $lookup,
    ) {}

    /**
     * @param  bool  $preview  Run the import and roll it back, reporting what a real run would do.
     *
     * @throws ImportException when the file itself cannot be read.
     */
    public function import(
        string $file,
        User $actor,
        bool $preview = false,
        string $sourceContext = AuditEvent::SOURCE_ORCHID,
    ): ImportResult {
        $records = ImportFileReader::read($file, self::REQUIRED_COLUMNS);

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
            $departmentCode = $values[self::COLUMN_DEPARTMENT_CODE] ?? '';
            $name = $values[self::COLUMN_NAME] ?? '';
            $code = $values[self::COLUMN_CODE] ?? '';
            $description = ($values[self::COLUMN_DESCRIPTION] ?? '') === ''
                ? null
                : $values[self::COLUMN_DESCRIPTION];

            $identifier = trim($departmentCode.' / '.$code, ' /');

            if ($organizationSlug === '' || $departmentCode === '') {
                $rows[] = ImportRow::skipped($number, $identifier, 'Missing organization slug or department code.');

                continue;
            }

            if ($name === '' || $code === '') {
                $rows[] = ImportRow::skipped($number, $identifier, 'Missing team name or code.');

                continue;
            }

            if (mb_strlen($name) > 255) {
                $rows[] = ImportRow::skipped($number, $identifier, 'Name must be 255 characters or fewer.');

                continue;
            }

            if (mb_strlen($code) > 64) {
                $rows[] = ImportRow::skipped($number, $identifier, 'Code must be 64 characters or fewer.');

                continue;
            }

            $department = $this->resolveDepartment($organizationSlug, $departmentCode);

            if ($department === null) {
                $rows[] = ImportRow::skipped($number, $identifier, sprintf(
                    'No department "%s" in organization "%s".',
                    $departmentCode,
                    $organizationSlug,
                ));

                continue;
            }

            $key = (string) $department->id.'/'.Str::lower($code);

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
                $department,
                $name,
                $code,
                $description,
                $actor,
                $sourceContext,
            );
        }

        $result = new ImportResult($rows, $preview);

        $this->audit->record(
            action: 'teams.imported',
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
        Department $department,
        string $name,
        string $code,
        ?string $description,
        User $actor,
        string $sourceContext,
    ): ImportRow {
        $existing = Team::query()
            ->where('department_id', $department->id)
            ->whereRaw('lower(code) = ?', [Str::lower($code)])
            ->first();

        try {
            if ($existing === null) {
                $this->teams->create($department, [
                    'name' => $name,
                    'code' => $code,
                    'description' => $description,
                ], $actor, $sourceContext);

                return ImportRow::imported($number, $identifier);
            }

            if ($existing->name === $name
                && $existing->code === $code
                && $existing->description === $description) {
                return ImportRow::skipped($number, $identifier, 'Already up to date.');
            }

            $this->teams->update($existing, [
                'name' => $name,
                'code' => $code,
                'description' => $description,
            ], $actor, $sourceContext);

            return ImportRow::updated($number, $identifier);
        } catch (TeamAdminException $exception) {
            return ImportRow::skipped($number, $identifier, $exception->getMessage());
        } catch (ValidationException $exception) {
            return ImportRow::skipped($number, $identifier, $exception->getMessage());
        }
    }

    private function resolveDepartment(string $organizationSlug, string $departmentCode): ?Department
    {
        $organization = $this->lookup->organization($organizationSlug);

        if ($organization === null) {
            return null;
        }

        return $this->lookup->department($organization, $departmentCode);
    }
}
