<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Reporting\StaffContactExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Department staff contact export (M13.3; REPORT-003, REPORT-006 through
 * REPORT-010; VOL-009, VOL-011, VOL-012).
 */
class StaffContactExportTest extends TestCase
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
            ->get("/api/events/{$scenario['event']->id}/exports/staff-contact");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader(
            'Content-Disposition',
            'attachment; filename="staff-contact-idaho-decompression-2026-20260621-192030.csv"',
        );

        $expected = (string) file_get_contents(base_path('tests/Fixtures/staff-contact-export-sample.csv'));

        $this->assertSame($this->normalize($expected), $this->normalize((string) $response->getContent()));
    }

    public function test_an_organizer_export_carries_phone_numbers_but_no_emergency_contacts(): void
    {
        $scenario = $this->scenario();

        $contents = (string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/staff-contact")
            ->assertOk()
            ->getContent();

        // REPORT-010 / VOL-011: organizers hold no default access to emergency
        // contacts, so the columns are absent rather than blank — a blank cell
        // has to keep meaning "none recorded" for the leads who do see them.
        $this->assertStringNotContainsString('emergency_contact_name', $contents);
        $this->assertStringNotContainsString('emergency_contact_phone', $contents);

        foreach ($scenario['staff'] as $staff) {
            $this->assertStringNotContainsString((string) $staff->emergency_contact_name, $contents);
            $this->assertStringNotContainsString((string) $staff->emergency_contact_phone, $contents);
        }

        // REPORT-009 excludes phone numbers from shift rosters (REPORT-008), not
        // from the contact list, which would otherwise not be one.
        $this->assertStringContainsString('+1-208-555-0101', $contents);
    }

    public function test_department_lead_exports_their_own_department_with_emergency_contacts(): void
    {
        Carbon::setTestNow('2026-06-21 19:20:30 UTC');

        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$scenario['event']->id}/exports/staff-contact");

        $response->assertOk();
        $response->assertHeader(
            'Content-Disposition',
            'attachment; filename="staff-contact-idaho-decompression-2026-rangers-20260621-192030.csv"',
        );

        $expected = (string) file_get_contents(base_path('tests/Fixtures/staff-contact-export-department-sample.csv'));

        $this->assertSame($this->normalize($expected), $this->normalize((string) $response->getContent()));

        // REPORT-007: only Rangers. Gate is another lead's contact list.
        $rows = $this->rows((string) $response->getContent());
        $this->assertSame(['Rangers'], array_values(array_unique(array_column($rows, 'department'))));

        // REPORT-009 / VOL-012: emergency contacts for their own staff.
        $vera = $this->rowFor($rows, 'Vera Staff');
        $this->assertSame('Vera Staff Contact', $vera['emergency_contact_name']);
        $this->assertSame('+1-208-555-0199', $vera['emergency_contact_phone']);
        $this->assertSame('+1-208-555-0101', $vera['staff_phone']);
        $this->assertSame('vera@idaho-burners.test', $vera['staff_email']);
        $this->assertSame('Dirt', $vera['teams']);
        $this->assertSame('RANGERS', $vera['department_code']);
    }

    public function test_the_contact_list_reports_membership_and_organization_status_rather_than_hiding_people(): void
    {
        $scenario = $this->scenario();

        $rows = $this->rows((string) $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$scenario['event']->id}/exports/staff-contact")
            ->assertOk()
            ->getContent());

        // A lead needs to see that someone on their list is not currently
        // working, not to find them silently missing from it.
        $ida = $this->rowFor($rows, 'Ida Inactive');
        $this->assertSame(DepartmentMembership::STATUS_INACTIVE, $ida['department_membership_status']);
        $this->assertSame(StaffOrganizationStatus::STATUS_INACTIVE, $ida['organization_status']);

        $vera = $this->rowFor($rows, 'Vera Staff');
        $this->assertSame(DepartmentMembership::STATUS_ACTIVE, $vera['department_membership_status']);
        $this->assertSame(StaffOrganizationStatus::STATUS_ACTIVE, $vera['organization_status']);

        // An archived membership is a past assignment, not a current contact.
        $this->assertNull($this->rowFor($rows, 'Bruno Gone'));
    }

    public function test_only_departments_participating_in_the_event_are_exported(): void
    {
        $scenario = $this->scenario();

        $contents = (string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/staff-contact")
            ->assertOk()
            ->getContent();

        // Department membership is an organization-level record. Without the
        // event participation intersection, an event contact list would carry
        // departments that are not working the event.
        $this->assertStringNotContainsString('Sanctuary', $contents);
        $this->assertStringNotContainsString('Sandy Sanctuary', $contents);
    }

    public function test_an_organizer_narrowing_to_a_department_still_gets_an_organizer_file(): void
    {
        $scenario = $this->scenario();

        $rows = $this->rows((string) $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$scenario['event']->id}/exports/staff-contact?department_id={$scenario['rangers']->id}")
            ->assertOk()
            ->getContent());

        $this->assertSame(['Rangers'], array_values(array_unique(array_column($rows, 'department'))));

        // REPORT-010 keys on who is exporting, not on how narrow the request
        // was, so asking for one department does not turn an organizer into
        // that department's lead.
        $this->assertArrayNotHasKey('emergency_contact_name', $rows[0]);
    }

    public function test_a_lead_who_is_also_an_organizer_keeps_emergency_contacts_for_their_own_department(): void
    {
        $scenario = $this->scenario();

        // VOL-012 belongs to the person, not to a request: making the Rangers
        // lead an organizer as well must not cost them their own department's
        // emergency contacts.
        $this->grantRole(
            $scenario['rangersLeadStaff'],
            $scenario['organizerDepartment'],
            PermissionCatalog::ROLE_ORGANIZER,
        );

        $eventId = $scenario['event']->id;

        $narrowed = $this->rows((string) $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$eventId}/exports/staff-contact?department_id={$scenario['rangers']->id}")
            ->assertOk()
            ->getContent());

        $this->assertSame('Vera Staff Contact', $this->rowFor($narrowed, 'Vera Staff')['emergency_contact_name']);

        // Exporting the whole event is an organizer export again, and Gate's
        // staff are not theirs to pull emergency contacts for.
        $eventWide = (string) $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$eventId}/exports/staff-contact")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Rita Roster', $eventWide);
        $this->assertStringNotContainsString('emergency_contact_name', $eventWide);
    }

    public function test_export_authority_is_refused_outside_the_callers_scope(): void
    {
        $scenario = $this->scenario();
        $eventId = $scenario['event']->id;

        // A department lead may not reach another department's contact list.
        $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$eventId}/exports/staff-contact?department_id={$scenario['gate']->id}")
            ->assertForbidden();

        // Plain staff hold no export capability, even over the department whose
        // list they are themselves on.
        $this->actingAsClient($scenario['plainStaffUser'])
            ->get("/api/events/{$eventId}/exports/staff-contact")
            ->assertForbidden();

        // An organizer of another organization has no authority over this event.
        $foreignOrganization = Organization::factory()->create();
        $foreignDepartment = Department::factory()->for($foreignOrganization)->create();
        $foreignOrganizer = $this->userWithRole(
            $foreignOrganization,
            $foreignDepartment,
            PermissionCatalog::ROLE_ORGANIZER,
            'Fern Foreign',
            'Fern',
            'fern',
        );

        $this->actingAsClient($foreignOrganizer['user'])
            ->get("/api/events/{$eventId}/exports/staff-contact")
            ->assertForbidden();

        // A department outside this event's organization is not addressable.
        $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$eventId}/exports/staff-contact?department_id={$foreignDepartment->id}")
            ->assertNotFound();

        $this->assertDatabaseMissing('audit_events', [
            'action' => 'event_staff_contact.exported',
        ]);
    }

    public function test_a_successful_export_is_audited_with_whether_emergency_contacts_went_with_it(): void
    {
        $scenario = $this->scenario();
        $eventId = $scenario['event']->id;

        $this->actingAsClient($scenario['rangersLead'])
            ->get("/api/events/{$eventId}/exports/staff-contact")
            ->assertOk();

        $departmentExport = AuditEvent::query()
            ->where('action', 'event_staff_contact.exported')
            ->sole();

        $this->assertSame((string) $scenario['rangersLead']->id, (string) $departmentExport->actor_user_id);
        $this->assertSame((string) $eventId, (string) $departmentExport->event_id);
        $this->assertSame((string) $scenario['rangers']->id, (string) $departmentExport->department_id);
        $this->assertSame(AuditEvent::SOURCE_API, $departmentExport->source_context);
        $this->assertSame('department', $departmentExport->after_json['scope']);
        $this->assertSame('csv', $departmentExport->after_json['format']);
        $this->assertSame(5, $departmentExport->after_json['row_count']);
        $this->assertSame([(string) $scenario['rangers']->id], $departmentExport->after_json['department_ids']);
        $this->assertTrue($departmentExport->after_json['emergency_contacts_included']);

        $this->actingAsClient($scenario['organizer'])
            ->get("/api/events/{$eventId}/exports/staff-contact")
            ->assertOk();

        $organizerExport = AuditEvent::query()
            ->where('action', 'event_staff_contact.exported')
            ->where('actor_user_id', $scenario['organizer']->id)
            ->sole();

        $this->assertNull($organizerExport->department_id);
        $this->assertSame('event', $organizerExport->after_json['scope']);
        $this->assertSame([], $organizerExport->after_json['department_ids']);
        $this->assertFalse($organizerExport->after_json['emergency_contacts_included']);
    }

    public function test_the_column_contract_names_emergency_contacts_as_an_addition(): void
    {
        // The base columns are what every caller gets; the emergency contact
        // pair is appended only for a caller who leads every exported
        // department, so the two are declared separately rather than filtered
        // out of one list.
        $this->assertNotContains('emergency_contact_name', StaffContactExportService::COLUMNS);
        $this->assertNotContains('emergency_contact_phone', StaffContactExportService::COLUMNS);
        $this->assertSame(
            ['emergency_contact_name', 'emergency_contact_phone'],
            StaffContactExportService::EMERGENCY_CONTACT_COLUMNS,
        );
    }

    /**
     * A two-department event with a third, non-participating department, plus
     * every membership shape the contact list can report: an active member, a
     * member of two departments, an inactive member, an archived membership,
     * and a member of a department that is not working this event.
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

        $rangers = $this->department($organization, 'Rangers', 'RANGERS', 'Dirt');
        $gate = $this->department($organization, 'Gate', 'GATE', 'Greeters');
        $sanctuary = $this->department($organization, 'Sanctuary', 'SANCTUARY', 'Sitters');
        $organizerDepartment = $this->department($organization, 'Organizers', 'ORG', 'Leads');

        // Rangers and Gate work this event; Sanctuary and the Organizers
        // department do not.
        foreach ([$rangers, $gate] as $department) {
            EventDepartmentAssignment::factory()->create([
                'event_id' => $event->id,
                'department_id' => $department->id,
            ]);
        }

        $vera = $this->staff($organization, 'Vera Staff', 'Vera', 'vera', [$rangers]);
        $alma = $this->staff($organization, 'Alma Assigned', 'Alma', 'alma', [$rangers, $gate]);
        $rita = $this->staff($organization, 'Rita Roster', 'Rita', 'rita', [$gate]);
        $sandy = $this->staff($organization, 'Sandy Sanctuary', 'Sandy', 'sandy', [$sanctuary]);

        $ida = $this->staff($organization, 'Ida Inactive', 'Ida', 'ida', [$rangers]);
        $this->setStatuses($ida, $rangers, DepartmentMembership::STATUS_INACTIVE, StaffOrganizationStatus::STATUS_INACTIVE);

        $bruno = $this->staff($organization, 'Bruno Gone', 'Bruno', 'bruno', [$rangers]);
        DepartmentMembership::query()
            ->where('staff_id', $bruno->id)
            ->where('department_id', $rangers->id)
            ->firstOrFail()
            ->forceFill(['archived_at' => now()])
            ->save();

        $organizer = $this->userWithRole(
            $organization,
            $organizerDepartment,
            PermissionCatalog::ROLE_ORGANIZER,
            'Olive Organizer',
            'Olive',
            'olive',
        );
        $rangersLead = $this->userWithRole(
            $organization,
            $rangers,
            PermissionCatalog::ROLE_DEPARTMENT_LEAD,
            'Dana Departmentlead',
            'Dana',
            'dana',
        );

        $plainStaff = $this->staff($organization, 'Perry Plain', 'Perry', 'perry', [$rangers]);
        $plainStaffUser = User::factory()->create();
        $plainStaffUser->staffProfiles()->attach($plainStaff->id);

        return [
            'organization' => $organization,
            'event' => $event,
            'rangers' => $rangers,
            'gate' => $gate,
            'sanctuary' => $sanctuary,
            'organizerDepartment' => $organizerDepartment,
            'organizer' => $organizer['user'],
            'rangersLead' => $rangersLead['user'],
            'rangersLeadStaff' => $rangersLead['staff'],
            'plainStaffUser' => $plainStaffUser,
            'staff' => [$vera, $alma, $rita, $ida, $sandy],
        ];
    }

    private function department(Organization $organization, string $name, string $code, string $teamName): Department
    {
        $department = Department::factory()->for($organization)->create(['name' => $name, 'code' => $code]);

        // Creating a department creates its default team; name it rather than
        // adding a second default the contact list would have to choose between.
        $this->defaultTeam($department)->forceFill(['name' => $teamName])->save();

        return $department;
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
            'phone' => '+1-208-555-0101',
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

    private function setStatuses(
        Staff $staff,
        Department $department,
        string $membershipStatus,
        string $organizationStatus,
    ): void {
        DepartmentMembership::query()
            ->where('staff_id', $staff->id)
            ->where('department_id', $department->id)
            ->firstOrFail()
            ->forceFill(['status' => $membershipStatus])
            ->save();

        StaffOrganizationStatus::query()
            ->where('staff_id', $staff->id)
            ->firstOrFail()
            ->forceFill(['status' => $organizationStatus])
            ->save();
    }

    /**
     * A staff profile with a login that carries a role through a team grant.
     *
     * @return array{user: User, staff: Staff}
     */
    private function userWithRole(
        Organization $organization,
        Department $department,
        string $roleCode,
        string $legalName,
        string $preferredName,
        string $handle,
    ): array {
        $staff = $this->staff($organization, $legalName, $preferredName, $handle, [$department]);
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $this->grantRole($staff, $department, $roleCode);

        return ['user' => $user, 'staff' => $staff];
    }

    /**
     * Put the staff member on a team of their own that carries the role.
     *
     * Team grants apply to everyone on the granted team, so reusing the
     * department's default team would quietly hand the same authority to every
     * ordinary member.
     */
    private function grantRole(Staff $staff, Department $department, string $roleCode): void
    {
        $team = Team::factory()->for($department)->create([
            'name' => $department->name.' Leads',
            'is_default' => false,
        ]);

        $membership = DepartmentMembership::query()
            ->where('staff_id', $staff->id)
            ->where('department_id', $department->id)
            ->first()
            ?? DepartmentMembership::factory()->for($department)->for($staff)->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => null,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);
    }

    private function defaultTeam(Department $department): Team
    {
        return Team::query()
            ->where('department_id', $department->id)
            ->where('is_default', true)
            ->firstOrFail();
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array<string, string>|null
     */
    private function rowFor(array $rows, string $legalName): ?array
    {
        foreach ($rows as $row) {
            if ($row['staff_legal_name'] === $legalName) {
                return $row;
            }
        }

        return null;
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
