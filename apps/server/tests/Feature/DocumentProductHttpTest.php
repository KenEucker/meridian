<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\DocumentFragment;
use App\Models\DocumentFragmentReference;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Documents\DocumentFragmentReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentProductHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_can_author_publish_list_and_export_organization_policy_from_product_api(): void
    {
        [$organization, $actor] = $this->organizationWithRole('organizer');

        $fragment = $this->actingAsClient($actor)
            ->postJson('/api/commands/create-document-fragment', [
                'organization_id' => $organization->id,
                'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
                'scope_id' => $organization->id,
                'name' => 'Conduct Baseline',
                'slug' => 'conduct-baseline',
                'markdown_source' => 'Be excellent to each other.',
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Conduct Baseline')
            ->json();

        $policy = $this->actingAsClient($actor)
            ->postJson('/api/commands/create-policy-document', [
                'organization_id' => $organization->id,
                'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
                'scope_id' => $organization->id,
                'title' => 'Volunteer Conduct',
                'slug' => 'volunteer-conduct',
                'markdown_source' => "# Volunteer Conduct\n\n{{fragment:conduct-baseline}}",
            ])
            ->assertCreated()
            ->assertJsonPath('document_type', 'policy')
            ->assertJsonPath('state', PolicyDocument::STATE_DRAFT)
            ->assertJsonPath('fragment_references.0.fragment_id', $fragment['id'])
            ->json();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/publish-policy-document', [
                'document_id' => $policy['id'],
                'reason' => 'Ready for staff visibility.',
            ])
            ->assertOk()
            ->assertJsonPath('state', PolicyDocument::STATE_PUBLISHED)
            ->assertJsonPath('visibility_summary', 'Published to staff in this organization.');

        $this->actingAsClient($actor)
            ->getJson("/api/organizations/{$organization->id}/documents")
            ->assertOk()
            ->assertJsonCount(1, 'documents')
            ->assertJsonCount(1, 'fragments')
            ->assertJsonPath('documents.0.title', 'Volunteer Conduct');

        $this->actingAsClient($actor)
            ->get("/api/policy-documents/{$policy['id']}/export/markdown")
            ->assertOk()
            ->assertSee('Be excellent to each other.');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'policy_document.exported',
            'entity_id' => $policy['id'],
            'source_context' => AuditEvent::SOURCE_API,
        ]);
    }

    public function test_department_lead_can_author_department_procedure_but_not_organization_document(): void
    {
        [$department, $actor] = $this->departmentWithRole('department_lead');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/create-procedure-document', [
                'organization_id' => $department->organization_id,
                'scope_type' => ProcedureDocument::SCOPE_DEPARTMENT,
                'scope_id' => $department->id,
                'title' => 'Radio Checkout',
                'slug' => 'radio-checkout',
                'markdown_source' => 'Issue radios from Logistics.',
            ])
            ->assertCreated()
            ->assertJsonPath('document_type', 'procedure')
            ->assertJsonPath('scope_label', 'Department: Rangers');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/create-policy-document', [
                'organization_id' => $department->organization_id,
                'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
                'scope_id' => $department->organization_id,
                'title' => 'Organization Rule',
                'slug' => 'organization-rule',
                'markdown_source' => 'Organization-wide text.',
            ])
            ->assertForbidden();
    }

    public function test_team_lead_fragment_update_returns_published_reference_impact(): void
    {
        [$team, $actor] = $this->teamWithRole('shift_lead');
        $fragment = DocumentFragment::factory()->for($team->department->organization)->create([
            'scope_type' => DocumentFragment::SCOPE_TEAM,
            'scope_id' => $team->id,
            'name' => 'Radio Etiquette',
            'slug' => 'radio-etiquette',
            'markdown_source' => 'Use plain language.',
        ]);
        $document = PolicyDocument::factory()->for($team->department->organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_TEAM,
            'scope_id' => $team->id,
            'title' => 'Team Radio Policy',
            'slug' => 'team-radio-policy',
            'markdown_source' => '{{fragment:radio-etiquette}}',
        ]);

        app(DocumentFragmentReferenceService::class)->synchronize($document);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/update-document-fragment', [
                'fragment_id' => $fragment->id,
                'organization_id' => $team->department->organization_id,
                'scope_type' => DocumentFragment::SCOPE_TEAM,
                'scope_id' => $team->id,
                'name' => 'Radio Etiquette',
                'slug' => 'radio-etiquette',
                'markdown_source' => 'Use plain language and confirm urgent calls.',
            ])
            ->assertOk()
            ->assertJsonPath('version', 2)
            ->assertJsonPath('referencing_documents.0.title', 'Team Radio Policy')
            ->assertJsonPath('referencing_documents.0.published', true);

        $this->assertDatabaseHas('document_fragment_references', [
            'fragment_id' => $fragment->id,
            'document_id' => $document->id,
            'document_type' => DocumentFragmentReference::DOCUMENT_TYPE_POLICY,
        ]);
    }

    public function test_staff_can_view_published_department_document_but_not_draft_or_fragments(): void
    {
        [$department, $actor] = $this->departmentWithRole('staff');

        ProcedureDocument::factory()->for($department->organization)->published()->create([
            'scope_type' => ProcedureDocument::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'title' => 'Published Department Procedure',
        ]);
        ProcedureDocument::factory()->for($department->organization)->create([
            'scope_type' => ProcedureDocument::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'title' => 'Draft Department Procedure',
        ]);
        DocumentFragment::factory()->for($department->organization)->create([
            'scope_type' => DocumentFragment::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'name' => 'Maintainer Fragment',
        ]);

        $this->actingAsClient($actor)
            ->getJson("/api/organizations/{$department->organization_id}/documents")
            ->assertOk()
            ->assertJsonCount(1, 'documents')
            ->assertJsonCount(0, 'fragments')
            ->assertJsonPath('documents.0.title', 'Published Department Procedure');
    }

    public function test_document_index_states_the_scopes_the_caller_may_maintain(): void
    {
        [$organization, $organizer] = $this->organizationWithRole('organizer');

        // The authoring form needs the scope options and the Event Info
        // placements before it has a document to read them off (data/API 11.4A),
        // so the index answers with both (M16.19).
        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/documents")
            ->assertOk()
            ->assertJsonPath('access.can_maintain', true)
            ->assertJsonCount(1, 'access.scopes')
            ->assertJsonPath('access.scopes.0.scope_type', PolicyDocument::SCOPE_ORGANIZATION)
            ->assertJsonPath('access.scopes.0.scope_id', (string) $organization->id)
            ->assertJsonPath('access.scopes.0.label', 'Organization: '.$organization->name)
            ->assertJsonPath('event_info_sections.0.value', 'directions')
            ->assertJsonPath('event_info_sections.0.label', 'How to get to the event')
            ->assertJsonCount(6, 'event_info_sections');
    }

    public function test_department_lead_maintains_only_their_own_department_scope(): void
    {
        [$department, $actor] = $this->departmentWithRole('department_lead');

        $this->actingAsClient($actor)
            ->getJson("/api/organizations/{$department->organization_id}/documents")
            ->assertOk()
            ->assertJsonPath('access.can_maintain', true)
            ->assertJsonCount(1, 'access.scopes')
            ->assertJsonPath('access.scopes.0.scope_type', PolicyDocument::SCOPE_DEPARTMENT)
            ->assertJsonPath('access.scopes.0.scope_id', (string) $department->id)
            ->assertJsonPath('access.scopes.0.label', 'Department: Rangers');
    }

    public function test_team_lead_maintains_only_the_team_they_lead(): void
    {
        [$team, $actor] = $this->teamWithRole('shift_lead');

        $this->actingAsClient($actor)
            ->getJson("/api/organizations/{$team->department->organization_id}/documents")
            ->assertOk()
            ->assertJsonPath('access.can_maintain', true)
            ->assertJsonCount(1, 'access.scopes')
            ->assertJsonPath('access.scopes.0.scope_type', PolicyDocument::SCOPE_TEAM)
            ->assertJsonPath('access.scopes.0.scope_id', (string) $team->id)
            ->assertJsonPath('access.scopes.0.label', 'Team: Dirt');
    }

    public function test_reader_receives_no_maintainable_scopes_and_no_maintainable_documents(): void
    {
        [$department, $actor] = $this->departmentWithRole('staff');

        ProcedureDocument::factory()->for($department->organization)->published()->create([
            'scope_type' => ProcedureDocument::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'title' => 'Published Department Procedure',
        ]);

        // A reader and a maintainer both receive published documents, so the
        // row itself has to say which of the two is looking at it; a client that
        // offered Edit here would only meet the command's refusal (CLIENT-006).
        $this->actingAsClient($actor)
            ->getJson("/api/organizations/{$department->organization_id}/documents")
            ->assertOk()
            ->assertJsonPath('access.can_maintain', false)
            ->assertJsonCount(0, 'access.scopes')
            ->assertJsonPath('documents.0.can_maintain', false);
    }

    public function test_maintainer_reads_can_maintain_on_their_own_document_and_not_on_another_scope(): void
    {
        [$department, $actor, $ownTeam] = $this->departmentWithRole('department_lead');
        $otherDepartment = Department::factory()
            ->for($department->organization)
            ->create(['name' => 'Gate', 'code' => 'GATE']);

        ProcedureDocument::factory()->for($department->organization)->published()->create([
            'scope_type' => ProcedureDocument::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'title' => 'Own Department Procedure',
        ]);
        PolicyDocument::factory()->for($department->organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_TEAM,
            'scope_id' => $ownTeam->id,
            'title' => 'Own Team Policy',
        ]);
        PolicyDocument::factory()->for($department->organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_DEPARTMENT,
            'scope_id' => $otherDepartment->id,
            'title' => 'Other Department Policy',
        ]);

        $documents = collect($this->actingAsClient($actor)
            ->getJson("/api/organizations/{$department->organization_id}/documents")
            ->assertOk()
            ->json('documents'))
            ->keyBy('title');

        $this->assertTrue($documents['Own Department Procedure']['can_maintain']);
        // Team-scoped documents are maintained by that team's designated lead
        // (data/API 11.2), so a member reads this one without maintaining it.
        $this->assertFalse($documents['Own Team Policy']['can_maintain']);
        // The other department's published policy is not visible at all.
        $this->assertFalse($documents->has('Other Department Policy'));
    }

    /**
     * @return array{Organization, User}
     */
    private function organizationWithRole(string $roleCode): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Organizers', 'code' => 'ORG']);
        $organization->forceFill(['organizers_department_id' => $department->id])->save();

        $team = Team::factory()->for($department)->create(['is_default' => true]);
        $user = $this->userOnTeam($team, $roleCode);

        return [$organization, $user];
    }

    /**
     * @return array{Department, User, Team}
     */
    private function departmentWithRole(string $roleCode): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers', 'code' => 'RANGERS']);
        $team = Team::factory()->for($department)->create(['is_default' => true]);
        $user = $this->userOnTeam($team, $roleCode);

        return [$department, $user, $team];
    }

    /**
     * @return array{Team, User}
     */
    private function teamWithRole(string $roleCode): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers', 'code' => 'RANGERS']);
        $team = Team::factory()->for($department)->create(['name' => 'Dirt', 'code' => 'DIRT']);
        $user = $this->userOnTeam($team, $roleCode);

        return [$team, $user];
    }

    private function userOnTeam(Team $team, string $roleCode): User
    {
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
            // shift_lead authority requires a designated lead membership (M11.17).
            'membership_role' => $roleCode === 'shift_lead' ? 'lead' : 'member',
        ]);

        if ($roleCode !== 'staff') {
            TeamGrant::factory()->create([
                'team_id' => $team->id,
                'event_id' => null,
                'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
            ]);
        }

        return $user;
    }
}
