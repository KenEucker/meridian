<?php

namespace App\Services\Waiver;

use App\Models\DocumentAcknowledgment;
use App\Models\DocumentVersionSnapshot;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Staff;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverCompletion;
use App\Services\Documents\DocumentRenderer;
use App\Services\Documents\DocumentScopeValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WaiverService
{
    public function __construct(
        private readonly DocumentScopeValidator $scopes,
        private readonly DocumentRenderer $renderer,
    ) {}

    /**
     * Create a waiver assigned at organization, department, or team scope (WAIVER-001).
     *
     * The waiver may reference a published policy/procedure document as the
     * text being agreed to (WAIVER-007), and is never required to (WAIVER-009).
     * Signed document contents are not stored (WAIVER-004).
     */
    public function create(
        Organization $organization,
        string $scopeType,
        string $scopeId,
        string $name,
        ?string $description = null,
        ?int $expiresAfterDays = null,
        ?string $documentType = null,
        ?string $documentId = null,
    ): Waiver {
        if (! in_array($scopeType, Waiver::scopeTypes(), true)) {
            throw WaiverScopeException::unsupportedScopeType();
        }

        if (! $this->scopes->isValid((string) $organization->id, $scopeType, $scopeId)) {
            throw WaiverScopeException::invalidScopeTarget();
        }

        $this->assertValidDocumentReference($organization, $documentType, $documentId);

        return Waiver::query()->create([
            'organization_id' => $organization->id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'name' => $name,
            'description' => $description,
            'expires_after_days' => $expiresAfterDays,
            'document_type' => $documentType,
            'document_id' => $documentId,
        ]);
    }

    /**
     * Record a staff member's waiver completion (WAIVER-003).
     *
     * Completion is tracked as complete/incomplete through current completion
     * records. The expiration date is derived from the waiver's configured
     * expiry window (WAIVER-002). Signed document contents are not stored
     * (WAIVER-004).
     *
     * When the waiver is document-backed, the completion records the
     * acknowledged document and document version under the same
     * version-recording rule policy/procedure acknowledgments use (WAIVER-008;
     * POL-043), and the acknowledged version's source and resolved text are
     * retained as an immutable snapshot — the completion has to be able to say
     * what the person was shown even after the document moves on. A waiver
     * with no document records exactly what it always did (WAIVER-009).
     */
    public function recordCompletion(
        Waiver $waiver,
        Staff $staff,
        ?Carbon $completedAt = null,
        ?User $recordedBy = null,
    ): WaiverCompletion {
        $completedAt ??= Carbon::now();

        $expiresAt = $waiver->expires_after_days !== null
            ? $completedAt->copy()->addDays($waiver->expires_after_days)
            : null;

        $document = $this->publishedDocumentFor($waiver);

        return DB::transaction(function () use ($waiver, $staff, $completedAt, $expiresAt, $recordedBy, $document): WaiverCompletion {
            $versionColumns = [
                'document_type' => null,
                'document_id' => null,
                'document_revision' => null,
                'fragment_revision' => null,
            ];

            if ($document !== null) {
                $versionColumns = [
                    'document_type' => (string) $waiver->document_type,
                    'document_id' => (string) $document->id,
                    'document_revision' => (int) $document->document_revision,
                    'fragment_revision' => (int) $document->fragment_revision,
                ];

                DocumentVersionSnapshot::query()->firstOrCreate(
                    [
                        'document_type' => (string) $waiver->document_type,
                        'document_id' => (string) $document->id,
                        'document_revision' => (int) $document->document_revision,
                        'fragment_revision' => (int) $document->fragment_revision,
                    ],
                    [
                        'markdown_source_snapshot' => $document->markdown_source,
                        'resolved_markdown_snapshot' => $this->renderer->resolvedMarkdown($document),
                        'snapshot_reason' => 'waiver_completion',
                    ],
                );
            }

            return WaiverCompletion::query()->create([
                'waiver_id' => $waiver->id,
                'staff_id' => $staff->id,
                'completed_at' => $completedAt,
                'expires_at' => $expiresAt,
                'recorded_by_user_id' => $recordedBy?->id,
                ...$versionColumns,
            ]);
        });
    }

    /**
     * Whether the staff member currently satisfies the waiver (WAIVER-003).
     */
    public function isCompleteFor(Waiver $waiver, Staff $staff, ?Carbon $moment = null): bool
    {
        return $waiver->isCompleteFor($staff, $moment);
    }

    /**
     * The referenced document's content rendered for the point of completion,
     * with referenced fragment text inline as document text (WAIVER-007;
     * POL-022). `null` for a waiver with no document reference (WAIVER-009).
     *
     * @return array{title: string, version: string, rendered_html: string}|null
     */
    public function renderedDocumentFor(Waiver $waiver): ?array
    {
        $document = $this->publishedDocumentFor($waiver);

        if ($document === null) {
            return null;
        }

        return [
            'title' => (string) $document->title,
            'version' => $document->version(),
            'rendered_html' => $this->renderer->render($document),
        ];
    }

    /**
     * The published document a document-backed waiver renders and records.
     *
     * A document-backed completion names the version the person was shown, so
     * a reference that no longer resolves to a published document is a refusal
     * rather than a completion that quietly records nothing (WAIVER-007).
     */
    private function publishedDocumentFor(Waiver $waiver): PolicyDocument|ProcedureDocument|null
    {
        if (! $waiver->isDocumentBacked()) {
            return null;
        }

        $document = $waiver->document();

        if ($document === null) {
            throw WaiverDocumentException::unknownDocument();
        }

        if (! $document->isPublished()) {
            throw WaiverDocumentException::documentNotPublished();
        }

        return $document;
    }

    private function assertValidDocumentReference(
        Organization $organization,
        ?string $documentType,
        ?string $documentId,
    ): void {
        if ($documentType === null && $documentId === null) {
            return;
        }

        if ($documentType === null || $documentId === null) {
            throw WaiverDocumentException::incompleteReference();
        }

        $documentModel = DocumentAcknowledgment::documentModelForType($documentType);

        if ($documentModel === null) {
            throw WaiverDocumentException::unsupportedDocumentType();
        }

        /** @var PolicyDocument|ProcedureDocument|null $document */
        $document = $documentModel::query()->find($documentId);

        if ($document === null) {
            throw WaiverDocumentException::unknownDocument();
        }

        if ((string) $document->organization_id !== (string) $organization->id) {
            throw WaiverDocumentException::documentOutsideOrganization();
        }

        if (! $document->isPublished()) {
            throw WaiverDocumentException::documentNotPublished();
        }
    }
}
