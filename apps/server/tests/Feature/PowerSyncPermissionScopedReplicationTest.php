<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
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
use App\Services\Permissions\EffectiveRole;
use App\Services\Permissions\EffectiveRoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PowerSyncRules;
use Tests\TestCase;

/**
 * The replication boundary, executed (CLIENT-021, CLIENT-022; technical spec
 * 9.5, 11A.7; data/API 7.3).
 *
 * `PowerSyncDeviceCacheProjectionTest` reads the shipped sync rules as text and
 * says what they mention. This runs them: it evaluates the streams in
 * `deploy/powersync/sync-config.yaml` against real records for a real signed
 * subject and asserts which record ids that subject receives. That is the only
 * way to state the two properties the requirements ask for — that a device holds
 * nothing its user could not retrieve through the API, and that withdrawing a
 * role changes what subsequently replicates — as behavior rather than as a
 * promise about a string.
 *
 * Effective roles are the scope, so each role-scoped assertion is paired with
 * {@see EffectiveRoleResolver}: the resolver decides what the API would answer,
 * and the projection may never exceed it.
 */
class PowerSyncPermissionScopedReplicationTest extends TestCase
{
    use RefreshDatabase;

    private PowerSyncRules $rules;

    private Organization $organization;

    private Department $rangers;

    private Team $dirt;

    private Event $event;

    private Shift $shift;

    /** A designated lead of the Dirt team. */
    private Staff $sam;

    private User $samUser;

    /** An ordinary member of the Dirt team, assigned to the shift. */
    private Staff $vera;

    private User $veraUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules = PowerSyncRules::shipped();

        $this->organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $this->rangers = Department::factory()->for($this->organization)->create(['name' => 'Rangers']);
        $this->dirt = Team::factory()->for($this->rangers)->create(['name' => 'Dirt']);
        $this->event = Event::factory()->for($this->organization)->create(['name' => 'Emberfall 2026']);

