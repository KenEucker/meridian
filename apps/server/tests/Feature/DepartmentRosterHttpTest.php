<?php

namespace Tests\Feature;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `department.roster` — the department staff list (M18.30; UI contract 12.4;
 * VOL-011, VOL-012).
 */
class DepartmentRosterHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_department_lead_reads_the_whole_department_with_emergency_contacts(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['lead'])
            ->getJson($this->path($scenario))
            ->assertOk();

        $response->assertJsonPath('context.department_label', 'Rangers');
        $response->assertJsonPath('context.participates_in_event', true);
        $response->assertJsonPath('access.whole_department', true);
        $response->assertJsonPath('access.emergency_contacts', true);

        $members = collect($response->json('members'));

        // Every active membership of the department, including the two role
        // holders, who are members of it too.
        $this->assertTrue($members->pluck('staff_id')->contains((string) $scenario['gate']->id));
        $this->assertTrue($members->pluck('staff_id')->contains((string) $scenario['patrol']->id));

        $gate = $members->firstWhere('staff_id', (string) $scenario['gate']->id);
        $this->assertSame('Gwen Gate', $gate['display_name']);
        $this->assertSame('555-0100', $gate['phone']);
        $this->assertSame('Casey Gate', $gate['emergency_contact_name']);
        $this->assertSame('555-0199', $gate['emergency_contact_phone']);
        $this->assertSame(DepartmentMembership::STATUS_ACTIVE, $gate['membership_status']);
        $this->assertSame(StaffOrganizationStatus::STATUS_ACTIVE, $gate['organization_status']);
        $this->assertSame(['Gate Team'], collect($gate['teams'])->pluck('name')->all());
    }

    /**
     * VOL-012 and VOL-011 together: a department lead reaches emergency
     * contacts for staff in their department, and nobody else reaches them
     * here at all.
     *
     * The two columns are absent from the payload rather than served empty. A
     * blank emergency contact has to keep meaning "none recorded" to the lead
     * reading it, which is the same call M13.3 made for the export file.
     */
    public function test_department_leads_reach_emergency_contacts_for_their_own_department_only(): void
    {
        $scenario = $this->scenario();

        // Their own department: present.
        $own = $this->actingAsClient($scenario['lead'])
            ->getJson($this->path($scenario))
            ->assertOk();

        $this->assertArrayHasKey('emergency_contact_phone', $own->json('members.0'));

        // Another department of the same organization, which they lead nothing
        // in: refused outright rather than served without the two columns.
        $this->actingAsClient($scenario['lead'])
            ->getJson(sprintf(
                '/api/events/%s/departments/%s/roster',
                $scenario['event']->id,
                $scenario['otherDepartment']->id,
            ))
            ->assertForbidden();

        // Planning reads the same department and does not reach them (VOL-012
        // names department leads).
        $planning = $this->actingAsClient($scenario['planning'])
            ->getJson($this->path($scenario))
            ->assertOk();

        $planning->assertJsonPath('access.whole_department', true);
        $planning->assertJsonPath('access.emergency_contacts', false);
        $this->assertArrayNotHasKey('emergency_contact_phone', $planning->json('members.0'));

        // Neither does a team lead, who reaches a narrower list.
        $teamLead = $this->actingAsClient($scenario['teamLead'])
            ->getJson($this->path($scenario))
            ->assertOk();

        $teamLead->assertJsonPath('access.emergency_contacts', false);
        $this->assertArrayNotHasKey('emergency_contact_phone', $teamLead->json('members.0'));

        // And neither does an organizer of the same organization, who holds no
        // department role here and so reaches no roster through this surface
        // (VOL-011).
        $this->actingAsClient($scenario['organizer'])
            ->getJson($this->path($scenario))
            ->assertForbidden();
    }

    public function test_a_team_lead_reads_only_the_teams_they_lead(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['teamLead'])
            ->getJson($this->path($scenario))
            ->assertOk();

        $response->assertJsonPath('access.whole_department', false);
        $response->assertJsonPath('access.led_team_ids', [(string) $scenario['gateTeam']->id]);

        $staffIds = collect($response->json('members'))->pluck('staff_id');
        $this->assertTrue($staffIds->contains((string) $scenario['gate']->id));
        $this->assertFalse($staffIds->contains((string) $scenario['patrol']->id));

        // The filter offers nothing the list behind it would refuse.
        $this->assertSame(
            [(string) $scenario['gateTeam']->id],
            collect($response->json('teams'))->pluck('id')->all(),
        );
    }

    public function test_a_department_member_with_no_lead_standing_is_refused(): void
    {
        $scenario = $this->scenario();

        $member = User::factory()->create();
        $member->staffProfiles()->attach($scenario['gate']->id);

        $this->actingAsClient($member)
            ->getJson($this->path($scenario))
            ->assertForbidden();
    }

    public function test_a_department_of_another_organization_is_not_found(): void
    {
        $scenario = $this->scenario();

        $foreign = Department::factory()
            ->for(Organization::factory()->create())
            ->create(['name' => 'Elsewhere']);

        $this->actingAsClient($scenario['lead'])
            ->getJson(sprintf(
                '/api/events/%s/departments/%s/roster',
                $scenario['event']->id,
                $foreign->id,
            ))
            ->assertNotFound();
    }

    /**
     * Non-active memberships stay on the list, statuses and all. A lead has to
     * be able to see that somebody in their department is Inactive rather than
     * wonder why they are missing.
     */
    public function test_a_non_active_membership_is_listed_with_its_status(): void
    {
        $scenario = $this->scenario();

        $benched = Staff::factory()->create([
            'legal_name' => 'Bo Benched',
            'preferred_name' => null,
            'handle' => 'bo',
        ]);
        DepartmentMembership::factory()
            ->for($scenario['department'])
            ->for($benched)
            ->create(['status' => DepartmentMembership::STATUS_INACTIVE]);

        $response = $this->actingAsClient($scenario['lead'])
            ->getJson($this->path($scenario))
            ->assertOk();

        $row = collect($response->json('members'))
            ->firstWhere('staff_id', (string) $benched->id);

        $this->assertNotNull($row);
        $this->assertSame(DepartmentMembership::STATUS_INACTIVE, $row['membership_status']);
    }

    private function path(array $scenario): string
    {
        return sprintf(
            '/api/events/%s/departments/%s/roster',
            $scenario['event']->id,
            $scenario['department']->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create(['name' => 'Emberfall 2027']);
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $otherDepartment = Department::factory()->for($organization)->create(['name' => 'Medical']);

        EventDepartmentAssignment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);

        $gateTeam = Team::factory()->for($department)->create(['name' => 'Gate Team']);
        $patrolTeam = Team::factory()->for($department)->create(['name' => 'Patrol Team']);

        $gate = $this->member($department, $gateTeam, [
            'legal_name' => 'Gwen Gate',
            'preferred_name' => null,
            'handle' => 'gwen',
            'phone' => '555-0100',
            'emergency_contact_name' => 'Casey Gate',
            'emergency_contact_phone' => '555-0199',
        ]);

        $patrol = $this->member($department, $patrolTeam, [
            'legal_name' => 'Pat Patrol',
            'preferred_name' => null,
            'handle' => 'pat',
            'phone' => '555-0200',
            'emergency_contact_name' => 'Robin Patrol',
            'emergency_contact_phone' => '555-0299',
        ]);

        foreach ([$gate, $patrol] as $staff) {
            StaffOrganizationStatus::factory()->create([
                'organization_id' => $organization->id,
                'staff_id' => $staff->id,
                'status' => StaffOrganizationStatus::STATUS_ACTIVE,
            ]);
        }

        $organizerDepartment = Department::factory()
            ->for($organization)
            ->create(['name' => 'Organizers']);
        $organizerTeam = Team::factory()->for($organizerDepartment)->create(['name' => 'Organizers']);

        return [
            'organization' => $organization,
            'event' => $event,
            'department' => $department,
            'otherDepartment' => $otherDepartment,
            'gateTeam' => $gateTeam,
            'patrolTeam' => $patrolTeam,
            'gate' => $gate,
            'patrol' => $patrol,
            // One team per role holder: a grant belongs to a team, so putting
            // two of these on one team would give each of them both roles.
            'lead' => $this->userWithRole(
                Team::factory()->for($department)->create(['name' => 'Leadership']),
                'department_lead',
            ),
            'planning' => $this->userWithRole(
                Team::factory()->for($department)->create(['name' => 'Planning']),
                'department_planning',
            ),
            'teamLead' => $this->userWithRole($gateTeam, 'shift_lead', membershipRole: 'lead'),
            'organizer' => $this->userWithRole($organizerTeam, 'organizer'),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function member(Department $department, Team $team, array $attributes): Staff
    {
        $staff = Staff::factory()->create($attributes);
        $membership = DepartmentMembership::factory()->for($department)->for($staff)->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        return $staff;
    }

    private function userWithRole(Team $team, string $roleCode, ?string $membershipRole = null): User
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);
        $membership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
            'membership_role' => $membershipRole ?? 'member',
        ]);
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        return $user;
    }
}
