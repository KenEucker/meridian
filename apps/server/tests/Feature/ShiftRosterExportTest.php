<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Reporting\ShiftRosterExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Event shift roster export (M13.2; REPORT-002, REPORT-006 through REPORT-008,
 * REPORT-010; SHIFT-011, SHIFT-013, SHIFT-015).
 */
class ShiftRosterExportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_organizer_exports_the_whole_event_and_the_file_matches_the_sample(): void
    {
        Carbon::setTestNow('2026-06-21 19:20:30 UTC');

        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/shift-roster");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader(
            'Content-Disposition',
            'attachment; filename="shift-roster-idaho-decompression-2026-20260621-192030.csv"',
        );

        $expected = (string) file_get_contents(base_path('tests/Fixtures/shift-roster-export-sample.csv'));

        $this->assertSame($this->normalize($expected), $this->normalize((string) $response->getContent()));
    }

    public function test_the_export_excludes_phone_numbers_and_emergency_contacts(): void
    {
        $scenario = $this->scenario();

        $contents = $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/shift-roster")
            ->assertOk()
            ->getContent();

        // REPORT-008: a shift roster carries no phone number and no emergency
        // contact. REPORT-010 says the same of any organizer export.
        foreach ($scenario['staff'] as $staff) {
            $this->assertStringNotContainsString((string) $staff->phone, $contents);
            $this->assertStringNotContainsString((string) $staff->emergency_contact_name, $contents);
            $this->assertStringNotContainsString((string) $staff->emergency_contact_phone, $contents);
        }
    }

    public function test_the_roster_reports_who_is_on_each_shift_and_how_they_got_there(): void
    {
        $scenario = $this->scenario();

        $rows = $this->rows($this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/shift-roster")
            ->assertOk()
            ->getContent());

        // Shifts read in schedule order, and every staff member on a shift is
        // one row of that shift.
        $this->assertSame([
            ['Gate Opening', 'Rita Roster'],
            ['Rangers Dirt Day', 'Alma Assigned'],
            ['Rangers Dirt Day', 'Vera Staff'],
            ['Rangers Dirt Night', ''],
            ['Gate Greeting Day', 'Alma Assigned'],
        ], array_map(
            static fn (array $row): array => [$row['shift_title'], $row['staff_legal_name']],
            $rows,
        ));

        // SHIFT-011 vs SHIFT-015: a self-signup and a lead assignment are told
        // apart in the file.
        $vera = $rows[2];
        $this->assertSame('Rangers', $vera['department']);
        $this->assertSame('Dirt', $vera['team']);
        $this->assertSame(ShiftAssignment::STATUS_SIGNED_UP, $vera['assignment_status']);
        $this->assertSame(ShiftRosterExportService::SOURCE_SELF_SIGNUP, $vera['assignment_source']);
        $this->assertSame('vera@idaho-burners.test', $vera['staff_email']);

        $alma = $rows[1];
        $this->assertSame(ShiftAssignment::STATUS_ASSIGNED, $alma['assignment_status']);
        $this->assertSame(ShiftRosterExportService::SOURCE_LEAD_ASSIGNED, $alma['assignment_source']);

        // Every row of a shift carries that shift's roster size and capacity, so
        // an under-filled shift is visible without counting rows by hand.
        $this->assertSame('2', $vera['assigned_staff_count']);
        $this->assertSame('4', $vera['shift_capacity']);
        $this->assertSame(ShiftRosterExportService::STATUS_SCHEDULED, $vera['shift_status']);
    }

    public function test_an_unstaffed_shift_still_appears_and_a_cancelled_shift_says_so(): void
    {
        $scenario = $this->scenario();

        $rows = $this->rows($this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/shift-roster")
            ->assertOk()
            ->getContent());

        // A shift nobody signed up for is the row a scheduler most needs.
        $night = $rows[3];
        $this->assertSame('Rangers Dirt Night', $night['shift_title']);
        $this->assertSame('0', $night['assigned_staff_count']);
        $this->assertSame('', $night['staff_legal_name']);
        $this->assertSame('', $night['assignment_status']);
        $this->assertSame('', $night['assignment_source']);
        $this->assertSame('', $night['shift_capacity']);

        // A cancelled shift is reported as cancelled rather than dropped.
        $cancelled = $rows[4];
        $this->assertSame('Gate Greeting Day', $cancelled['shift_title']);
        $this->assertSame(ShiftRosterExportService::STATUS_CANCELLED, $cancelled['shift_status']);
    }

    public function test_removed_staff_are_off_the_roster(): void
    {
        $scenario = $this->scenario();

        // SHIFT-013: a department lead removed Bruno from the day shift, so the
        // roster no longer expects him.
        $contents = (string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/shift-roster")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Bruno Removed', $contents);
        $this->assertSame('2', $this->rows($contents)[2]['assigned_staff_count']);
    }

    public function test_department_lead_export_is_limited_to_their_own_department(): void
    {
        Carbon::setTestNow('2026-06-21 19:20:30 UTC');

        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$scenario['event']->id}/exports/shift-roster");

        $response->assertOk();
        $response->assertHeader(
            'Content-Disposition',
            'attachment; filename="shift-roster-idaho-decompression-2026-rangers-20260621-192030.csv"',
        );

        // REPORT-007: only Rangers shifts, including the unstaffed one. Gate
        // shifts are another department's roster.
        $this->assertSame(
            ['Rangers Dirt Day', 'Rangers Dirt Day', 'Rangers Dirt Night'],
            array_column($this->rows($response->getContent()), 'shift_title'),
        );
        $this->assertStringNotContainsString('Gate', (string) $response->getContent());
    }

    public function test_organizer_may_narrow_the_export_to_one_department(): void
    {
        $scenario = $this->scenario();

        $rows = $this->rows($this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/shift-roster?department_id={$scenario['gate']->id}")
            ->assertOk()
            ->getContent());

        $this->assertSame(
            ['Gate Opening', 'Gate Greeting Day'],
            array_column($rows, 'shift_title'),
        );
    }

    public function test_export_authority_is_refused_outside_the_callers_scope(): void
    {
        $scenario = $this->scenario();
        $eventId = $scenario['event']->id;

        // A department lead may not reach another department's roster.
        $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$eventId}/exports/shift-roster?department_id={$scenario['gate']->id}")
            ->assertForbidden();

        // Plain staff hold no export capability at all, even for a shift they
        // are themselves on.
        $this->actingAsClient($scenario['plainStaffUser'])
            ->get("/api/events/{$eventId}/exports/shift-roster")
            ->assertForbidden();

        // An organizer of another organization has no authority over this event.
        $foreignOrganization = Organization::factory()->create();
        $foreignDepartment = Department::factory()->for($foreignOrganization)->create();
        $foreignOrganizer = $this->userWithRole($foreignDepartment, PermissionRole::query()
            ->where('code', 'organizer')
            ->firstOrFail());

        $this->actingAsClient($foreignOrganizer)
            ->get("/api/events/{$eventId}/exports/shift-roster")
            ->assertForbidden();

        // A department outside this event's organization is not addressable.
        $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$eventId}/exports/shift-roster?department_id={$foreignDepartment->id}")
            ->assertNotFound();

        $this->assertDatabaseMissing('audit_events', [
            'action' => 'event_shift_roster.exported',
        ]);
    }

    public function test_a_successful_export_is_audited_as_a_sensitive_read(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$scenario['event']->id}/exports/shift-roster")
            ->assertOk();

        $audit = AuditEvent::query()
            ->where('action', 'event_shift_roster.exported')
            ->sole();

        $this->assertSame((string) $scenario['rangersLead']->id, (string) $audit->actor_user_id);
        $this->assertSame((string) $scenario['event']->id, (string) $audit->event_id);
        $this->assertSame((string) $scenario['rangers']->id, (string) $audit->department_id);
        $this->assertSame(AuditEvent::SOURCE_API, $audit->source_context);
        $this->assertSame('department', $audit->after_json['scope']);
        $this->assertSame('csv', $audit->after_json['format']);
        $this->assertSame(3, $audit->after_json['row_count']);
        $this->assertSame([(string) $scenario['rangers']->id], $audit->after_json['department_ids']);
    }

    /**
     * A two-department event that exercises every roster shape the export can
     * report: a self-signup, a lead assignment, a removed assignment, a shift
     * nobody is on, a cancelled shift, and a shift that already happened.
     *
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        $organization = Organization::factory()->create(['name' => 'Idaho Burners', 'slug' => 'idaho-burners']);
        $event = Event::factory()->for($organization)->create([
            'name' => 'Idaho Decompression 2026',
            'slug' => 'idaho-decompression-2026',
        ]);

        [$rangers, $rangersTeam] = $this->department($organization, 'Rangers', 'RANGERS', 'Dirt');
        [$gate, $gateTeam] = $this->department($organization, 'Gate', 'GATE', 'Greeters');

        [$organizerDepartment] = $this->department($organization, 'Organizers', 'ORG', 'Leads');
        $organizer = $this->userWithRole($organizerDepartment, PermissionRole::query()
            ->where('code', 'organizer')
            ->firstOrFail());
        $rangersLead = $this->userWithRole($rangers, PermissionRole::query()
            ->where('code', 'department_lead')
            ->firstOrFail());

        $vera = $this->staff($organization, 'Vera Staff', 'Vera', 'vera', [$rangers]);
        $alma = $this->staff($organization, 'Alma Assigned', 'Alma', 'alma', [$rangers, $gate]);
        $bruno = $this->staff($organization, 'Bruno Removed', 'Bruno', 'bruno', [$rangers]);
        $rita = $this->staff($organization, 'Rita Roster', 'Rita', 'rita', [$gate]);

        $plainStaff = $this->staff($organization, 'Perry Plain', 'Perry', 'perry', [$rangers]);
        $plainStaffUser = User::factory()->create();
        $plainStaffUser->staffProfiles()->attach($plainStaff->id);

        $dayShift = $this->shift($event, $rangers, $rangersTeam, 'Rangers Dirt Day', now()->addDays(10), 4);
        $nightShift = $this->shift($event, $rangers, $rangersTeam, 'Rangers Dirt Night', now()->addDays(11));
        $gateShift = $this->shift($event, $gate, $gateTeam, 'Gate Greeting Day', now()->addDays(12));
        $openingShift = $this->shift($event, $gate, $gateTeam, 'Gate Opening', now()->subDays(3));

        // Vera signed herself up; a lead assigned Alma (SHIFT-011, SHIFT-015).
        $this->signUp($dayShift, $vera);
        $this->assign($dayShift, $alma, $rangersLead);

        // Bruno was removed from the day shift (SHIFT-013).
        $this->signUp($dayShift, $bruno)->forceFill(['removed_at' => now()])->save();

        // Nobody signed up for the night shift.

        $this->assign($gateShift, $alma, $organizer);
        $gateShift->forceFill(['cancelled_at' => now()])->save();

        $this->signUp($openingShift, $rita);

        return [
            'organization' => $organization,
            'event' => $event,
            'rangers' => $rangers,
            'gate' => $gate,
            'organizer' => $organizer,
            'rangersLead' => $rangersLead,
            'plainStaffUser' => $plainStaffUser,
            'staff' => [$vera, $alma, $bruno, $rita],
        ];
    }

    /**
     * @return array{Department, Team}
     */
    private function department(Organization $organization, string $name, string $code, string $teamName): array
    {
        $department = Department::factory()->for($organization)->create(['name' => $name, 'code' => $code]);
        $team = Team::factory()->for($department)->create(['name' => $teamName, 'is_default' => true]);

        return [$department, $team];
    }

    /**
     * @param  list<Department>  $departments
     */
    private function staff(
        Organization $organization,
        string $legalName,
        string $preferredName,
        string $handle,
        array $departments,
    ): Staff {
        $staff = Staff::factory()->create([
            'legal_name' => $legalName,
            'preferred_name' => $preferredName,
            'handle' => $handle,
            'email' => $handle.'@idaho-burners.test',
            'phone' => '+1-208-555-0100',
            'emergency_contact_name' => $legalName.' Contact',
            'emergency_contact_phone' => '+1-208-555-0199',
            'date_of_birth' => '1990-01-15',
        ]);

        StaffOrganizationStatus::query()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
            'status_reason' => 'Test setup.',
            'status_changed_at' => now(),
        ]);

        foreach ($departments as $department) {
            $membership = DepartmentMembership::factory()->for($department)->for($staff)->create();

            TeamMembership::factory()->create([
                'team_id' => $this->defaultTeam($department)->id,
                'staff_id' => $staff->id,
                'department_membership_id' => $membership->id,
            ]);
        }

        return $staff;
    }

    /**
     * A user who carries a role through a team grant.
     *
     * The grant gets its own team: team grants apply to everyone on the granted
     * team, so putting the role holder on the department's default team would
     * quietly hand the same authority to every ordinary member.
     */
    private function userWithRole(Department $department, PermissionRole $role): User
    {
        $team = Team::factory()->for($department)->create([
            'name' => $department->name.' Leads',
            'is_default' => false,
        ]);
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()->for($department)->for($staff)->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => null,
            'permission_role_id' => $role->id,
        ]);

        return $user;
    }

    private function defaultTeam(Department $department): Team
    {
        return Team::query()
            ->where('department_id', $department->id)
            ->where('is_default', true)
            ->firstOrFail();
    }

    private function shift(
        Event $event,
        Department $department,
        Team $team,
        string $title,
        Carbon $startsAt,
        ?int $capacity = null,
    ): Shift {
        return Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(8),
            'capacity' => $capacity,
        ]);
    }

    private function signUp(Shift $shift, Staff $staff): ShiftAssignment
    {
        return ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
        ]);
    }

    private function assign(Shift $shift, Staff $staff, User $assigner): ShiftAssignment
    {
        return ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            'assigned_by_user_id' => $assigner->id,
        ]);
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(string $csv): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', trim($csv)) ?: []));
        $header = str_getcsv(array_shift($lines) ?? '', escape: '');

        return array_map(
            static fn (string $line): array => array_combine($header, str_getcsv($line, escape: '')),
            $lines,
        );
    }

    private function normalize(string $csv): string
    {
        return implode("\n", preg_split('/\r\n|\r|\n/', trim($csv)) ?: []);
    }
}