        EventDepartmentAssignment::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->rangers->id,
        ]);

        $this->shift = Shift::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->rangers->id,
            'eligible_team_id' => $this->dirt->id,
        ]);

        [$this->sam, $this->samUser] = $this->staffMember('Sam Shiftlead', $this->dirt, 'lead');
        [$this->vera, $this->veraUser] = $this->staffMember('Vera Staff', $this->dirt, 'member');

        ShiftAssignment::factory()->create([
            'shift_id' => $this->shift->id,
            'staff_id' => $this->vera->id,
        ]);
    }

    public function test_a_staff_member_receives_their_own_associations(): void
    {
        $projection = $this->rules->projectionForStream('regular_staff_cache', $this->veraUser->id);

        $this->assertSame([$this->organization->id], $projection['organizations'] ?? []);
        $this->assertSame([$this->rangers->id], $projection['departments'] ?? []);
        $this->assertSame([$this->dirt->id], $projection['teams'] ?? []);
        $this->assertSame([$this->event->id], $projection['events'] ?? []);
        $this->assertSame([$this->shift->id], $projection['shifts'] ?? []);
    }

    public function test_a_staff_member_receives_nothing_belonging_to_another_organization(): void
    {
        $otherTeam = Team::factory()->create();
        [$stranger, $strangerUser] = $this->staffMember('Ira Ineligible', $otherTeam, 'member');

        $strangerShift = Shift::factory()->create([
            'department_id' => $otherTeam->department_id,
            'eligible_team_id' => $otherTeam->id,
        ]);
        ShiftAssignment::factory()->create([
            'shift_id' => $strangerShift->id,
            'staff_id' => $stranger->id,
        ]);

        $vera = $this->rules->projectionFor($this->veraUser->id);
        $stranger = $this->rules->projectionFor($strangerUser->id);

        $this->assertContains($this->shift->id, $vera['shifts']);
        $this->assertNotContains($strangerShift->id, $vera['shifts']);
        $this->assertContains($strangerShift->id, $stranger['shifts']);
        $this->assertNotContains($this->shift->id, $stranger['shifts']);
        $this->assertNotContains($this->dirt->id, $stranger['teams']);
    }

    public function test_a_designated_team_lead_receives_the_roster_their_grant_covers(): void
    {
        $this->grant(PermissionCatalog::ROLE_SHIFT_LEAD, $this->dirt);

        $this->assertSame(
            [PermissionCatalog::ROLE_SHIFT_LEAD],
            $this->effectiveRoleCodes($this->sam),
        );

        $projection = $this->rules->projectionForStream('shift_lead_cache', $this->samUser->id);

        $this->assertContains($this->vera->id, $projection['staff'] ?? []);
        $this->assertContains($this->shift->id, $projection['shifts'] ?? []);
        $this->assertNotEmpty($projection['shift_assignments'] ?? []);
    }

    /**
     * The grant belongs to the team; the authority belongs to its designated
     * leads (TEAM-009). An undesignated member holds no `shift_lead` role
     * through the API, so their device receives no shift-lead records either.
     */
    public function test_an_undesignated_member_of_a_granted_team_receives_no_shift_lead_records(): void
    {
        $this->grant(PermissionCatalog::ROLE_SHIFT_LEAD, $this->dirt);

        $this->assertSame([], $this->effectiveRoleCodes($this->vera));
        $this->assertSame([], $this->rules->projectionForStream('shift_lead_cache', $this->veraUser->id));
    }

    public function test_a_demoted_shift_lead_stops_receiving_previously_replicated_records(): void
    {
        $grant = $this->grant(PermissionCatalog::ROLE_SHIFT_LEAD, $this->dirt);

        $replicated = $this->rules->projectionForStream('shift_lead_cache', $this->samUser->id);
        $this->assertContains($this->vera->id, $replicated['staff'] ?? []);

        $grant->forceFill(['revoked_at' => now()])->save();

        $this->assertSame([], $this->effectiveRoleCodes($this->sam));
        $this->assertSame([], $this->rules->projectionForStream('shift_lead_cache', $this->samUser->id));

        // The demotion withdraws the roster, not the person's own records: they
        // are still staff, and still hold what any staff member holds.
        $this->assertSame(
            [$this->dirt->id],
            $this->rules->projectedIds('regular_staff_cache', $this->samUser->id, 'teams'),
        );
    }

    public function test_losing_the_lead_designation_stops_shift_lead_replication(): void
    {
        $this->grant(PermissionCatalog::ROLE_SHIFT_LEAD, $this->dirt);

        $this->assertContains(
            $this->vera->id,
            $this->rules->projectedIds('shift_lead_cache', $this->samUser->id, 'staff'),
        );

        TeamMembership::query()
            ->where('team_id', $this->dirt->id)
            ->where('staff_id', $this->sam->id)
            ->update(['membership_role' => 'member']);

        $this->assertSame([], $this->effectiveRoleCodes($this->sam));
        $this->assertSame([], $this->rules->projectionForStream('shift_lead_cache', $this->samUser->id));
    }

    public function test_a_demoted_department_lead_stops_receiving_the_department_roster(): void
    {
        $grant = $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LEAD, $this->dirt);

        $replicated = $this->rules->projectionForStream('department_lead_cache', $this->samUser->id);
        $this->assertContains($this->vera->id, $replicated['staff'] ?? []);
        $this->assertContains($this->rangers->id, $replicated['departments'] ?? []);

        $grant->forceFill(['revoked_at' => now()])->save();

        $this->assertSame([], $this->effectiveRoleCodes($this->sam));
        $this->assertSame([], $this->rules->projectionForStream('department_lead_cache', $this->samUser->id));
    }

    /**
     * Archiving the membership the grant hangs off withdraws the role, and with
     * it both the roster the role carried and the team the membership carried.
     */
    public function test_archiving_a_team_membership_stops_replication(): void
    {
        $this->grant(PermissionCatalog::ROLE_SHIFT_LEAD, $this->dirt);

        $this->assertNotEmpty($this->rules->projectionForStream('shift_lead_cache', $this->samUser->id));

        TeamMembership::query()
            ->where('team_id', $this->dirt->id)
            ->where('staff_id', $this->sam->id)
            ->update(['archived_at' => now()]);

        $this->assertSame([], $this->effectiveRoleCodes($this->sam));
        $this->assertSame([], $this->rules->projectionForStream('shift_lead_cache', $this->samUser->id));
        $this->assertSame([], $this->rules->projectedIds('regular_staff_cache', $this->samUser->id, 'teams'));
    }

    /**
     * A grant scoped to one event does not replicate another event's records,
     * which is the same narrowing the resolver applies when it resolves roles at
     * a context event.
     */
    public function test_an_event_scoped_grant_does_not_replicate_another_events_shifts(): void
    {
        $otherEvent = Event::factory()->for($this->organization)->create();
        $otherShift = Shift::factory()->create([
            'event_id' => $otherEvent->id,
            'department_id' => $this->rangers->id,
            'eligible_team_id' => $this->dirt->id,
        ]);

        $this->grant(PermissionCatalog::ROLE_SHIFT_LEAD, $this->dirt, $this->event);

        $shifts = $this->rules->projectedIds('shift_lead_cache', $this->samUser->id, 'shifts');

        $this->assertContains($this->shift->id, $shifts);
        $this->assertNotContains($otherShift->id, $shifts);
    }

    public function test_unpublished_documents_do_not_replicate_to_staff(): void
    {
        $published = PolicyDocument::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->id,
        ]);

        $draft = PolicyDocument::factory()->create([
            'organization_id' => $this->organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->id,
        ]);

        $documents = $this->rules->projectedIds('regular_staff_cache', $this->veraUser->id, 'policy_documents');

        $this->assertContains($published->id, $documents);
        $this->assertNotContains($draft->id, $documents);
    }

    public function test_a_login_with_no_staff_profile_replicates_nothing(): void
    {
        $user = User::factory()->create();

        foreach ($this->rules->streamNames() as $stream) {
            $this->assertSame(
                [],
                $this->rules->projectionForStream($stream, $user->id),
                "The {$stream} stream projected records to a login with no staff profile.",
            );
        }
    }

    /**
     * The rules resolve grants by role code. A code the catalog does not publish
     * would match no role, and the stream it guards would quietly stop
     * replicating — so the codes are checked against the catalog and against the
     * roles actually seeded from it.
     */
    public function test_the_rules_resolve_role_codes_the_permission_catalog_publishes(): void
    {
        $referenced = $this->rules->referencedRoleCodes();

        $this->assertNotEmpty($referenced);

        foreach ($referenced as $code) {
            $this->assertArrayHasKey($code, PermissionCatalog::roles());
            $this->assertTrue(
                PermissionRole::query()->where('code', $code)->exists(),
                "The sync rules resolve the role code {$code}, which no permission role carries.",
            );
        }
    }

    /**
     * @return list<string>
     */
    private function effectiveRoleCodes(Staff $staff): array
    {
        $codes = (new EffectiveRoleResolver)
            ->resolveForStaff($staff, $this->event)
            ->map(fn (EffectiveRole $role): string => $role->roleCode)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $codes;
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

        $departmentMembership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
            'membership_role' => $membershipRole,
        ]);

        return [$staff, $user];
    }
}
