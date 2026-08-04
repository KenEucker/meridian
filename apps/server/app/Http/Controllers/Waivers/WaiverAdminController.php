<?php

declare(strict_types=1);

namespace App\Http\Controllers\Waivers;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DocumentAcknowledgment;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverCompletion;
use App\Services\Waiver\WaiverDocumentException;
use App\Services\Waiver\WaiverProductAccess;
use App\Services\Waiver\WaiverScopeException;
use App\Services\Waiver\WaiverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Waiver administration (M18.18; WAIVER-001 through WAIVER-006, WAIVER-010).
 *
 * `WaiverService` has been able to create a scoped, expiring waiver since M7.2
 * and shift signup has enforced completion since M7.6, but the service never
 * had a caller outside tests and seeders: an organization could not create a
 * waiver, attach a document, or record that somebody signed one without a
 * tinker session. This controller is that path.
 *
 * Authority follows the scope of the waiver (WAIVER-010), matching the
 * policy/procedure maintenance rule through {@see WaiverProductAccess}:
 * organization-scoped waivers answer to organizers, department-scoped to
 * department leads, team-scoped to team leads. Every read and command here
 * resolves the same list, so what the surface offers and what the node
 * enforces never diverge (CLIENT-006).
 *
 * Completion recording is scoped twice: the recorder must hold the waiver's
 * scope authority, and the staff member must be somebody the waiver actually
 * asks — a completion recorded for a staff member outside the waiver's scope
 * would be a record of something that was never required of them.
 */
final class WaiverAdminController extends Controller
{
    public function __construct(
        private readonly WaiverProductAccess $access,
        private readonly WaiverService $waivers,
    ) {}

    /**
     * Every waiver in the caller's maintainable scopes, with what the create
     * form needs: the scopes the caller holds and the published documents a
     * waiver may reference (WAIVER-007).
     */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $scopes = $this->access->maintainableScopes($user, $organization);

        if ($scopes === []) {
            return $this->forbidden();
        }

        $waivers = $this->maintainableWaivers($organization, $scopes);

