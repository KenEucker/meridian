<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\AuditEvent;
use App\Models\DocumentAcknowledgment;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\DocumentVersionSnapshot;
use App\Models\Node;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Staff;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Accepts a policy/procedure acknowledgment through the connected Laravel
 * write path. Offline acknowledgment creation remains out of scope for Alpha 1.
 */
class DocumentAcknowledgmentService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly DocumentRenderer $renderer,
        private readonly DocumentScopeValidator $scopes,
    ) {}

    public function acknowledge(
        DocumentAcknowledgmentRequirement $requirement,
        User $user,
        Node $acceptedByNode,
        ?Staff $staff = null,
    ): DocumentAcknowledgment {
        return DB::transaction(function () use ($requirement, $user, $acceptedByNode, $staff): DocumentAcknowledgment {
            $requirement = DocumentAcknowledgmentRequirement::query()
                ->lockForUpdate()
                ->find($requirement->getKey());

            if ($requirement === null) {
                throw new InvalidArgumentException('The acknowledgment requirement does not exist.');
            }

            $this->assertValidRequirement($requirement);
            $node = $this->activeNodeFor($acceptedByNode, $requirement->organization_id);
            $this->assertStaffBelongsToUser($staff, $user);

            $document = $this->documentFor($requirement);
            $documentType = $requirement->document_type;
            $documentRevision = $document->document_revision;
            $fragmentRevision = $document->fragment_revision;

            DocumentVersionSnapshot::query()->firstOrCreate(
                [
                    'document_type' => $documentType,
                    'document_id' => $document->id,
                    'document_revision' => $documentRevision,
                    'fragment_revision' => $fragmentRevision,
                ],
                [
                    'markdown_source_snapshot' => $document->markdown_source,
                    'resolved_markdown_snapshot' => $this->renderer->resolvedMarkdown($document),
                    'snapshot_reason' => 'acknowledgment',
                ],
            );

            $acknowledgment = DocumentAcknowledgment::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'document_type' => $documentType,
                    'document_id' => $document->id,
                    'document_revision' => $documentRevision,
                    'fragment_revision' => $fragmentRevision,
                    'scope_type' => $requirement->scope_type,
                    'scope_id' => $requirement->scope_id,
                ],
                [
                    'staff_id' => $staff?->id,
                    'acknowledged_at' => now(),
                    'accepted_by_node_id' => $node->id,
                ],
            );

            if ($acknowledgment->wasRecentlyCreated) {
                $this->audit->recordForEntity(
                    entity: $acknowledgment,
                    action: 'document_acknowledgment.accepted',
                    actorUser: $user,
                    actorNode: $node,
                    organizationId: $requirement->organization_id,
                    departmentId: $this->scopes->departmentId($requirement->scope_type, $requirement->scope_id),
                    after: $this->auditSnapshot($acknowledgment),
                    sourceContext: AuditEvent::SOURCE_API,
                );
            }

            return $acknowledgment;
        });
    }

    private function assertValidRequirement(DocumentAcknowledgmentRequirement $requirement): void
    {
        if (! $requirement->isActive()) {
            throw new InvalidArgumentException('The acknowledgment requirement is inactive.');
        }

        if (! in_array($requirement->document_type, DocumentAcknowledgment::documentTypes(), true)) {
            throw new InvalidArgumentException('The acknowledgment requirement has an unsupported document type.');
        }

        if (! in_array($requirement->scope_type, DocumentAcknowledgmentRequirement::scopeTypes(), true)
            || ! $this->scopes->isValid($requirement->organization_id, $requirement->scope_type, $requirement->scope_id)) {
            throw new InvalidArgumentException('The acknowledgment requirement has an invalid scope target.');
        }

        if (! in_array($requirement->requirement_context, DocumentAcknowledgmentRequirement::requirementContexts(), true)) {
            throw new InvalidArgumentException('The acknowledgment requirement has an unsupported context.');
        }
    }

    private function activeNodeFor(Node $acceptedByNode, string $organizationId): Node
    {
        $node = Node::query()->active()->find($acceptedByNode->getKey());

        if ($node === null) {
            throw new InvalidArgumentException('Acknowledgments must be accepted by an active connected node.');
        }

        if ($node->organization_id !== null && $node->organization_id !== $organizationId) {
            throw new InvalidArgumentException('The accepting node must belong to the acknowledgment organization.');
        }

        return $node;
    }

    private function assertStaffBelongsToUser(?Staff $staff, User $user): void
    {
        if ($staff === null) {
            return;
        }

        if (! $user->staffProfiles()->whereKey($staff->getKey())->exists()) {
            throw new InvalidArgumentException('The acknowledgment staff profile must belong to the acknowledging user.');
        }
    }

    private function documentFor(DocumentAcknowledgmentRequirement $requirement): PolicyDocument|ProcedureDocument
    {
        $documentModel = DocumentAcknowledgment::documentModelForType($requirement->document_type);

        if ($documentModel === null) {
            throw new InvalidArgumentException('The acknowledgment requirement has an unsupported document type.');
        }

        /** @var PolicyDocument|ProcedureDocument|null $document */
        $document = $documentModel::query()->find($requirement->document_id);

        if ($document === null) {
            throw new InvalidArgumentException('The acknowledgment document does not exist.');
        }

        if ($document->organization_id !== $requirement->organization_id) {
            throw new InvalidArgumentException('The acknowledgment document must belong to the requirement organization.');
        }

        if (! $document->isPublished()) {
            throw new InvalidArgumentException('Only published documents may be acknowledged.');
        }

        return $document;
    }

    /**
     * @return array<string, int|string|null>
     */
    private function auditSnapshot(DocumentAcknowledgment $acknowledgment): array
    {
        return [
            'user_id' => $acknowledgment->user_id,
            'staff_id' => $acknowledgment->staff_id,
            'document_type' => $acknowledgment->document_type,
            'document_id' => $acknowledgment->document_id,
            'document_revision' => $acknowledgment->document_revision,
            'fragment_revision' => $acknowledgment->fragment_revision,
            'scope_type' => $acknowledgment->scope_type,
            'scope_id' => $acknowledgment->scope_id,
            'accepted_by_node_id' => $acknowledgment->accepted_by_node_id,
        ];
    }
}
