<?php

namespace Tests\Feature;

use App\Models\DocumentAcknowledgment;
use App\Models\DocumentFragment;
use App\Models\DocumentVersionSnapshot;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Staff;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Waiver\WaiverDocumentException;
use App\Services\Waiver\WaiverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Document-backed waivers (M18.17; WAIVER-007 through WAIVER-009; POL-022,
 * POL-043).
 */
class WaiverDocumentBackedTest extends TestCase
{
    use RefreshDatabase;

    private function waiverService(): WaiverService
    {
        return app(WaiverService::class);
    }

    public function test_waiver_may_reference_a_published_policy_document(): void
    {
        $organization = Organization::factory()->create();
        $document = PolicyDocument::factory()->for($organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);

        $waiver = $this->waiverService()->create(
            $organization,
            Waiver::SCOPE_ORGANIZATION,
            (string) $organization->id,
            'Liability Waiver',
            documentType: DocumentAcknowledgment::DOCUMENT_TYPE_POLICY,
            documentId: (string) $document->id,
        );

        $this->assertTrue($waiver->isDocumentBacked());
        $this->assertSame(DocumentAcknowledgment::DOCUMENT_TYPE_POLICY, $waiver->document_type);
        $this->assertSame((string) $document->id, (string) $waiver->document_id);
        $this->assertTrue($waiver->document()->is($document));
    }

    public function test_waiver_rejects_unpublished_document_reference(): void
    {
        $organization = Organization::factory()->create();
        $document = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);

        $this->expectException(WaiverDocumentException::class);
        $this->expectExceptionMessage('Only published documents may back a waiver.');

        $this->waiverService()->create(
            $organization,
            Waiver::SCOPE_ORGANIZATION,
            (string) $organization->id,
            'Draft-backed Waiver',
            documentType: DocumentAcknowledgment::DOCUMENT_TYPE_POLICY,
            documentId: (string) $document->id,
        );
    }

    public function test_waiver_rejects_document_from_another_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $document = PolicyDocument::factory()->for($otherOrganization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $otherOrganization->id,
        ]);

        $this->expectException(WaiverDocumentException::class);
        $this->expectExceptionMessage('The referenced document must belong to the waiver organization.');

        $this->waiverService()->create(
            $organization,
            Waiver::SCOPE_ORGANIZATION,
            (string) $organization->id,
            'Cross-organization Document Waiver',
            documentType: DocumentAcknowledgment::DOCUMENT_TYPE_POLICY,
            documentId: (string) $document->id,
        );
    }

    public function test_waiver_rejects_half_of_a_document_reference(): void
    {
        $organization = Organization::factory()->create();

        $this->expectException(WaiverDocumentException::class);
        $this->expectExceptionMessage('A document-backed waiver names both a document type and a document.');

        $this->waiverService()->create(
            $organization,
            Waiver::SCOPE_ORGANIZATION,
            (string) $organization->id,
            'Half-referenced Waiver',
            documentType: DocumentAcknowledgment::DOCUMENT_TYPE_POLICY,
        );
    }

    public function test_completion_records_the_acknowledged_document_version(): void
    {
        $organization = Organization::factory()->create();
        $document = ProcedureDocument::factory()->for($organization)->published()->create([
            'scope_type' => ProcedureDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'document_revision' => 3,
            'fragment_revision' => 1,
            'markdown_source' => 'Follow the **medical waiver** procedure.',
        ]);
        $waiver = $this->waiverService()->create(
            $organization,
            Waiver::SCOPE_ORGANIZATION,
            (string) $organization->id,
            'Medical Waiver',
            documentType: DocumentAcknowledgment::DOCUMENT_TYPE_PROCEDURE,
            documentId: (string) $document->id,
        );
        $staff = Staff::factory()->create();
        $recorder = User::factory()->create();

        $completion = $this->waiverService()->recordCompletion(
            $waiver,
            $staff,
            Carbon::parse('2026-05-01 09:00:00'),
            $recorder,
        );

        // WAIVER-008 / POL-043: the acknowledged document and version ride the
        // completion record.
        $this->assertSame(DocumentAcknowledgment::DOCUMENT_TYPE_PROCEDURE, $completion->document_type);
        $this->assertSame((string) $document->id, (string) $completion->document_id);
        $this->assertSame(3, $completion->document_revision);
        $this->assertSame(1, $completion->fragment_revision);

        // The acknowledged version's text is retained as an immutable snapshot,
        // so what was shown survives later edits of the document.
        $snapshot = DocumentVersionSnapshot::query()
            ->where('document_type', DocumentAcknowledgment::DOCUMENT_TYPE_PROCEDURE)
            ->where('document_id', $document->id)
            ->where('document_revision', 3)
            ->where('fragment_revision', 1)
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertSame('waiver_completion', $snapshot->snapshot_reason);
        $this->assertStringContainsString('medical waiver', $snapshot->resolved_markdown_snapshot);
    }

    public function test_completion_refuses_when_the_referenced_document_is_no_longer_published(): void
    {
        $organization = Organization::factory()->create();
        $document = PolicyDocument::factory()->for($organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
        $waiver = $this->waiverService()->create(
            $organization,
            Waiver::SCOPE_ORGANIZATION,
            (string) $organization->id,
            'Withdrawn Document Waiver',
            documentType: DocumentAcknowledgment::DOCUMENT_TYPE_POLICY,
            documentId: (string) $document->id,
        );

        $document->update(['state' => PolicyDocument::STATE_ARCHIVED]);

        $this->expectException(WaiverDocumentException::class);
        $this->expectExceptionMessage('Only published documents may back a waiver.');

        $this->waiverService()->recordCompletion($waiver, Staff::factory()->create());
    }

    public function test_waiver_without_a_document_behaves_unchanged(): void
    {
        // WAIVER-009: no reference, no document behavior — the completion
        // carries no version columns and nothing renders.
        $waiver = Waiver::factory()->expiresAfterDays(30)->create();
        $staff = Staff::factory()->create();

        $completion = $this->waiverService()->recordCompletion(
            $waiver,
            $staff,
            Carbon::parse('2026-05-01 09:00:00'),
        );

        $this->assertFalse($waiver->isDocumentBacked());
        $this->assertNull($waiver->document());
        $this->assertNull($completion->document_type);
        $this->assertNull($completion->document_id);
        $this->assertNull($completion->document_revision);
        $this->assertNull($completion->fragment_revision);
        $this->assertNotNull($completion->expires_at);
        $this->assertNull($this->waiverService()->renderedDocumentFor($waiver));
        $this->assertSame(0, DocumentVersionSnapshot::query()->count());
        $this->assertTrue($waiver->isCompleteFor($staff, Carbon::parse('2026-05-02 09:00:00')));
    }

    public function test_rendered_document_inlines_fragment_text_at_completion(): void
    {
        $organization = Organization::factory()->create();
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
        $waiver = $this->waiverService()->create(
            $organization,
            Waiver::SCOPE_ORGANIZATION,
            (string) $organization->id,
            'Liability Waiver',
            documentType: DocumentAcknowledgment::DOCUMENT_TYPE_POLICY,
            documentId: (string) $document->id,
        );

        $rendered = $this->waiverService()->renderedDocumentFor($waiver);

        $this->assertNotNull($rendered);
        $this->assertSame('Event Liability Policy', $rendered['title']);
        // POL-022: fragment text is document text by the time it is shown.
        $this->assertStringContainsString('<strong>inherent risks</strong>', $rendered['rendered_html']);
        $this->assertStringNotContainsString('{{fragment:waiver-risks}}', $rendered['rendered_html']);
    }
}
