<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\AuditEvent;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Persists policy/procedure edits, keeping fragment references, version
 * metadata, authorship, and audit history in one transaction.
 */
class DocumentAdminService
{
    public function __construct(
        private readonly DocumentFragmentReferenceService $references,
        private readonly AuditService $audit,
        private readonly DocumentScopeValidator $scopes,
    ) {}

    /**
     * @param  array<string, string|null>  $attributes
     */
    public function save(
        PolicyDocument|ProcedureDocument $document,
        array $attributes,
        User $actor,
        ?string $reason = null,
        string $sourceContext = AuditEvent::SOURCE_ORCHID,
    ): PolicyDocument|ProcedureDocument {
        return DB::transaction(function () use ($document, $attributes, $actor, $reason, $sourceContext): PolicyDocument|ProcedureDocument {
            $isNew = ! $document->exists;
            $before = $isNew ? null : $this->snapshot($document);

            $document->fill($attributes);

            if ($isNew) {
                $document->forceFill([
                    'document_revision' => 1,
                    'fragment_revision' => 0,
                    'created_by_user_id' => $actor->id,
                ]);
            } elseif ($document->getOriginal('state') === $document::STATE_PUBLISHED && $document->isDirty([
                // `event_info_section` is deliberately absent: moving a
                // published document onto or off Event Info changes where it is
                // displayed, not what it says, and a version bump would tell
                // acknowledgment review that the text changed when it did not.
                'organization_id',
                'scope_type',
                'scope_id',
                'title',
                'slug',
                'markdown_source',
            ])) {
                $document->forceFill([
                    'document_revision' => (int) $document->getOriginal('document_revision') + 1,
                    'fragment_revision' => 0,
                ]);
            }

            $this->applyLifecycleTimestamps($document, $isNew);
            $document->updated_by_user_id = $actor->id;
            $document->save();

            $this->references->synchronize($document);

            $after = $this->snapshot($document->refresh());

            if ($isNew || $before !== $after) {
                $this->audit->recordForEntity(
                    entity: $document,
                    action: $this->auditAction($document, $before),
                    actorUser: $actor,
                    organizationId: $document->organization_id,
                    departmentId: $this->scopes->departmentId($document->scope_type, $document->scope_id),
                    before: $before,
                    after: $after,
                    reason: $reason,
                    sourceContext: $sourceContext,
                );
            }

            return $document->load('fragmentReferences.fragment');
        });
    }

    private function applyLifecycleTimestamps(PolicyDocument|ProcedureDocument $document, bool $isNew): void
    {
        $originalState = $isNew ? null : $document->getOriginal('state');

        if ($document->state === $document::STATE_PUBLISHED && $originalState !== $document::STATE_PUBLISHED) {
            $document->published_at = now();
        }

        if ($document->state === $document::STATE_ARCHIVED && $originalState !== $document::STATE_ARCHIVED) {
            $document->archived_at = now();
        }

        if ($document->state !== $document::STATE_ARCHIVED) {
            $document->archived_at = null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    private function auditAction(PolicyDocument|ProcedureDocument $document, ?array $before): string
    {
        $prefix = $document instanceof PolicyDocument ? 'policy_document' : 'procedure_document';

        if ($before === null) {
            return $prefix.'.created';
        }

        return match ($document->state) {
            $document::STATE_PUBLISHED => $before['state'] === $document::STATE_PUBLISHED
                ? $prefix.'.updated'
                : $prefix.'.published',
            $document::STATE_ARCHIVED => $before['state'] === $document::STATE_ARCHIVED
                ? $prefix.'.updated'
                : $prefix.'.archived',
            default => $prefix.'.updated',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(PolicyDocument|ProcedureDocument $document): array
    {
        return [
            'organization_id' => $document->organization_id,
            'scope_type' => $document->scope_type,
            'scope_id' => $document->scope_id,
            'title' => $document->title,
            'slug' => $document->slug,
            'event_info_section' => $document->event_info_section,
            'markdown_source' => $document->markdown_source,
            'state' => $document->state,
            'document_revision' => $document->document_revision,
            'fragment_revision' => $document->fragment_revision,
            'published_at' => $document->published_at?->toIso8601String(),
            'archived_at' => $document->archived_at?->toIso8601String(),
        ];
    }
}
