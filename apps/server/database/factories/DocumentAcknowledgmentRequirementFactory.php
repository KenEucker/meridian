<?php

namespace Database\Factories;

use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentAcknowledgmentRequirement>
 */
class DocumentAcknowledgmentRequirementFactory extends Factory
{
    protected $model = DocumentAcknowledgmentRequirement::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'scope_type' => DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
            'scope_id' => fn (array $attributes): string => (string) $attributes['organization_id'],
            'document_type' => DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY,
            'document_id' => function (array $attributes): string {
                return PolicyDocument::factory()->create([
                    'organization_id' => $attributes['organization_id'],
                    'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
                    'scope_id' => $attributes['organization_id'],
                ])->id;
            },
            'requirement_context' => DocumentAcknowledgmentRequirement::CONTEXT_SIGNUP,
            'active' => true,
        ];
    }

    public function forDocument(PolicyDocument|ProcedureDocument $document): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $document->organization_id,
            'document_type' => $document instanceof PolicyDocument
                ? DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY
                : DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_PROCEDURE,
            'document_id' => $document->id,
        ]);
    }

    public function training(): static
    {
        return $this->state(fn (): array => [
            'requirement_context' => DocumentAcknowledgmentRequirement::CONTEXT_TRAINING,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'active' => false,
        ]);
    }
}
