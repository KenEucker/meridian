<?php

namespace Tests\Feature;

use App\Models\DocumentFragment;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Services\Documents\DocumentRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class DocumentRendererTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_document_renders_sanitized_markdown_and_current_fragment_content_inline(): void
    {
        $organization = Organization::factory()->create();
        $fragment = DocumentFragment::factory()->for($organization)->create([
            'slug' => 'radio-expectations',
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => "## Radio expectations\n\nUse the radio **only when needed**. <script>alert('fragment')</script>",
        ]);
        $document = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => "# Safety\n\n<script>alert('document')</script>\n\n{{fragment:radio-expectations}}\n\n[Unsafe](javascript:alert('link'))",
        ]);

        $rendered = app(DocumentRenderer::class)->render($document);

        $this->assertStringContainsString('<h1>Safety</h1>', $rendered);
        $this->assertStringContainsString('<h2>Radio expectations</h2>', $rendered);
        $this->assertStringContainsString('<strong>only when needed</strong>', $rendered);
        $this->assertStringNotContainsString('{{fragment:radio-expectations}}', $rendered);
        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringNotContainsString('javascript:', $rendered);
        $this->assertStringNotContainsString('MERIDIAN_DOCUMENT_FRAGMENT_', $rendered);

        $fragment->update(['markdown_source' => 'Use the updated radio procedure.']);

        $updated = app(DocumentRenderer::class)->render($document);

        $this->assertStringContainsString('Use the updated radio procedure.', $updated);
        $this->assertStringNotContainsString('only when needed', $updated);
    }

    public function test_procedure_document_renders_inline_paragraph_fragment_with_surrounding_prose(): void
    {
        $organization = Organization::factory()->create();
        DocumentFragment::factory()->for($organization)->create([
            'slug' => 'check-in-greeting',
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => 'welcome **every volunteer**',
        ]);
        $document = ProcedureDocument::factory()->for($organization)->create([
            'scope_type' => ProcedureDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => 'At check-in, {{fragment:check-in-greeting}} before verifying the wristband.',
        ]);

        $rendered = app(DocumentRenderer::class)->render($document);

        $this->assertStringContainsString(
            '<p>At check-in, welcome <strong>every volunteer</strong> before verifying the wristband.</p>',
            $rendered,
        );
    }

    public function test_document_viewer_component_shows_metadata_and_rendered_content(): void
    {
        $organization = Organization::factory()->create();
        $document = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'title' => 'Volunteer Safety',
            'state' => PolicyDocument::STATE_PUBLISHED,
            'document_revision' => 2,
            'fragment_revision' => 4,
            'markdown_source' => 'Read the **safety** guidance.',
        ]);

        $rendered = Blade::render('<x-document-viewer :document="$document" />', compact('document'));

        $this->assertStringContainsString('<article', $rendered);
        $this->assertStringContainsString('Policy', $rendered);
        $this->assertStringContainsString('Volunteer Safety', $rendered);
        $this->assertStringContainsString('Organization', $rendered);
        $this->assertStringContainsString('2.04', $rendered);
        $this->assertStringContainsString('Published', $rendered);
        $this->assertStringContainsString('<strong>safety</strong>', $rendered);
    }
}
