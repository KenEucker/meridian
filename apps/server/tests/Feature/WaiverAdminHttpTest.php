<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\DocumentFragment;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\PolicyDocument;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Credential\CredentialEligibilityService;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Shift\ShiftRequirementService;
use App\Services\Shift\ShiftSignupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Waiver administration (M18.18; WAIVER-001 through WAIVER-006, WAIVER-010).
 *
 * Authority follows the scope of the waiver, matching the policy/procedure
 * maintenance rule: organization-scoped waivers by organizers,
 * department-scoped by department leads, team-scoped by team leads.
 */
class WaiverAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organizer_lists_only_the_scopes_they_maintain(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);

        Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'name' => 'General Liability Waiver',
        ]);
        Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'name' => 'Gate Safety Waiver',
        ]);
        Waiver::factory()->for($otherOrganization)->create([
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $otherOrganization->id,
            'name' => 'Other Organization Waiver',
        ]);

        PolicyDocument::factory()->for($organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'title' => 'Liability Policy',
        ]);
        PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'title' => 'Draft Policy',
        ]);

        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/waivers")
            ->assertOk()
            // The organizer maintains organization scope; the department
            // waiver answers to the department lead, not to them.
            ->assertJsonCount(1, 'waivers')
            ->assertJsonPath('waivers.0.name', 'General Liability Waiver')
            ->assertJsonMissing(['name' => 'Gate Safety Waiver'])
            ->assertJsonMissing(['name' => 'Other Organization Waiver'])
            // The create form offers published documents only (WAIVER-007).
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.title', 'Liability Policy');
    }

    public function test_a_user_with_no_maintainable_scope_is_refused(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        $this->actingAsClient($user)
            ->getJson("/api/organizations/{$organization->id}/waivers")
            ->assertForbidden();
    }

    public function test_an_organizer_creates_a_document_backed_organization_waiver(): void
    {
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);
        $document = PolicyDocument::factory()->for($organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'title' => 'Liability Policy',
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/create-waiver', [
                'organization_id' => $organization->id,
                'scope_type' => Waiver::SCOPE_ORGANIZATION,
                'scope_id' => $organization->id,
                'name' => 'General Liability Waiver',
                'expires_after_days' => 365,
                'document_type' => 'policy',
                'document_id' => $document->id,
            ])
            ->assertCreated()
            ->assertJsonPath('waiver.name', 'General Liability Waiver')
            ->assertJsonPath('waiver.expires_after_days', 365)
            ->assertJsonPath('waiver.document.title', 'Liability Policy');

        $this->assertNotNull(
            AuditEvent::query()->where('action', 'waiver.created')->first(),
        );
    }

    public function test_a_department_lead_cannot_create_an_organization_scoped_waiver(): void
    {
        $organization = Organization::factory()->create();
        [$lead] = $this->departmentLeadFor($organization);

        $this->actingAsClient($lead)
            ->postJson('/api/commands/create-waiver', [
                'organization_id' => $organization->id,
                'scope_type' => Waiver::SCOPE_ORGANIZATION,
                'scope_id' => $organization->id,
                'name' => 'Reaching Past Scope',
            ])
            ->assertForbidden();
    }

    public function test_a_department_lead_creates_a_department_scoped_waiver(): void
    {
        $organization = Organization::factory()->create();
        [$lead, $department] = $this->departmentLeadFor($organization);

        $this->actingAsClient($lead)
            ->postJson('/api/commands/create-waiver', [
                'organization_id' => $organization->id,
                'scope_type' => Waiver::SCOPE_DEPARTMENT,
                'scope_id' => $department->id,
                'name' => 'Department Safety Waiver',
            ])
            ->assertCreated()
            ->assertJsonPath('waiver.scope_type', 'department');
    }

    public function test_a_team_lead_maintains_only_their_own_team_scope(): void
    {
        $organization = Organization::factory()->create();
        [$teamLead, $team, $department] = $this->teamLeadFor($organization);
        $otherTeam = Team::factory()->for($department)->create(['name' => 'Other Team']);

        $this->actingAsClient($teamLead)
            ->postJson('/api/commands/create-waiver', [
                'organization_id' => $organization->id,
                'scope_type' => Waiver::SCOPE_TEAM,
                'scope_id' => $team->id,
                'name' => 'Team Equipment Waiver',
            ])
            ->assertCreated();

        $this->actingAsClient($teamLead)
            ->postJson('/api/commands/create-waiver', [
                'organization_id' => $organization->id,
                'scope_type' => Waiver::SCOPE_TEAM,
                'scope_id' => $otherTeam->id,
                'name' => 'Someone Else\'s Team Waiver',
            ])
            ->assertForbidden();

        // The same boundary holds for maintaining an existing waiver.
        $otherTeamWaiver = Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_TEAM,
            'scope_id' => $otherTeam->id,
            'name' => 'Unreachable Waiver',
        ]);

        $this->actingAsClient($teamLead)
            ->postJson('/api/commands/update-waiver', [
                'waiver_id' => $otherTeamWaiver->id,
                'name' => 'Renamed From Outside',
            ])
            ->assertForbidden();
    }

    public function test_an_organizer_updates_archives_and_restores_a_waiver(): void
    {
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);
        $waiver = Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'name' => 'General Liability Waiver',
            'expires_after_days' => null,
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-waiver', [
                'waiver_id' => $waiver->id,
                'name' => 'Annual Liability Waiver',
                'expires_after_days' => 365,
            ])
            ->assertOk()
            ->assertJsonPath('waiver.name', 'Annual Liability Waiver')
            ->assertJsonPath('waiver.expires_after_days', 365);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/archive-waiver', ['waiver_id' => $waiver->id])
            ->assertOk()
            ->assertJsonPath('waiver.archived', true);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/restore-waiver', ['waiver_id' => $waiver->id])
            ->assertOk()
            ->assertJsonPath('waiver.archived', false);

        foreach (['waiver.updated', 'waiver.archived', 'waiver.restored'] as $action) {
            $this->assertNotNull(
                AuditEvent::query()->where('action', $action)->first(),
                "expected audit action {$action}",
            );
        }
    }

    public function test_recording_a_completion_requires_the_staff_member_to_be_in_scope(): void
    {
        $organization = Organization::factory()->create();
        [$lead, $department] = $this->departmentLeadFor($organization);
        $waiver = Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'name' => 'Department Safety Waiver',
        ]);
        $outsider = Staff::factory()->create();

        $this->actingAsClient($lead)
            ->postJson('/api/commands/record-waiver-completion', [
                'waiver_id' => $waiver->id,
                'staff_id' => $outsider->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This waiver does not apply to that staff member.');
    }

    public function test_a_department_lead_records_a_completion_and_the_roster_shows_it(): void
    {
        $organization = Organization::factory()->create();
        [$lead, $department] = $this->departmentLeadFor($organization);
        $waiver = Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'name' => 'Department Safety Waiver',
            'expires_after_days' => 30,
        ]);
        $member = Staff::factory()->create(['preferred_name' => 'Alex Doe']);
        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($member, $department);

        $this->actingAsClient($lead)
            ->postJson('/api/commands/record-waiver-completion', [
                'waiver_id' => $waiver->id,
                'staff_id' => $member->id,
            ])
            ->assertCreated();

        $response = $this->actingAsClient($lead)
            ->getJson("/api/organizations/{$organization->id}/waivers/{$waiver->id}")
            ->assertOk()
            ->json();

        $row = collect($response['roster'])
            ->firstWhere('staff_id', (string) $member->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['complete']);
        $this->assertFalse($row['lapsed']);
        $this->assertNotNull($row['expires_at']);

        $this->assertNotNull(
            AuditEvent::query()->where('action', 'waiver_completion.recorded')->first(),
        );
    }

    public function test_the_detail_read_renders_a_document_backed_waiver_inline(): void
    {
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);
        DocumentFragment::factory()->for($organization)->create([
            'slug' => 'waiver-risks',
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => 'You acknowledge the **inherent risks** of event work.',
        ]);
        $document = PolicyDocument::factory()->for($organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'title' => 'Event Liability Policy',
            'markdown_source' => "# Liability\n\n{{fragment:waiver-risks}}",
        ]);
        $waiver = Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'name' => 'Liability Waiver',
            'document_type' => 'policy',
            'document_id' => $document->id,
        ]);

        $response = $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/waivers/{$waiver->id}")
            ->assertOk()
            ->assertJsonPath('rendered_document.title', 'Event Liability Policy')
            ->json();

        // POL-022: fragment text is document text at the point of completion.
        $this->assertStringContainsString(
            '<strong>inherent risks</strong>',
            $response['rendered_document']['rendered_html'],
        );
    }

    public function test_an_archived_waiver_refuses_new_completions(): void
    {
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);
        $waiver = Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'name' => 'Retired Waiver',
            'archived_at' => Carbon::now()->subDay(),
        ]);
        $staff = Staff::factory()->create();
        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/record-waiver-completion', [
                'waiver_id' => $waiver->id,
                'staff_id' => $staff->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This waiver is archived and no longer accepts completions.');
    }

    public function test_an_expired_waiver_blocks_credential_eligibility(): void
    {
        // WAIVER-006 through the administration path: the completion is
        // recorded through the command with a completion date old enough that
        // the waiver's expiry window has already closed.
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $staff = Staff::factory()->create(['date_of_birth' => '1990-01-15']);
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);
        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);
        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);
        $department->load('defaultTeam');

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'title' => 'Gate Lead',
            'signup_opens_at' => null,
            'signup_closes_at' => null,
        ]);

        $waiver = Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'name' => 'Annual Liability Waiver',
            'expires_after_days' => 30,
        ]);
        app(ShiftRequirementService::class)->addWaiverRequirement($shift, $waiver);

        // Current completion first, so signup succeeds.
        $this->actingAsClient($organizer)
            ->postJson('/api/commands/record-waiver-completion', [
                'waiver_id' => $waiver->id,
                'staff_id' => $staff->id,
            ])
            ->assertCreated();

        app(ShiftSignupService::class)->signUp($shift->refresh(), $staff, $user);

        // The waiver then lapses before the event.
        $waiver->completions()
            ->where('staff_id', $staff->id)
            ->update(['expires_at' => Carbon::now()->subDay()]);

        $credential = app(CredentialEligibilityService::class)->recalculate($event, $staff);

        $this->assertNotNull($credential);
        $this->assertTrue($credential->isBlocked());
        $this->assertSame(
            CredentialEligibilityService::REASON_MISSING_REQUIRED_WAIVER,
            $credential->status_reason,
        );
    }

    private function organizerFor(Organization $organization): User
    {
        [$user] = $this->userWithRole('organizer', $organization);

        return $user;
    }

    /**
     * @return array{0: User, 1: Department}
     */
    private function departmentLeadFor(Organization $organization): array
    {
        [$user, $department] = $this->userWithRole('department_lead', $organization);

        return [$user, $department];
    }

    /**
     * @return array{0: User, 1: Team, 2: Department}
     */
    private function teamLeadFor(Organization $organization): array
    {
        [$user, $department, $team] = $this->userWithRole('shift_lead', $organization);

        return [$user, $team, $department];
    }

    /**
     * @return array{0: User, 1: Department, 2: Team}
     */
    private function userWithRole(string $roleCode, Organization $organization): array
    {
        $department = Department::factory()->for($organization)->create();

        if ($roleCode === 'organizer') {
            $organization->forceFill(['organizers_department_id' => $department->id])->save();
        }

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
            // A shift_lead grant only takes effect for the members who lead
            // the team (EffectiveRoleResolver).
            'membership_role' => $roleCode === 'shift_lead' ? 'lead' : 'member',
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => null,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        return [$user, $department, $team];
    }
}