        return response()->json([
            'organization_id' => (string) $organization->getKey(),
            'organization_name' => $organization->name,
            'scopes' => $scopes,
            'documents' => $this->publishedDocumentOptions($organization),
            'waivers' => $waivers
                ->map(fn (Waiver $waiver): array => $this->waiverRow($waiver))
                ->sortBy(fn (array $row): string => ($row['archived'] ? '1' : '0')
                    .'|'.Str::lower((string) $row['name']))
                ->values()
                ->all(),
        ]);
    }

    /**
     * One waiver with its completion roster and, for a document-backed waiver,
     * the referenced document rendered with fragment text inline — the text a
     * completion acknowledges, shown where completion is recorded (WAIVER-007;
     * POL-022).
     */
    public function show(Request $request, Organization $organization, Waiver $waiver): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if ((string) $waiver->organization_id !== (string) $organization->getKey()
            || ! $this->access->canMaintainWaiver($user, $waiver)) {
            return $this->forbidden();
        }

        $renderedDocument = null;
        $documentUnavailableReason = null;

        try {
            $renderedDocument = $this->waivers->renderedDocumentFor($waiver);
        } catch (WaiverDocumentException $exception) {
            $documentUnavailableReason = $exception->getMessage();
        }

        return response()->json([
            'waiver' => $this->waiverRow($waiver),
            'rendered_document' => $renderedDocument,
            'document_unavailable_reason' => $documentUnavailableReason,
            'roster' => $this->rosterRows($waiver),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', Rule::exists(Organization::class, 'id')],
            'scope_type' => ['required', 'string', Rule::in(Waiver::scopeTypes())],
            'scope_id' => ['required', 'uuid'],
            ...$this->waiverRules(),
        ]);

        $organization = Organization::query()->findOrFail((string) $validated['organization_id']);

        if (! $this->access->canMaintainScope(
            $user,
            $organization,
            (string) $validated['scope_type'],
            (string) $validated['scope_id'],
        )) {
            return $this->forbidden();
        }

        try {
            $waiver = $this->waivers->create(
                organization: $organization,
                scopeType: (string) $validated['scope_type'],
                scopeId: (string) $validated['scope_id'],
                name: (string) $validated['name'],
                description: $validated['description'] ?? null,
                expiresAfterDays: $validated['expires_after_days'] ?? null,
                documentType: $validated['document_type'] ?? null,
                documentId: $validated['document_id'] ?? null,
                actor: $user,
            );
        } catch (WaiverScopeException|WaiverDocumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['waiver' => $this->waiverRow($waiver)], 201);
    }

    public function update(Request $request): JsonResponse
    {
        [$user, $waiver] = $this->resolveManagedWaiver($request);

        if ($waiver === null) {
            return $this->forbidden();
        }

        $validated = $request->validate($this->waiverRules());

        try {
            $waiver = $this->waivers->update($waiver, $validated, $user);
        } catch (WaiverDocumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['waiver' => $this->waiverRow($waiver)]);
    }

    public function archive(Request $request): JsonResponse
    {
        [$user, $waiver] = $this->resolveManagedWaiver($request);

        if ($waiver === null) {
            return $this->forbidden();
        }

        $waiver = $this->waivers->archive($waiver, $user);

        return response()->json(['waiver' => $this->waiverRow($waiver)]);
    }

    public function restore(Request $request): JsonResponse
    {
        [$user, $waiver] = $this->resolveManagedWaiver($request);

        if ($waiver === null) {
            return $this->forbidden();
        }

        $waiver = $this->waivers->restore($waiver, $user);

        return response()->json(['waiver' => $this->waiverRow($waiver)]);
    }

    /**
     * Record that a staff member completed a waiver (WAIVER-003).
     *
     * An archived waiver refuses new completions: it is no longer asked of
     * anybody, and a completion of it would immediately count toward shift
     * eligibility checks that no longer include it.
     */
    public function recordCompletion(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'waiver_id' => ['required', 'uuid', Rule::exists(Waiver::class, 'id')],
            'staff_id' => ['required', 'uuid', Rule::exists(Staff::class, 'id')],
            'completed_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $waiver = Waiver::query()->findOrFail((string) $validated['waiver_id']);

        if (! $this->access->canMaintainWaiver($user, $waiver)) {
            return $this->forbidden();
        }

        if ($waiver->isArchived()) {
            return response()->json([
                'message' => 'This waiver is archived and no longer accepts completions.',
            ], 422);
        }

        $staff = Staff::query()->findOrFail((string) $validated['staff_id']);

        if (! $this->access->appliesToStaff($waiver, $staff)) {
            return response()->json([
                'message' => 'This waiver does not apply to that staff member.',
            ], 422);
        }

        $completedAt = isset($validated['completed_at']) && $validated['completed_at'] !== null
            ? Carbon::parse((string) $validated['completed_at'])
            : null;

        try {
            $completion = $this->waivers->recordCompletion($waiver, $staff, $completedAt, $user);
        } catch (WaiverDocumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'waiver_id' => (string) $waiver->id,
            'staff_id' => (string) $staff->id,
            'completed_at' => $completion->completed_at?->toIso8601String(),
            'expires_at' => $completion->expires_at?->toIso8601String(),
            'acknowledged_version' => $completion->document_revision === null
                ? null
                : $this->versionLabel((int) $completion->document_revision, (int) $completion->fragment_revision),
        ], 201);
    }

    /**
     * @return array{0: User, 1: Waiver|null}
     */
    private function resolveManagedWaiver(Request $request): array
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'waiver_id' => ['required', 'uuid', Rule::exists(Waiver::class, 'id')],
        ]);

        $waiver = Waiver::query()->findOrFail((string) $validated['waiver_id']);

        return [$user, $this->access->canMaintainWaiver($user, $waiver) ? $waiver : null];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function waiverRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'expires_after_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'document_type' => ['sometimes', 'nullable', 'string', Rule::in(DocumentAcknowledgment::documentTypes())],
            'document_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /**
     * @param  list<array{scope_type: string, scope_id: string, label: string}>  $scopes
     * @return Collection<int, Waiver>
     */
    private function maintainableWaivers(Organization $organization, array $scopes): Collection
    {
        $query = Waiver::query()->where('organization_id', $organization->getKey());

        $query->where(function ($outer) use ($scopes): void {
            foreach ($scopes as $scope) {
                $outer->orWhere(fn ($inner) => $inner
                    ->where('scope_type', $scope['scope_type'])
                    ->where('scope_id', $scope['scope_id']));
            }
        });

        return $query->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function waiverRow(Waiver $waiver): array
    {
        $document = $waiver->isDocumentBacked() ? $waiver->document() : null;

        $currentCompletions = $waiver->completions()->current()->count();
        $totalCompletions = $waiver->completions()->count();

        return [
            'id' => (string) $waiver->getKey(),
            'organization_id' => (string) $waiver->organization_id,
            'scope_type' => $waiver->scope_type,
            'scope_id' => (string) $waiver->scope_id,
            'scope_label' => $this->scopeLabel($waiver),
            'name' => $waiver->name,
            'description' => $waiver->description,
            'expires_after_days' => $waiver->expires_after_days,
            'document' => $document === null ? null : [
                'document_type' => $waiver->document_type,
                'document_id' => (string) $document->getKey(),
                'title' => $document->title,
                'version' => $document->version(),
                'published' => $document->isPublished(),
            ],
            'archived' => $waiver->isArchived(),
            'archived_at' => $waiver->archived_at?->toIso8601String(),
            'current_completion_count' => $currentCompletions,
            'total_completion_count' => $totalCompletions,
        ];
    }

    /**
     * The completion roster: who the waiver asks, and where each of them
     * stands (WAIVER-003). A staff member whose only completions have lapsed
     * reads as lapsed rather than merely incomplete, because that is the state
     * WAIVER-006 turns into a credential block.
     *
     * @return list<array<string, mixed>>
     */
    private function rosterRows(Waiver $waiver): array
    {
        $completions = WaiverCompletion::query()
            ->where('waiver_id', $waiver->getKey())
            ->orderByDesc('completed_at')
            ->get()
            ->groupBy(fn (WaiverCompletion $completion): string => (string) $completion->staff_id);

        return $this->access->subjectStaff($waiver)
            ->map(function (Staff $staff) use ($completions): array {
                /** @var Collection<int, WaiverCompletion> $own */
                $own = $completions->get((string) $staff->getKey(), collect());
                $current = $own->first(fn (WaiverCompletion $completion): bool => ! $completion->isExpiredAt());
                $latest = $own->first();

                return [
                    'staff_id' => (string) $staff->getKey(),
                    'display_name' => $staff->displayName(),
                    'handle' => $staff->handle,
                    'complete' => $current !== null,
                    'lapsed' => $current === null && $latest !== null,
                    'completed_at' => ($current ?? $latest)?->completed_at?->toIso8601String(),
                    'expires_at' => ($current ?? $latest)?->expires_at?->toIso8601String(),
                    'acknowledged_version' => ($current ?? $latest) === null || ($current ?? $latest)->document_revision === null
                        ? null
                        : $this->versionLabel(
                            (int) ($current ?? $latest)->document_revision,
                            (int) ($current ?? $latest)->fragment_revision,
                        ),
                ];
            })
            ->sortBy(fn (array $row): string => Str::lower((string) $row['display_name']).'|'.$row['staff_id'])
            ->values()
            ->all();
    }

    /**
     * The published documents a waiver may reference (WAIVER-007), which is
     * the same list the create and update commands accept.
     *
     * @return list<array<string, mixed>>
     */
    private function publishedDocumentOptions(Organization $organization): array
    {
        return collect([
            ...PolicyDocument::query()
                ->where('organization_id', $organization->getKey())
                ->published()
                ->get()
                ->map(fn (PolicyDocument $document): array => [
                    'document_type' => DocumentAcknowledgment::DOCUMENT_TYPE_POLICY,
                    'document_id' => (string) $document->getKey(),
                    'title' => $document->title,
                    'version' => $document->version(),
                ])
                ->all(),
            ...ProcedureDocument::query()
                ->where('organization_id', $organization->getKey())
                ->published()
                ->get()
                ->map(fn (ProcedureDocument $document): array => [
                    'document_type' => DocumentAcknowledgment::DOCUMENT_TYPE_PROCEDURE,
                    'document_id' => (string) $document->getKey(),
                    'title' => $document->title,
                    'version' => $document->version(),
                ])
                ->all(),
        ])
            ->sortBy(fn (array $option): string => Str::lower((string) $option['title']))
            ->values()
            ->all();
    }

    private function scopeLabel(Waiver $waiver): string
    {
        return match ($waiver->scope_type) {
            Waiver::SCOPE_ORGANIZATION => 'Organization: '
                .(Organization::query()->find($waiver->scope_id)?->name ?? 'Unknown'),
            Waiver::SCOPE_DEPARTMENT => 'Department: '
                .(Department::query()->find($waiver->scope_id)?->name ?? 'Unknown'),
            Waiver::SCOPE_TEAM => 'Team: '
                .(Team::query()->find($waiver->scope_id)?->name ?? 'Unknown'),
            default => 'Unknown scope',
        };
    }

    private function versionLabel(int $documentRevision, int $fragmentRevision): string
    {
        return sprintf('%d.%02d', $documentRevision, $fragmentRevision);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'message' => 'You do not have permission to administer these waivers.',
        ], 403);
    }
}
