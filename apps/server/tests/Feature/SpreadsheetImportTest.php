<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Imports\AssignmentImportService;
use App\Services\Imports\ImportException;
use App\Services\Imports\ImportFileReader;
use App\Services\Imports\ImportRow;
use App\Services\Imports\ShiftImportService;
use App\Services\Imports\TeamImportService;
use App\Services\Imports\UserImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\Workbook;
use Tests\TestCase;

/**
 * God-mode spreadsheet import for users, teams, shifts, and assignments
 * (technical spec 22.2).
 *
 * The four imports are already covered row by row against CSV in their own
 * tests. This covers what a workbook adds: the same table arriving in a format
 * where strings live in a shared table, empty cells are absent rather than
 * empty, deleted rows leave gaps in the numbering an operator reads outcomes
 * by, and a date is a number wearing a format.
 */
class SpreadsheetImportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Event $event;

    private Department $rangers;

    private Department $gate;

    private Team $dirt;

    private Team $greeters;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00'));

        $this->organization = Organization::factory()->create([
            'name' => 'Northwood Collective',
            'slug' => 'northwood-collective',
        ]);

        $this->event = Event::factory()->for($this->organization)->create([
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall-2026',
            'timezone' => 'America/Los_Angeles',
        ]);

        $this->rangers = Department::factory()->for($this->organization)->create([
            'name' => 'Rangers',
            'code' => 'RANGERS',
        ]);

        $this->gate = Department::factory()->for($this->organization)->create([
            'name' => 'Gate',
            'code' => 'GATE',
        ]);

        $this->dirt = Team::factory()->for($this->rangers)->create(['name' => 'Dirt', 'code' => 'DIRT']);
        $this->greeters = Team::factory()->for($this->gate)->create(['name' => 'Greeters', 'code' => 'GREETERS']);

        $this->operator = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.imports' => true,
            ],
        ]);
    }

    public function test_a_workbook_creates_users_the_way_the_same_table_as_csv_does(): void
    {
        $workbook = Workbook::of([
            ['email', 'name'],
            ['vera.staff@example.org', 'Vera Staff'],
            ['sam.shiftlead@example.org', 'Sam Shiftlead'],
            ['dana.departmentlead@example.org', 'Dana Departmentlead'],
        ]);

        $result = app(UserImportService::class)->import($workbook, $this->operator);

        $this->assertSame(3, $result->imported());
        $this->assertSame(0, $result->skipped());
        $this->assertDatabaseHas('users', [
            'email' => 'vera.staff@example.org',
            'name' => 'Vera Staff',
        ]);
        $this->assertDatabaseHas('users', ['email' => 'dana.departmentlead@example.org']);
    }

    /**
     * The point of accepting a workbook is that it is the same import. A file
     * saved both ways has to land on the same rows, or an operator has to know
     * which format the server prefers before they can trust the preview.
     */
    public function test_a_workbook_and_its_csv_export_produce_the_same_outcome(): void
    {
        $rows = [
            ['email', 'name'],
            ['vera.staff@example.org', 'Vera Staff'],
            ['not-an-email', 'Broken Row'],
            ['sam.shiftlead@example.org', ''],
        ];

        $fromWorkbook = app(UserImportService::class)->import(Workbook::of($rows), $this->operator, preview: true);
        $fromCsv = app(UserImportService::class)->import($this->csv($rows), $this->operator, preview: true);

        $this->assertSame(
            array_map(static fn (ImportRow $row): array => $row->toArray(), $fromCsv->rows),
            array_map(static fn (ImportRow $row): array => $row->toArray(), $fromWorkbook->rows),
        );
    }

    /**
     * A workbook omits an empty cell entirely rather than writing an empty one,
     * so the columns after it have to be rebuilt from their own references.
     * Read positionally, this row would import a team whose code is its
     * description.
     */
    public function test_an_omitted_cell_does_not_shift_the_columns_after_it(): void
    {
        $workbook = Workbook::of([
            ['organization_slug', 'department_code', 'name', 'code', 'description'],
            ['northwood-collective', 'RANGERS', 'Command', 'COMMAND', null],
            ['northwood-collective', 'GATE', 'Perimeter', 'PERIMETER', 'Walks the fence'],
        ]);

        $result = app(TeamImportService::class)->import($workbook, $this->operator);

        $this->assertSame(2, $result->imported());

        $command = Team::query()->where('code', 'COMMAND')->firstOrFail();

        $this->assertSame('Command', $command->name);
        $this->assertSame((string) $this->rangers->id, (string) $command->department_id);
        $this->assertNull($command->description);
        $this->assertSame('Walks the fence', Team::query()->where('code', 'PERIMETER')->value('description'));
    }

    /**
     * A date typed into a spreadsheet is stored as a count of days, not as the
     * text the operator sees. The import has to land on the time they wrote.
     */
    public function test_a_date_formatted_cell_becomes_the_start_the_spreadsheet_shows(): void
    {
        $workbook = Workbook::of([
            ['organization_slug', 'event_slug', 'department_code', 'team_code', 'title', 'starts_at', 'ends_at', 'capacity'],
            [
                'northwood-collective',
                'emberfall-2026',
                'RANGERS',
                'DIRT',
                'Dirt Patrol Day',
                Workbook::date('2026-08-28 09:00'),
                Workbook::date('2026-08-28 17:00'),
                Workbook::number(6),
            ],
        ]);

        $result = app(ShiftImportService::class)->import($workbook, $this->operator);

        $this->assertSame(1, $result->imported());

        $shift = Shift::query()->where('title', 'Dirt Patrol Day')->firstOrFail();

        // 09:00 in America/Los_Angeles is 16:00 UTC in August, the same reading
        // the CSV of this table gets.
        $this->assertSame('2026-08-28T16:00:00+00:00', $shift->starts_at->toIso8601String());
        $this->assertSame('2026-08-29T00:00:00+00:00', $shift->ends_at->toIso8601String());
        $this->assertSame(6, $shift->capacity);
    }

    /**
     * A shared template formats its dates with a format code of its own rather
     * than one of the built-in ids, and that is still a date.
     */
    public function test_a_workbook_defined_date_format_is_read_as_a_date(): void
    {
        $workbook = Workbook::of([
            ['organization_slug', 'event_slug', 'department_code', 'team_code', 'title', 'starts_at', 'ends_at'],
            [
                'northwood-collective',
                'emberfall-2026',
                'RANGERS',
                'DIRT',
                'Templated Shift',
                Workbook::customDate('2026-08-28 09:00'),
                Workbook::customDate('2026-08-28 17:00'),
            ],
        ]);

        app(ShiftImportService::class)->import($workbook, $this->operator);

        $this->assertSame(
            '2026-08-28T16:00:00+00:00',
            Shift::query()->where('title', 'Templated Shift')->firstOrFail()->starts_at->toIso8601String(),
        );
    }

    /**
     * A cell carrying a time and no date has no date in it, and the reader does
     * not invent one at the spreadsheet epoch.
     */
    public function test_a_time_only_cell_keeps_its_time_and_invents_no_date(): void
    {
        $records = ImportFileReader::read(
            Workbook::of([
                ['title', 'starts_at'],
                ['Dirt Patrol Day', Workbook::time('09:00')],
            ]),
            ['title'],
        );

        $this->assertSame('09:00:00', $records[0]['values']['starts_at']);
    }

    public function test_an_inline_string_reads_the_same_as_a_shared_one(): void
    {
        $records = ImportFileReader::read(
            Workbook::of([
                ['email', 'name'],
                [Workbook::inline('vera.staff@example.org'), 'Vera Staff'],
            ]),
            ['email', 'name'],
        );

        $this->assertSame('vera.staff@example.org', $records[0]['values']['email']);
        $this->assertSame('Vera Staff', $records[0]['values']['name']);
    }

    /**
     * A roster built with lookups leaves `#N/A` behind where a lookup failed.
     * The row is refused with the operator's own broken cell in the reason,
     * rather than read as empty and refused for a reason they cannot find.
     */
    public function test_a_formula_error_cell_refuses_its_row_and_says_what_it_held(): void
    {
        $workbook = Workbook::of([
            ['email', 'name'],
            [Workbook::error(), 'Missing Lookup'],
            ['vera.staff@example.org', 'Vera Staff'],
        ]);

        $result = app(UserImportService::class)->import($workbook, $this->operator);

        $this->assertSame(1, $result->imported());
        $this->assertSame(1, $result->skipped());
        $this->assertSame('Email address is not valid.', $result->rows[0]->reason);
        $this->assertSame('#n/a', $result->rows[0]->identifier);
    }

    /**
     * One bad row must not abort the file, and the row numbers the outcomes
     * carry have to be the ones the operator sees in their own spreadsheet —
     * including past a row they emptied, which a workbook drops rather than
     * writing as blank.
     */
    public function test_bad_rows_are_skipped_with_the_row_numbers_the_spreadsheet_shows(): void
    {
        $workbook = Workbook::of([
            ['email', 'name'],
            ['vera.staff@example.org', 'Vera Staff'],
            ['not-an-email', 'Broken Row'],
            [],
            ['sam.shiftlead@example.org', 'Sam Shiftlead'],
        ]);

        $result = app(UserImportService::class)->import($workbook, $this->operator);

        $this->assertSame(2, $result->imported());
        $this->assertSame(1, $result->skipped());

        $this->assertSame([2, 3, 5], array_map(
            static fn (ImportRow $row): int => $row->row,
            $result->rows,
        ));
        $this->assertSame('Email address is not valid.', $result->rows[1]->reason);
        $this->assertDatabaseHas('users', ['email' => 'sam.shiftlead@example.org']);
    }

    public function test_assignments_import_from_a_workbook(): void
    {
        app(ShiftImportService::class)->import(
            (string) file_get_contents(base_path('tests/Fixtures/shifts-import-sample.csv')),
            $this->operator,
        );

        $vera = $this->eligibleStaff('vera.staff@example.org', $this->rangers, $this->dirt);
        $gwen = $this->eligibleStaff('gwen.greeter@example.org', $this->gate, $this->greeters);

        $workbook = Workbook::of([
            ['organization_slug', 'event_slug', 'department_code', 'shift_title', 'shift_starts_at', 'staff_email'],
            ['northwood-collective', 'emberfall-2026', 'RANGERS', 'Dirt Patrol Day', Workbook::date('2026-08-28 09:00'), 'vera.staff@example.org'],
            ['northwood-collective', 'emberfall-2026', 'GATE', 'Gate Opening', Workbook::date('2026-08-28 06:00'), 'gwen.greeter@example.org'],
        ]);

        $result = app(AssignmentImportService::class)->import($workbook, $this->operator);

        $this->assertSame(2, $result->imported());
        $this->assertSame(0, $result->skipped());
        $this->assertSame(2, ShiftAssignment::query()->count());
        $this->assertNotNull($this->assignment('Dirt Patrol Day', $vera));
        $this->assertNotNull($this->assignment('Gate Opening', $gwen));
    }

    /**
     * A workbook can hold several sheets and only one of them can be the
     * import. The first is the one taken, and no attempt is made to guess.
     */
    public function test_the_first_sheet_is_the_imported_one(): void
    {
        $workbook = Workbook::ofSheets([
            [
                ['email', 'name'],
                ['vera.staff@example.org', 'Vera Staff'],
            ],
            [
                ['email', 'name'],
                ['sam.shiftlead@example.org', 'Sam Shiftlead'],
            ],
        ]);

        $result = app(UserImportService::class)->import($workbook, $this->operator);

        $this->assertSame(1, $result->imported());
        $this->assertDatabaseHas('users', ['email' => 'vera.staff@example.org']);
        $this->assertDatabaseMissing('users', ['email' => 'sam.shiftlead@example.org']);
    }

    /**
     * A file refused whole is refused in the words of the format the operator
     * actually uploaded.
     */
    public function test_a_workbook_without_the_required_columns_is_refused_whole(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('The spreadsheet must include a "email" header column.');

        app(UserImportService::class)->import(
            Workbook::of([
                ['username', 'name'],
                ['vera', 'Vera Staff'],
            ]),
            $this->operator,
        );
    }

    public function test_a_workbook_with_only_a_header_row_is_refused_whole(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('The spreadsheet has a header row but no data rows.');

        app(UserImportService::class)->import(
            Workbook::of([['email', 'name']]),
            $this->operator,
        );
    }

    /**
     * A legacy `.xls` is a compound binary file rather than a zip. Read as CSV
     * it would be refused for a missing header column, which sends an operator
     * looking for a column that is in the file they are staring at.
     */
    public function test_a_legacy_xls_workbook_is_refused_by_name(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('That file is a legacy .xls workbook.');

        app(UserImportService::class)->import(
            "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 64),
            $this->operator,
        );
    }

    public function test_an_archive_that_is_not_a_workbook_is_refused_whole(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('That file is not a spreadsheet Meridian can read.');

        app(UserImportService::class)->import(Workbook::notAWorkbook(), $this->operator);
    }

    /**
     * A workbook has no legitimate reason to declare a document type, and an
     * XML parser that follows one is how an uploaded file gets to read the
     * server. The file is refused rather than parsed.
     */
    public function test_a_workbook_declaring_a_document_type_is_refused_whole(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('The spreadsheet contains XML Meridian will not read.');

        app(UserImportService::class)->import(Workbook::withDocumentType(), $this->operator);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function csv(array $rows): string
    {
        $csv = '';

        foreach ($rows as $row) {
            $csv .= implode(',', array_map(
                static fn (mixed $cell): string => '"'.str_replace('"', '""', (string) $cell).'"',
                $row,
            ))."\n";
        }

        return $csv;
    }

    private function eligibleStaff(string $email, Department $department, Team $team): Staff
    {
        $staff = Staff::factory()->create(['email' => $email]);

        $membership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        return $staff;
    }

    private function assignment(string $shiftTitle, Staff $staff): ShiftAssignment
    {
        return ShiftAssignment::query()
            ->where('shift_id', Shift::query()->where('title', $shiftTitle)->firstOrFail()->id)
            ->where('staff_id', $staff->id)
            ->firstOrFail();
    }
}
