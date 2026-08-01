<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftTrainingRequirement;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\Training;
use App\Models\User;
use App\Services\Imports\AssignmentImportService;
use App\Services\Imports\ImportException;
use App\Services\Imports\ImportRow;
use App\Services\Imports\ShiftImportService;
use App\Services\Shift\ShiftAssignmentException;
use App\Services\Shift\ShiftAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * God-mode CSV import for shift assignments (technical spec 22.2).
 */
class AssignmentImportTest extends TestCase
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

        $this->operator = $this->importOperator();

        app(ShiftImportService::class)->import(
            (string) file_get_contents(base_path('tests/Fixtures/shifts-import-sample.csv')),
            $this->operator,
        );
    }

    public function test_it_assigns_staff_from_the_sample_fixture(): void
    {
        $vera = $this->eligibleStaff('vera.staff@example.org', $this->rangers, $this->dirt);
        $gwen = $this->eligibleStaff('gwen.greeter@example.org', $this->gate, $this->greeters);

        $result = $this->service()->import($this->fixture('assignments-import-sample.csv'), $this->operator);

        $this->assertSame(3, $result->imported());
        $this->assertSame(0, $result->skipped());

        $assignment = $this->assignment('Dirt Patrol Day', $vera);

        $this->assertSame(ShiftAssignment::STATUS_ASSIGNED, $assignment->assignment_status);
        $this->assertSame((string) $this->operator->id, (string) $assignment->assigned_by_user_id);
        $this->assertNull($assignment->removed_at);

        $this->assertNotNull($this->assignment('Gate Opening', $gwen));
        $this->assertSame(3, ShiftAssignment::query()->count());
    }

    /**
     * Assignment authority for an import is the console permission, not a
     * department lead role — and it is a real gate, not an absent one.
     */
    public function test_a_user_without_the_import_permission_cannot_assign(): void
    {
        $vera = $this->eligibleStaff('vera.staff@example.org', $this->rangers, $this->dirt);
        $shift = $this->shift('Dirt Patrol Day');

        $this->expectException(ShiftAssignmentException::class);
        $this->expectExceptionMessage('You are not authorized to assign staff to this shift.');

        app(ShiftAssignmentService::class)->assignStaffToShiftFromImport($shift, $vera, User::factory()->create());
    }

    /**
     * A schedule usually arrives as a spreadsheet after signup closed, which is
     * the one gate an import is allowed to pass.
     */
    public function test_a_closed_signup_window_does_not_refuse_an_imported_assignment(): void
    {
        $vera = $this->eligibleStaff('vera.staff@example.org', $this->rangers, $this->dirt);

        $this->shift('Dirt Patrol Day')->forceFill([
            'signup_opens_at' => Carbon::parse('2026-07-01 00:00:00'),
            'signup_closes_at' => Carbon::parse('2026-07-15 00:00:00'),
        ])->save();

        $result = $this->service()->import($this->dayRow($vera->email), $this->operator);

        $this->assertSame(1, $result->imported());
        $this->assertNotNull($this->assignment('Dirt Patrol Day', $vera));
    }

    /**
     * Everything that protects the person being assigned still refuses the row.
     */
    public function test_ineligible_staff_are_skipped_with_the_domain_reason(): void
    {
        $stranger = Staff::factory()->create(['email' => 'stranger@example.org']);

        $wrongTeam = $this->eligibleStaff('wrong.team@example.org', $this->rangers, $this->dirt);
        TeamMembership::query()->where('staff_id', $wrongTeam->id)->delete();

        $blocked = $this->eligibleStaff('blocked@example.org', $this->rangers, $this->dirt);
        StaffOrganizationStatus::factory()->create([
            'organization_id' => $this->organization->id,
            'staff_id' => $blocked->id,
            'status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF,
        ]);

        // The training requirement goes on the night shift so it gates only the
        // row that is meant to test it.
        $untrained = $this->eligibleStaff('untrained@example.org', $this->rangers, $this->dirt);
        ShiftTrainingRequirement::query()->create([
            'shift_id' => $this->shift('Dirt Patrol Night')->id,
            'training_id' => Training::factory()->create(['organization_id' => $this->organization->id])->id,
        ]);

        $result = $this->service()->import(
            $this->header()
            .$this->dayRowValues($stranger->email)
            .$this->dayRowValues($wrongTeam->email)
            .$this->dayRowValues($blocked->email)
            ."northwood-collective,emberfall-2026,RANGERS,Dirt Patrol Night,2026-08-28 17:00,{$untrained->email}\n",
            $this->operator,
        );

        $this->assertSame(0, $result->imported());
        $this->assertSame(4, $result->skipped());

        $reasons = array_map(static fn (ImportRow $row): ?string => $row->reason, $result->rows);

        $this->assertSame([
            'Staff must belong to the shift department before assignment.',
            'Staff must belong to the shift eligible team before assignment.',
            'Do Not Staff records cannot be assigned to shifts.',
            'Required training must be complete before shift signup.',
        ], $reasons);

        $this->assertSame(0, ShiftAssignment::query()->count());
    }

    public function test_rerunning_the_same_file_reports_already_assigned_instead_of_duplicating(): void
    {
        $this->eligibleStaff('vera.staff@example.org', $this->rangers, $this->dirt);
        $this->eligibleStaff('gwen.greeter@example.org', $this->gate, $this->greeters);

        $service = $this->service();
        $service->import($this->fixture('assignments-import-sample.csv'), $this->operator);
        $result = $service->import($this->fixture('assignments-import-sample.csv'), $this->operator);

        $this->assertSame(0, $result->imported());
        $this->assertSame(3, $result->skipped());
        $this->assertSame('This staff member is already assigned to the shift.', $result->rows[0]->reason);
        $this->assertSame(3, ShiftAssignment::query()->count());
    }

    /**
     * A double-booking is a decision a lead may make, so the row is written and
     * the overlap is reported rather than swallowed.
     */
    public function test_an_overlapping_assignment_is_imported_with_a_note(): void
    {
        $vera = $this->eligibleStaff('vera.staff@example.org', $this->rangers, $this->dirt);

        app(ShiftImportService::class)->import(
            "organization_slug,event_slug,department_code,team_code,title,starts_at,ends_at\n"
            ."northwood-collective,emberfall-2026,RANGERS,DIRT,Dirt Overlap,2026-08-28 12:00,2026-08-28 20:00\n",
            $this->operator,
        );

        $service = $this->service();
        $service->import($this->dayRow($vera->email), $this->operator);

        $result = $service->import(
            $this->header()
            ."northwood-collective,emberfall-2026,RANGERS,Dirt Overlap,2026-08-28 12:00,{$vera->email}\n",
            $this->operator,
        );

        $this->assertSame(1, $result->imported());
        $this->assertStringContainsString('overlaps with Dirt Patrol Day', (string) $result->rows[0]->reason);
    }

    /**
     * Two shifts with the same title and start in one department are only
     * distinguishable by team, so the row says which one or is skipped.
     */
    public function test_an_ambiguous_shift_is_skipped_until_a_team_code_names_one(): void
    {
        $second = Team::factory()->for($this->rangers)->create(['name' => 'Command', 'code' => 'COMMAND']);
        $vera = $this->eligibleStaff('vera.staff@example.org', $this->rangers, $second);

        app(ShiftImportService::class)->import(
            "organization_slug,event_slug,department_code,team_code,title,starts_at,ends_at\n"
            ."northwood-collective,emberfall-2026,RANGERS,COMMAND,Dirt Patrol Day,2026-08-28 09:00,2026-08-28 17:00\n",
            $this->operator,
        );

        $service = $this->service();
        $ambiguous = $service->import($this->dayRow($vera->email), $this->operator);

        $this->assertSame(0, $ambiguous->imported());
        $this->assertSame(
            'That title and start matches 2 shifts in this department. Add a team_code column to name one.',
            $ambiguous->rows[0]->reason,
        );

        $named = $service->import(
            "organization_slug,event_slug,department_code,team_code,shift_title,shift_starts_at,staff_email\n"
            ."northwood-collective,emberfall-2026,RANGERS,COMMAND,Dirt Patrol Day,2026-08-28 09:00,{$vera->email}\n",
            $this->operator,
        );

        $this->assertSame(1, $named->imported());

        $assignment = ShiftAssignment::query()->where('staff_id', $vera->id)->firstOrFail();
        $this->assertSame((string) $second->id, (string) $assignment->shift->eligible_team_id);
    }

    public function test_unresolvable_rows_are_skipped_with_a_reason_and_good_rows_still_import(): void
    {
        $vera = $this->eligibleStaff('vera.staff@example.org', $this->rangers, $this->dirt);

        $csv = $this->header()
            ."northwood-collective,no-such-event,RANGERS,Dirt Patrol Day,2026-08-28 09:00,{$vera->email}\n"
            ."northwood-collective,emberfall-2026,NOPE,Dirt Patrol Day,2026-08-28 09:00,{$vera->email}\n"
            ."northwood-collective,emberfall-2026,RANGERS,No Such Shift,2026-08-28 09:00,{$vera->email}\n"
            ."northwood-collective,emberfall-2026,RANGERS,Dirt Patrol Day,not a date,{$vera->email}\n"
            ."northwood-collective,emberfall-2026,RANGERS,Dirt Patrol Day,2026-08-28 09:00,nobody@example.org\n"
            ."northwood-collective,emberfall-2026,RANGERS,Dirt Patrol Day,2026-08-28 09:00,{$vera->email}\n"
            ."northwood-collective,emberfall-2026,RANGERS,Dirt Patrol Day,2026-08-28 09:00,VERA.STAFF@example.org\n";

        $result = $this->service()->import($csv, $this->operator);

        $this->assertSame(1, $result->imported());
        $this->assertSame(6, $result->skipped());

        $reasons = array_map(static fn (ImportRow $row): ?string => $row->reason, $result->rows);

        $this->assertSame([
            'No event "no-such-event" in organization "northwood-collective".',
            'No department "NOPE" in organization "northwood-collective".',
            'No shift "No Such Shift" starting 2026-08-28 09:00 in department "RANGERS".',
            'Shift start must be a readable date and time.',
            'No staff member with email "nobody@example.org".',
            null,
            'Duplicate of row 7 in this file.',
        ], $reasons);

        $this->assertSame(1, ShiftAssignment::query()->count());
    }

    public function test_a_cancelled_shift_does_not_accept_an_imported_assignment(): void
    {
        $vera = $this->eligibleStaff('vera.staff@example.org', $this->rangers, $this->dirt);
        $this->shift('Dirt Patrol Day')->forceFill(['cancelled_at' => Carbon::now()])->save();

        $result = $this->service()->import($this->dayRow($vera->email), $this->operator);

        $this->assertSame(0, $result->imported());
        $this->assertSame('Cancelled shifts do not accept assignment.', $result->rows[0]->reason);
    }

    public function test_a_preview_reports_outcomes_without_writing_anything(): void
    {
        $vera = $this->eligibleStaff('vera.staff@example.org', $this->rangers, $this->dirt);

        $result = $this->service()->import($this->dayRow($vera->email), $this->operator, preview: true);

        $this->assertTrue($result->preview);
        $this->assertSame(1, $result->imported());
        $this->assertSame(0, ShiftAssignment::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'shift_assignment.assigned')->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'shift_assignments.imported')->count());
    }

    /**
     * Imported assignments go through the same domain path a lead assignment
     * does, so they are audited the same way.
     */
    public function test_it_records_the_domain_audit_events_and_a_run_summary(): void
    {
        $vera = $this->eligibleStaff('vera.staff@example.org', $this->rangers, $this->dirt);

        $this->service()->import($this->dayRow($vera->email), $this->operator);

        $assigned = AuditEvent::query()->where('action', 'shift_assignment.assigned')->firstOrFail();
        $this->assertSame((string) $this->operator->id, (string) $assigned->actor_user_id);
        $this->assertSame(AuditEvent::SOURCE_ORCHID, $assigned->source_context);
        $this->assertSame((string) $this->event->id, (string) $assigned->event_id);
        $this->assertSame((string) $this->rangers->id, (string) $assigned->department_id);

        $run = AuditEvent::query()->where('action', 'shift_assignments.imported')->firstOrFail();
        $this->assertSame(
            ['imported' => 1, 'updated' => 0, 'skipped' => 0, 'preview' => false],
            $run->after_json,
        );
    }

    public function test_a_file_without_the_required_columns_is_refused_whole(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('The CSV file must include a "staff_email" header column.');

        $this->service()->import(
            "organization_slug,event_slug,department_code,shift_title,shift_starts_at\n"
            ."northwood-collective,emberfall-2026,RANGERS,Dirt Patrol Day,2026-08-28 09:00\n",
            $this->operator,
        );
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

    private function importOperator(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.imports' => true,
            ],
        ]);
    }

    private function header(): string
    {
        return "organization_slug,event_slug,department_code,shift_title,shift_starts_at,staff_email\n";
    }

    private function dayRow(string $email): string
    {
        return $this->header().$this->dayRowValues($email);
    }

    private function dayRowValues(string $email): string
    {
        return "northwood-collective,emberfall-2026,RANGERS,Dirt Patrol Day,2026-08-28 09:00,{$email}\n";
    }

    private function shift(string $title): Shift
    {
        return Shift::query()->where('title', $title)->firstOrFail();
    }

    private function assignment(string $shiftTitle, Staff $staff): ShiftAssignment
    {
        return ShiftAssignment::query()
            ->where('shift_id', $this->shift($shiftTitle)->id)
            ->where('staff_id', $staff->id)
            ->firstOrFail();
    }

    private function service(): AssignmentImportService
    {
        return app(AssignmentImportService::class);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/'.$name));
    }
}
