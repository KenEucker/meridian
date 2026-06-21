<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DocumentFragment;
use App\Models\DocumentFragmentReference;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\User;
use App\Services\Documents\DocumentFragmentReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

class DocumentOrchidTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    public function test_policy_list_separates_policy_documents_from_procedures(): void
    {
        $organization = Organization::factory()->create(['name' => 'Idaho Burners']);

        PolicyDocument::factory()->for($organization)->create([
            'title' => 'Volunteer Conduct Policy',
            'scope_id' => $organization->id,
        ]);
        ProcedureDocument::factory()->for($organization)->create([
            'title' => 'Radio Checkout Procedure',
            'scope_id' => $organization->id,
        ]);

        $response = $this->actingAs($this->documentAdmin())
            ->get(route('platform.policy-documents'));

        $response->assertOk();
        $response->assertSee('Policy Documents');
        $response->assertSee('Volunteer Conduct Policy');
        $response->assertDontSee('Radio Checkout Procedure');
        $response->assertSee('Organization: Idaho Burners');
        $response->assertSee('Draft');
        $response->assertSee('1.00');
    }

    public function test_document_admin_screens_require_their_resource_permissions(): void
    {
        $user = User::factory()->create([
            'permissions' => ['platform.index' => true],
        ]);

        $this->actingAs($user)
            ->get(route('platform.policy-documents'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('platform.procedure-documents'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('platform.document-fragments'))
            ->assertForbidden();
    }

    public function test_orchid_creates_a_policy_with_validated_fragment_references_and_audit_history(): void
    {
        $organization = Organization::factory()->create();
        $fragment = DocumentFragment::factory()->for($organization)->create([
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'slug' => 'org-expectations',
            'name' => 'Organization Expectations',
            'version' => 4,
        ]);
        $admin = $this->documentAdmin();

        $response = $this->screen('platform.policy-documents.create')
            ->actingAs($admin)
            ->withoutFollowingRedirects()
            ->method('save', [
                'document' => [
                    'organization_id' => $organization->id,
                    'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
                    'scope_id' => $organization->id,
                    'title' => 'Volunteer Conduct Policy',
                    'slug' => 'volunteer-conduct-policy',
                    'markdown_source' => "# Conduct\n\n{{fragment:org-expectations}}",
                    'state' => PolicyDocument::STATE_DRAFT,
                ],
            ]);

        $response->assertRedirect(route('platform.policy-documents'));

        $document = PolicyDocument::query()->where('slug', 'volunteer-conduct-policy')->firstOrFail();

        $this->assertSame($admin->id, $document->created_by_user_id);
        $this->assertSame($admin->id, $document->updated_by_user_id);
        $this->assertSame(1, $document->document_revision);
        $this->assertSame(0, $document->fragment_revision);
        $this->assertDatabaseHas('document_fragment_references', [
            'document_type' => DocumentFragmentReference::DOCUMENT_TYPE_POLICY,
            'document_id' => $document->id,
            'fragment_id' => $fragment->id,
            'token' => '{{fragment:org-expectations}}',
            'fragment_version_at_last_edit' => $fragment->version,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'policy_document.created',
            'entity_id' => $document->id,
            'actor_user_id' => $admin->id,
            'organization_id' => $organization->id,
            'source_context' => AuditEvent::SOURCE_ORCHID,
        ]);
    }

    public function test_broken_fragment_references_block_document_save_without_creating_a_record(): void
    {
        $organization = Organization::factory()->create();

        $response = $this->screen('platform.procedure-documents.create')
            ->actingAs($this->documentAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'document' => [
                    'organization_id' => $organization->id,
                    'scope_type' => ProcedureDocument::SCOPE_ORGANIZATION,
                    'scope_id' => $organization->id,
                    'title' => 'Radio Checkout Procedure',
                    'slug' => 'radio-checkout',
                    'markdown_source' => '{{fragment:missing-guidance}}',
                    'state' => ProcedureDocument::STATE_DRAFT,
                ],
            ]);

        $response->assertSessionHasErrors('document.markdown_source');
        $this->assertDatabaseCount('procedure_documents', 0);
        $this->assertDatabaseCount('document_fragment_references', 0);
    }

    public function test_publishing_requires_a_reason_and_records_the_publish_audit_event(): void
    {
        $organization = Organization::factory()->create();
        $document = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'state' => PolicyDocument::STATE_DRAFT,
        ]);
        $admin = $this->documentAdmin();

        $missingReason = $this->screen('platform.policy-documents.edit', ['policyDocument' => $document->id])
            ->actingAs($admin)
            ->withoutFollowingRedirects()
            ->method('save', [
                'document' => [
                    'organization_id' => $organization->id,
                    'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
                    'scope_id' => $organization->id,
                    'title' => $document->title,
                    'slug' => $document->slug,
                    'markdown_source' => $document->markdown_source,
                    'state' => PolicyDocument::STATE_PUBLISHED,
                ],
            ]);

        $missingReason->assertSessionHasErrors('document.reason');
        $this->assertSame(PolicyDocument::STATE_DRAFT, $document->refresh()->state);

        $published = $this->screen('platform.policy-documents.edit', ['policyDocument' => $document->id])
            ->actingAs($admin)
            ->withoutFollowingRedirects()
            ->method('save', [
                'document' => [
                    'organization_id' => $organization->id,
                    'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
                    'scope_id' => $organization->id,
                    'title' => $document->title,
                    'slug' => $document->slug,
                    'markdown_source' => $document->markdown_source,
                    'state' => PolicyDocument::STATE_PUBLISHED,
                    'reason' => 'Approved for the summer event.',
                ],
            ]);

        $published->assertRedirect(route('platform.policy-documents'));
        $document->refresh();

        $this->assertSame(PolicyDocument::STATE_PUBLISHED, $document->state);
        $this->assertNotNull($document->published_at);
        $this->assertSame(1, $document->document_revision);
        $this->assertSame(0, $document->fragment_revision);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'policy_document.published',
            'entity_id' => $document->id,
            'actor_user_id' => $admin->id,
            'reason' => 'Approved for the summer event.',
            'source_context' => AuditEvent::SOURCE_ORCHID,
        ]);
    }

    public function test_policy_editor_shows_saved_inline_preview_and_current_fragment_version(): void
    {
        $organization = Organization::factory()->create();
        $fragment = DocumentFragment::factory()->for($organization)->create([
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'slug' => 'radio-safety',
            'name' => 'Radio Safety',
            'version' => 3,
            'markdown_source' => '**Use clear radio language.**',
        ]);
        $document = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => "# Safe Operations\n\n{{fragment:radio-safety}}",
        ]);

        app(DocumentFragmentReferenceService::class)->synchronize($document);

        $response = $this->actingAs($this->documentAdmin())
            ->get(route('platform.policy-documents.edit', $document));

        $response->assertOk();
        $response->assertSee('Edit Policy');
        $response->assertSee('Rendered preview');
        $response->assertSee('Use clear radio language.', false);
        $response->assertSee('{{fragment:radio-safety}}', false);
        $response->assertSee('Radio Safety');
        $response->assertSee((string) $fragment->version);
        $response->assertSee('1.00');
    }

    public function test_published_document_content_edit_bumps_its_document_revision_and_resets_fragment_revision(): void
    {
        $organization = Organization::factory()->create();
        $document = PolicyDocument::factory()->for($organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'document_revision' => 2,
            'fragment_revision' => 4,
            'markdown_source' => 'Original published policy.',
        ]);
        $admin = $this->documentAdmin();

        $response = $this->screen('platform.policy-documents.edit', ['policyDocument' => $document->id])
            ->actingAs($admin)
            ->withoutFollowingRedirects()
            ->method('save', [
                'document' => [
                    'organization_id' => $organization->id,
                    'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
                    'scope_id' => $organization->id,
                    'title' => $document->title,
                    'slug' => $document->slug,
                    'markdown_source' => 'Updated published policy.',
                    'state' => PolicyDocument::STATE_PUBLISHED,
                ],
            ]);

        $response->assertRedirect(route('platform.policy-documents'));
        $document->refresh();

        $this->assertSame(3, $document->document_revision);
        $this->assertSame(0, $document->fragment_revision);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'policy_document.updated',
            'entity_id' => $document->id,
            'actor_user_id' => $admin->id,
            'source_context' => AuditEvent::SOURCE_ORCHID,
        ]);
    }

    public function test_fragment_editor_shows_referencing_policy_and_procedure_before_editing(): void
    {
        $organization = Organization::factory()->create();
        $fragment = DocumentFragment::factory()->for($organization)->create([
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'slug' => 'shared-guidance',
            'name' => 'Shared Guidance',
        ]);
        $policy = PolicyDocument::factory()->for($organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'title' => 'Volunteer Expectations',
            'markdown_source' => '{{fragment:shared-guidance}}',
        ]);
        $procedure = ProcedureDocument::factory()->for($organization)->published()->create([
            'scope_type' => ProcedureDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'title' => 'Gate Opening Procedure',
            'markdown_source' => '{{fragment:shared-guidance}}',
        ]);

        app(DocumentFragmentReferenceService::class)->synchronize($policy);
        app(DocumentFragmentReferenceService::class)->synchronize($procedure);

        $response = $this->actingAs($this->documentAdmin())
            ->get(route('platform.document-fragments.edit', $fragment));

        $response->assertOk();
        $response->assertSee('Save Fragment Changes');
        $response->assertSee('Referencing documents');
        $response->assertSee('Volunteer Expectations');
        $response->assertSee('Gate Opening Procedure');
        $response->assertSee('Policy');
        $response->assertSee('Procedure');
        $response->assertSee('Published');
        $response->assertSee('Published document impact');
        $response->assertSee('Editing this fragment will bump versions for 2 published documents.');
    }

    public function test_fragment_editor_does_not_warn_when_only_draft_and_archived_documents_reference_it(): void
    {
        $organization = Organization::factory()->create();
        $fragment = DocumentFragment::factory()->for($organization)->create([
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'slug' => 'draft-only-guidance',
        ]);
        $draftPolicy = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => '{{fragment:draft-only-guidance}}',
        ]);
        $archivedProcedure = ProcedureDocument::factory()->for($organization)->archived()->create([
            'scope_type' => ProcedureDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => '{{fragment:draft-only-guidance}}',
        ]);

        app(DocumentFragmentReferenceService::class)->synchronize($draftPolicy);
        app(DocumentFragmentReferenceService::class)->synchronize($archivedProcedure);

        $response = $this->actingAs($this->documentAdmin())
            ->get(route('platform.document-fragments.edit', $fragment));

        $response->assertOk();
        $response->assertSee('Save Fragment Changes');
        $response->assertSee('Draft');
        $response->assertSee('Archived');
        $response->assertDontSee('Published document impact');
        $response->assertDontSee('Editing this fragment will bump versions for');
    }

    public function test_fragment_save_rejects_nested_references_and_validates_scope_ownership(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $otherDepartment = Department::factory()->for($otherOrganization)->create();

        $response = $this->screen('platform.document-fragments.create')
            ->actingAs($this->documentAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'fragment' => [
                    'organization_id' => $organization->id,
                    'scope_type' => DocumentFragment::SCOPE_DEPARTMENT,
                    'scope_id' => $otherDepartment->id,
                    'name' => 'Nested Guidance',
                    'slug' => 'nested-guidance',
                    'markdown_source' => '{{fragment:another-fragment}}',
                ],
            ]);

        $response->assertSessionHasErrors('fragment.scope_id');
        $this->assertDatabaseCount('document_fragments', 0);

        $nested = $this->screen('platform.document-fragments.create')
            ->actingAs($this->documentAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'fragment' => [
                    'organization_id' => $organization->id,
                    'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
                    'scope_id' => $organization->id,
                    'name' => 'Nested Guidance',
                    'slug' => 'nested-guidance',
                    'markdown_source' => '{{fragment:another-fragment}}',
                ],
            ]);

        $nested->assertSessionHasErrors('fragment.markdown_source');
        $this->assertDatabaseCount('document_fragments', 0);
    }

    private function documentAdmin(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.policy-documents' => true,
                'platform.procedure-documents' => true,
                'platform.document-fragments' => true,
            ],
        ]);
    }
}
