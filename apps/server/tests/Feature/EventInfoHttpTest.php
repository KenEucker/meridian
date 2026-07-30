<?php

namespace Tests\Feature;

use App\Domain\Documents\EventInfoSection;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Event Info document selection and assembly (M11.20).
 */
class EventInfoHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_info_returns_every_section_with_explicit_empty_descriptions(): void
    {
        [$event, , , $actor] = $this->eventWithStaff();

        $response = $this->actingAsClient($actor)
            ->getJson("/api/events/{$event->id}/info")
            ->assertOk()
            ->assertJsonPath('event.id', (string) $event->id)
            ->assertJsonCount(6, 'sections')
            ->assertJsonPath('sections.0.section', EventInfoSection::DIRECTIONS)
            ->assertJsonPath('sections.0.label', 'How to get to the event')
            ->assertJsonPath('sections.5.section', EventInfoSection::REQUIREMENTS);

        foreach ($response->json('sections') as $section) {
            $this->assertSame([], $section['documents']);
            $this->assertNotNull($section['empty_description']);
        }

        $this->assertSame(EventInfoSection::keys(), $response->json('section_order'));
    }

    public function test_published_assigned_documents_replace_the_section_empty_state(): void
    {
        [$event, $organization, $department, $actor] = $this->eventWithStaff();

        PolicyDocument::factory()->for($organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'title' => 'Getting Here',
            'slug' => 'getting-here',
            'event_info_section' => EventInfoSection::DIRECTIONS,
            'markdown_source' => "# Getting Here\n\nTake the north access road to Gate 1.",
        ]);
        ProcedureDocument::factory()->for($organization)->published()->create([
            'scope_type' => ProcedureDocument::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'title' => 'Ranger Packing List',
            'slug' => 'ranger-packing-list',
            'event_info_section' => EventInfoSection::PACKING,
            'markdown_source' => 'Bring dust goggles and a working headlamp.',
        ]);

        $response = $this->actingAsClient($actor)
            ->getJson("/api/events/{$event->id}/info")
            ->assertOk()
            ->assertJsonPath('sections.0.documents.0.title', 'Getting Here')
            ->assertJsonPath('sections.0.documents.0.document_type', 'policy')
            ->assertJsonPath('sections.0.documents.0.scope_label', "Organization: {$organization->name}")
            ->assertJsonPath('sections.0.empty_description', null)
            ->assertJsonPath('sections.2.section', EventInfoSection::PACKING)
            ->assertJsonPath('sections.2.documents.0.title', 'Ranger Packing List')
            ->assertJsonPath('sections.2.documents.0.document_type', 'procedure');

        $this->assertStringContainsString(
            'Take the north access road to Gate 1.',
            $response->json('sections.0.documents.0.rendered_html'),
        );

        // Sections without an assignment stay explicitly empty rather than
        // borrowing content from a neighbouring section.
        $this->assertSame([], $response->json('sections.1.documents'));
        $this->assertNotNull($response->json('sections.1.empty_description'));
    }

    public function test_draft_and_archived_assignments_never_reach_event_info_even_for_their_maintainer(): void
    {
        [$event, $organization, $department, $actor] = $this->eventWithStaff('department_lead');

        PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'title' => 'Draft Directions',
            'event_info_section' => EventInfoSection::DIRECTIONS,
            'state' => PolicyDocument::STATE_DRAFT,
        ]);
        PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'title' => 'Retired Directions',
            'event_info_section' => EventInfoSection::DIRECTIONS,
            'state' => PolicyDocument::STATE_ARCHIVED,
            'archived_at' => now(),
        ]);

        $this->actingAsClient($actor)
            ->getJson("/api/events/{$event->id}/info")
            ->assertOk()
            ->assertJsonPath('sections.0.documents', [])
            ->assertJsonPath('sections.0.empty_description', EventInfoSection::emptyDescription(EventInfoSection::DIRECTIONS));
    }

    public function test_event_info_does_not_widen_document_visibility(): void
    {
        [$event, $organization, $department, $actor] = $this->eventWithStaff();

        $otherDepartment = Department::factory()->for($organization)->create(['name' => 'Gate', 'code' => 'GATE']);
        $otherTeam = Team::factory()->for($otherDepartment)->create(['name' => 'Credentials', 'code' => 'CRED']);
        $memberTeam = Team::factory()->for($department)->create(['name' => 'Dirt', 'code' => 'DIRT']);

        ProcedureDocument::factory()->for($organization)->published()->create([
            'scope_type' => ProcedureDocument::SCOPE_DEPARTMENT,
            'scope_id' => $otherDepartment->id,
            'title' => 'Gate Housing',
            'event_info_section' => EventInfoSection::HOUSING,
        ]);
        ProcedureDocument::factory()->for($organization)->published()->create([
            'scope_type' => ProcedureDocument::SCOPE_TEAM,
            'scope_id' => $otherTeam->id,
            'title' => 'Credentials Housing',
            'event_info_section' => EventInfoSection::HOUSING,
        ]);
        ProcedureDocument::factory()->for($organization)->published()->create([
            'scope_type' => ProcedureDocument::SCOPE_TEAM,
            'scope_id' => $memberTeam->id,
            'title' => 'Dirt Housing',
            'event_info_section' => EventInfoSection::HOUSING,
        ]);

        $this->actingAsClient($actor)
            ->getJson("/api/events/{$event->id}/info")
            ->assertOk()
            ->assertJsonPath('sections.4.section', EventInfoSection::HOUSING)
            ->assertJsonCount(0, 'sections.4.documents');

        TeamMembership::factory()->create([
            'team_id' => $memberTeam->id,
            'staff_id' => $actor->staffProfiles()->firstOrFail()->id,
            'department_membership_id' => DepartmentMembership::query()
                ->where('staff_id', $actor->staffProfiles()->firstOrFail()->id)
                ->firstOrFail()->id,
            'membership_role' => 'member',
        ]);

        $this->actingAsClient($actor)
            ->getJson("/api/events/{$event->id}/info")
            ->assertOk()
            ->assertJsonCount(1, 'sections.4.documents')
            ->assertJsonPath('sections.4.documents.0.title', 'Dirt Housing');
    }

    public function test_documents_in_a_section_are_ordered_from_broadest_scope_to_narrowest(): void
    {
        [$event, $organization, $department, $actor] = $this->eventWithStaff();

        ProcedureDocument::factory()->for($organization)->published()->create([
            'scope_type' => ProcedureDocument::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'title' => 'A Department Exception',
            'event_info_section' => EventInfoSection::FOOD,
        ]);
        PolicyDocument::factory()->for($organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'title' => 'Z Organization Meals',
            'event_info_section' => EventInfoSection::FOOD,
        ]);

        $this->actingAsClient($actor)
            ->getJson("/api/events/{$event->id}/info")
            ->assertOk()
            ->assertJsonPath('sections.3.section', EventInfoSection::FOOD)
            ->assertJsonPath('sections.3.documents.0.title', 'Z Organization Meals')
            ->assertJsonPath('sections.3.documents.1.title', 'A Department Exception');
    }

    public function test_event_info_requires_staff_standing_in_the_event_organization(): void
    {
        [$event] = $this->eventWithStaff();

        $this->getJson("/api/events/{$event->id}/info")->assertUnauthorized();

        $outsider = User::factory()->create();
        $outsider->staffProfiles()->attach(Staff::factory()->create()->id);

        $this->actingAsClient($outsider)
            ->getJson("/api/events/{$event->id}/info")
            ->assertForbidden()
            ->assertJsonPath('message', 'Event information requires staff standing in this event organization.');
    }

    public function test_maintainer_assigns_and_clears_the_event_info_section_without_bumping_the_document_version(): void
    {
        [$event, $organization, $department, $actor] = $this->eventWithStaff('department_lead');

        $document = $this->actingAsClient($actor)
            ->postJson('/api/commands/create-procedure-document', [
                'organization_id' => $organization->id,
                'scope_type' => ProcedureDocument::SCOPE_DEPARTMENT,
                'scope_id' => $department->id,
                'title' => 'Arrival Checklist',
                'slug' => 'arrival-checklist',
                'event_info_section' => EventInfoSection::ARRIVAL,
                'markdown_source' => 'Check in at the staff gate with photo ID.',
            ])
            ->assertCreated()
            ->assertJsonPath('event_info_section', EventInfoSection::ARRIVAL)
            ->assertJsonPath('event_info_section_label', 'Arrival requirements')
            ->json();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/publish-procedure-document', [
                'document_id' => $document['id'],
                'reason' => 'Ready for staff.',
            ])
            ->assertOk()
            ->assertJsonPath('event_info_section', EventInfoSection::ARRIVAL)
            ->assertJsonPath('version', '1.00');

        $this->actingAsClient($actor)
            ->getJson("/api/events/{$event->id}/info")
            ->assertOk()
            ->assertJsonPath('sections.1.documents.0.title', 'Arrival Checklist');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/update-procedure-document', [
                'document_id' => $document['id'],
                'organization_id' => $organization->id,
                'scope_type' => ProcedureDocument::SCOPE_DEPARTMENT,
                'scope_id' => $department->id,
                'title' => 'Arrival Checklist',
                'slug' => 'arrival-checklist',
                'event_info_section' => null,
                'markdown_source' => 'Check in at the staff gate with photo ID.',
            ])
            ->assertOk()
            ->assertJsonPath('event_info_section', null)
            // Placement is not content, so the published version is unchanged.
            ->assertJsonPath('version', '1.00')
            ->assertJsonPath('state', ProcedureDocument::STATE_PUBLISHED);

        $this->actingAsClient($actor)
            ->getJson("/api/events/{$event->id}/info")
            ->assertOk()
            ->assertJsonPath('sections.1.documents', []);
    }

    public function test_unknown_event_info_section_is_refused(): void
    {
        [, $organization, $department, $actor] = $this->eventWithStaff('department_lead');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/create-procedure-document', [
                'organization_id' => $organization->id,
                'scope_type' => ProcedureDocument::SCOPE_DEPARTMENT,
                'scope_id' => $department->id,
                'title' => 'Weather Notes',
                'slug' => 'weather-notes',
                'event_info_section' => 'weather',
                'markdown_source' => 'Dust storms are likely after noon.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_info_section');
    }

    /**
     * @return array{Event, Organization, Department, User}
     */
    private function eventWithStaff(string $roleCode = 'staff'): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers', 'code' => 'RANGERS']);
        $team = Team::factory()->for($department)->create(['is_default' => true]);

        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        StaffOrganizationStatus::factory()->active()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
        ]);

        $membership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
            'membership_role' => 'member',
        ]);

        if ($roleCode !== 'staff') {
            TeamGrant::factory()->create([
                'team_id' => $team->id,
                'event_id' => null,
                'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
            ]);
        }

        return [$event, $organization, $department, $user];
    }
}
