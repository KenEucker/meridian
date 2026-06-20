<?php

namespace App\Services\Documents;

use App\Models\Department;
use App\Models\DocumentFragment;
use App\Models\DocumentFragmentReference;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Validates and stores the resolved fragment references for a policy or
 * procedure document (POL-019 through POL-022, POL-036, and POL-037).
 */
class DocumentFragmentReferenceService
{
    public function __construct(private readonly DocumentFragmentReferenceParser $parser) {}

    /**
     * Validate the document source without changing its stored references.
     *
     * @return list<array{token: string, slug: string, fragment: DocumentFragment}>
     *
     * @throws DocumentFragmentReferenceException
     */
    public function validate(PolicyDocument|ProcedureDocument $document): array
    {
        $references = $this->parser->parse($document->markdown_source);

        return array_map(function (array $reference) use ($document): array {
            $fragment = $this->resolveFragment($document, $reference['slug']);

            return [
                ...$reference,
                'fragment' => $fragment,
            ];
        }, $references);
    }

    /**
     * Persist the current reference set after validation succeeds.
     *
     * @return list<DocumentFragmentReference>
     *
     * @throws DocumentFragmentReferenceException
     */
    public function synchronize(PolicyDocument|ProcedureDocument $document): array
    {
        if (! $document->exists) {
            throw new LogicException('Persist the document before synchronizing fragment references.');
        }

        $resolvedReferences = $this->validate($document);
        $documentType = $this->documentTypeFor($document);

        return DB::transaction(function () use ($document, $documentType, $resolvedReferences): array {
            $tokens = array_column($resolvedReferences, 'token');

            $staleReferences = DocumentFragmentReference::query()
                ->where('document_type', $documentType)
                ->where('document_id', $document->id);

            if ($tokens === []) {
                $staleReferences->delete();
            } else {
                $staleReferences->whereNotIn('token', $tokens)->delete();
            }

            return array_map(function (array $reference) use ($document, $documentType): DocumentFragmentReference {
                /** @var DocumentFragment $fragment */
                $fragment = $reference['fragment'];

                return DocumentFragmentReference::query()->updateOrCreate(
                    [
                        'document_type' => $documentType,
                        'document_id' => $document->id,
                        'token' => $reference['token'],
                    ],
                    [
                        'fragment_id' => $fragment->id,
                        'fragment_version_at_last_edit' => $fragment->version,
                    ],
                );
            }, $resolvedReferences);
        });
    }

    /**
     * @throws BrokenDocumentFragmentReferenceException
     * @throws IneligibleDocumentFragmentReferenceException
     */
    private function resolveFragment(PolicyDocument|ProcedureDocument $document, string $slug): DocumentFragment
    {
        $fragments = DocumentFragment::query()
            ->where('organization_id', $document->organization_id)
            ->where('slug', $slug)
            ->get();

        if ($fragments->isEmpty()) {
            throw new BrokenDocumentFragmentReferenceException(
                "The fragment reference '{$slug}' does not resolve within this organization.",
            );
        }

        if ($fragments->count() > 1) {
            throw new BrokenDocumentFragmentReferenceException(
                "The fragment reference '{$slug}' is ambiguous within this organization.",
            );
        }

        /** @var DocumentFragment $fragment */
        $fragment = $fragments->sole();

        if (! $this->fragmentIsEligibleForDocument($fragment, $document)) {
            throw new IneligibleDocumentFragmentReferenceException(
                "The fragment reference '{$slug}' is outside the document's allowed scope.",
            );
        }

        return $fragment;
    }

    private function fragmentIsEligibleForDocument(
        DocumentFragment $fragment,
        PolicyDocument|ProcedureDocument $document,
    ): bool {
        $documentScope = $this->documentScope($document);

        if ($documentScope === null || ! $this->hasValidFragmentScope($fragment)) {
            return false;
        }

        if ($fragment->scope_type === DocumentFragment::SCOPE_ORGANIZATION) {
            return true;
        }

        if ($fragment->scope_type === DocumentFragment::SCOPE_DEPARTMENT) {
            return match ($documentScope['type']) {
                DocumentFragment::SCOPE_DEPARTMENT => $documentScope['id'] === $fragment->scope_id,
                DocumentFragment::SCOPE_TEAM => $documentScope['department_id'] === $fragment->scope_id,
                default => false,
            };
        }

        return $documentScope['type'] === DocumentFragment::SCOPE_TEAM
            && $documentScope['id'] === $fragment->scope_id;
    }

    /**
     * @return array{type: string, id: string, department_id?: string}|null
     */
    private function documentScope(PolicyDocument|ProcedureDocument $document): ?array
    {
        if ($document->scope_type === DocumentFragment::SCOPE_ORGANIZATION) {
            return $document->scope_id === $document->organization_id
                ? ['type' => DocumentFragment::SCOPE_ORGANIZATION, 'id' => $document->scope_id]
                : null;
        }

        if ($document->scope_type === DocumentFragment::SCOPE_DEPARTMENT) {
            $department = Department::query()
                ->whereKey($document->scope_id)
                ->where('organization_id', $document->organization_id)
                ->first();

            return $department === null
                ? null
                : ['type' => DocumentFragment::SCOPE_DEPARTMENT, 'id' => $department->id];
        }

        if ($document->scope_type === DocumentFragment::SCOPE_TEAM) {
            $team = Team::query()
                ->whereKey($document->scope_id)
                ->whereHas('department', fn ($query) => $query->where('organization_id', $document->organization_id))
                ->first();

            return $team === null
                ? null
                : [
                    'type' => DocumentFragment::SCOPE_TEAM,
                    'id' => $team->id,
                    'department_id' => $team->department_id,
                ];
        }

        return null;
    }

    private function hasValidFragmentScope(DocumentFragment $fragment): bool
    {
        if ($fragment->scope_type === DocumentFragment::SCOPE_ORGANIZATION) {
            return $fragment->scope_id === $fragment->organization_id;
        }

        if ($fragment->scope_type === DocumentFragment::SCOPE_DEPARTMENT) {
            return Department::query()
                ->whereKey($fragment->scope_id)
                ->where('organization_id', $fragment->organization_id)
                ->exists();
        }

        if ($fragment->scope_type === DocumentFragment::SCOPE_TEAM) {
            return Team::query()
                ->whereKey($fragment->scope_id)
                ->whereHas('department', fn ($query) => $query->where('organization_id', $fragment->organization_id))
                ->exists();
        }

        return false;
    }

    private function documentTypeFor(PolicyDocument|ProcedureDocument $document): string
    {
        return $document instanceof PolicyDocument
            ? DocumentFragmentReference::DOCUMENT_TYPE_POLICY
            : DocumentFragmentReference::DOCUMENT_TYPE_PROCEDURE;
    }
}
