<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\HoursWorked;
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
use App\Services\Reporting\HoursWorkedExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Actual hours worked export (M13.4; REPORT-004, REPORT-006, REPORT-007,
 * REPORT-010; HOURS-001 through HOURS-008).
 */
class HoursWorkedExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Hours are time-anchored data: the file reports scheduled windows, actual
     * windows, correction moments, and freeze moments side by side. The whole
     * scenario is therefore built on one fixed clock rather than on offsets
     * from a moving now, so every timestamp in the committed sample is the
     * timestamp the export is supposed to produce.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-21 19:20:30 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_organizer_exports_the_whole_event_and_the_file_matches_the_sample(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/hours-worked");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader(
            'Content-Disposition',
            'attachment; filename="hours-worked-idaho-decompression-2026-20260621-192030.csv"',
        );

        $expected = (string) file_get_contents(base_path('tests/Fixtures/hours-worked-export-sample.csv'));

        $this->assertSame($this->normalize($expected), $this->normalize((string) $response->getContent()));
    }

    public function test_the_export_reports_actual_hours_beside_the_scheduled_window(): void
    {
        $scenario = $this->scenario();

        $rows = $this->rows((string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/hours-worked")
            ->assertOk()
            ->getContent());

        // HOURS-001: scheduled and actual are different numbers, and the file
        // shows both so a short or long shift is visible without opening the
        // schedule. Vera left half an hour early.
        $vera = $this->rowFor($rows, 'Vera Staff');
        $this->assertSame('480', $vera['scheduled_minutes']);
        $this->assertSame('450', $vera['minutes_worked']);
        $this->assertSame('7.50', $vera['hours_worked']);

        // HOURS-002: the actual start and end are the record, not the schedule.
        $this->assertSame('2026-06-19T19:10:00+00:00', $vera['actual_started_at']);
        $this->assertSame('2026-06-20T02:40:00+00:00', $vera['actual_ended_at']);
        $this->assertSame('2026-06-19T19:00:00+00:00', $vera['shift_starts_at']);
        $this->assertSame('2026-06-20T03:00:00+00:00', $vera['shift_ends_at']);

        // HOURS-003, HOURS-004: every row names its shift and its department.
        $this->assertSame('Rangers Dirt Day', $vera['shift_title']);
        $this->assertSame('Rangers', $vera['department']);
        $this->assertSame('Dirt', $vera['team']);
        $this->assertSame('vera@idaho-burners.test', $vera['staff_email']);
        $this->assertSame(HoursWorked::STATUS_RECORDED, $vera['hours_status']);

        // Alma stayed two hours past the scheduled end; the overage is reported
        // rather than clipped to the shift.
        $alma = $this->rowFor($rows, 'Alma Assigned');
        $this->assertSame('600', $alma['minutes_worked']);
        $this->assertSame('10.00', $alma['hours_worked']);
    }

    public function test_only_recorded_hours_produce_rows(): void
    {
        $scenario = $this->scenario();

        $contents = (string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/hours-worked")
            ->assertOk()
            ->getContent();

        // HOURS-005, HOURS-006: hours cannot exist without a worked shift, so a
        // no-show and a staff member still checked in have nothing to report
        // here. Who was expected on a shift is the shift roster's answer.
        $this->assertStringNotContainsString('Nora Noshow', $contents);
        $this->assertStringNotContainsString('Ollie Onshift', $contents);

        // A shift nobody worked is absent for the same reason.
        $this->assertStringNotContainsString('Rangers Dirt Night', $contents);

        $this->assertCount(3, $this->rows($contents));
    }

    public function test_correction_and_freeze_state_are_reported_on_every_row(): void
    {
        $scenario = $this->scenario();

        $rows = $this->rows((string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/hours-worked")
            ->assertOk()
            ->getContent());

        // HOURS-007: a corrected total says when it was corrected, so a reader
        // can tell an edited number from the one the clock produced.
        $alma = $this->rowFor($rows, 'Alma Assigned');
        $this->assertSame('2026-06-20T18:00:00+00:00', $alma['corrected_at']);

        // HOURS-008: the grace period has closed on Alma's hours, so the total
        // is final and can be a credit basis (CREDIT-001).
        $this->assertSame(HoursWorkedExportService::CORRECTION_STATE_FROZEN, $alma['correction_state']);
        $this->assertSame('2026-06-28T00:00:00+00:00', $alma['frozen_at']);

        // Vera's hours were never corrected and are still correctable, which is
        // exactly what a reader needs to know before treating them as final.
        $vera = $this->rowFor($rows, 'Vera Staff');
        $this->assertSame('', $vera['corrected_at']);
        $this->assertSame(HoursWorkedExportService::CORRECTION_STATE_OPEN, $vera['correction_state']);
        $this->assertSame('', $vera['frozen_at']);

        // Frozen without ever being corrected is its own case: the grace period
        // simply ran out on an uncontested record.
        $rita = $this->rowFor($rows, 'Rita Roster');
        $this->assertSame('', $rita['corrected_at']);
        $this->assertSame(HoursWorkedExportService::CORRECTION_STATE_FROZEN, $rita['correction_state']);
    }

    public function test_the_export_excludes_phone_numbers_emergency_contacts_and_dates_of_birth(): void
    {
        $scenario = $this->scenario();

        $contents = (string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/hours-worked")
            ->assertOk()
            ->getContent();

        // REPORT-010: an organizer export carries no emergency contacts. A
        // timesheet needs none of these fields to be a timesheet.
        foreach ($scenario['staff'] as $staff) {
            $this->assertStringNotContainsString((string) $staff->phone, $contents);
            $this->assertStringNotContainsString((string) $staff->emergency_contact_name, $contents);
            $this->assertStringNotContainsString((string) $staff->emergency_contact_phone, $contents);
            $this->assertStringNotContainsString('1990-01-15', $contents);
        }
    }

    public function test_department_lead_export_is_limited_to_their_own_department(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$scenario['event']->id}/exports/hours-worked");

        $response->assertOk();
        $response->assertHeader(
            'Content-Disposition',
            'attachment; filename="hours-worked-idaho-decompression-2026-rangers-20260621-192030.csv"',
        );

        // REPORT-007: Gate's hours belong to Gate's lead.
        $rows = $this->rows((string) $response->getContent());
        $this->assertSame(['Rangers'], array_values(array_unique(array_column($rows, 'department'))));
        $this->assertStringNotContainsString('Rita Roster', (string) $response->getContent());
    }

    public function test_organizer_may_narrow_the_export_to_one_department(): void
    {
        $scenario = $this->scenario();

        $rows = $this->rows((string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/hours-worked?department_id={$scenario['gate']->id}")
            ->assertOk()
            ->getContent());

        $this->assertSame(['Rita Roster'], array_column($rows, 'staff_legal_name'));
    }

    public function test_hours_of_another_event_are_not_exported(): void
    {
        $scenario = $this->scenario();

        $contents = (string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/hours-worked")
            ->assertOk()
            ->getContent();

        // The same department works more than one event; a timesheet for this
        // event must not carry last year's hours.
        $this->assertStringNotContainsString('Perry Prior', $contents);
    }

    public function test_export_authority_is_refused_outside_the_callers_scope(): void
    {
        $scenario = $this->scenario();
        $eventId = $scenario['event']->id;

        // A department lead may not reach another department's hours.
        $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$eventId}/exports/hours-worked?department_id={$scenario['gate']->id}")
            ->assertForbidden();

        // Plain staff hold no export capability, even over the hours they
        // themselves worked.
        $this->actingAsClient($scenario['plainStaffUser'])
            ->get("/api/events/{$eventId}/exports/hours-worked")
            ->assertForbidden();

        // An organizer of another organization has no authority over this event.
        $foreignOrganization = Organization::factory()->create();
        $foreignDepartment = Department::factory()->for($foreignOrganization)->create();
        $foreignOrganizer = $this->userWithRole($foreignDepartment, PermissionRole::query()
            ->where('code', 'organizer')
            ->firstOrFail());

        $this->actingAsClient($foreignOrganizer)
            ->get("/api/events/{$eventId}/exports/hours-worked")
            ->assertForbidden();

        // A department outside this event's organization is not addressable.
        $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$eventId}/exports/hours-worked?department_id={$foreignDepartment->id}")
            ->assertNotFound();

        $this->assertDatabaseMissing('audit_events', [
            'action' => 'event_hours_worked.exported',
        ]);
    }

    public function test_a_successful_export_is_audited_as_a_sensitive_read(): void
    {
        $scenario = $this->scenario();
        $eventId = $scenario['event']->id;

        $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$eventId}/exports/hours-worked")
            ->assertOk();

        $departmentExport = AuditEvent::query()
            ->where('action', 'event_hours_worked.exported')
            ->sole();

        $this->assertSame((string) $scenario['rangersLead']->id, (string) $departmentExport->actor_user_id);
        $this->assertSame((string) $eventId, (string) $departmentExport->event_id);
        $this->assertSame((string) $scenario['rangers']->id, (string) $departmentExport->department_id);
        $this->assertSame(AuditEvent::SOURCE_API, $departmentExport->source_context);
        $this->assertSame('department', $departmentExport->after_json['scope']);
        $this->assertSame('csv', $departmentExport->after_json['format']);
        $this->assertSame(2, $departmentExport->after_json['row_count']);
        $this->assertSame([(string) $scenario['rangers']->id], $departmentExport->after_json['department_ids']);

        // The audit records the total the file handed over, which is the number
        // a later dispute about hours or credits is actually about.
        $this->assertSame(1050, $departmentExport->after_json['total_minutes_worked']);

        $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$eventId}/exports/hours-worked")
            ->assertOk();

        $organizerExport = AuditEvent::query()
            ->where('action', 'event_hours_worked.exported')
            ->where('actor_user_id', $scenario['organizer']->id)
            ->sole();

        $this->assertNull($organizerExport->department_id);
        $this->assertSame('event', $organizerExport->after_json['scope']);
        $this->assertSame([], $organizerExport->after_json['department_ids']);
        $this->assertSame(1230, $organizerExport->after_json['total_minutes_worked']);
    }

    /**
     * A two-department event carrying every hours shape the export can report:
     * a straightforward record, one that ran past its scheduled end and was
     * later corrected and frozen, one frozen without ever being corrected, a
     * no-show, an open check-in, an unworked shift, and hours the same
     * department recorded for a different event.
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
        $alma = $this->staff($organization, 'Alma Assigned', 'Alma', 'alma', [$rangers]);
        $nora = $this->staff($organization, 'Nora Noshow', 'Nora', 'nora', [$rangers]);
        $ollie = $this->staff($organization, 'Ollie Onshift', 'Ollie', 'ollie', [$rangers]);
        $rita = $this->staff($organization, 'Rita Roster', 'Rita', 'rita', [$gate]);

        $plainStaff = $this->staff($organization, 'Perry Plain', 'Perry', 'perry', [$rangers]);
        $plainStaffUser = User::factory()->create();
        $plainStaffUser->staffProfiles()->attach($plainStaff->id);

        $openingShift = $this->shift($event, $gate, $gateTeam, 'Gate Opening', '2026-06-18 19:00:00');
        $dayShift = $this->shift($event, $rangers, $rangersTeam, 'Rangers Dirt Day', '2026-06-19 19:00:00');
        $nightShift = $this->shift($event, $rangers, $rangersTeam, 'Rangers Dirt Night', '2026-06-20 19:00:00');

        // Vera worked most of her shift and nobody has touched the record.
        $this->hours($dayShift, $vera, '2026-06-19 19:10:00', '2026-06-20 02:40:00');

        // Alma stayed two hours past the scheduled end; a lead corrected the
        // record afterwards (HOURS-007) and the grace period has since closed
        // on it (HOURS-008).
        $this->hours(
            $dayShift,
            $alma,
            '2026-06-19 18:55:00',
            '2026-06-20 04:55:00',
            correctedAt: '2026-06-20 18:00:00',
            frozenAt: '2026-06-28 00:00:00',
        );

        // Rita's hours froze without ever being corrected.
        $this->hours($openingShift, $rita, '2026-06-18 19:05:00', '2026-06-18 22:05:00', frozenAt: '2026-06-28 00:00:00');

        // Nora never arrived and Ollie is still checked in, so neither has
        // hours to report; nobody worked the night shift at all.
        $this->attendanceOnly($dayShift, $nora, AttendanceRecord::STATE_NO_SHOW);
        $this->attendanceOnly($nightShift, $ollie, AttendanceRecord::STATE_CHECKED_IN);

        // The same department worked a different event last year.
        $priorEvent = Event::factory()->for($organization)->create([
            'name' => 'Idaho Decompression 2025',
            'slug' => 'idaho-decompression-2025',
        ]);
        $priorStaff = $this->staff($organization, 'Perry Prior', 'Perry', 'pprior', [$rangers]);
        $priorShift = $this->shift($priorEvent, $rangers, $rangersTeam, 'Rangers Dirt Day', '2025-06-19 19:00:00');
        $this->hours($priorShift, $priorStaff, '2025-06-19 19:00:00', '2025-06-20 03:00:00');

        return [
            'organization' => $organization,
            'event' => $event,
            'rangers' => $rangers,
            'gate' => $gate,
            'organizer' => $organizer,
            'rangersLead' => $rangersLead,
            'plainStaffUser' => $plainStaffUser,
            'staff' => [$vera, $alma, $nora, $ollie, $rita, $priorStaff],
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

    private function shift(Event $event, Department $department, Team $team, string $title, string $startsAt): Shift
    {
        $start = Carbon::parse($startsAt, 'UTC');

        return Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => $title,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(8),
            'capacity' => null,
        ]);
    }

    /**
     * A worked shift: the assignment, the checked-out attendance record, and the
     * canonical hours the checkout path creates from them.
     */
    private function hours(
        Shift $shift,
        Staff $staff,
        string $actualStartedAt,
        string $actualEndedAt,
        ?string $correctedAt = null,
        ?string $frozenAt = null,
    ): HoursWorked {
        $startedAt = Carbon::parse($actualStartedAt, 'UTC');
        $endedAt = Carbon::parse($actualEndedAt, 'UTC');

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
        ]);

        $record = AttendanceRecord::factory()->create([
            'shift_id' => $shift->id,
            'shift_assignment_id' => $assignment->id,
            'staff_id' => $staff->id,
            'current_state' => $correctedAt === null
                ? AttendanceRecord::STATE_CHECKED_OUT
                : AttendanceRecord::STATE_CORRECTED,
            'checked_in_at' => $startedAt,
            'checked_out_at' => $endedAt,
            'corrected_at' => $correctedAt === null ? null : Carbon::parse($correctedAt, 'UTC'),
        ]);

        return HoursWorked::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'attendance_record_id' => $record->id,
            'actual_started_at' => $startedAt,
            'actual_ended_at' => $endedAt,
            'minutes_worked' => (int) $startedAt->diffInMinutes($endedAt),
            'server_corrected_at' => $correctedAt === null ? null : Carbon::parse($correctedAt, 'UTC'),
            'frozen_at' => $frozenAt === null ? null : Carbon::parse($frozenAt, 'UTC'),
        ]);
    }

    /**
     * Attendance without hours: the shape a no-show or an open check-in leaves
     * behind, and the shape the export must not turn into a row.
     */
    private function attendanceOnly(Shift $shift, Staff $staff, string $state): AttendanceRecord
    {
        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
        ]);

        return AttendanceRecord::factory()->create([
            'shift_id' => $shift->id,
            'shift_assignment_id' => $assignment->id,
            'staff_id' => $staff->id,
            'current_state' => $state,
            'checked_in_at' => $state === AttendanceRecord::STATE_NO_SHOW ? null : $shift->starts_at,
            'checked_out_at' => null,
            'no_show_at' => $state === AttendanceRecord::STATE_NO_SHOW ? $shift->starts_at : null,
        ]);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array<string, string>
     */
    private function rowFor(array $rows, string $legalName): array
    {
        foreach ($rows as $row) {
            if ($row['staff_legal_name'] === $legalName) {
                return $row;
            }
        }

        $this->fail("No exported row for {$legalName}.");
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
