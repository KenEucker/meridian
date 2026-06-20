<?php

namespace Tests\Feature;

use App\Jobs\BumpPublishedDocumentFragmentRevisions;
use App\Models\DocumentFragment;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Services\Documents\DocumentFragmentReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DocumentFragmentVersionBumpJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_fragment_text_change_queues_a_post_commit_revision_bump_for_its_new_version(): void
    {
        Queue::fake();
        $fragment = DocumentFragment::factory()->create();

        $fragment->update(['markdown_source' => 'Updated fragment text.']);

        Queue::assertPushed(
            BumpPublishedDocumentFragmentRevisions::class,
            fn (BumpPublishedDocumentFragmentRevisions $job): bool => $job->fragmentId === $fragment->id
                && $job->fragmentVersion === 2,
        );
    }

    public function test_job_bumps_each_referencing_published_document_once_and_preserves_document_revision(): void
    {
        [$fragment, $policy, $procedure] = $this->referencedPublishedDocuments();

        $fragment->update(['markdown_source' => 'Updated fragment text.']);
        $job = new BumpPublishedDocumentFragmentRevisions($fragment->id, $fragment->version);

        $job->handle();
        $job->handle();

        $this->assertSame(1, $policy->refresh()->document_revision);
        $this->assertSame(1, $procedure->refresh()->document_revision);
        $this->assertSame(1, $policy->fragment_revision);
        $this->assertSame(1, $procedure->fragment_revision);
        $this->assertSame('1.01', $policy->version());
        $this->assertSame('1.01', $procedure->version());
        $this->assertDatabaseCount('document_fragment_version_bumps', 2);
    }

    public function test_job_does_not_bump_draft_or_archived_referencing_documents(): void
    {
        $organization = Organization::factory()->create();
        $fragment = DocumentFragment::factory()->for($organization)->create([
            'slug' => 'safety-language',
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
        $draft = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => '{{fragment:safety-language}}',
        ]);
        $archived = ProcedureDocument::factory()->for($organization)->archived()->create([
            'scope_type' => ProcedureDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => '{{fragment:safety-language}}',
        ]);
        $referenceService = app(DocumentFragmentReferenceService::class);
        $referenceService->synchronize($draft);
        $referenceService->synchronize($archived);

        $fragment->update(['markdown_source' => 'Updated fragment text.']);
        (new BumpPublishedDocumentFragmentRevisions($fragment->id, $fragment->version))->handle();

        $this->assertSame(0, $draft->refresh()->fragment_revision);
        $this->assertSame(0, $archived->refresh()->fragment_revision);
        $this->assertDatabaseCount('document_fragment_version_bumps', 0);
    }

    public function test_fragment_metadata_changes_do_not_queue_or_bump_document_versions(): void
    {
        Queue::fake();
        [$fragment, $policy, $procedure] = $this->referencedPublishedDocuments();

        $fragment->update(['name' => 'Renamed fragment']);

        Queue::assertNothingPushed();
        $this->assertSame(1, $fragment->refresh()->version);
        $this->assertSame(0, $policy->refresh()->fragment_revision);
        $this->assertSame(0, $procedure->refresh()->fragment_revision);
        $this->assertDatabaseCount('document_fragment_version_bumps', 0);
    }

    /**
     * @return array{DocumentFragment, PolicyDocument, ProcedureDocument}
     */
    private function referencedPublishedDocuments(): array
    {
        $organization = Organization::factory()->create();
        $fragment = DocumentFragment::factory()->for($organization)->create([
            'slug' => 'safety-language',
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
        $policy = PolicyDocument::factory()->for($organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => '{{fragment:safety-language}}',
        ]);
        $procedure = ProcedureDocument::factory()->for($organization)->published()->create([
            'scope_type' => ProcedureDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => '{{fragment:safety-language}}',
        ]);
        $referenceService = app(DocumentFragmentReferenceService::class);
        $referenceService->synchronize($policy);
        $referenceService->synchronize($procedure);

        return [$fragment, $policy, $procedure];
    }
}
