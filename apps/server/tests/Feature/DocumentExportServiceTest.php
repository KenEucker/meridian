<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DocumentFragment;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\User;
use App\Services\Documents\DocumentExport;
use App\Services\Documents\DocumentExportService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DocumentExportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_policy_markdown_export_resolves_fragments_and_matches_the_sample(): void
    {
        Carbon::setTestNow('2026-06-21 19:20:30 UTC');

        $organization = Organization::factory()->create();
        DocumentFragment::factory()->for($organization)->create([
            'slug' => 'radio-expectations',
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => 'Use the radio **only when needed**.',
        ]);
        $document = PolicyDocument::factory()->for($organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'title' => 'Volunteer Safety',
            'document_revision' => 3,
            'fragment_revision' => 2,
            'markdown_source' => "## Safe Operations\n\n{{fragment:radio-expectations}}",
        ]);
        $actor = $this->documentExporter('platform.policy-documents');

        $export = app(DocumentExportService::class)->export(
            $document,
            $actor,
            DocumentExport::FORMAT_MARKDOWN,
            AuditEvent::SOURCE_ORCHID,
        );

        $expected = trim((string) file_get_contents(base_path('tests/Fixtures/document-export-sample.md')));

        $this->assertSame($expected, trim($export->contents));
        $this->assertSame('text/markdown; charset=UTF-8', $export->mimeType);
        $this->assertSame('policy-volunteer-safety-3-02.md', $export->filename);
        $this->assertStringNotContainsString('{{fragment:radio-expectations}}', $export->contents);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'policy_document.exported',
            'entity_id' => $document->id,
            'actor_user_id' => $actor->id,
            'organization_id' => $organization->id,
            'source_context' => AuditEvent::SOURCE_ORCHID,
        ]);

        $audit = AuditEvent::query()->sole();
        $this->assertSame(DocumentExport::FORMAT_MARKDOWN, $audit->after_json['format']);
        $this->assertSame('policy', $audit->after_json['document_type']);
        $this->assertSame('3.02', $audit->after_json['document_version']);
    }

    public function test_procedure_pdf_export_contains_rendered_fragment_text_and_metadata(): void
    {
        Carbon::setTestNow('2026-06-21 19:20:30 UTC');

        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        DocumentFragment::factory()->for($organization)->create([
            'slug' => 'gate-safety',
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => 'Keep the gate lane clear.',
        ]);
        $document = ProcedureDocument::factory()->for($organization)->published()->create([
            'scope_type' => ProcedureDocument::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'title' => 'Gate Opening',
            'document_revision' => 4,
            'fragment_revision' => 1,
            'markdown_source' => "# Open Gate\n\n{{fragment:gate-safety}}",
        ]);
        $actor = $this->documentExporter('platform.procedure-documents');

        $export = app(DocumentExportService::class)->export(
            $document,
            $actor,
            DocumentExport::FORMAT_PDF,
            AuditEvent::SOURCE_ORCHID,
        );

        $this->assertStringStartsWith('%PDF-1.4', $export->contents);
        $this->assertStringContainsString('Document type: Procedure', $export->contents);
        $this->assertStringContainsString('Document title: Gate Opening', $export->contents);
        $this->assertStringContainsString('Document version: 4.01', $export->contents);
        $this->assertStringContainsString('Scope: Department', $export->contents);
        $this->assertStringContainsString('Keep the gate lane clear.', $export->contents);
        $this->assertStringNotContainsString('{{fragment:gate-safety}}', $export->contents);
        $this->assertSame('application/pdf', $export->mimeType);
        $this->assertSame('procedure-gate-opening-4-01.pdf', $export->filename);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'procedure_document.exported',
            'entity_id' => $document->id,
            'actor_user_id' => $actor->id,
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'source_context' => AuditEvent::SOURCE_ORCHID,
        ]);

        $this->actingAs($actor)
            ->get(route('platform.procedure-documents.edit', $document))
            ->assertOk()
            ->assertSee('Export Markdown')
            ->assertSee('Export PDF');
    }

    public function test_unauthorized_actor_cannot_export_and_no_audit_event_is_created(): void
    {
        $organization = Organization::factory()->create();
        $document = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);

        try {
            app(DocumentExportService::class)->export(
                $document,
                User::factory()->create(),
                DocumentExport::FORMAT_MARKDOWN,
            );
            $this->fail('Unauthorized users must not export documents.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('audit_events', 0);
        }

        $this->actingAs(User::factory()->create([
            'permissions' => ['platform.index' => true],
        ]))
            ->get(route('platform.policy-documents.export', [
                'policyDocument' => $document,
                'format' => DocumentExport::FORMAT_MARKDOWN,
            ]))
            ->assertForbidden();

        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_saved_policy_orchid_screen_offers_and_downloads_export_actions(): void
    {
        Carbon::setTestNow('2026-06-21 19:20:30 UTC');

        $organization = Organization::factory()->create();
        $document = PolicyDocument::factory()->for($organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'title' => 'Volunteer Safety',
            'document_revision' => 3,
            'fragment_revision' => 2,
            'markdown_source' => 'Follow the safety procedure.',
        ]);
        $actor = $this->documentExporter('platform.policy-documents');

        $this->actingAs($actor)
            ->get(route('platform.policy-documents.edit', $document))
            ->assertOk()
            ->assertSee('Export Markdown')
            ->assertSee('Export PDF')
            ->assertSee(route('platform.policy-documents.export', [
                'policyDocument' => $document,
                'format' => DocumentExport::FORMAT_MARKDOWN,
            ]), false)
            ->assertSee(route('platform.policy-documents.export', [
                'policyDocument' => $document,
                'format' => DocumentExport::FORMAT_PDF,
            ]), false);

        $response = $this->actingAs($actor)->get(route('platform.policy-documents.export', [
            'policyDocument' => $document,
            'format' => DocumentExport::FORMAT_MARKDOWN,
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/markdown; charset=UTF-8');
        $response->assertHeader('content-disposition', 'attachment; filename="policy-volunteer-safety-3-02.md"');
        $response->assertSee('Follow the safety procedure.');
        $this->assertDatabaseHas('audit_events', [
            'action' => 'policy_document.exported',
            'entity_id' => $document->id,
            'actor_user_id' => $actor->id,
            'source_context' => AuditEvent::SOURCE_ORCHID,
        ]);
    }

    public function test_unsupported_format_is_rejected_without_an_audit_event(): void
    {
        $organization = Organization::factory()->create();
        $document = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);

        $this->expectException(InvalidArgumentException::class);

        try {
            app(DocumentExportService::class)->export(
                $document,
                $this->documentExporter('platform.policy-documents'),
                'docx',
            );
        } finally {
            $this->assertDatabaseCount('audit_events', 0);
        }
    }

    private function documentExporter(string $permission): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                $permission => true,
            ],
        ]);
    }
}
