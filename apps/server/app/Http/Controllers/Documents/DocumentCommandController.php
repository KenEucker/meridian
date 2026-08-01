<?php

namespace App\Http\Controllers\Documents;

use App\Domain\Documents\EventInfoSection;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Documents\Concerns\SerializesProductDocuments;
use App\Models\AuditEvent;
use App\Models\DocumentFragment;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Organization;
use App\Services\Documents\DocumentAdminService;
use App\Services\Documents\DocumentFragmentAdminService;
use App\Services\Documents\DocumentFragmentReferenceException;
use App\Services\Documents\DocumentProductAccess;
use App\Services\Documents\DocumentRenderer;
use App\Services\Documents\DocumentScopeValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DocumentCommandController extends Controller
{
    use SerializesProductDocuments;

    public function createPolicy(
        Request $request,
        DocumentAdminService $documents,
        DocumentProductAccess $access,
        DocumentScopeValidator $scopes,
        DocumentRenderer $renderer,
    ): JsonResponse {
        return $this->createDocument($request, new PolicyDocument, $documents, $access, $scopes, $renderer);
    }

    public function updatePolicy(
        Request $request,
        DocumentAdminService $documents,
        DocumentProductAccess $access,
        DocumentScopeValidator $scopes,
        DocumentRenderer $renderer,
    ): JsonResponse {
        $document = PolicyDocument::query()->findOrFail((string) $request->validate([
            'document_id' => ['required', 'uuid', Rule::exists(PolicyDocument::class, 'id')],
        ])['document_id']);

        return $this->updateDocument($request, $document, $documents, $access, $scopes, $renderer);
    }

    public function publishPolicy(
        Request $request,
        DocumentAdminService $documents,
        DocumentProductAccess $access,
        DocumentRenderer $renderer,
    ): JsonResponse {
        $document = PolicyDocument::query()->findOrFail((string) $request->validate([
            'document_id' => ['required', 'uuid', Rule::exists(PolicyDocument::class, 'id')],
            'reason' => ['required', 'string', 'max:2000'],
        ])['document_id']);

        return $this->transitionDocument($request, $document, PolicyDocument::STATE_PUBLISHED, $documents, $access, $renderer);
    }

    public function archivePolicy(
        Request $request,
        DocumentAdminService $documents,
        DocumentProductAccess $access,
        DocumentRenderer $renderer,
    ): JsonResponse {
        $document = PolicyDocument::query()->findOrFail((string) $request->validate([
            'document_id' => ['required', 'uuid', Rule::exists(PolicyDocument::class, 'id')],
            'reason' => ['required', 'string', 'max:2000'],
        ])['document_id']);

        return $this->transitionDocument($request, $document, PolicyDocument::STATE_ARCHIVED, $documents, $access, $renderer);
    }

    public function createProcedure(
        Request $request,
        DocumentAdminService $documents,
        DocumentProductAccess $access,
        DocumentScopeValidator $scopes,
        DocumentRenderer $renderer,
    ): JsonResponse {
        return $this->createDocument($request, new ProcedureDocument, $documents, $access, $scopes, $renderer);
    }

    public function updateProcedure(
        Request $request,
        DocumentAdminService $documents,
        DocumentProductAccess $access,
        DocumentScopeValidator $scopes,
        DocumentRenderer $renderer,
    ): JsonResponse {
        $document = ProcedureDocument::query()->findOrFail((string) $request->validate([
            'document_id' => ['required', 'uuid', Rule::exists(ProcedureDocument::class, 'id')],
        ])['document_id']);

        return $this->updateDocument($request, $document, $documents, $access, $scopes, $renderer);
    }

    public function publishProcedure(
        Request $request,
        DocumentAdminService $documents,
        DocumentProductAccess $access,
        DocumentRenderer $renderer,
    ): JsonResponse {
        $document = ProcedureDocument::query()->findOrFail((string) $request->validate([
            'document_id' => ['required', 'uuid', Rule::exists(ProcedureDocument::class, 'id')],
            'reason' => ['required', 'string', 'max:2000'],
        ])['document_id']);

        return $this->transitionDocument($request, $document, ProcedureDocument::STATE_PUBLISHED, $documents, $access, $renderer);
    }

    public function archiveProcedure(
        Request $request,
        DocumentAdminService $documents,
        DocumentProductAccess $access,
        DocumentRenderer $renderer,
    ): JsonResponse {
        $document = ProcedureDocument::query()->findOrFail((string) $request->validate([
            'document_id' => ['required', 'uuid', Rule::exists(ProcedureDocument::class, 'id')],
            'reason' => ['required', 'string', 'max:2000'],
        ])['document_id']);

        return $this->transitionDocument($request, $document, ProcedureDocument::STATE_ARCHIVED, $documents, $access, $renderer);
    }

    public function createFragment(
        Request $request,
        DocumentFragmentAdminService $fragments,
        DocumentProductAccess $access,
        DocumentScopeValidator $scopes,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $attributes = $this->validateFragment($request, $scopes);
        $organization = Organization::query()->findOrFail($attributes['organization_id']);

        if (! $access->canMaintainScope($user, $organization, $attributes['scope_type'], $attributes['scope_id'])) {
            return response()->json(['message' => 'You do not have permission to maintain fragments for this scope.'], 403);
        }

        try {
            $fragment = $fragments->save(new DocumentFragment, $attributes, $user, AuditEvent::SOURCE_API);
        } catch (DocumentFragmentReferenceException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->fragmentPayload($fragment), 201);
    }

    public function updateFragment(
        Request $request,
        DocumentFragmentAdminService $fragments,
        DocumentProductAccess $access,
        DocumentScopeValidator $scopes,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $fragment = DocumentFragment::query()->findOrFail((string) $request->validate([
            'fragment_id' => ['required', 'uuid', Rule::exists(DocumentFragment::class, 'id')],
        ])['fragment_id']);

        if (! $access->canMaintainFragment($user, $fragment)) {
            return response()->json(['message' => 'You do not have permission to maintain this fragment.'], 403);
        }

        $attributes = $this->validateFragment($request, $scopes);
        $organization = Organization::query()->findOrFail($attributes['organization_id']);
        if (! $access->canMaintainScope($user, $organization, $attributes['scope_type'], $attributes['scope_id'])) {
            return response()->json(['message' => 'You do not have permission to move this fragment to that scope.'], 403);
        }

        try {
            $fragment = $fragments->save($fragment, $attributes, $user, AuditEvent::SOURCE_API);
        } catch (DocumentFragmentReferenceException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->fragmentPayload($fragment));
    }

    private function createDocument(
        Request $request,
        PolicyDocument|ProcedureDocument $document,
        DocumentAdminService $documents,
        DocumentProductAccess $access,
        DocumentScopeValidator $scopes,
        DocumentRenderer $renderer,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $attributes = $this->validateDocument($request, $document, $scopes);
        $organization = Organization::query()->findOrFail($attributes['organization_id']);

        if (! $access->canMaintainScope($user, $organization, $attributes['scope_type'], $attributes['scope_id'])) {
            return response()->json(['message' => 'You do not have permission to maintain documents for this scope.'], 403);
        }

        try {
            $document = $documents->save($document, $attributes, $user, null, AuditEvent::SOURCE_API);
        } catch (DocumentFragmentReferenceException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->documentPayload($document, $renderer, $access, $user), 201);
    }

    private function updateDocument(
        Request $request,
        PolicyDocument|ProcedureDocument $document,
        DocumentAdminService $documents,
        DocumentProductAccess $access,
        DocumentScopeValidator $scopes,
        DocumentRenderer $renderer,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canMaintainDocument($user, $document)) {
            return response()->json(['message' => 'You do not have permission to maintain this document.'], 403);
        }

        $attributes = $this->validateDocument($request, $document, $scopes);
        $organization = Organization::query()->findOrFail($attributes['organization_id']);
        if (! $access->canMaintainScope($user, $organization, $attributes['scope_type'], $attributes['scope_id'])) {
            return response()->json(['message' => 'You do not have permission to move this document to that scope.'], 403);
        }

        $attributes['state'] = $document->state;

        try {
            $document = $documents->save($document, $attributes, $user, null, AuditEvent::SOURCE_API);
        } catch (DocumentFragmentReferenceException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->documentPayload($document, $renderer, $access, $user));
    }

    private function transitionDocument(
        Request $request,
        PolicyDocument|ProcedureDocument $document,
        string $state,
        DocumentAdminService $documents,
        DocumentProductAccess $access,
        DocumentRenderer $renderer,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canMaintainDocument($user, $document)) {
            return response()->json(['message' => 'You do not have permission to maintain this document.'], 403);
        }

        $reason = (string) $request->input('reason');
        $attributes = $document->only([
            'organization_id',
            'scope_type',
            'scope_id',
            'title',
            'slug',
            'event_info_section',
            'markdown_source',
        ]);
        $attributes['state'] = $state;

        try {
            $document = $documents->save($document, $attributes, $user, $reason, AuditEvent::SOURCE_API);
        } catch (DocumentFragmentReferenceException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->documentPayload($document, $renderer, $access, $user));
    }

    /**
     * @return array<string, string|null>
     */
    private function validateDocument(
        Request $request,
        PolicyDocument|ProcedureDocument $document,
        DocumentScopeValidator $scopes,
    ): array {
        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', Rule::exists(Organization::class, 'id')],
            'scope_type' => ['required', 'string', Rule::in($document::scopeTypes())],
            'scope_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            // Assigning an Event Info section is part of maintaining the
            // document, so it needs no capability beyond the maintain-scope
            // check the caller already passed (M11.20).
            'event_info_section' => ['sometimes', 'nullable', 'string', Rule::in(EventInfoSection::keys())],
            'markdown_source' => ['required', 'string'],
        ]);

        $validated['event_info_section'] = $request->has('event_info_section')
            ? ($validated['event_info_section'] ?? null)
            : $document->event_info_section;

        if (! $scopes->isValid((string) $validated['organization_id'], (string) $validated['scope_type'], (string) $validated['scope_id'])) {
            throw ValidationException::withMessages([
                'scope_id' => ['The scope target must belong to the selected organization and scope type.'],
            ]);
        }

        $validated['state'] = $document->exists ? $document->state : PolicyDocument::STATE_DRAFT;

        return $validated;
    }

    /**
     * @return array<string, string>
     */
    private function validateFragment(Request $request, DocumentScopeValidator $scopes): array
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', Rule::exists(Organization::class, 'id')],
            'scope_type' => ['required', 'string', Rule::in(DocumentFragment::scopeTypes())],
            'scope_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'markdown_source' => ['required', 'string'],
        ]);

        if (! $scopes->isValid((string) $validated['organization_id'], (string) $validated['scope_type'], (string) $validated['scope_id'])) {
            throw ValidationException::withMessages([
                'scope_id' => ['The scope target must belong to the selected organization and scope type.'],
            ]);
        }

        return $validated;
    }
}
