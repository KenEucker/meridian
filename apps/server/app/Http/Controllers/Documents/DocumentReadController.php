<?php

namespace App\Http\Controllers\Documents;

use App\Domain\Documents\EventInfoSection;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Documents\Concerns\SerializesProductDocuments;
use App\Models\DocumentFragment;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Services\Documents\DocumentProductAccess;
use App\Services\Documents\DocumentRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DocumentReadController extends Controller
{
    use SerializesProductDocuments;

    public function index(
        Request $request,
        Organization $organization,
        DocumentProductAccess $access,
        DocumentRenderer $renderer,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $state = (string) $request->query('state', 'all');
        if (! in_array($state, ['all', 'draft', 'published', 'archived'], true)) {
            return response()->json(['message' => 'State filter must be all, draft, published, or archived.'], 422);
        }

        $policyDocuments = $this->visibleDocuments(
            PolicyDocument::query()->where('organization_id', $organization->id)->get(),
            $user,
            $access,
            $state,
        );
        $procedureDocuments = $this->visibleDocuments(
            ProcedureDocument::query()->where('organization_id', $organization->id)->get(),
            $user,
            $access,
            $state,
        );
        $fragments = DocumentFragment::query()
            ->where('organization_id', $organization->id)
            ->get()
            ->filter(fn (DocumentFragment $fragment): bool => $access->canMaintainFragment($user, $fragment))
            ->values();
        $maintainableScopes = $access->maintainableScopes($user, $organization);

        return response()->json([
            'organization_id' => (string) $organization->id,
            // What this caller may maintain in this organization, and the
            // Event Info placements a document may be assigned to (11.4A).
            // The authoring form needs both before it has a document to read
            // them off, and answering them here keeps the options the form
            // offers and the options the commands accept the same options.
            'access' => [
                'can_maintain' => $maintainableScopes !== [],
                'scopes' => $maintainableScopes,
            ],
            'event_info_sections' => array_map(
                fn (string $section): array => [
                    'value' => $section,
                    'label' => EventInfoSection::label($section),
                ],
                EventInfoSection::keys(),
            ),
            'documents' => $policyDocuments
                ->concat($procedureDocuments)
                ->sortBy(fn (PolicyDocument|ProcedureDocument $document): string => $document->title.'|'.($document instanceof PolicyDocument ? 'policy' : 'procedure'))
                ->map(fn (PolicyDocument|ProcedureDocument $document): array => $this->documentPayload($document, $renderer, $access, $user))
                ->values()
                ->all(),
            'fragments' => $fragments
                ->sortBy('name')
                ->map(fn (DocumentFragment $fragment): array => $this->fragmentPayload($fragment))
                ->values()
                ->all(),
        ]);
    }

    public function policy(
        Request $request,
        PolicyDocument $policyDocument,
        DocumentProductAccess $access,
        DocumentRenderer $renderer,
    ): JsonResponse {
        return $this->showDocument($request, $policyDocument, $access, $renderer);
    }

    public function procedure(
        Request $request,
        ProcedureDocument $procedureDocument,
        DocumentProductAccess $access,
        DocumentRenderer $renderer,
    ): JsonResponse {
        return $this->showDocument($request, $procedureDocument, $access, $renderer);
    }

    public function fragment(
        Request $request,
        DocumentFragment $fragment,
        DocumentProductAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canMaintainFragment($user, $fragment)) {
            return response()->json(['message' => 'You do not have permission to maintain this fragment.'], 403);
        }

        return response()->json($this->fragmentPayload($fragment));
    }

    private function showDocument(
        Request $request,
        PolicyDocument|ProcedureDocument $document,
        DocumentProductAccess $access,
        DocumentRenderer $renderer,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canViewDocument($user, $document)) {
            return response()->json(['message' => 'You do not have permission to view this document.'], 403);
        }

        return response()->json($this->documentPayload($document, $renderer, $access, $user));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PolicyDocument|ProcedureDocument>  $documents
     * @return \Illuminate\Support\Collection<int, PolicyDocument|ProcedureDocument>
     */
    private function visibleDocuments($documents, $user, DocumentProductAccess $access, string $state)
    {
        return $documents
            ->filter(fn (PolicyDocument|ProcedureDocument $document): bool => $access->canViewDocument($user, $document))
            ->filter(fn (PolicyDocument|ProcedureDocument $document): bool => $state === 'all' || $document->state === $state)
            ->values();
    }
}
