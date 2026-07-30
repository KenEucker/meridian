<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPolicy;
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
use App\Services\Credits\CreditCalculationService;
use App\Services\Credits\CreditPolicyResolution;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Credits earned export (M13.6; REPORT-005, REPORT-006, REPORT-007, REPORT-010;
 * CREDIT-001 through CREDIT-005).
 */
class CreditsEarnedExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A credit entry is a claim about a moment: these hours, that rate, frozen
     * then. The scenario runs on one fixed clock so the calculation moment in
     * every exported row is the moment the domain is supposed to have written,
     * rather than whatever the wall clock said when the suite ran.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-15 17:00:00 UTC');
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
            ->get("/api/events/{$scenario['event']->id}/exports/credits-earned");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader(
            'Content-Disposition',
            'attachment; filename="credits-earned-idaho-decompression-2026-20260715-170000.csv"',
        );

        $expected = (string) file_get_contents(base_path('tests/Fixtures/credits-earned-export-sample.csv'));

        $this->assertSame($this->normalize($expected), $this->normalize((string) $response->getContent()));
    }

    public function test_every_row_carries_the_basis_its_credits_were_calculated_from(): void
    {
        $scenario = $this->scenario();

        $rows = $this->rows((string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/credits-earned")
            ->assertOk()
            ->getContent());

        // CREDIT-005: the arithmetic is re-checkable inside the row. Vera worked
        // 450 minutes at the organization default rate, and the printed hours
        // multiplied by the printed rate is the printed credits.
        $vera = $this->rowFor($rows, 'Vera Staff');
        $this->assertSame('450', $vera['minutes_worked']);
        $this->assertSame('7.50', $vera['hours']);
        $this->assertSame('1.000', $vera['credit_multiplier']);
        $this->assertSame('7.50', $vera['credits']);
        $this->assertSame('Standard Credit', $vera['credit_policy_name']);

        // A different rate produces a different answer from the same file, which
        // is the point of exporting the multiplier beside the result.
        $rita = $this->rowFor($rows, 'Rita Roster');
        $this->assertSame('180', $rita['minutes_worked']);
        $this->assertSame('3.00', $rita['hours']);
        $this->assertSame('1.500', $rita['credit_multiplier']);
        $this->assertSame('4.50', $rita['credits']);
        $this->assertSame('Overnight Gate', $rita['credit_policy_name']);

        // CREDIT-001, CREDIT-004: the hours were final before they were used and
        // the entry froze at calculation, so a reader can see both moments
        // without opening the ledger.
        $this->assertSame('2026-06-28T00:00:00+00:00', $vera['hours_frozen_at']);
        $this->assertSame('2026-07-15T17:00:00+00:00', $vera['calculated_at']);
        $this->assertSame(CreditLedgerEntry::STATUS_FROZEN, $vera['credit_status']);
        $this->assertSame(CreditLedgerEntry::ENTRY_TYPE_CALCULATED, $vera['entry_type']);

        // HOURS-007: a corrected basis says when it was corrected, so credits
        // built on an edited total are distinguishable from credits built on
        // the one the clock produced.
        $alma = $this->rowFor($rows, 'Alma Assigned');
        $this->assertSame('2026-06-20T18:00:00+00:00', $alma['hours_corrected_at']);
        $this->assertSame('10.00', $alma['credits']);
        $this->assertSame('', $vera['hours_corrected_at']);

        // Each row still names the shift and department the work belongs to.
        $this->assertSame('Rangers Dirt Day', $vera['shift_title']);
        $this->assertSame('Rangers', $vera['department']);
        $this->assertSame('Dirt', $vera['team']);
        $this->assertSame('vera@idaho-burners.test', $vera['staff_email']);
    }

    /**
     * CREDIT-002 and CREDIT-003 produce identical credits whenever a shift
     * policy and the organization default carry the same multiplier. Only the
     * source separates them, so the file has to say which one applied.
     */
    public function test_the_export_reports_which_policy_governed_and_not_only_the_rate(): void
    {
        $scenario = $this->scenario();

        $rows = $this->rows((string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/credits-earned")
            ->assertOk()
            ->getContent());

        $this->assertSame(
            CreditPolicyResolution::SOURCE_SHIFT,
            $this->rowFor($rows, 'Rita Roster')['policy_source'],
        );
        $this->assertSame(
            CreditPolicyResolution::SOURCE_ORGANIZATION,
            $this->rowFor($rows, 'Vera Staff')['policy_source'],
        );
    }

    /**
     * The basis is read from the frozen entry, never from the policy record it
     * names. A policy renamed and re-rated after an event closes would otherwise
     * silently restate what that event paid every time the file was pulled again.
     */
    public function test_a_renamed_or_re_rated_policy_does_not_restate_a_frozen_export(): void
    {
        $scenario = $this->scenario();

        $scenario['defaultPolicy']->forceFill([
            'name' => 'Standard Credit (2027 rates)',
            'credit_multiplier' => '2.000',
        ])->save();

        $rows = $this->rows((string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/credits-earned")
            ->assertOk()
            ->getContent());

        $vera = $this->rowFor($rows, 'Vera Staff');

        $this->assertSame('Standard Credit', $vera['credit_policy_name']);
        $this->assertSame('1.000', $vera['credit_multiplier']);
        $this->assertSame('7.50', $vera['credits']);
    }

    /**
     * The ledger is the source, not the hours table. Hours that are frozen but
     * have not been through a calculation run are owed, not earned, and the
     * hours worked export is where a reader finds them.
     */
    public function test_hours_that_have_not_been_credited_yet_produce_no_row(): void
    {
        $scenario = $this->scenario();

        $contents = (string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/credits-earned")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Uma Uncredited', $contents);
        $this->assertCount(3, $this->rows($contents));
    }

    /**
     * ORG-010 leaves no third candidate after the shift policy and the
     * organization default, so an organization that has configured neither is
     * not running credits. An empty file says that; a file of zero-credit rows
     * would be indistinguishable from a policy that genuinely credits nothing.
     */
    public function test_an_event_with_no_configured_credit_policy_exports_no_rows(): void
    {
        $scenario = $this->scenario(configureCreditPolicies: false);

        $contents = (string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/credits-earned")
            ->assertOk()
            ->getContent();

        $this->assertSame(0, CreditLedgerEntry::query()->count());
        $this->assertSame([], $this->rows($contents));
        $this->assertStringContainsString('event_name,department,team', $contents);
    }

    public function test_the_export_excludes_phone_numbers_emergency_contacts_and_dates_of_birth(): void
    {
        $scenario = $this->scenario();

        $contents = (string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/credits-earned")
            ->assertOk()
            ->getContent();

        // REPORT-010: an organizer export carries no emergency contacts. A
        // credit statement needs none of these fields to be a credit statement.
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
            ->get("/api/events/{$scenario['event']->id}/exports/credits-earned");

        $response->assertOk();
        $response->assertHeader(
            'Content-Disposition',
            'attachment; filename="credits-earned-idaho-decompression-2026-rangers-20260715-170000.csv"',
        );

        // REPORT-007: Gate's credits belong to Gate's lead.
        $rows = $this->rows((string) $response->getContent());
        $this->assertSame(['Rangers'], array_values(array_unique(array_column($rows, 'department'))));
        $this->assertStringNotContainsString('Rita Roster', (string) $response->getContent());
    }

    public function test_organizer_may_narrow_the_export_to_one_department(): void
    {
        $scenario = $this->scenario();

        $rows = $this->rows((string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/credits-earned?department_id={$scenario['gate']->id}")
            ->assertOk()
            ->getContent());

        $this->assertSame(['Rita Roster'], array_column($rows, 'staff_legal_name'));
    }

    public function test_credits_of_another_event_are_not_exported(): void
    {
        $scenario = $this->scenario();

        $contents = (string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/credits-earned")
            ->assertOk()
            ->getContent();

        // The same department and the same policy credited last year's event;
        // this event's statement must not carry last year's credits.
        $this->assertStringNotContainsString('Perry Prior', $contents);
    }

    public function test_export_authority_is_refused_outside_the_callers_scope(): void
    {
        $scenario = $this->scenario();
        $eventId = $scenario['event']->id;

        // A department lead may not reach another department's credits.
        $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$eventId}/exports/credits-earned?department_id={$scenario['gate']->id}")
            ->assertForbidden();

        // Plain staff hold no export capability, even over the credits they
        // themselves earned.
        $this->actingAsClient($scenario['plainStaffUser'])
            ->get("/api/events/{$eventId}/exports/credits-earned")
            ->assertForbidden();

        // An organizer of another organization has no authority over this event.
        $foreignOrganization = Organization::factory()->create();
        $foreignDepartment = Department::factory()->for($foreignOrganization)->create();
        $foreignOrganizer = $this->userWithRole($foreignDepartment, PermissionRole::query()
            ->where('code', 'organizer')
            ->firstOrFail());

        $this->actingAsClient($foreignOrganizer)
            ->get("/api/events/{$eventId}/exports/credits-earned")
            ->assertForbidden();

        // A department outside this event's organization is not addressable.
        $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$eventId}/exports/credits-earned?department_id={$foreignDepartment->id}")
            ->assertNotFound();

        $this->assertDatabaseMissing('audit_events', [
            'action' => 'event_credits_earned.exported',
        ]);
    }

    public function test_a_successful_export_is_audited_as_a_sensitive_read(): void
    {
        $scenario = $this->scenario();
        $eventId = $scenario['event']->id;

        $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$eventId}/exports/credits-earned")
            ->assertOk();

        $departmentExport = AuditEvent::query()
            ->where('action', 'event_credits_earned.exported')
            ->sole();

        $this->assertSame((string) $scenario['rangersLead']->id, (string) $departmentExport->actor_user_id);
        $this->assertSame((string) $eventId, (string) $departmentExport->event_id);
        $this->assertSame((string) $scenario['rangers']->id, (string) $departmentExport->department_id);
        $this->assertSame(AuditEvent::SOURCE_API, $departmentExport->source_context);
        $this->assertSame('department', $departmentExport->after_json['scope']);
        $this->assertSame('csv', $departmentExport->after_json['format']);
        $this->assertSame(2, $departmentExport->after_json['row_count']);
        $this->assertSame([(string) $scenario['rangers']->id], $departmentExport->after_json['department_ids']);

        // The audit records both totals the file handed over: the basis and the
        // result, which is what a later dispute about what someone earned is
        // actually about.
        $this->assertSame('17.50', $departmentExport->after_json['total_hours']);
        $this->assertSame('17.50', $departmentExport->after_json['total_credits']);

        $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$eventId}/exports/credits-earned")
            ->assertOk();

        $organizerExport = AuditEvent::query()
            ->where('action', 'event_credits_earned.exported')
            ->where('actor_user_id', $scenario['organizer']->id)
            ->sole();

        $this->assertNull($organizerExport->department_id);
        $this->assertSame('event', $organizerExport->after_json['scope']);
        $this->assertSame([], $organizerExport->after_json['department_ids']);
        $this->assertSame('20.50', $organizerExport->after_json['total_hours']);
        $this->assertSame('22.00', $organizerExport->after_json['total_credits']);
    }

    /**
     * A two-department event whose hours have all been frozen and credited: one
     * shift priced by its own policy (CREDIT-002), one priced by the
     * organization default (CREDIT-003), one record corrected before it froze,
     * hours frozen after the run and therefore not yet credited, and a prior
     * event of the same organization credited under the same default.
     *
     * The calculation is run through {@see CreditCalculationService} rather than
     * by writing ledger rows directly, so the calculation basis this export
     * reports is the basis the domain actually produces.
     *
     * @return array<string, mixed>
     */
    private function scenario(bool $configureCreditPolicies = true): array
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
        $uma = $this->staff($organization, 'Uma Uncredited', 'Uma', 'uma', [$rangers]);
        $rita = $this->staff($organization, 'Rita Roster', 'Rita', 'rita', [$gate]);

        $plainStaff = $this->staff($organization, 'Perry Plain', 'Perry', 'perry', [$rangers]);
        $plainStaffUser = User::factory()->create();
        $plainStaffUser->staffProfiles()->attach($plainStaff->id);

        $openingShift = $this->shift($event, $gate, $gateTeam, 'Gate Opening', '2026-06-18 19:00:00');
        $dayShift = $this->shift($event, $rangers, $rangersTeam, 'Rangers Dirt Day', '2026-06-19 19:00:00');

        $defaultPolicy = null;

        if ($configureCreditPolicies) {
            // ORG-009: the organization default, which prices anything a shift
            // does not price itself.
            $defaultPolicy = CreditPolicy::factory()
                ->for($organization)
                ->multiplier('1.000')
                ->create(['name' => 'Standard Credit']);

            $organization->forceFill(['default_credit_policy_id' => $defaultPolicy->id])->save();

            // SHIFT-010: the gate opening is priced at its own rate.
            $gatePolicy = CreditPolicy::factory()
                ->for($organization)
                ->multiplier('1.500')
                ->create(['name' => 'Overnight Gate', 'shift_id' => $openingShift->id]);

            $openingShift->forceFill(['credit_policy_id' => $gatePolicy->id])->save();
        }

        // Rita's hours froze without ever being corrected.
        $this->hours($openingShift, $rita, minutes: 180, frozenAt: '2026-06-28 00:00:00');

        // Alma stayed two hours past the scheduled end; a lead corrected the
        // record afterwards (HOURS-007) and the grace period has since closed on
        // it (HOURS-008), so the corrected total is what got credited.
        $this->hours(
            $dayShift,
            $alma,
            minutes: 600,
            frozenAt: '2026-06-28 00:00:00',
            correctedAt: '2026-06-20 18:00:00',
        );

        // Vera worked most of her shift and nobody has touched the record.
        $this->hours($dayShift, $vera, minutes: 450, frozenAt: '2026-06-28 00:00:00');

        app(CreditCalculationService::class)->calculateForEvent($event, $organizer);

        // Uma's hours froze after the run, so they are owed rather than earned
        // and no ledger entry exists for them yet.
        $this->hours($dayShift, $uma, minutes: 300, frozenAt: '2026-07-16 00:00:00');

        // The same department worked and was credited for a different event.
        $priorEvent = Event::factory()->for($organization)->create([
            'name' => 'Idaho Decompression 2025',
            'slug' => 'idaho-decompression-2025',
        ]);
        $priorStaff = $this->staff($organization, 'Perry Prior', 'Perry', 'pprior', [$rangers]);
        $priorShift = $this->shift($priorEvent, $rangers, $rangersTeam, 'Rangers Dirt Day', '2025-06-19 19:00:00');
        $this->hours($priorShift, $priorStaff, minutes: 480, frozenAt: '2025-06-28 00:00:00');

        app(CreditCalculationService::class)->calculateForEvent($priorEvent, $organizer);

        return [
            'organization' => $organization->refresh(),
            'event' => $event,
            'rangers' => $rangers,
            'gate' => $gate,
            'defaultPolicy' => $defaultPolicy,
            'organizer' => $organizer,
            'rangersLead' => $rangersLead,
            'plainStaffUser' => $plainStaffUser,
            'staff' => [$vera, $alma, $uma, $rita, $priorStaff],
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
        int $minutes,
        string $frozenAt,
        ?string $correctedAt = null,
    ): HoursWorked {
        $startedAt = $shift->starts_at->copy();
        $endedAt = $startedAt->copy()->addMinutes($minutes);

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
            'minutes_worked' => $minutes,
            'server_corrected_at' => $correctedAt === null ? null : Carbon::parse($correctedAt, 'UTC'),
            'frozen_at' => Carbon::parse($frozenAt, 'UTC'),
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
