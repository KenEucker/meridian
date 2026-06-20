<?php

namespace App\Jobs;

use App\Models\DocumentFragmentReference;
use App\Models\DocumentFragmentVersionBump;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Bumps the fragment-revision component of every published document affected
 * by one document-fragment version change (POL-039 through POL-042).
 */
class BumpPublishedDocumentFragmentRevisions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $fragmentId,
        public readonly int $fragmentVersion,
    ) {}

    public function handle(): void
    {
        DocumentFragmentReference::query()
            ->where('fragment_id', $this->fragmentId)
            ->get(['document_type', 'document_id'])
            ->unique(fn (DocumentFragmentReference $reference): string => $reference->document_type.':'.$reference->document_id)
            ->each(function (DocumentFragmentReference $reference): void {
                $this->bumpPublishedDocument($reference->document_type, $reference->document_id);
            });
    }

    private function bumpPublishedDocument(string $documentType, string $documentId): void
    {
        $documentClass = match ($documentType) {
            DocumentFragmentReference::DOCUMENT_TYPE_POLICY => PolicyDocument::class,
            DocumentFragmentReference::DOCUMENT_TYPE_PROCEDURE => ProcedureDocument::class,
            default => null,
        };

        if ($documentClass === null) {
            return;
        }

        DB::transaction(function () use ($documentClass, $documentType, $documentId): void {
            /** @var PolicyDocument|ProcedureDocument|null $document */
            $document = $documentClass::query()
                ->published()
                ->lockForUpdate()
                ->find($documentId);

            if ($document === null) {
                return;
            }

            $bump = DocumentFragmentVersionBump::query()->firstOrCreate([
                'fragment_id' => $this->fragmentId,
                'fragment_version' => $this->fragmentVersion,
                'document_type' => $documentType,
                'document_id' => $documentId,
            ]);

            if ($bump->wasRecentlyCreated) {
                $document->increment('fragment_revision');
            }
        });
    }
}
