<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CreditPolicy;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Node;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Organization configuration read and update (M18.14; ORG-017, ORG-018,
 * ORG-020, ORG-021; data/API 10.1).
 */
class OrganizationConfigurationHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organizer_reads_the_configuration_with_its_options(): void
    {
        [$organization, $organizer, $department] = $this->organizationWithOrganizer();

        $organization->forceFill([
            'prospective_inactive_threshold_years' => 1,
            'active_inactive_threshold_years' => 2,
            'calendar_year_start_month' => 3,
            'calendar_year_start_day' => 1,
        ])->save();

        $policy = CreditPolicy::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Standard credit',
            'shift_id' => null,
        ]);
        // A shift override cannot be an organization default and is not offered.
        $event = Event::factory()->for($organization)->create();
        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);
        CreditPolicy::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Shift override',
            'event_id' => $event->id,
            'shift_id' => $shift->id,
        ]);

        $response = $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/configuration")
            ->assertOk()
            ->assertJsonPath('configuration.prospective_inactive_threshold_years', 1)
            ->assertJsonPath('configuration.active_inactive_threshold_years', 2)
            ->assertJsonPath('configuration.calendar_year_start_month', 3)
            // The documented ORG-017 default, present without anyone setting it.
            ->assertJsonPath('configuration.hours_correction_grace_period_days', 14)
            ->assertJsonPath('governance.editable', true)
            ->assertJsonPath('governance.frozen_by_event', null);

        $creditPolicyIds = array_column($response->json('options.credit_policies'), 'id');
        $this->assertContains($policy->id, $creditPolicyIds);
        $this->assertCount(1, $creditPolicyIds);

        $departmentIds = array_column($response->json('options.departments'), 'id');
        $this->assertContains($department->id, $departmentIds);
    }

    public function test_an_organizer_updates_the_configuration_and_the_change_is_audited(): void
    {
        [$organization, $organizer, $department] = $this->organizationWithOrganizer();

        $policy = CreditPolicy::factory()->create([
            'organization_id' => $organization->id,
            'shift_id' => null,
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-configuration', [
                'organization_id' => $organization->id,
                'prospective_inactive_threshold_years' => 2,
                'active_inactive_threshold_years' => 4,
                'hours_correction_grace_period_days' => 21,
                'calendar_year_start_month' => 10,
                'calendar_year_start_day' => 1,
                'default_credit_policy_id' => $policy->id,
                'default_ic_department_id' => $department->id,
                'default_placement_department_id' => $department->id,
            ])
            ->assertOk()
            ->assertJsonPath('configuration.hours_correction_grace_period_days', 21)
            ->assertJsonPath('configuration.default_credit_policy_id', $policy->id);

        $organization->refresh();
        $this->assertSame(21, $organization->hours_correction_grace_period_days);
        $this->assertSame(2, $organization->prospective_inactive_threshold_years);
        $this->assertSame(4, $organization->active_inactive_threshold_years);
        $this->assertSame($department->id, (string) $organization->default_placement_department_id);

        $audit = AuditEvent::query()
            ->where('action', 'organization.configuration_updated')
            ->where('entity_id', $organization->id)
            ->first();

        $this->assertNotNull($audit);
        // Before and after cover the whole configuration, so the entry shows
        // the fields that moved against the ones that stood still.
        $this->assertSame(14, $audit->before_json['hours_correction_grace_period_days']);
        $this->assertSame(21, $audit->after_json['hours_correction_grace_period_days']);
    }

    public function test_the_update_is_partial_and_a_present_null_clears(): void
    {
        [$organization, $organizer] = $this->organizationWithOrganizer();

        $organization->forceFill([
            'prospective_inactive_threshold_years' => 1,
            'active_inactive_threshold_years' => 2,
        ])->save();

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-configuration', [
                'organization_id' => $organization->id,
                'active_inactive_threshold_years' => null,
            ])
            ->assertOk();

        $organization->refresh();
        // The absent key stood still; the present null cleared.
        $this->assertSame(1, $organization->prospective_inactive_threshold_years);
        $this->assertNull($organization->active_inactive_threshold_years);
    }

    public function test_invalid_values_are_refused_with_a_sentence(): void
    {
        [$organization, $organizer] = $this->organizationWithOrganizer();

        // The grace period is never optional (ORG-017).
        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-configuration', [
                'organization_id' => $organization->id,
                'hours_correction_grace_period_days' => null,
            ])
            ->assertStatus(422);

        // A calendar year start needs the pair: clearing only the day leaves a
        // month with no date to name.
        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-configuration', [
                'organization_id' => $organization->id,
                'calendar_year_start_month' => 6,
                'calendar_year_start_day' => null,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The calendar year start needs both a month and a day, or neither.');

        // February 29th does not exist every year and is refused.
        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-configuration', [
                'organization_id' => $organization->id,
                'calendar_year_start_month' => 2,
                'calendar_year_start_day' => 29,
            ])
            ->assertStatus(422);

        // Every refusal left the stored pair as the factory seeded it.
        $organization->refresh();
        $this->assertSame(1, $organization->calendar_year_start_month);
        $this->assertSame(1, $organization->calendar_year_start_day);
    }

    public function test_references_outside_the_organization_are_refused(): void
    {
        [$organization, $organizer] = $this->organizationWithOrganizer();
        $other = Organization::factory()->create();
        $foreignDepartment = Department::factory()->for($other)->create();
        $foreignPolicy = CreditPolicy::factory()->create([
            'organization_id' => $other->id,
            'shift_id' => null,
        ]);
        $archivedDepartment = Department::factory()->for($organization)->create([
            'archived_at' => Carbon::now(),
        ]);

        foreach ([
            ['default_ic_department_id' => $foreignDepartment->id],
            ['default_credit_policy_id' => $foreignPolicy->id],
            ['organizers_department_id' => $archivedDepartment->id],
        ] as $change) {
            $this->actingAsClient($organizer)
                ->postJson('/api/commands/update-organization-configuration', [
                    'organization_id' => $organization->id,
                    ...$change,
                ])
                ->assertStatus(422);
        }

        $this->assertNull($organization->refresh()->default_ic_department_id);
    }

    public function test_a_staff_coordinator_cannot_read_or_edit_configuration(): void
    {
        // ORG-020: only organizers and Lead Organizers. The Staff Coordinator
        // reviews applications and holds no other organizer governance
        // authority (TEAM-014), configuration included.
        [$organization] = $this->organizationWithOrganizer();
        $coordinator = $this->userWithRole('staff_coordinator', $organization);

        $this->actingAsClient($coordinator)
            ->getJson("/api/organizations/{$organization->id}/configuration")
            ->assertForbidden();

        $this->actingAsClient($coordinator)
            ->postJson('/api/commands/update-organization-configuration', [
                'organization_id' => $organization->id,
                'hours_correction_grace_period_days' => 3,
            ])
            ->assertForbidden();

        $this->assertSame(14, $organization->refresh()->hours_correction_grace_period_days);
    }

    public function test_an_organizer_of_another_organization_is_refused(): void
    {
        [$organization] = $this->organizationWithOrganizer();
        [, $outsider] = $this->organizationWithOrganizer();

        $this->actingAsClient($outsider)
            ->getJson("/api/organizations/{$organization->id}/configuration")
            ->assertForbidden();

        $this->actingAsClient($outsider)
            ->postJson('/api/commands/update-organization-configuration', [
                'organization_id' => $organization->id,
                'hours_correction_grace_period_days' => 3,
            ])
            ->assertForbidden();
    }

    public function test_configuration_edits_are_frozen_during_the_active_event_window(): void
    {
        // ORG-021: the same governance freeze that covers policies and
        // branding (BRAND-021) covers configuration.
        [$organization, $organizer] = $this->organizationWithOrganizer();

        Event::factory()->for($organization)->create([
            'active_event_window_starts_at' => now()->subDay(),
            'active_event_window_ends_at' => now()->addDay(),
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-configuration', [
                'organization_id' => $organization->id,
                'hours_correction_grace_period_days' => 30,
            ])
            ->assertStatus(409);

        $this->assertSame(14, $organization->refresh()->hours_correction_grace_period_days);

        // The read stays open and says why edits are not.
        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/configuration")
            ->assertOk()
            ->assertJsonPath('governance.editable', false)
            ->assertJsonPath('governance.frozen_by_event.name', fn (?string $name): bool => $name !== null);
    }

    public function test_configuration_edits_are_refused_on_an_onsite_node(): void
    {
        // ORG-021: central is authoritative for organization configuration.
        [$organization, $organizer] = $this->organizationWithOrganizer();

        Node::factory()->create([
            'node_role' => Node::ROLE_ONSITE,
            'is_local' => true,
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-configuration', [
                'organization_id' => $organization->id,
                'hours_correction_grace_period_days' => 30,
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'central node'));

        $this->assertSame(14, $organization->refresh()->hours_correction_grace_period_days);
    }

    public function test_unauthenticated_requests_are_refused(): void
    {
        $organization = Organization::factory()->create();

        $this->getJson("/api/organizations/{$organization->id}/configuration")->assertUnauthorized();
        $this->postJson('/api/commands/update-organization-configuration', [
            'organization_id' => $organization->id,
            'hours_correction_grace_period_days' => 3,
        ])->assertUnauthorized();
    }

    /**
     * @return array{0: Organization, 1: User, 2: Department}
     */
    private function organizationWithOrganizer(): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $organization->forceFill(['organizers_department_id' => $department->id])->save();

        return [$organization, $this->userWithRole('organizer', $organization, $department), $department];
    }

    private function userWithRole(
        string $roleCode,
        Organization $organization,
        ?Department $department = null,
    ): User {
        $department ??= Department::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        return $user;
    }
}
