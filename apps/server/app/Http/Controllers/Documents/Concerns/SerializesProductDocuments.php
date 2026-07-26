<?php

namespace App\Http\Controllers\Documents\Concerns;

use App\Domain\Documents\EventInfoSection;
use App\Models\DocumentFragment;
use App\Models\DocumentFragmentReference;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Services\Documents\DocumentRenderer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

trait SerializesProductDocuments
{
    /**
     * @return array<string, mixed>
     */
    private function documentPayload(PolicyDocument|ProcedureDocument $document, DocumentRenderer $renderer): array
    {
        $document->loadMissing([
            'organization',
            'organizationScope',
            'departmentScope',
            'teamScope',
            'fragmentReferences.fragment',
        ]);

        $documentType = $document instanceof PolicyDocument ? 'policy' : 'procedure';

        return [
            'id' => (string) $document->id,
            'document_type' => $documentType,
            'organization_id' => (string) $document->organization_id,
            'scope_type' => $document->scope_type,
            'scope_id' => (string) $document->scope_id,
            'scope_label' => $this->scopeLabel($document),
            'title' => $document->title,
            'slug' => $document->slug,
            'event_info_section' => $document->event_info_section,
            'event_info_section_label' => EventInfoSection::label($document->event_info_section),
            'markdown_source' => $document->markdown_source,
            'rendered_html' => $renderer->render($document),
            'state' => $document->state,
            'state_label' => $document::stateLabels()[$document->state] ?? Str::headline($document->state),
            'version' => $document->version(),
            'published_at' => $document->published_at?->toIso8601String(),
            'archived_at' => $document->archived_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
            'fragment_references' => $document->fragmentReferences
                ->map(fn (DocumentFragmentReference $reference): array => [
                    'token' => $reference->token,
                    'fragment_id' => (string) $reference->fragment_id,
                    'fragment_name' => $reference->fragment?->name,
                    'fragment_slug' => $reference->fragment?->slug,
                    'fragment_version' => $reference->fragment?->version,
                    'fragment_version_at_last_edit' => $reference->fragment_version_at_last_edit,
                ])
                ->values()
                ->all(),
            'visibility_summary' => $this->visibilitySummary($document),
            'export_formats' => ['markdown', 'pdf'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fragmentPayload(DocumentFragment $fragment): array
    {
        $fragment->loadMissing(['organization', 'organizationScope', 'departmentScope', 'teamScope']);

        return [
            'id' => (string) $fragment->id,
            'organization_id' => (string) $fragment->organization_id,
            'scope_type' => $fragment->scope_type,
            'scope_id' => (string) $fragment->scope_id,
            'scope_label' => $this->scopeLabel($fragment),
            'name' => $fragment->name,
            'slug' => $fragment->slug,
            'markdown_source' => $fragment->markdown_source,
            'version' => $fragment->version,
            'updated_at' => $fragment->updated_at?->toIso8601String(),
            'referencing_documents' => $this->referencingDocuments($fragment),
        ];
    }

    private function scopeLabel(PolicyDocument|ProcedureDocument|DocumentFragment $record): string
    {
        return match ($record->scope_type) {
            PolicyDocument::SCOPE_ORGANIZATION => 'Organization: '.($record->organizationScope?->name ?? 'Not configured'),
            PolicyDocument::SCOPE_DEPARTMENT => 'Department: '.($record->departmentScope?->name ?? 'Not configured'),
            PolicyDocument::SCOPE_TEAM => 'Team: '.($record->teamScope?->name ?? 'Not configured'),
            default => Str::headline($record->scope_type),
        };
    }

    private function visibilitySummary(PolicyDocument|ProcedureDocument $document): string
    {
        if (! $document->isPublished()) {
            return 'Draft and archived documents are visible only to permitted maintainers.';
        }

        return match ($document->scope_type) {
            PolicyDocument::SCOPE_ORGANIZATION => 'Published to staff in this organization.',
            PolicyDocument::SCOPE_DEPARTMENT => 'Published to members of this department.',
            PolicyDocument::SCOPE_TEAM => 'Published to members of this team.',
            default => 'Published according to document scope.',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function referencingDocuments(DocumentFragment $fragment): array
    {
        $referenceIds = DocumentFragmentReference::query()
            ->where('fragment_id', $fragment->id)
            ->get(['document_type', 'document_id'])
            ->groupBy('document_type')
            ->map(fn (Collection $references) => $references->pluck('document_id')->unique()->values()->all());

        return collect([
            ...$this->referencingDocumentsFor(
                PolicyDocument::class,
                $referenceIds->get(DocumentFragmentReference::DOCUMENT_TYPE_POLICY, []),
                'policy',
            ),
            ...$this->referencingDocumentsFor(
                ProcedureDocument::class,
                $referenceIds->get(DocumentFragmentReference::DOCUMENT_TYPE_PROCEDURE, []),
                'procedure',
            ),
        ])
            ->sortBy('title')
            ->values()
            ->all();
    }

    /**
     * @param  class-string<PolicyDocument|ProcedureDocument>  $model
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function referencingDocumentsFor(string $model, array $ids, string $documentType): array
    {
        if ($ids === []) {
            return [];
        }

        return $model::query()
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (PolicyDocument|ProcedureDocument $document): array => [
                'id' => (string) $document->id,
                'document_type' => $documentType,
                'title' => $document->title,
                'state' => $document->state,
                'version' => $document->version(),
                'published' => $document->isPublished(),
            ])
            ->all();
    }
}
