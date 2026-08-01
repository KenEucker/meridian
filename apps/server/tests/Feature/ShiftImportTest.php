<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\ShiftTrainingRequirement;
use App\Models\Team;
use App\Models\Training;
use App\Models\User;
use App\Services\Imports\ImportException;
use App\Services\Imports\ImportRow;
use App\Services\Imports\ShiftImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * God-mode CSV import for shifts (technical spec 22.2).
 */
class ShiftImportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Event $event;

    private Department $rangers;

    private Department $gate;

    private Team $dirt;

    protected function setUp(): void
    {
        parent::setUp();

        // The committed sample carries fixed dates, so the clock is fixed
        // before them: an imported shift that has already started is a
        // different case, and it belongs in its own test rather than arriving
        // on its own the week the sample ages out.
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
        Team::factory()->for($this->gate)->create(['name' => 'Greeters', 'code' => 'GREETERS']);
    }

    public function test_it_creates_shifts_from_the_sample_fixture(): void
    {
        $actor = User::factory()->create();

        $result = $this->service()->import($this->fixture('shifts-import-sample.csv'), $actor);

        $this->assertSame(3, $result->imported());
        $this->assertSame(0, $result->skipped());
        $this->assertSame(3, Shift::query()->count());

        $day = $this->shift('Dirt Patrol Day');

        $this->assertSame((string) $this->event->id, (string) $day->event_id);
        $this->assertSame((string) $this->rangers->id, (string) $day->department_id);
        $this->assertSame((string) $this->dirt->id, (string) $day->eligible_team_id);
        $this->assertSame(6, $day->capacity);

        // 09:00 in America/Los_Angeles is 16:00 UTC in August; a spreadsheet
        // carries the times the shift is worked, not UTC.
        $this->assertSame('2026-08-28T16:00:00+00:00', $day->starts_at->toIso8601String());
        $this->assertSame('2026-08-29T00:00:00+00:00', $day->ends_at->toIso8601String());
        $this->assertSame('2026-08-01T16:00:00+00:00', $day->signup_opens_at?->toIso8601String());

        $this->assertNull($this->shift('Gate Opening')->capacity);
        $this->assertNull($this->shift('Dirt Patrol Night')->signup_opens_at);
    }

    public function test_a_time_that_states_its_own_offset_is_honored_as_written(): void
    {
        $actor = User::factory()->create();

        $this->service()->import(
            $this->header()."northwood-collective,emberfall-2026,RANGERS,DIRT,Offset Shift,2026-08-28T09:00:00Z,2026-08-28T17:00:00Z\n",
            $actor,
        );

        $this->assertSame('2026-08-28T09:00:00+00:00', $this->shift('Offset Shift')->starts_at->toIso8601String());
    }

    public function test_rerunning_the_same_file_changes_nothing(): void
    {
        $actor = User::factory()->create();
        $service = $this->service();

        $service->import($this->fixture('shifts-import-sample.csv'), $actor);
        $result = $service->import($this->fixture('shifts-import-sample.csv'), $actor);

        $this->assertSame(0, $result->imported());
        $this->assertSame(0, $result->updated());
        $this->assertSame(3, $result->skipped());
        $this->assertSame('Already up to date.', $result->rows[0]->reason);
        $this->assertSame(3, Shift::query()->count());
    }

    public function test_a_corrected_end_time_and_capacity_update_the_matched_shift(): void
    {
        $actor = User::factory()->create();
        $service = $this->service();

        $service->import($this->fixture('shifts-import-sample.csv'), $actor);

        $result = $service->import(
            "Organization Slug,Event Slug,Department Code,Team Code,Title,Starts At,Ends At,Capacity\n"
            ."northwood-collective,emberfall-2026,rangers,dirt,Dirt Patrol Day,2026-08-28 09:00,2026-08-28 21:00,8\n",
            $actor,
        );

        $this->assertSame(1, $result->updated());
        $this->assertSame(3, Shift::query()->count());

        $day = $this->shift('Dirt Patrol Day');
        $this->assertSame('2026-08-29T04:00:00+00:00', $day->ends_at->toIso8601String());
        $this->assertSame(8, $day->capacity);

        // A column the file leaves out keeps what the shift already carries.
        $this->assertSame('2026-08-01T16:00:00+00:00', $day->signup_opens_at?->toIso8601String());
    }

    public function test_a_present_but_empty_optional_column_clears_the_value(): void
    {
        $actor = User::factory()->create();
        $service = $this->service();

        $service->import($this->fixture('shifts-import-sample.csv'), $actor);

        $service->import(
            $this->header('capacity,signup_opens_at')
            ."northwood-collective,emberfall-2026,RANGERS,DIRT,Dirt Patrol Day,2026-08-28 09:00,2026-08-28 17:00,,\n",
            $actor,
        );

        $day = $this->shift('Dirt Patrol Day');
        $this->assertNull($day->capacity);
        $this->assertNull($day->signup_opens_at);
    }

    /**
     * The title and the scheduled start are the row's identity, so editing
     * either one adds a shift rather than renaming one. This is the documented
     * trade-off of matching a record that carries no code of its own.
     */
    public function test_a_retitled_row_creates_a_second_shift_rather_than_renaming(): void
    {
        $actor = User::factory()->create();
        $service = $this->service();

        $service->import($this->fixture('shifts-import-sample.csv'), $actor);

        $result = $service->import(
            $this->header()."northwood-collective,emberfall-2026,RANGERS,DIRT,Dirt Patrol Daylight,2026-08-28 09:00,2026-08-28 17:00\n",
            $actor,
        );

        $this->assertSame(1, $result->imported());
        $this->assertSame(4, Shift::query()->count());
        $this->assertNotNull($this->shift('Dirt Patrol Day'));
        $this->assertNotNull($this->shift('Dirt Patrol Daylight'));
    }

    /**
     * The file cannot express training or waiver requirements, so an update
     * must not be read as removing the ones a lead set deliberately.
     */
    public function test_an_update_keeps_training_requirements_the_file_cannot_carry(): void
    {
        $actor = User::factory()->create();
        $service = $this->service();

        $service->import($this->fixture('shifts-import-sample.csv'), $actor);

        $shift = $this->shift('Dirt Patrol Day');
        $training = Training::factory()->create(['organization_id' => $this->organization->id]);
        ShiftTrainingRequirement::query()->create([
            'shift_id' => $shift->id,
            'training_id' => $training->id,
        ]);

        $result = $service->import(
            $this->header('capacity')
            ."northwood-collective,emberfall-2026,RANGERS,DIRT,Dirt Patrol Day,2026-08-28 09:00,2026-08-28 17:00,9\n",
            $actor,
        );

        $this->assertSame(1, $result->updated());
        $this->assertSame(9, $this->shift('Dirt Patrol Day')->capacity);
        $this->assertSame(1, ShiftTrainingRequirement::query()->where('shift_id', $shift->id)->count());
    }

    /**
     * One unresolvable row must not abort the file.
     */
    public function test_unresolvable_rows_are_skipped_with_a_reason_and_good_rows_still_import(): void
    {
        $actor = User::factory()->create();

        $csv = $this->header('capacity')
            ."no-such-org,emberfall-2026,RANGERS,DIRT,Dirt Patrol Day,2026-08-28 09:00,2026-08-28 17:00,\n"
            ."northwood-collective,no-such-event,RANGERS,DIRT,Dirt Patrol Day,2026-08-28 09:00,2026-08-28 17:00,\n"
            ."northwood-collective,emberfall-2026,NOPE,DIRT,Dirt Patrol Day,2026-08-28 09:00,2026-08-28 17:00,\n"
            ."northwood-collective,emberfall-2026,RANGERS,GREETERS,Dirt Patrol Day,2026-08-28 09:00,2026-08-28 17:00,\n"
            ."northwood-collective,emberfall-2026,RANGERS,DIRT,,2026-08-28 09:00,2026-08-28 17:00,\n"
            ."northwood-collective,emberfall-2026,RANGERS,DIRT,Dirt Patrol Day,not a date,2026-08-28 17:00,\n"
            ."northwood-collective,emberfall-2026,RANGERS,DIRT,Dirt Patrol Backwards,2026-08-28 09:00,2026-08-28 08:00,\n"
            ."northwood-collective,emberfall-2026,RANGERS,DIRT,Dirt Patrol Day,2026-08-28 09:00,2026-08-28 17:00,none\n"
            ."northwood-collective,emberfall-2026,RANGERS,DIRT,Dirt Patrol Day,2026-08-28 09:00,2026-08-28 17:00,6\n"
            ."northwood-collective,emberfall-2026,RANGERS,DIRT,dirt patrol day,2026-08-28 09:00,2026-08-28 17:00,6\n";

        $result = $this->service()->import($csv, $actor);

        $this->assertSame(1, $result->imported());
        $this->assertSame(9, $result->skipped());

        $reasons = array_map(static fn (ImportRow $row): ?string => $row->reason, $result->rows);

        $this->assertSame([
            'No organization "no-such-org".',
            'No event "no-such-event" in organization "northwood-collective".',
            'No department "NOPE" in organization "northwood-collective".',
            'No team "GREETERS" in department "RANGERS".',
            'Missing shift title.',
            'Start and end must both be readable dates and times.',
            'Shift end must be after shift start.',
            'Capacity must be a whole number of 1 or more, or empty for no limit.',
            null,
            'Duplicate of row 10 in this file.',
        ], $reasons);

        $this->assertSame(1, Shift::query()->count());
    }

    public function test_a_cancelled_shift_is_reported_rather_than_revived(): void
    {
        $actor = User::factory()->create();
        $service = $this->service();

        $service->import($this->fixture('shifts-import-sample.csv'), $actor);
        $this->shift('Dirt Patrol Day')->forceFill(['cancelled_at' => Carbon::now()])->save();

        $result = $service->import(
            $this->header('capacity')
            ."northwood-collective,emberfall-2026,RANGERS,DIRT,Dirt Patrol Day,2026-08-28 09:00,2026-08-28 17:00,10\n",
            $actor,
        );

        $this->assertSame(0, $result->updated());
        $this->assertSame(1, $result->skipped());
        $this->assertStringContainsString('cancelled', (string) $result->rows[0]->reason);
        $this->assertNotNull($this->shift('Dirt Patrol Day')->cancelled_at);
        $this->assertSame(6, $this->shift('Dirt Patrol Day')->capacity);
    }

    public function test_a_preview_reports_outcomes_without_writing_anything(): void
    {
        $actor = User::factory()->create();

        $result = $this->service()->import(
            $this->fixture('shifts-import-sample.csv'),
            $actor,
            preview: true,
        );

        $this->assertTrue($result->preview);
        $this->assertSame(3, $result->imported());
        $this->assertSame(0, Shift::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'shift.created')->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'shifts.imported')->count());
    }

    /**
     * Imports go through the same domain service the shift screen uses, so an
     * imported shift is audited the same way a hand-created one is.
     */
    public function test_it_records_the_domain_audit_events_and_a_run_summary(): void
    {
        $actor = User::factory()->create();
        $service = $this->service();

        $service->import(
            $this->header()."northwood-collective,emberfall-2026,RANGERS,DIRT,Dirt Patrol Day,2026-08-28 09:00,2026-08-28 17:00\n",
            $actor,
        );
        $service->import(
            $this->header()."northwood-collective,emberfall-2026,RANGERS,DIRT,Dirt Patrol Day,2026-08-28 09:00,2026-08-28 18:00\n",
            $actor,
        );

        $created = AuditEvent::query()->where('action', 'shift.created')->firstOrFail();
        $this->assertSame((string) $actor->id, (string) $created->actor_user_id);
        $this->assertSame(AuditEvent::SOURCE_ORCHID, $created->source_context);
        $this->assertSame((string) $this->rangers->id, (string) $created->department_id);
        $this->assertSame((string) $this->event->id, (string) $created->event_id);

        $this->assertSame(1, AuditEvent::query()->where('action', 'shift.updated')->count());

        $run = AuditEvent::query()->where('action', 'shifts.imported')->firstOrFail();
        $this->assertSame(
            ['imported' => 1, 'updated' => 0, 'skipped' => 0, 'preview' => false],
            $run->after_json,
        );
    }

    public function test_a_file_without_the_required_columns_is_refused_whole(): void
    {
        $actor = User::factory()->create();

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('The CSV file must include a "ends_at" header column.');

        $this->service()->import(
            "organization_slug,event_slug,department_code,team_code,title,starts_at\n"
            ."northwood-collective,emberfall-2026,RANGERS,DIRT,Dirt Patrol Day,2026-08-28 09:00\n",
            $actor,
        );
    }

    private function header(string $optionalColumns = ''): string
    {
        $header = 'organization_slug,event_slug,department_code,team_code,title,starts_at,ends_at';

        return $optionalColumns === ''
            ? $header."\n"
            : $header.','.$optionalColumns."\n";
    }

    private function shift(string $title): Shift
    {
        return Shift::query()->where('title', $title)->firstOrFail();
    }

    private function service(): ShiftImportService
    {
        return app(ShiftImportService::class);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/'.$name));
    }
}
