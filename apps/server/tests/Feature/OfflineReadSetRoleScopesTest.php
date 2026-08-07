<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Modules\ModuleKey;
use App\Domain\Permissions\PermissionCatalog;
use App\Models\AttendanceRecord;
use App\Models\CurrentDeploymentAssignment;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Deployment;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\EventDepartmentPresence;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\PolicyDocument;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Modules\ActiveModuleResolver;
use Database\Seeders\AttendanceScenarioSeeder;
use Database\Seeders\DevelopmentScenarioSeeder;
use Database\Seeders\DocumentScenarioSeeder;
use Database\Seeders\EquipmentScenarioSeeder;
use Database\Seeders\IncidentScenarioSeeder;
use Database\Seeders\IncidentTypeDefaultsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\ShiftScenarioSeeder;
use Database\Seeders\TrainingAndWaiverScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The role-additive sections of the offline read set (M18.47; CLIENT-021,
 * CLIENT-022, TEAM-009; technical spec 9.3, 9.5, 11A.7; data/API 7.1, 7.3;
 * ADR-0003).
 *
 * `PowerSyncPermissionScopedReplicationTest` and
 * `PowerSyncDeviceCacheProjectionTest` proved the shift-lead and department-lead
 * boundary against `deploy/powersync/sync-config.yaml` — one by running the
 * shipped streams, one by reading them as text. Those assertions are ported here
 * onto the endpoint that replaces them, and the ones the sync dialect could not
 * express are added: department Logistics, Operations, and Planning, which the
 * rules never had streams for at all.
 *
 * The properties are the requirements' own. A device holds nothing its user
 * could not retrieve through the API (CLIENT-021), a change to what the user
 * holds changes the next set (CLIENT-022), and a grant held by a team is
 * exercised only by the members designated to it (TEAM-009).
 */
class OfflineReadSetRoleScopesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Department $rangers;

    /** The crew team: shifts are eligible to it, and its members work them. */
    private Team $dirt;

    /**
     * The authority team.
     *
     * Department roles hang here rather than on the crew, because a team grant
     * reaches every member of the team it is on: putting `department_logistics`
     * on Dirt would make Vera a Logistics operator, and a fixture where the
     * ordinary-staff persona silently holds every capability cannot test a
     * permission boundary.
     */
    private Team $rangerLeads;

    private Event $event;

    private Shift $shift;

    /** A designated lead of Dirt, and a member of the authority team. */
    private Staff $sam;

    private User $samUser;

    /** An ordinary member of Dirt, assigned to the shift. */
    private Staff $vera;

    private User $veraUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $this->rangers = Department::factory()->for($this->organization)->create(['name' => 'Rangers']);
        $this->dirt = Team::factory()->for($this->rangers)->create(['name' => 'Dirt']);
        $this->rangerLeads = Team::factory()->for($this->rangers)->create(['name' => 'Ranger Leads']);
        $this->event = Event::factory()->for($this->organization)->create(['name' => 'Emberfall 2026']);

        EventDepartmentAssignment::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->rangers->id,
        ]);

        // Inside the desk horizon, so the Logistics index reaches it.
        $this->shift = Shift::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->rangers->id,
            'eligible_team_id' => $this->dirt->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(4),
            'capacity' => 3,
        ]);

        [$this->sam, $this->samUser] = $this->staffMember('Sam Shiftlead', $this->dirt, 'lead');
        [$this->vera, $this->veraUser] = $this->staffMember('Vera Staff', $this->dirt, 'member');

        $this->alsoBelongsTo($this->sam, $this->rangerLeads);

        ShiftAssignment::factory()->create([
            'shift_id' => $this->shift->id,
            'staff_id' => $this->vera->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Shift lead (TEAM-009)
    |--------------------------------------------------------------------------
    */

    public function test_a_designated_team_lead_receives_the_roster_their_grant_covers(): void
    {
        $this->grant(PermissionCatalog::ROLE_SHIFT_LEAD, $this->dirt);

        $sections = $this->sectionsFor($this->samUser);

        $this->assertContains($this->vera->id, $this->ids($sections, 'shift_lead_staff'));
        $this->assertContains($this->shift->id, $this->ids($sections, 'shift_lead_shifts'));
        $this->assertNotEmpty($sections['shift_lead_shift_assignments']);
        $this->assertNotEmpty($sections['shift_lead_team_memberships']);
    }

    /**
     * The grant belongs to the team; the authority belongs to its designated
     * leads. An undesignated member holds no `shift_lead` role through the API,
     * so their device receives none of the shift-lead sections either — and the
     * sections are absent rather than empty, because an empty roster is a claim
     * about a team this caller may not make.
     */
    public function test_an_undesignated_member_of_a_granted_team_receives_no_shift_lead_sections(): void
    {
        $this->grant(PermissionCatalog::ROLE_SHIFT_LEAD, $this->dirt);

        $sections = $this->sectionsFor($this->veraUser);

        foreach ($this->shiftLeadSectionNames() as $absent) {
            $this->assertArrayNotHasKey($absent, $sections);
        }
    }

    public function test_a_demoted_shift_lead_stops_receiving_the_roster(): void
    {
        $grant = $this->grant(PermissionCatalog::ROLE_SHIFT_LEAD, $this->dirt);

        $this->assertContains(
            $this->vera->id,
            $this->ids($this->sectionsFor($this->samUser), 'shift_lead_staff'),
        );

        $grant->forceFill(['revoked_at' => now()])->save();

        $sections = $this->sectionsFor($this->samUser);

        foreach ($this->shiftLeadSectionNames() as $absent) {
            $this->assertArrayNotHasKey($absent, $sections);
        }

        // The demotion withdraws the roster, not the person's own records: they
        // are still staff, and still hold what any staff member holds.
        $this->assertSame([$this->dirt->id, $this->rangerLeads->id], $this->ids($sections, 'teams'));
    }

    public function test_losing_the_lead_designation_stops_the_shift_lead_sections(): void
    {
        $this->grant(PermissionCatalog::ROLE_SHIFT_LEAD, $this->dirt);

        $this->assertArrayHasKey('shift_lead_staff', $this->sectionsFor($this->samUser));

        TeamMembership::query()
            ->where('team_id', $this->dirt->id)
            ->where('staff_id', $this->sam->id)
            ->update(['membership_role' => 'member']);

        $this->assertArrayNotHasKey('shift_lead_staff', $this->sectionsFor($this->samUser));
    }

    /**
     * A grant scoped to one event reaches that event and no other, which is the
     * narrowing `EffectiveRoleResolver` applies and this set inherits by
     * resolving roles once per event rather than once per caller.
     */
    public function test_an_event_scoped_grant_does_not_reach_another_events_shifts(): void
    {
        $otherEvent = Event::factory()->for($this->organization)->create();
        EventDepartmentAssignment::factory()->create([
            'event_id' => $otherEvent->id,
            'department_id' => $this->rangers->id,
        ]);

        $otherShift = Shift::factory()->create([
            'event_id' => $otherEvent->id,
            'department_id' => $this->rangers->id,
            'eligible_team_id' => $this->dirt->id,
        ]);

        $this->grant(PermissionCatalog::ROLE_SHIFT_LEAD, $this->dirt, $this->event);

        $shifts = $this->ids($this->sectionsFor($this->samUser), 'shift_lead_shifts');

        $this->assertContains($this->shift->id, $shifts);
        $this->assertNotContains($otherShift->id, $shifts);
    }

    /**
     * A shift lead maintains their own team's documents
     * (`DocumentProductAccess::canMaintainScope`), so the drafts and archived
     * revisions travel to them and to nobody else. Everybody else holds only
     * what the regular-staff list gives them, which is published and in audience.
     */
    public function test_a_shift_lead_holds_the_drafts_of_the_team_they_lead(): void
    {
        $this->grant(PermissionCatalog::ROLE_SHIFT_LEAD, $this->dirt);

        $draft = PolicyDocument::factory()->create([
            'organization_id' => $this->organization->id,
            'scope_type' => PolicyDocument::SCOPE_TEAM,
            'scope_id' => $this->dirt->id,
        ]);

        $this->assertContains(
            $draft->id,
            $this->ids($this->sectionsFor($this->samUser), 'shift_lead_policy_documents'),
        );

        $this->assertNotContains(
            $draft->id,
            $this->ids($this->sectionsFor($this->veraUser), 'policy_documents'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Department lead (technical spec 9.3)
    |--------------------------------------------------------------------------
    */

    public function test_a_department_lead_receives_the_roster_schedule_and_attendance(): void
    {
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LEAD, $this->rangerLeads);

        $attendance = AttendanceRecord::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->rangers->id,
            'shift_id' => $this->shift->id,
            'staff_id' => $this->vera->id,
        ]);

        $sections = $this->sectionsFor($this->samUser);

        $this->assertContains($this->vera->id, $this->ids($sections, 'department_lead_staff'));
        $this->assertContains($this->rangers->id, $this->ids($sections, 'department_lead_departments'));
        $this->assertContains($this->shift->id, $this->ids($sections, 'department_lead_shifts'));
        $this->assertContains($attendance->id, $this->ids($sections, 'department_lead_attendance'));
        $this->assertNotEmpty($sections['department_lead_shift_assignments']);
    }

    public function test_a_demoted_department_lead_stops_receiving_the_department_roster(): void
    {
        $grant = $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LEAD, $this->rangerLeads);

        $this->assertContains(
            $this->vera->id,
            $this->ids($this->sectionsFor($this->samUser), 'department_lead_staff'),
        );

        $grant->forceFill(['revoked_at' => now()])->save();

        $this->assertArrayNotHasKey('department_lead_staff', $this->sectionsFor($this->samUser));
    }

    /**
     * Archiving the membership the grant hangs off withdraws the role, and with
     * it every section the role composed.
     */
    public function test_archiving_the_membership_a_grant_hangs_off_stops_the_sections(): void
    {
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LEAD, $this->rangerLeads);

        $this->assertArrayHasKey('department_lead_staff', $this->sectionsFor($this->samUser));

        TeamMembership::query()
            ->where('team_id', $this->rangerLeads->id)
            ->where('staff_id', $this->sam->id)
            ->update(['archived_at' => now()]);

        $this->assertArrayNotHasKey('department_lead_staff', $this->sectionsFor($this->samUser));
    }

    /**
     * A department lead maintains their department's documents. They do not
     * maintain a team's: team scope belongs to that team's designated leads
     * (`DocumentProductAccess::canMaintainScope`), and the sync rules gave a
     * department lead every team document in their department — wider than the
     * API allows, which is exactly what CLIENT-021 forbids a device to hold.
     */
    public function test_a_department_lead_holds_department_drafts_and_not_a_teams(): void
    {
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LEAD, $this->rangerLeads);

        $departmentDraft = PolicyDocument::factory()->create([
            'organization_id' => $this->organization->id,
            'scope_type' => PolicyDocument::SCOPE_DEPARTMENT,
            'scope_id' => $this->rangers->id,
        ]);

        $teamDraft = PolicyDocument::factory()->create([
            'organization_id' => $this->organization->id,
            'scope_type' => PolicyDocument::SCOPE_TEAM,
            'scope_id' => $this->dirt->id,
        ]);

        $documents = $this->ids($this->sectionsFor($this->samUser), 'department_lead_policy_documents');

        $this->assertContains($departmentDraft->id, $documents);
        $this->assertNotContains($teamDraft->id, $documents);
    }

    /*
    |--------------------------------------------------------------------------
    | Department Logistics (SLB-003 through SLB-021)
    |--------------------------------------------------------------------------
    */

    public function test_department_logistics_receives_the_desk_indexes(): void
    {
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS, $this->rangerLeads);

        $presence = EventDepartmentPresence::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->rangers->id,
            'staff_id' => $this->vera->id,
            'current_state' => EventDepartmentPresence::STATE_ON_SITE,
        ]);

        $radio = EquipmentItem::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->rangers->id,
            'event_id' => $this->event->id,
        ]);

        $checkout = EquipmentCheckout::factory()->create([
            'equipment_item_id' => $radio->id,
            'event_id' => $this->event->id,
            'staff_id' => $this->vera->id,
            'shift_id' => $this->shift->id,
            'returned_at' => null,
        ]);

        $attendance = AttendanceRecord::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->rangers->id,
            'shift_id' => $this->shift->id,
            'staff_id' => $this->vera->id,
        ]);

        $sections = $this->sectionsFor($this->samUser);

        $this->assertContains(
            $this->vera->id,
            array_column($sections['logistics_staff_index'], 'staff_id'),
        );
        $this->assertContains($presence->id, $this->ids($sections, 'logistics_presence'));
        $this->assertContains($this->shift->id, $this->ids($sections, 'logistics_shift_index'));
        $this->assertContains($attendance->id, $this->ids($sections, 'logistics_attendance'));
        $this->assertContains($radio->id, $this->ids($sections, 'logistics_equipment_index'));
        $this->assertContains($checkout->id, $this->ids($sections, 'logistics_equipment_checkouts'));
        $this->assertNotEmpty($sections['logistics_shift_assignments']);
    }

    /**
     * A returned checkout is history and an online report's business. What a
     * desk with no signal needs is what is still owed back.
     */
    public function test_a_returned_checkout_is_not_carried(): void
    {
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS, $this->rangerLeads);

        $radio = EquipmentItem::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->rangers->id,
        ]);

        $returned = EquipmentCheckout::factory()->create([
            'equipment_item_id' => $radio->id,
            'event_id' => $this->event->id,
            'staff_id' => $this->vera->id,
            'returned_at' => now(),
        ]);

        $this->assertNotContains(
            $returned->id,
            $this->ids($this->sectionsFor($this->samUser), 'logistics_equipment_checkouts'),
        );
    }

    /**
     * Scope is the boundary and it is silent (EQUIP-015): another department's
     * inventory is absent from the index rather than present and refused, so a
     * device cannot be used to discover that an asset tag exists.
     */
    public function test_another_departments_equipment_is_absent_from_the_index(): void
    {
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS, $this->rangerLeads);

        $otherDepartment = Department::factory()->for($this->organization)->create(['name' => 'Gate']);
        $theirs = EquipmentItem::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $otherDepartment->id,
        ]);

        $this->assertNotContains(
            $theirs->id,
            $this->ids($this->sectionsFor($this->samUser), 'logistics_equipment_index'),
        );
    }

    public function test_a_caller_without_the_logistics_role_receives_no_logistics_sections(): void
    {
        $sections = $this->sectionsFor($this->veraUser);

        foreach ([
            'logistics_staff_index',
            'logistics_presence',
            'logistics_shift_index',
            'logistics_attendance',
            'logistics_equipment_index',
            'logistics_equipment_checkouts',
        ] as $absent) {
            $this->assertArrayNotHasKey($absent, $sections);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Department Operations (SLB-009, SLB-010, SLB-022)
    |--------------------------------------------------------------------------
    */

    public function test_department_operations_receives_deployments_and_the_assignments_they_attach_to(): void
    {
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_OPERATIONS, $this->rangerLeads);

        $deployment = Deployment::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->rangers->id,
        ]);

        $assignment = CurrentDeploymentAssignment::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->rangers->id,
            'shift_id' => $this->shift->id,
            'staff_id' => $this->vera->id,
            'deployment_id' => $deployment->id,
        ]);

        $sections = $this->sectionsFor($this->samUser);

        $this->assertContains($deployment->id, $this->ids($sections, 'operations_deployment_options'));
        $this->assertContains($assignment->id, $this->ids($sections, 'operations_current_deployments'));
        $this->assertNotEmpty($sections['operations_shift_assignments']);
    }

    /**
     * "Capability-authorized overview module payloads only; the Operations
     * Center shell does not expand cache authority by itself" (technical spec
     * 9.3). An Operations grant caches deployments; it does not cache the
     * equipment inventory a Logistics grant would, and it caches no incident at
     * all.
     */
    public function test_the_operations_center_shell_expands_no_cache_authority(): void
    {
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_OPERATIONS, $this->rangerLeads);

        EquipmentItem::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->rangers->id,
        ]);

        $sections = $this->sectionsFor($this->samUser);

        $this->assertArrayHasKey('operations_deployment_options', $sections);
        $this->assertArrayNotHasKey('logistics_equipment_index', $sections);
        $this->assertArrayNotHasKey('logistics_staff_index', $sections);
    }

    /*
    |--------------------------------------------------------------------------
    | Department Planning (SLB-019, SLB-020)
    |--------------------------------------------------------------------------
    */

    public function test_department_planning_receives_identity_free_aggregates(): void
    {
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_PLANNING, $this->rangerLeads);

        $sections = $this->sectionsFor($this->samUser);
        $rows = $sections['planning_aggregates'];

        $this->assertNotEmpty($rows);

        $row = $rows[0];

        $this->assertSame($this->shift->id, $row['id']);
        $this->assertSame(3, $row['capacity']);
        $this->assertSame(1, $row['signed_up_or_assigned_count']);
        $this->assertArrayHasKey('planned_hours', $row);
        $this->assertArrayHasKey('variance_hours', $row);
        $this->assertArrayHasKey('status_label', $row);

        foreach ($rows as $aggregate) {
            $this->assertArrayNotHasKey('staff_id', $aggregate);
            $this->assertArrayNotHasKey('assignment_id', $aggregate);
        }

        // The rows are the shift's numbers, and nobody's name reaches them.
        $this->assertStringNotContainsString(
            (string) $this->vera->legal_name,
            json_encode($rows, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Computed rows need a freshness the stored ones do not: a stale count reads
     * exactly like a current one. It is reported in readiness rather than on the
     * rows, because a timestamp inside a section would move the set's version
     * every time it was composed and turn every refresh into a full transfer.
     */
    public function test_the_planning_aggregates_carry_freshness_metadata_outside_the_version(): void
    {
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_PLANNING, $this->rangerLeads);

        $first = $this->readSet($this->samUser);
        $freshness = $first->json('readiness.aggregate_freshness');

        $this->assertSame(['planning_aggregates'], array_column($freshness, 'section'));
        $this->assertNotNull($freshness[0]['computed_at']);

        // Composed twice, unchanged: the freshness moves and the version does
        // not, which is what keeps the 304 available to a planner on a weak
        // connection.
        $second = $this->readSet($this->samUser, headers: [
            'If-None-Match' => (string) $first->headers->get('ETag'),
        ]);

        $second->assertStatus(304);
    }

    public function test_a_caller_without_the_planning_role_receives_no_aggregates(): void
    {
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS, $this->rangerLeads);

        $this->assertArrayNotHasKey('planning_aggregates', $this->sectionsFor($this->samUser));
    }

    /*
    |--------------------------------------------------------------------------
    | The boundary every section is held to
    |--------------------------------------------------------------------------
    */

    /**
     * The M8.2 exclusions, over the whole role-scoped set. They are about what a
     * device may hold rather than about whose record it is, so a roster is held
     * to them exactly as the caller's own record is.
     */
    public function test_no_role_scoped_section_carries_an_excluded_staff_field(): void
    {
        foreach ([
            PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS,
            PermissionCatalog::ROLE_DEPARTMENT_OPERATIONS,
            PermissionCatalog::ROLE_DEPARTMENT_PLANNING,
            PermissionCatalog::ROLE_DEPARTMENT_LEAD,
        ] as $role) {
            $this->grant($role, $this->rangerLeads);
        }

        $this->grant(PermissionCatalog::ROLE_SHIFT_LEAD, $this->dirt);

        $this->vera->forceFill([
            'email' => 'vera@northwood.test',
            'phone' => '555-0100',
            'date_of_birth' => '1990-01-01',
            'emergency_contact_name' => 'Someone Else',
            'emergency_contact_phone' => '555-0199',
            'profile_picture_path' => 'staff/vera.webp',
        ])->save();

        StaffOrganizationStatus::query()
            ->where('staff_id', $this->vera->id)
            ->update(['status_reason' => 'A note the organization keeps.']);

        $body = (string) $this->readSet($this->samUser)->getContent();

        // The roster is present, so the absences below are absences rather than
        // an empty response passing by accident.
        $this->assertStringContainsString('Vera Staff', $body);

        foreach ([
            'vera@northwood.test',
            '555-0100',
            '1990-01-01',
            'Someone Else',
            '555-0199',
            'staff/vera.webp',
            'A note the organization keeps.',
            'emergency_contact',
            'date_of_birth',
            'status_reason',
            'profile_picture',
        ] as $excluded) {
            $this->assertStringNotContainsString($excluded, $body);
        }
    }

    /**
     * "Incidents should not be greedily synced" (technical spec 9.3). No role in
     * this task's scope carries one, and the Operations Center's incident module
     * composes from IC standing through its own surface rather than from this
     * set.
     */
    public function test_no_role_scoped_section_carries_an_incident(): void
    {
        foreach ([
            PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS,
            PermissionCatalog::ROLE_DEPARTMENT_OPERATIONS,
            PermissionCatalog::ROLE_DEPARTMENT_PLANNING,
            PermissionCatalog::ROLE_DEPARTMENT_LEAD,
        ] as $role) {
            $this->grant($role, $this->rangerLeads);
        }

        $sections = $this->sectionsFor($this->samUser);

        foreach (array_keys($sections) as $name) {
            $this->assertStringNotContainsString('incident', $name);
        }
    }

    /**
     * The module boundary applies to the role-additive sections exactly as it
     * applies to the regular-staff ones: an inactive module contributes nothing,
     * whatever the caller's role permits them to read (MOD-016).
     */
    public function test_an_inactive_module_takes_its_role_scoped_sections_with_it(): void
    {
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS, $this->rangerLeads);
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LEAD, $this->rangerLeads);

        EquipmentItem::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->rangers->id,
        ]);

        $this->withoutModules(ModuleKey::Equipment);

        $sections = $this->sectionsFor($this->samUser);

        $this->assertArrayNotHasKey('logistics_equipment_index', $sections);
        $this->assertArrayNotHasKey('logistics_equipment_checkouts', $sections);
        $this->assertArrayNotHasKey('department_lead_equipment_checkouts', $sections);

        // The staff index is core and survives: a department has people whether
        // or not the organization tracks radios (MOD-004).
        $this->assertArrayHasKey('logistics_staff_index', $sections);
        $this->assertArrayHasKey('logistics_shift_index', $sections);
    }

    public function test_the_version_is_stable_across_composals_of_an_unchanged_role_scoped_set(): void
    {
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS, $this->rangerLeads);

        $this->assertSame(
            $this->readSet($this->samUser)->json('version'),
            $this->readSet($this->samUser)->json('version'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The measurements M18.48 chooses a storage layer from
    |--------------------------------------------------------------------------
    */

    /**
     * The recorded size of each role-additive scope against the seeded scenario.
     *
     * ADR-0003 makes the storage decision a measurement rather than a judgement,
     * and M18.47's job is the half M18.46 could not measure: the Logistics
     * indexes are the largest sections in the set and are the ones that decide
     * whether the client needs a query engine or can filter a loaded set in
     * memory.
     *
     * Three seeded personas cover the five lists. Sam holds
     * `department_logistics`, `department_operations`, and
     * `department_planning`; Tess leads the Dirt crew, which is where the
     * shift-lead roster actually lands (Sam's `shift_lead` grant is on a leads
     * team whose members work the crew's shifts rather than being eligible for
     * them, so his shift-lead scope is correctly near-empty); Dana holds
     * `department_lead`. Sizes are reported per section and totalled per scope,
     * because "how big is the set" and "which section is big" are different
     * questions and only the second one tells M18.48 anything.
     *
     * Measured at M18.47 against the development scenario:
     *
     * ```text
     * caller  total    scope             bytes   rows   largest section
     * Sam     47,979   logistics        25,457     74   logistics_staff_index   8,551
     *                  planning          8,083     20   planning_aggregates     7,114
     *                  operations        2,603     11   operations_shift_assignments 1,063
     * Tess    18,227   shift_lead        9,769     36   shift_lead_shifts       5,671
     * Dana    34,033   department_lead  24,442     93   department_lead_shifts  6,158
     * ```
     *
     * **The Logistics indexes are the largest scope, at 25 kB and 74 rows, and
     * they are not large.** The whole set for the heaviest caller is under 50 kB
     * — smaller than one photograph — and the biggest single section is the
     * department's staff index at 8.5 kB. Nothing here needs a query engine: a
     * plain IndexedDB store loading the set and filtering in memory answers
     * every search these sections exist for, and M18.48 should choose that
     * unless a larger scenario says otherwise.
     *
     * What this does *not* measure is a large event. The seeded scenario is one
     * department of a dozen staff; a department of four hundred with four
     * hundred tracked units would multiply the staff and equipment indexes
     * roughly linearly, which puts the Logistics scope in the low megabytes
     * rather than the low kilobytes. That is the measurement to take before the
     * store is committed to at scale, and it is a measurement rather than
     * something to guess at here.
     */
    public function test_the_role_scoped_set_sizes_are_measured_against_the_seeded_scenario(): void
    {
        $this->seed([
            PermissionCatalogSeeder::class,
            DevelopmentScenarioSeeder::class,
            IncidentTypeDefaultsSeeder::class,
            TrainingAndWaiverScenarioSeeder::class,
            ShiftScenarioSeeder::class,
            EquipmentScenarioSeeder::class,
            AttendanceScenarioSeeder::class,
            DocumentScenarioSeeder::class,
            IncidentScenarioSeeder::class,
        ]);

        $measured = [];

        foreach ([
            'sam.shiftlead@northwood-collective.test',
            'tess.teamlead@northwood-collective.test',
            'dana.departmentlead@northwood-collective.test',
        ] as $email) {
            $user = User::query()->where('email', $email)->firstOrFail();
            $response = $this->readSet($user);
            $response->assertOk();

            $sections = $response->json('sections');
            $byScope = [];

            foreach ($sections as $name => $rows) {
                $scope = $this->scopeOf($name);
                $bytes = strlen(json_encode($rows, JSON_THROW_ON_ERROR));

                $byScope[$scope] ??= ['bytes' => 0, 'rows' => 0, 'sections' => []];
                $byScope[$scope]['bytes'] += $bytes;
                $byScope[$scope]['rows'] += count($rows);
                $byScope[$scope]['sections'][$name] = $bytes;
            }

            ksort($byScope);

            fwrite(STDERR, sprintf(
                "\n[M18.47] %s: %d bytes total\n",
                $email,
                strlen((string) $response->getContent()),
            ));

            foreach ($byScope as $scope => $totals) {
                fwrite(STDERR, sprintf(
                    "  %-18s %7d bytes  %4d rows  %s\n",
                    $scope,
                    $totals['bytes'],
                    $totals['rows'],
                    json_encode($totals['sections']),
                ));
            }

            $measured[$email] = $byScope;
        }

        $sam = $measured['sam.shiftlead@northwood-collective.test'];

        // Sam holds four of the five role-additive lists, so every one of them
        // is measured rather than merely reachable.
        foreach (['logistics', 'operations', 'planning', 'shift_lead'] as $scope) {
            $this->assertArrayHasKey($scope, $sam, "The {$scope} scope composed no section for a caller holding its role.");
            $this->assertGreaterThan(0, $sam[$scope]['bytes']);
        }

        $this->assertArrayHasKey(
            'department_lead',
            $measured['dana.departmentlead@northwood-collective.test'],
        );

        // Tess leads the crew itself, so her shift-lead scope carries a roster
        // and its shifts rather than the empty one a grant on a leads team
        // produces. She is the persona the shift-lead measurement is read from.
        $this->assertNotEmpty(
            $measured['tess.teamlead@northwood-collective.test']['shift_lead']['sections']['shift_lead_shifts'] ?? null,
        );

        /*
         * A ceiling rather than a target. It fails when a scope has changed size
         * by an order of magnitude, which is the moment the M18.48 storage
         * decision would need revisiting — not when a seeded shift is added.
         */
        foreach ($measured as $email => $byScope) {
            foreach ($byScope as $scope => $totals) {
                $this->assertLessThan(
                    2 * 1024 * 1024,
                    $totals['bytes'],
                    "The {$scope} scope measured {$totals['bytes']} bytes for {$email}. "
                    .'A set this size refreshes whole only on a connection an event does not have; '
                    .'revisit the M18.48 storage decision before raising this ceiling.',
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Which of technical spec 9.3's lists a section belongs to.
     */
    private function scopeOf(string $section): string
    {
        foreach ([
            'logistics_' => 'logistics',
            'operations_' => 'operations',
            'planning_' => 'planning',
            'shift_lead_' => 'shift_lead',
            'department_lead_' => 'department_lead',
        ] as $prefix => $scope) {
            if (str_starts_with($section, $prefix)) {
                return $scope;
            }
        }

        return 'regular_staff';
    }

    /**
     * @return list<string>
     */
    private function shiftLeadSectionNames(): array
    {
        return [
            'shift_lead_staff',
            'shift_lead_shifts',
            'shift_lead_shift_assignments',
            'shift_lead_team_memberships',
            'shift_lead_policy_documents',
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function readSet(User $user, array $headers = []): TestResponse
    {
        return $this->actingAsClient($user)
            ->getJson(route('api.offline-read-set'), $headers);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function sectionsFor(User $user): array
    {
        return $this->readSet($user)->json('sections');
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $sections
     * @return list<string>
     */
    private function ids(array $sections, string $section): array
    {
        return array_column($sections[$section] ?? [], 'id');
    }

    private function withoutModules(ModuleKey ...$inactive): void
    {
        $this->app->instance(ActiveModuleResolver::class, new class($inactive) extends ActiveModuleResolver
        {
            /**
             * @param  list<ModuleKey>  $inactive
             */
            public function __construct(private readonly array $inactive) {}

            public function activeFor(string $organizationId): array
            {
                return array_values(array_filter(
                    ModuleKey::cases(),
                    fn (ModuleKey $module): bool => ! in_array($module, $this->inactive, true),
                ));
            }
        });
    }

    private function grant(string $roleCode, Team $team, ?Event $event = null): TeamGrant
    {
        return TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => $event?->id,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);
    }

    /**
     * @return array{0: Staff, 1: User}
     */
    private function staffMember(string $name, Team $team, string $membershipRole): array
    {
        $staff = Staff::factory()->create(['legal_name' => $name]);
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        StaffOrganizationStatus::factory()->create([
            'organization_id' => $team->department->organization_id,
            'staff_id' => $staff->id,
        ]);

        $this->alsoBelongsTo($staff, $team, $membershipRole);

        return [$staff, $user];
    }

    private function alsoBelongsTo(Staff $staff, Team $team, string $membershipRole = 'member'): void
    {
        $team->loadMissing('department');

        StaffOrganizationStatus::query()->firstOrCreate([
            'organization_id' => $team->department->organization_id,
            'staff_id' => $staff->id,
        ], [
            'status' => 'active',
        ]);

        $departmentMembership = DepartmentMembership::query()->firstOrCreate([
            'department_id' => $team->department_id,
            'staff_id' => $staff->id,
        ], [
            'status' => DepartmentMembership::STATUS_ACTIVE,
        ]);

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
            'membership_role' => $membershipRole,
        ]);
    }
}
