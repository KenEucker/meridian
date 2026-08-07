<?php

declare(strict_types=1);

namespace App\Services\Offline\Concerns;

use App\Domain\Modules\ModuleKey;
use App\Models\DocumentFragment;
use App\Models\DocumentFragmentReference;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Services\Offline\OfflineReadSetSection;
use Illuminate\Database\Eloquent\Builder;

/**
 * The governance text a maintainer holds offline (technical spec 9.3; POL-*).
 *
 * "Draft, published, and archived policy/procedure documents and fragments they
 * are allowed to maintain" is one line of section 9.3 that two different roles
 * answer at two different scopes: a designated team lead maintains their team's,
 * and a department lead maintains their department's
 * ({@see \App\Services\Documents\DocumentProductAccess::canMaintainScope}). The
 * scopes differ; the composition does not, so it lives here once.
 *
 * The state filter is the point of it. Every staff member already holds the
 * published documents in their audience through the regular-staff list; what a
 * maintainer additionally holds is the *unpublished* ones — the draft they are
 * part-way through and the archived revision they may need to look back at. A
 * maintainer with no signal who could see only what everybody else can see would
 * have no reason to open the surface at all.
 *
 * `sync-config.yaml` gave a department lead the team-scoped documents of every
 * team in their department. That exceeded what the API allows: team scope is
 * maintained by that team's designated leads and by nobody else, and a device
 * may not hold what its user could not retrieve (CLIENT-021). Composing through
 * one scope rule is what makes the two agree.
 */
trait ComposesDocumentSections
{
    /**
     * @param  list<string>  $scopeIds
     * @return list<OfflineReadSetSection>
     */
    private function maintainableDocumentSections(string $prefix, string $scopeType, array $scopeIds): array
    {
        $policyIds = $this->maintainableDocumentIds(PolicyDocument::class, $scopeType, $scopeIds);
        $procedureIds = $this->maintainableDocumentIds(ProcedureDocument::class, $scopeType, $scopeIds);

        $references = $this->rows(
            DocumentFragmentReference::query()
                ->where(function (Builder $query) use ($policyIds, $procedureIds): void {
                    $query
                        ->where(fn (Builder $policy) => $policy
                            ->where('document_type', DocumentFragmentReference::DOCUMENT_TYPE_POLICY)
                            ->whereIn('document_id', $policyIds))
                        ->orWhere(fn (Builder $procedure) => $procedure
                            ->where('document_type', DocumentFragmentReference::DOCUMENT_TYPE_PROCEDURE)
                            ->whereIn('document_id', $procedureIds));
                })
                ->orderBy('id'),
            fn (DocumentFragmentReference $reference): array => [
                'id' => (string) $reference->getKey(),
                'document_type' => $reference->document_type,
                'document_id' => (string) $reference->document_id,
                'fragment_id' => (string) $reference->fragment_id,
                'token' => $reference->token,
                'fragment_version_at_last_edit' => $reference->fragment_version_at_last_edit,
            ],
        );

        return [
            OfflineReadSetSection::owned($prefix.'_policy_documents', ModuleKey::Documents, $this->rows(
                PolicyDocument::query()
                    ->where('scope_type', $scopeType)
                    ->whereIn('scope_id', $scopeIds)
                    ->orderBy('title')
                    ->orderBy('id'),
                fn (PolicyDocument $document): array => $this->maintainableDocumentRow($document),
            )),

            OfflineReadSetSection::owned($prefix.'_procedure_documents', ModuleKey::Documents, $this->rows(
                ProcedureDocument::query()
                    ->where('scope_type', $scopeType)
                    ->whereIn('scope_id', $scopeIds)
                    ->orderBy('title')
                    ->orderBy('id'),
                fn (ProcedureDocument $document): array => $this->maintainableDocumentRow($document),
            )),

            OfflineReadSetSection::owned($prefix.'_document_fragment_references', ModuleKey::Documents, $references),

            /*
             * Two ways in, and both are the maintainer's own: the fragments their
             * documents reference, and the fragments in the scope they maintain,
             * which they may edit whether or not a document uses one yet.
             */
            OfflineReadSetSection::owned($prefix.'_document_fragments', ModuleKey::Documents, $this->rows(
                DocumentFragment::query()
                    ->where(function (Builder $query) use ($scopeType, $scopeIds, $references): void {
                        $query
                            ->where(fn (Builder $owned) => $owned
                                ->where('scope_type', $scopeType)
                                ->whereIn('scope_id', $scopeIds))
                            ->orWhereIn('id', array_column($references, 'fragment_id'));
                    })
                    ->orderBy('name')
                    ->orderBy('id'),
                fn (DocumentFragment $fragment): array => [
                    'id' => (string) $fragment->getKey(),
                    'organization_id' => (string) $fragment->organization_id,
                    'scope_type' => $fragment->scope_type,
                    'scope_id' => (string) $fragment->scope_id,
                    'name' => $fragment->name,
                    'slug' => $fragment->slug,
                    'markdown_source' => $fragment->markdown_source,
                    'version' => $fragment->version,
                ],
            )),
        ];
    }

    /**
     * @param  class-string<PolicyDocument|ProcedureDocument>  $documentClass
     * @param  list<string>  $scopeIds
     * @return list<string>
     */
    private function maintainableDocumentIds(string $documentClass, string $scopeType, array $scopeIds): array
    {
        /** @var list<string> $ids */
        $ids = $documentClass::query()
            ->where('scope_type', $scopeType)
            ->whereIn('scope_id', $scopeIds)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return $ids;
    }

    /**
     * The maintainer's view of a document, which carries its state.
     *
     * The reader's view does not: everything in the regular-staff list is
     * published by construction, so a `state` column there would say the same
     * word on every row. Here it is the difference between the draft somebody is
     * writing and the revision they retired.
     *
     * @return array<string, mixed>
     */
    private function maintainableDocumentRow(PolicyDocument|ProcedureDocument $document): array
    {
        return [
            'id' => (string) $document->getKey(),
            'organization_id' => (string) $document->organization_id,
            'scope_type' => $document->scope_type,
            'scope_id' => (string) $document->scope_id,
            'title' => $document->title,
            'slug' => $document->slug,
            'markdown_source' => $document->markdown_source,
            'state' => $document->state,
            'document_revision' => $document->document_revision,
            'fragment_revision' => $document->fragment_revision,
            'published_at' => $this->moment($document->published_at),
            'archived_at' => $this->moment($document->archived_at),
        ];
    }
}
