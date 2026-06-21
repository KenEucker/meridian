<?php

namespace Database\Factories;

use App\Models\DocumentAcknowledgment;
use App\Models\Node;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentAcknowledgment>
 */
class DocumentAcknowledgmentFactory extends Factory
{
    protected $model = DocumentAcknowledgment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'staff_id' => null,
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
            'scope_type' => DocumentAcknowledgment::SCOPE_ORGANIZATION,
            'scope_id' => fn (array $attributes): string => (string) DocumentAcknowledgment::documentModelForType($attributes['document_type'])::query()
                ->whereKey($attributes['document_id'])
                ->value('organization_id'),
            'acknowledged_at' => now(),
            'accepted_by_node_id' => Node::factory(),
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
            'scope_type' => DocumentAcknowledgment::SCOPE_ORGANIZATION,
            'scope_id' => $document->organization_id,
        ]);
    }

    public function forStaff(Staff $staff): static
    {
        return $this->state(fn (): array => [
            'staff_id' => $staff->id,
        ]);
    }
}
