<?php

namespace Database\Factories;

use App\Models\DocumentAcknowledgment;
use App\Models\DocumentVersionSnapshot;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentVersionSnapshot>
 */
class DocumentVersionSnapshotFactory extends Factory
{
    protected $model = DocumentVersionSnapshot::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_type' => DocumentAcknowledgment::DOCUMENT_TYPE_POLICY,
            'document_id' => function (): string {
                $organization = Organization::factory()->create();

                return PolicyDocument::factory()->for($organization)->published()->create([
                    'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
                    'scope_id' => $organization->id,
                ])->id;
            },
            'document_revision' => 1,
            'fragment_revision' => 0,
            'markdown_source_snapshot' => '# Acknowledgment document',
            'resolved_markdown_snapshot' => '# Acknowledgment document',
            'snapshot_reason' => 'acknowledgment',
        ];
    }

    public function forDocument(PolicyDocument|ProcedureDocument $document): static
    {
        return $this->state(fn (): array => [
            'document_type' => $document instanceof PolicyDocument
                ? DocumentAcknowledgment::DOCUMENT_TYPE_POLICY
                : DocumentAcknowledgment::DOCUMENT_TYPE_PROCEDURE,
            'document_id' => $document->id,
            'document_revision' => $document->document_revision,
            'fragment_revision' => $document->fragment_revision,
            'markdown_source_snapshot' => $document->markdown_source,
            'resolved_markdown_snapshot' => $document->markdown_source,
        ]);
    }
}
