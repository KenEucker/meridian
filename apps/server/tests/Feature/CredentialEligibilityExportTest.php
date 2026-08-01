<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventCredential;
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
use App\Services\Credential\CredentialEligibilityService;
use App\Services\Credential\CredentialRevocationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Event credential eligibility export (M13.1; REPORT-001, REPORT-006 through
 * REPORT-008, REPORT-010; CRED-004, CRED-010, CRED-013, CRED-014).
 */
class CredentialEligibilityExportTest extends TestCase
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
            ->get("/api/events/{$scenario['event']->id}/exports/credential-eligibility");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader(
            'Content-Disposition',
            'attachment; filename="credential-eligibility-emberfall-2026-20260621-192030.csv"',
        );

        $contents = $response->getContent();
        $expected = (string) file_get_contents(base_path('tests/Fixtures/credential-eligibility-export-sample.csv'));

        $this->assertSame($this->normalize($expected), $this->normalize($contents));
    }

    public function test_the_export_excludes_phone_numbers_emergency_contacts_and_birth_dates(): void
    {
        $scenario = $this->scenario();

        $contents = $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/credential-eligibility")
            ->assertOk()
            ->getContent();

        // REPORT-008 / REPORT-010: no phone number and no emergency contact
        // reaches an organizer export. Date of birth stays out too; an
        // age-related block is reported by its reason instead (CRED-006).
        foreach ($scenario['staff'] as $staff) {
            $this->assertStringNotContainsString((string) $staff->phone, $contents);
            $this->assertStringNotContainsString((string) $staff->emergency_contact_name, $contents);
            $this->assertStringNotContainsString((string) $staff->emergency_contact_phone, $contents);
            $this->assertStringNotContainsString($staff->date_of_birth->format('Y-m-d'), $contents);
        }
    }

    public function test_the_export_reports_recorded_credential_state_without_recalculating_it(): void
    {
        $scenario = $this->scenario();

        // A credential Blocked because every shift was removed stays Blocked in
        // the file (CRED-010), and a revoked credential keeps its completed
        // shift while recalculation leaves the revoked state alone (CRED-013).
        $rows = $this->rows($this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/credential-eligibility")
            ->assertOk()
            ->getContent());

        $this->assertSame(
            ['Alma Multi', 'Bruno Blocked', 'Rita Revoked', 'Vera Staff'],
            array_column($rows, 'staff_legal_name'),
        );

        $blocked = $rows[1];
        $this->assertSame(EventCredential::STATUS_BLOCKED, $blocked['credential_status']);
        $this->assertSame(CredentialEligibilityService::REASON_NO_SIGNED_UP_SHIFTS, $blocked['status_reason']);
        $this->assertSame('No signed-up shifts', $blocked['status_reason_label']);
        $this->assertSame('0', $blocked['credential_shift_count']);
        $this->assertSame('', $blocked['departments']);

        $revoked = $rows[2];
        $this->assertSame(EventCredential::STATUS_REVOKED, $revoked['credential_status']);
        $this->assertSame(CredentialRevocationService::REASON_MANUAL_REVOCATION, $revoked['status_reason']);
        $this->assertSame('Gate', $revoked['departments']);
        $this->assertSame('1', $revoked['credential_shift_count']);
        $this->assertNotSame('', $revoked['revoked_at']);

        // Multi-department credentials list every department the counting
        // shifts belong to (CRED-001 allows one credential across departments).
        $this->assertSame('Gate; Rangers', $rows[0]['departments']);
        $this->assertSame('2', $rows[0]['credential_shift_count']);

        $eligible = $rows[3];
        $this->assertSame(EventCredential::STATUS_ELIGIBLE, $eligible['credential_status']);
        $this->assertSame('', $eligible['status_reason']);
        $this->assertSame('Rangers', $eligible['departments']);
    }

    public function test_unscheduled_work_after_a_shift_starts_is_not_counted(): void
    {
        $scenario = $this->scenario();
        $unscheduled = Shift::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['rangers']->id,
            'eligible_team_id' => $scenario['rangersTeam']->id,
            'title' => 'Live Coverage',
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->addHours(6),
        ]);
        ShiftAssignment::factory()->create([
            'shift_id' => $unscheduled->id,
            'staff_id' => $scenario['vera']->id,
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            'assigned_by_user_id' => $scenario['organizer']->id,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $rows = $this->rows($this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/credential-eligibility")
            ->assertOk()
            ->getContent());

        // CRED-014: unscheduled work does not retroactively grant eligibility,
        // so the export must not count it either.
        $this->assertSame('1', $rows[3]['credential_shift_count']);
        $this->assertSame('Rangers', $rows[3]['departments']);
    }

    public function test_department_lead_export_is_limited_to_their_own_department(): void
    {
        Carbon::setTestNow('2026-06-21 19:20:30 UTC');

        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$scenario['event']->id}/exports/credential-eligibility");

        $response->assertOk();
        $response->assertHeader(
            'Content-Disposition',
            'attachment; filename="credential-eligibility-emberfall-2026-rangers-20260621-192030.csv"',
        );

        $rows = $this->rows($response->getContent());

        // REPORT-007: department-scoped export. Alma is a Rangers member who
        // also works Gate, so she is in scope; Rita is Gate-only and is not.
        // Bruno has no remaining shifts but is still a Rangers member, which is
        // exactly the row a lead needs to see.
        $this->assertSame(
            ['Alma Multi', 'Bruno Blocked', 'Vera Staff'],
            array_column($rows, 'staff_legal_name'),
        );
        $this->assertStringNotContainsString('Rita Revoked', $response->getContent());
    }

    public function test_organizer_may_narrow_the_export_to_one_department(): void
    {
        $scenario = $this->scenario();

        $rows = $this->rows($this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/credential-eligibility?department_id={$scenario['gate']->id}")
            ->assertOk()
            ->getContent());

        $this->assertSame(
            ['Alma Multi', 'Rita Revoked'],
            array_column($rows, 'staff_legal_name'),
        );
    }

    public function test_export_authority_is_refused_outside_the_callers_scope(): void
    {
        $scenario = $this->scenario();
        $eventId = $scenario['event']->id;

        // A department lead may not reach another department's rows.
        $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$eventId}/exports/credential-eligibility?department_id={$scenario['gate']->id}")
            ->assertForbidden();

        // Plain staff hold no export capability at all.
        $this->actingAsClient($scenario['plainStaffUser'])
            ->get("/api/events/{$eventId}/exports/credential-eligibility")
            ->assertForbidden();

        // An organizer of another organization has no authority over this event.
        $foreignOrganization = Organization::factory()->create();
        $foreignDepartment = Department::factory()->for($foreignOrganization)->create();
        $foreignOrganizer = $this->userWithRole($foreignDepartment, PermissionRole::query()
            ->where('code', 'organizer')
            ->firstOrFail());

        $this->actingAsClient($foreignOrganizer)
            ->get("/api/events/{$eventId}/exports/credential-eligibility")
            ->assertForbidden();

        // A department outside this event's organization is not addressable.
        $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$eventId}/exports/credential-eligibility?department_id={$foreignDepartment->id}")
            ->assertNotFound();

        $this->assertDatabaseMissing('audit_events', [
            'action' => 'event_credential_eligibility.exported',
        ]);
    }

    public function test_a_successful_export_is_audited_as_a_sensitive_read(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$scenario['event']->id}/exports/credential-eligibility")
            ->assertOk();

        $audit = AuditEvent::query()
            ->where('action', 'event_credential_eligibility.exported')
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
     * A four-person event that exercises every credential state the export can
     * report: eligible in one department, eligible across two departments,
     * blocked after every shift was removed, and revoked with a completed
     * shift preserved.
     *
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective', 'slug' => 'northwood-collective']);
        $event = Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall-2026',
            'minimum_staff_age' => null,
        ]);

        [$rangers, $rangersTeam] = $this->department($organization, 'Rangers', 'RANGERS', 'Dirt');
        [$gate, $gateTeam] = $this->department($organization, 'Gate', 'GATE', 'Greeters');

        $organizerRole = PermissionRole::query()->where('code', 'organizer')->firstOrFail();
        $departmentLeadRole = PermissionRole::query()->where('code', 'department_lead')->firstOrFail();

        [$organizerDepartment] = $this->department($organization, 'Organizers', 'ORG', 'Leads');
        $organizer = $this->userWithRole($organizerDepartment, $organizerRole);
        $rangersLead = $this->userWithRole($rangers, $departmentLeadRole);

        $vera = $this->staff($organization, 'Vera Staff', 'Vera', 'vera', [$rangers]);
        $alma = $this->staff($organization, 'Alma Multi', 'Alma', 'alma', [$rangers, $gate]);
        $bruno = $this->staff($organization, 'Bruno Blocked', 'Bruno', 'bruno', [$rangers]);
        $rita = $this->staff($organization, 'Rita Revoked', 'Rita', 'rita', [$gate]);

        $plainStaff = $this->staff($organization, 'Perry Plain', 'Perry', 'perry', [$rangers]);
        $plainStaffUser = User::factory()->create();
        $plainStaffUser->staffProfiles()->attach($plainStaff->id);

        $eligibility = app(CredentialEligibilityService::class);

        $rangersShift = $this->shift($event, $rangers, $rangersTeam, 'Rangers Dirt Day', now()->addDays(10));
        $gateShift = $this->shift($event, $gate, $gateTeam, 'Gate Greeting Day', now()->addDays(11));

        $this->signUp($rangersShift, $vera);
        $this->signUp($rangersShift, $alma);
        $this->signUp($gateShift, $alma);
        $eligibility->recalculate($event, $vera);
        $eligibility->recalculate($event, $alma);

        // Bruno signed up, then every shift was removed (CRED-010).
        $brunoAssignment = $this->signUp($rangersShift, $bruno);
        $eligibility->recalculate($event, $bruno);
        $brunoAssignment->forceFill(['removed_at' => now()])->save();
        $eligibility->recalculate($event, $bruno);

        // Rita worked a completed Gate shift and held a future one, then an
        // organizer revoked her credential (CRED-011 through CRED-013).
        $completedGateShift = $this->shift($event, $gate, $gateTeam, 'Gate Opening', now()->subDays(3));
        $this->signUp($completedGateShift, $rita);
        $this->signUp($gateShift, $rita);
        $eligibility->recalculate($event, $rita);
        app(CredentialRevocationService::class)->revoke($event, $rita, $organizer, 'Export scenario revocation');

        return [
            'organization' => $organization,
            'event' => $event,
            'rangers' => $rangers,
            'rangersTeam' => $rangersTeam,
            'gate' => $gate,
            'organizer' => $organizer,
            'rangersLead' => $rangersLead,
            'plainStaffUser' => $plainStaffUser,
            'vera' => $vera,
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
            'email' => $handle.'@northwood-collective.test',
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

    private function shift(Event $event, Department $department, Team $team, string $title, Carbon $startsAt): Shift
    {
        return Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(8),
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
