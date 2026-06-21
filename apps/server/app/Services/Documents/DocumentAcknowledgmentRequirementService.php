<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\Organization;
use InvalidArgumentException;

/**
 * Creates valid organization- or department-scoped document acknowledgment
 * requirements. Recording a person's acknowledgment remains M6.10 work.
 */
class DocumentAcknowledgmentRequirementService
{
    public function __construct(private readonly DocumentScopeValidator $scopes) {}

    public function create(
        Organization $organization,
        string $documentType,
        string $documentId,
        string $scopeType,
        string $scopeId,
        string $requirementContext,
        bool $active = true,
    ): DocumentAcknowledgmentRequirement {
        $this->assertSupportedDocumentType($documentType);
        $this->assertSupportedScopeType($scopeType);
        $this->assertSupportedContext($requirementContext);

        $documentModel = DocumentAcknowledgmentRequirement::documentModelForType($documentType);

        if ($documentModel === null) {
            throw new InvalidArgumentException('Unsupported acknowledgment document type.');
        }

        $document = $documentModel::query()->find($documentId);

        if ($document === null) {
            throw new InvalidArgumentException('The selected acknowledgment document does not exist.');
        }

        if ((string) $document->organization_id !== (string) $organization->id) {
            throw new InvalidArgumentException('The selected acknowledgment document must belong to the requirement organization.');
        }

        if (! $this->scopes->isValid($organization->id, $scopeType, $scopeId)) {
            throw new InvalidArgumentException('The scope target must belong to the requirement organization and scope type.');
        }

        return DocumentAcknowledgmentRequirement::query()->create([
            'organization_id' => $organization->id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'requirement_context' => $requirementContext,
            'active' => $active,
        ]);
    }

    private function assertSupportedDocumentType(string $documentType): void
    {
        if (! in_array($documentType, DocumentAcknowledgmentRequirement::documentTypes(), true)) {
            throw new InvalidArgumentException('Unsupported acknowledgment document type.');
        }
    }

    private function assertSupportedScopeType(string $scopeType): void
    {
        if (! in_array($scopeType, DocumentAcknowledgmentRequirement::scopeTypes(), true)) {
            throw new InvalidArgumentException('Acknowledgment requirements must use organization or department scope.');
        }
    }

    private function assertSupportedContext(string $requirementContext): void
    {
        if (! in_array($requirementContext, DocumentAcknowledgmentRequirement::requirementContexts(), true)) {
            throw new InvalidArgumentException('Acknowledgment requirements may only be used during signup or training.');
        }
    }
}
