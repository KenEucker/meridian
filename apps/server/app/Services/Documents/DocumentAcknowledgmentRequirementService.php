<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\Organization;
use InvalidArgumentException;

/**
 * Creates and retires valid organization- or department-scoped document
 * acknowledgment requirements (POL-023 through POL-027, POL-046, POL-047).
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

    /**
     * Retire a requirement, or put it back (M18.6).
     *
     * A switch rather than a delete, and the acknowledgments already recorded
     * against it are untouched either way. Somebody who read a policy and said
     * so did that; an organizer deciding to stop asking the next person is a
     * different fact, and erasing the first to record the second would lose the
     * one of the two that is history.
     */
    public function setActive(
        DocumentAcknowledgmentRequirement $requirement,
        bool $active,
    ): DocumentAcknowledgmentRequirement {
        $requirement->forceFill(['active' => $active])->save();

        return $requirement->refresh();
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
