<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Modules\ModuleKey;
use App\Domain\Modules\SyncedTable;
use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use App\Models\OrganizationModule;
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
use App\Services\Offline\DepartmentLeadSections;
use App\Services\Offline\DepartmentLogisticsSections;
use App\Services\Offline\DepartmentOperationsSections;
use App\Services\Offline\DepartmentPlanningSections;
use App\Services\Offline\DirectorySections;
use App\Services\Offline\OfflineReadSetScopeResolver;
use App\Services\Offline\OfflineReadSetSection;
use App\Services\Offline\OfflineReadSetTableOwnership;
use App\Services\Offline\RegularStaffSections;
use App\Services\Offline\ShiftLeadSections;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Module-scoped replication (MOD-016; technical spec 9.5, 11A.7, 15A.5;
 * data/API 7.6; M19.17).
 *
 * The composer has carried the module boundary since M18.46, but it carried it
 * against a resolver the tests handed an answer to, because `organization_modules`
 * did not exist yet. It does now (M19.11), and everything here drives the real
 * table: a module is switched off the way an organizer or God Mode switches it
 * off, and the next set the device is handed is what the assertions read.
 *
 * Three properties, and they are the requirement's own words.
 *
 * **Permission is not enough.** A device does not hold records belonging to a
 * module the organization does not run, *regardless of what its user is
 * permitted to read* — so the caller here holds every role in the product and
 * still receives nothing from an inactive module.
 *
 * **Deactivation is not deletion.** Activating a module replicates its permitted
 * records back, unchanged, and the content-addressed version returns to what it
 * was: the records were withheld from the set, never removed from the
 * organization (MOD-022).
 *
 * **The boundary is declared at the grain of the table.** Data/API 7.6 asks
 * every synced table to declare its owning module or declare itself core, and
 * {@see SyncedTable} is that declaration. The coverage assertions below are what
 * keep it in agreement with the sections the read set actually composes — in
 * particular that no section is owned *more widely* than the table it projects,
 * which is the direction that leaks records onto a device.
 */
class ModuleScopedReplicationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Department $rangers;

    /** The crew team Sam is the designated lead of. */
    private Team $dirt;

    /** The authority team the department-wide grants hang on. */
    private Team $rangerLeads;

    private Event $event;

    private Shift $shift;

    /** Holds every role in the product, so permission is never what is missing. */
    private User $samUser;

    private Staff $vera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionCatalogSeeder::class);

        $this->organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $this->rangers = Department::factory()->for($this->organization)->create(['name' => 'Rangers']);
        $this->dirt = Team::factory()->for($this->rangers)->create(['name' => 'Dirt']);
        $this->rangerLeads = Team::factory()->for($this->rangers)->create(['name' => 'Ranger Leads']);
        $this->event = Event::factory()->for($this->organization)->create(['name' => 'Emberfall 2026']);

        EventDepartmentAssignment::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->rangers->id,
        ]);

        // Inside the desk horizon, so the Logistics and Operations indexes reach
        // it rather than the sections being empty for a reason of their own.
        $this->shift = Shift::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->rangers->id,
            'eligible_team_id' => $this->dirt->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(4),
            'capacity' => 3,
        ]);

        [$sam, $this->samUser] = $this->staffMember('Sam Shiftlead', $this->dirt, 'lead');
        [$this->vera] = $this->staffMember('Vera Staff', $this->dirt, 'member');

        $this->alsoBelongsTo($sam, $this->rangerLeads);

        ShiftAssignment::factory()->create([
            'shift_id' => $this->shift->id,
            'staff_id' => $this->vera->id,
        ]);

        $this->grant(PermissionCatalog::ROLE_SHIFT_LEAD, $this->dirt);

        foreach ([
            PermissionCatalog::ROLE_DEPARTMENT_LEAD,
            PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS,
            PermissionCatalog::ROLE_DEPARTMENT_OPERATIONS,
            PermissionCatalog::ROLE_DEPARTMENT_PLANNING,
        ] as $role) {
            $this->grant($role, $this->rangerLeads);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | MOD-016: the module boundary on real state
    |--------------------------------------------------------------------------
    */

    /**
     * The requirement's own sentence: a device shall not hold records belonging
     * to a module that is inactive for the organization. The caller here is a
     * shift lead, a department lead, and the whole department operations desk,
     * so every one of these sections is one they are permitted to read — and
     * none of them arrives.
     */
    public function test_an_inactive_modules_records_do_not_replicate_to_a_permitted_user(): void
    {
        $this->assertContains(
            $this->shift->id,
            $this->ids($this->sectionsFor($this->samUser), 'department_lead_shifts'),
            'The fixture should replicate the shift before Scheduling is switched off.',
        );

        $this->deactivate(ModuleKey::Scheduling);

        $sections = $this->sectionsFor($this->samUser);

        foreach ([
            'shifts',
            'shift_assignments',
            'shift_lead_shifts',
            'shift_lead_shift_assignments',
            'department_lead_shifts',
            'department_lead_shift_assignments',
            'department_lead_attendance',
            'logistics_shift_index',
            'logistics_shift_assignments',
            'logistics_attendance',
            'logistics_future_signups',
            'operations_shift_assignments',
            'planning_aggregates',
            'planning_teams',
        ] as $absent) {
            $this->assertArrayNotHasKey(
                $absent,
                $sections,
                "The {$absent} section reached a device whose organization does not run Scheduling.",
            );
        }

        // Not one shift id survives anywhere in the set, whatever the section is
        // called: absence is about the records, not about the section names.
        $this->assertStringNotContainsString(
            (string) $this->shift->id,
            (string) json_encode($sections),
        );

        /*
         * Core is untouched (MOD-004). An organization that does not schedule
         * still has departments, teams, people, and an event, and its desks
         * still know who is on site.
         */
        foreach ([
            'organizations',
            'departments',
            'teams',
            'staff',
            'events',
            'logistics_staff_index',
            'logistics_presence',
            'department_lead_staff',
        ] as $core) {
            $this->assertArrayHasKey($core, $sections);
        }
    }

    /**
     * The set reports the boundary it was composed under, so a device that lost
     * a section overnight can say which of the two boundaries took it.
     */
    public function test_the_set_reports_the_active_modules_it_was_composed_under(): void
    {
        $this->deactivate(ModuleKey::Scheduling);

        $modules = $this->readSet($this->samUser)->json('readiness.active_modules');

        $this->assertNotContains(ModuleKey::Scheduling->value, $modules);
        $this->assertContains(ModuleKey::Documents->value, $modules);
    }

    /**
     * Activating a module replicates its permitted records back. The version is
     * over the content, so returning to the version the set had before the
     * module was switched off is the strongest available statement that nothing
     * about the records changed while they were withheld (MOD-022).
     */
    public function test_reactivating_a_module_replicates_its_records_back(): void
    {
        $before = $this->readSet($this->samUser)->json('version');

        $this->deactivate(ModuleKey::Scheduling);

        $withoutScheduling = $this->readSet($this->samUser);
        $this->assertArrayNotHasKey('department_lead_shifts', $withoutScheduling->json('sections'));
        $this->assertNotSame($before, $withoutScheduling->json('version'));

        $this->activate(ModuleKey::Scheduling);

        $restored = $this->readSet($this->samUser);

        $this->assertContains(
            $this->shift->id,
            $this->ids($restored->json('sections'), 'department_lead_shifts'),
        );
        $this->assertSame($before, $restored->json('version'));
    }

    /**
     * Active is both halves and either one alone withholds the records
     * (MOD-005). A revoked entitlement takes them even though the organization
     * still has the module enabled — and enabling it again while entitlement is
     * still revoked does not bring them back.
     */
    public function test_a_revoked_entitlement_withholds_records_the_organization_still_enables(): void
    {
        $published = PolicyDocument::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->id,
        ]);

        $this->assertContains(
            $published->id,
            $this->ids($this->sectionsFor($this->samUser), 'policy_documents'),
        );

        $this->stateFor(ModuleKey::Documents, entitled: false, enabled: true);

        $this->assertArrayNotHasKey('policy_documents', $this->sectionsFor($this->samUser));
    }

    /**
     * Module state belongs to one organization. A staff member standing in two
     * of them holds each organization's records under that organization's own
     * configuration, so switching a module off in one is not a way to take the
     * other's records off the same device.
     */
    public function test_a_module_inactive_in_one_organization_leaves_anothers_records_alone(): void
    {
        $otherOrganization = Organization::factory()->create(['name' => 'Southreach Assembly']);
        $otherDepartment = Department::factory()->for($otherOrganization)->create();
        $otherTeam = Team::factory()->for($otherDepartment)->create();
        $otherEvent = Event::factory()->for($otherOrganization)->create();

        EventDepartmentAssignment::factory()->create([
            'event_id' => $otherEvent->id,
            'department_id' => $otherDepartment->id,
        ]);

        $sam = $this->samUser->staffProfiles()->firstOrFail();
        $this->alsoBelongsTo($sam, $otherTeam);

        $mine = PolicyDocument::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->id,
        ]);

        $theirs = PolicyDocument::factory()->published()->create([
            'organization_id' => $otherOrganization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $otherOrganization->id,
        ]);

        $this->deactivate(ModuleKey::Documents, $otherOrganization);

        $documents = $this->ids($this->sectionsFor($this->samUser), 'policy_documents');

        $this->assertContains($mine->id, $documents);
        $this->assertNotContains($theirs->id, $documents);
    }

    /*
    |--------------------------------------------------------------------------
    | Data/API 7.6: every synced table declares its owning module
    |--------------------------------------------------------------------------
    */

    public function test_every_declared_synced_table_exists_in_the_schema(): void
    {
        foreach (SyncedTable::cases() as $table) {
            $this->assertTrue(
                Schema::hasTable($table->value),
                "`{$table->value}` is declared as a synced table and does not exist.",
            );
        }
    }

    /**
     * Data/API 7.6's default, stated as a test rather than as a comment: a table
     * with no declaration is core, so an undeclared table keeps replicating
     * rather than silently disappearing from devices. `audit_events` stands in
     * for every table that never leaves the node — it is not synced at all, and
     * the point is that asking about it yields "core" rather than an error.
     */
    public function test_a_table_with_no_declaration_is_core(): void
    {
        $this->assertNull(SyncedTable::ownerOf('audit_events'));
        $this->assertNull(SyncedTable::ownerOf('a_table_this_build_has_never_heard_of'));
    }

    /**
     * A section that reaches a device without a declaration is a table nobody
     * decided about, which is how a module-owned record ends up replicating to
     * an organization that does not run the module.
     */
    public function test_every_replicated_section_declares_a_synced_table(): void
    {
        foreach ($this->composedSections() as $section) {
            $this->assertNotNull(
                OfflineReadSetTableOwnership::tableFor($section->name),
                "The {$section->name} section replicates and declares no synced table.",
            );
        }
    }

    /**
     * The rule the two declarations are held to: **a section may be owned more
     * narrowly than the table it projects, never more widely.**
     *
     * Narrower is legitimate — the desks' attendance lists are attendance for
     * shifts and belong to Scheduling even though `attendance_records` is core.
     * Wider is a records leak: a section carrying an inactive module's rows
     * while declaring itself core would put them on the device anyway, and that
     * is invisible in review because the section reads perfectly well alone.
     */
    public function test_no_section_is_owned_more_widely_than_the_table_it_projects(): void
    {
        foreach ($this->composedSections() as $section) {
            $table = OfflineReadSetTableOwnership::tableFor($section->name);

            if ($table === null) {
                continue;
            }

            $tableModule = $table->module();

            if ($tableModule === null) {
                // A core table may be projected as core or narrowed by a
                // section that is about less than the whole table.
                continue;
            }

            $this->assertSame(
                $tableModule,
                $section->module,
                "The {$section->name} section projects `{$table->value}`, which "
                ."{$tableModule->label()} owns, and declares a different owner.",
            );
        }
    }

    /**
     * No dead entries. A declaration for a section the read set stopped
     * composing is a claim about what a device holds that nothing backs, and it
     * would keep a table's ownership looking settled after the section that
     * carried it was removed.
     */
    public function test_every_declared_section_is_one_the_read_set_composes(): void
    {
        $composed = array_map(
            static fn (OfflineReadSetSection $section): string => $section->name,
            $this->composedSections(),
        );

        foreach (array_keys(OfflineReadSetTableOwnership::all()) as $declared) {
            $this->assertContains(
                $declared,
                $composed,
                "The {$declared} section is declared and is not composed by the read set.",
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    /**
     * Every section this build can compose, for a caller holding every role.
     *
     * Read from the contributors rather than from the endpoint because the
     * question is about each section's declared module, and the wire format
     * carries rows alone. The contributors are the same instances the composer
     * uses; nothing here re-implements the composition.
     *
     * @return list<OfflineReadSetSection>
     */
    private function composedSections(): array
    {
        $scope = app(OfflineReadSetScopeResolver::class)->resolve($this->samUser);

        $sections = [];

        foreach ([
            RegularStaffSections::class,
            DepartmentLogisticsSections::class,
            DepartmentOperationsSections::class,
            DepartmentPlanningSections::class,
            ShiftLeadSections::class,
            DepartmentLeadSections::class,
            DirectorySections::class,
        ] as $contributor) {
            $sections = [...$sections, ...app($contributor)->sectionsFor($scope)];
        }

        return $sections;
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

    /**
     * Switch a module off the way the organizer surface does: entitled, and not
     * enabled (MOD-008).
     */
    private function deactivate(ModuleKey $module, ?Organization $organization = null): void
    {
        $this->stateFor($module, entitled: true, enabled: false, organization: $organization);
    }

    private function activate(ModuleKey $module, ?Organization $organization = null): void
    {
        $this->stateFor($module, entitled: true, enabled: true, organization: $organization);
    }

    private function stateFor(
        ModuleKey $module,
        bool $entitled,
        bool $enabled,
        ?Organization $organization = null,
    ): void {
        OrganizationModule::query()->updateOrCreate(
            [
                'organization_id' => ($organization ?? $this->organization)->id,
                'module_key' => $module->value,
            ],
            [
                'entitled' => $entitled,
                'enabled' => $enabled,
            ],
        );
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
