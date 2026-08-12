<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Domain\Modules\ModuleKey;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DocumentAcknowledgment;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Staff;
use App\Services\Audit\AuditService;
use App\Services\Documents\DocumentAcknowledgmentAccess;
use App\Services\Documents\DocumentAcknowledgmentRequirementService;
use App\Services\Documents\DocumentAcknowledgmentService;
use App\Services\Documents\DocumentRenderer;
use App\Services\Modules\ActiveModuleResolver;
use App\Services\Node\NodeSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The document acknowledgment path (M18.6; POL-023 through POL-027, POL-043
 * through POL-047; UI contract 12.1, 12.3, 12.6).
 *
 * `DocumentAcknowledgmentRequirementService` has been able to say a document
 * must be acknowledged since M6.9, and `DocumentAcknowledgmentService` has been
 * able to record that somebody did since M6.10. Neither had a caller. This
 * controller is the whole path between them: an organizer says what must be
 * acknowledged, a staff member reads it and says they have, and the organizer
 * reads back who did.
 *
 * Three properties run through every endpoint here.
 *
 * **A requirement asks a person, not everybody.** The domain service records
 * any user's acceptance of any active requirement, which was safe while its only
 * caller was a test. `subjectStaffFor` is the gate a command needs: a
 * requirement scoped to a department asks that department's active members, one
 * scoped to an organization asks anybody holding a status with it — prospective
 * included, because POL-024 puts acknowledgment in signup — and a caller outside
 * both is refused rather than quietly written into the review list.
 *
 * **The version is the record.** POL-043 wants the acknowledged document and
 * version stored, and the acknowledgment row already carries both revisions.
 * What this adds is the reading: a row says which version was accepted and
 * whether the document has moved since, and POL-045 decides what that means —
 * nothing. A changed document does not re-require anything, so a moved version
 * is reported as a fact and never as an outstanding item.
 *
 * **It is not a gate.** POL-026 and POL-027 keep acknowledgment out of shift
 * signup and credential eligibility, and nothing in Meridian consults these
 * records to decide either. The read says so in as many words, because a screen
 * that lists outstanding requirements next to a schedule invites exactly the
 * inference the two requirements forbid.
 */
final class DocumentAcknowledgmentController extends Controller
{
    public function __construct(
        private readonly DocumentAcknowledgmentAccess $access,
        private readonly NodeSetupService $nodes,
    ) {}

    /**
     * Everything this caller has been asked to acknowledge (POL-024, POL-025).
     *
     * Both contexts in one answer, because they are one person's list. The
     * signup surface filters to `signup` and the staff ledger shows all of it;
     * neither decides which requirements exist, and a client that asked for only
     * half would be a client that has to know about the halves.
     *
     * Requirements pointing at an unpublished document are absent. The
     * acknowledge command refuses one — "Only published documents may be
     * acknowledged" — and listing an item nobody can act on is listing a fault
     * as a task.
     */
    public function mine(
        Request $request,
        DocumentRenderer $renderer,
        ActiveModuleResolver $modules,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $staffProfiles = $user->staffProfiles()->get();

        $organizationIds = $staffProfiles
            ->flatMap(fn (Staff $staff): array => $staff->organizationStatuses()->pluck('organization_id')->all())
            ->map(fn ($id): string => (string) $id)
            ->unique();

        $departmentIds = $staffProfiles
            ->flatMap(fn (Staff $staff): array => $staff->departmentMemberships()->active()->pluck('department_id')->all())
            ->map(fn ($id): string => (string) $id)
            ->unique();

        $requirements = DocumentAcknowledgmentRequirement::query()
            ->active()
            ->where(function ($query) use ($organizationIds, $departmentIds): void {
                $query
                    ->where(fn ($scoped) => $scoped
                        ->where('scope_type', DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION)
                        ->whereIn('scope_id', $organizationIds->all()))
                    ->orWhere(fn ($scoped) => $scoped
                        ->where('scope_type', DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT)
                        ->whereIn('scope_id', $departmentIds->all()));
            })
            ->get()
            /*
             * One person's list can span organizations, and this read is core
             * for exactly that reason: the route gate refuses it only when *no*
             * organization the caller belongs to runs Documents (data/API 5.9).
             * Narrowing the rows is this half of the rule — an organization with
             * Documents inactive contributes nothing to the list rather than
             * taking the list away from the one that does run it (MOD-019).
             */
            ->filter(fn (DocumentAcknowledgmentRequirement $requirement): bool => $modules->isActive(
                (string) $requirement->organization_id,
                ModuleKey::Documents,
            ))
            ->values();

        $acknowledgments = DocumentAcknowledgment::query()
            ->where('user_id', $user->getKey())
            ->get();

        $rows = $requirements
            ->map(fn (DocumentAcknowledgmentRequirement $requirement): ?array => $this->requirementForSubject(
                $requirement,
                $acknowledgments,
                $renderer,
            ))
            ->filter()
            ->sortBy(fn (array $row): string => ($row['acknowledged'] ? '1' : '0')
                .'|'.Str::lower((string) $row['document_title']))
            ->values()
            ->all();

        return response()->json([
            'requirements' => $rows,
            'outstanding_count' => count(array_filter($rows, fn (array $row): bool => ! $row['acknowledged'])),
            // POL-026 and POL-027, stated rather than left to be inferred from
            // the absence of a consequence.
            'gating' => [
                'blocks_shift_signup' => false,
                'blocks_credential_eligibility' => false,
                'explanation' => 'Acknowledgments are recorded for the record. An outstanding one does not block shift signup or event credential eligibility.',
            ],
        ]);
    }

    /**
     * Record one acknowledgment (POL-043, POL-044).
     *
     * The accepting node is this install's own. Nothing publishes a node id to a
     * browser and nothing should: which node took the acceptance is provenance
     * the node itself owns, and an install with none configured says so rather
     * than recording a guess.
     *
     * Repeating an acknowledgment is not an error. The service is idempotent on
     * the version already accepted, so a person who presses the button twice, or
     * on a second device, gets the row they already have.
     */
    public function acknowledge(
        Request $request,
        DocumentAcknowledgmentService $acknowledgments,
        DocumentRenderer $renderer,
    ): JsonResponse {
        $validated = $request->validate([
            'requirement_id' => ['required', 'uuid', 'exists:document_acknowledgment_requirements,id'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $requirement = DocumentAcknowledgmentRequirement::query()
            ->findOrFail((string) $validated['requirement_id']);

        $staff = $this->access->subjectStaffFor($user, $requirement);

        if ($staff === null) {
            return response()->json([
                'message' => 'This acknowledgment was not asked of you.',
            ], 403);
        }

        $node = $this->nodes->activeNode();

        if ($node === null) {
            return response()->json([
                'message' => 'This node is not configured, so an acknowledgment cannot record where it was accepted.',
            ], 422);
        }

        try {
            $acknowledgments->acknowledge(
                requirement: $requirement,
                user: $user,
                acceptedByNode: $node,
                staff: $staff,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'requirement' => $this->requirementForSubject(
                $requirement,
                DocumentAcknowledgment::query()->where('user_id', $user->getKey())->get(),
                $renderer,
            ),
        ]);
    }

    /**
     * The organizer review surface's read (UI contract 12.6).
     *
     * Requirements with who has acknowledged them and who has not, plus the
     * published documents and department scopes a new requirement can be built
     * from — the create form needs both before there is a requirement to read
     * them off, and answering them here keeps what the form offers and what the
     * command accepts the same list.
     *
     * Retired requirements stay in the answer. An organizer checking whether
     * something was ever asked is looking for exactly the row a list of active
     * requirements would have dropped.
     */
    public function review(
        Request $request,
        Organization $organization,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $this->access->canReview($user, $organization)) {
            return $this->reviewRefusal();
        }

        $requirements = DocumentAcknowledgmentRequirement::query()
            ->where('organization_id', $organization->getKey())
            ->get();

        return response()->json([
            'organization_id' => (string) $organization->getKey(),
            'organization_name' => $organization->name,
            'requirements' => $requirements
                ->map(fn (DocumentAcknowledgmentRequirement $requirement): array => $this->reviewRow($requirement, $organization))
                ->sortBy(fn (array $row): string => ($row['active'] ? '0' : '1')
                    .'|'.Str::lower((string) $row['document_title']))
                ->values()
                ->all(),
            'documents' => $this->publishedDocumentOptions($organization),
            'scopes' => $this->scopeOptions($organization),
            'contexts' => array_map(
                fn (string $context): array => [
                    'value' => $context,
                    'label' => $this->contextLabel($context),
                ],
                DocumentAcknowledgmentRequirement::requirementContexts(),
            ),
        ]);
    }

    /**
     * Ask for a document to be acknowledged (POL-023, POL-046, POL-047).
     *
     * The document must be published. A requirement pointing at a draft is one
     * the acknowledge command refuses for everybody it is addressed to, which
     * makes it a trap rather than a requirement, and the read filters it out
     * anyway — so it is refused where somebody can still do something about it.
     */
    public function createRequirement(
        Request $request,
        DocumentAcknowledgmentRequirementService $requirements,
        AuditService $audit,
    ): JsonResponse {
        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'document_type' => ['required', 'string', 'in:'.implode(',', DocumentAcknowledgmentRequirement::documentTypes())],
            'document_id' => ['required', 'uuid'],
            'scope_type' => ['required', 'string', 'in:'.implode(',', DocumentAcknowledgmentRequirement::scopeTypes())],
            'scope_id' => ['required', 'uuid'],
            'requirement_context' => ['required', 'string', 'in:'.implode(',', DocumentAcknowledgmentRequirement::requirementContexts())],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $organization = Organization::query()->findOrFail((string) $validated['organization_id']);

        if (! $this->access->canReview($user, $organization)) {
            return $this->reviewRefusal();
        }

        $document = $this->documentFor(
            (string) $validated['document_type'],
            (string) $validated['document_id'],
        );

        if ($document === null || (string) $document->organization_id !== (string) $organization->getKey()) {
            return response()->json([
                'message' => 'The selected acknowledgment document does not exist in this organization.',
            ], 422);
        }

        if (! $document->isPublished()) {
            return response()->json([
                'message' => 'Only a published document can be required. Publish it first, then require it.',
            ], 422);
        }

        try {
            $requirement = $requirements->create(
                organization: $organization,
                documentType: (string) $validated['document_type'],
                documentId: (string) $validated['document_id'],
                scopeType: (string) $validated['scope_type'],
                scopeId: (string) $validated['scope_id'],
                requirementContext: (string) $validated['requirement_context'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $audit->recordForEntity(
            entity: $requirement,
            action: 'document_acknowledgment_requirement.created',
            actorUser: $user,
            organizationId: (string) $organization->getKey(),
            departmentId: $requirement->scope_type === DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT
                ? (string) $requirement->scope_id
                : null,
            after: $this->requirementAuditSnapshot($requirement),
            sourceContext: AuditEvent::SOURCE_API,
        );

        return response()->json([
            'requirement' => $this->reviewRow($requirement, $organization),
        ], 201);
    }

    /**
     * Retire a requirement, or put it back.
     *
     * The acknowledgments recorded against it are not touched. Retiring is a
     * decision to stop asking, not a claim that nobody ever answered.
     */
    public function setRequirementActive(
        Request $request,
        DocumentAcknowledgmentRequirementService $requirements,
        AuditService $audit,
    ): JsonResponse {
        $validated = $request->validate([
            'requirement_id' => ['required', 'uuid', 'exists:document_acknowledgment_requirements,id'],
            'active' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $requirement = DocumentAcknowledgmentRequirement::query()
            ->findOrFail((string) $validated['requirement_id']);
        $organization = Organization::query()->findOrFail((string) $requirement->organization_id);

        if (! $this->access->canReview($user, $organization)) {
            return $this->reviewRefusal();
        }

        $before = $this->requirementAuditSnapshot($requirement);
        $requirement = $requirements->setActive($requirement, (bool) $validated['active']);

        $audit->recordForEntity(
            entity: $requirement,
            action: (bool) $validated['active']
                ? 'document_acknowledgment_requirement.restored'
                : 'document_acknowledgment_requirement.retired',
            actorUser: $user,
            organizationId: (string) $organization->getKey(),
            departmentId: $requirement->scope_type === DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT
                ? (string) $requirement->scope_id
                : null,
            before: $before,
            after: $this->requirementAuditSnapshot($requirement),
            sourceContext: AuditEvent::SOURCE_API,
        );

        return response()->json([
            'requirement' => $this->reviewRow($requirement, $organization),
        ]);
    }

    /**
     * One requirement as the person it was asked of sees it, or null when it
     * points at a document that cannot be acknowledged.
     *
     * `document_changed_since` is the POL-045 sentence in a field: the version
     * accepted is not the version standing, and that changes nothing about
     * whether this row is outstanding. It is here so a surface can say so
     * instead of leaving a reader to wonder whether they are behind.
     *
     * @param  Collection<int, DocumentAcknowledgment>  $acknowledgments
     * @return array<string, mixed>|null
     */
    private function requirementForSubject(
        DocumentAcknowledgmentRequirement $requirement,
        Collection $acknowledgments,
        DocumentRenderer $renderer,
    ): ?array {
        $document = $this->documentFor($requirement->document_type, (string) $requirement->document_id);

        if ($document === null || ! $document->isPublished()) {
            return null;
        }

        $accepted = $acknowledgments->first(
            fn (DocumentAcknowledgment $acknowledgment): bool => $acknowledgment->document_type === $requirement->document_type
                && (string) $acknowledgment->document_id === (string) $requirement->document_id
                && $acknowledgment->scope_type === $requirement->scope_type
                && (string) $acknowledgment->scope_id === (string) $requirement->scope_id,
        );

        return [
            'requirement_id' => (string) $requirement->getKey(),
            'organization_id' => (string) $requirement->organization_id,
            'scope_type' => $requirement->scope_type,
            'scope_id' => (string) $requirement->scope_id,
            'scope_label' => $this->scopeLabel($requirement),
            'requirement_context' => $requirement->requirement_context,
            'requirement_context_label' => $this->contextLabel($requirement->requirement_context),
            'document_type' => $requirement->document_type,
            'document_id' => (string) $document->getKey(),
            'document_title' => $document->title,
            'document_version' => $document->version(),
            // The text itself, with referenced fragments rendered inline as
            // document text (POL-022). Somebody cannot acknowledge what they
            // have not been shown.
            'rendered_html' => $renderer->render($document),
            'acknowledged' => $accepted !== null,
            'acknowledged_at' => $accepted?->acknowledged_at?->toIso8601String(),
            'acknowledged_version' => $accepted === null
                ? null
                : $this->versionLabel((int) $accepted->document_revision, (int) $accepted->fragment_revision),
            'document_changed_since' => $accepted !== null
                && ((int) $accepted->document_revision !== (int) $document->document_revision
                    || (int) $accepted->fragment_revision !== (int) $document->fragment_revision),
        ];
    }

    /**
     * One requirement as the organizer who set it sees it.
     *
     * Identity is the name and the handle and stops there, for the same reason
     * the credential list stops there: reading who acknowledged a policy is not
     * a reason to read anybody's contact details.
     *
     * @return array<string, mixed>
     */
    private function reviewRow(
        DocumentAcknowledgmentRequirement $requirement,
        Organization $organization,
    ): array {
        $document = $this->documentFor($requirement->document_type, (string) $requirement->document_id);

        $subjects = $this->subjectsFor($requirement);

        $accepted = DocumentAcknowledgment::query()
            ->where('document_type', $requirement->document_type)
            ->where('document_id', $requirement->document_id)
            ->where('scope_type', $requirement->scope_type)
            ->where('scope_id', $requirement->scope_id)
            ->get()
            ->keyBy(fn (DocumentAcknowledgment $acknowledgment): string => (string) $acknowledgment->staff_id);

        $rows = $subjects
            ->map(function (Staff $staff) use ($accepted, $document): array {
                $acknowledgment = $accepted->get((string) $staff->getKey());

                return [
                    'staff_id' => (string) $staff->getKey(),
                    'display_name' => $staff->displayName(),
                    'handle' => $staff->handle,
                    'acknowledged' => $acknowledgment !== null,
                    'acknowledged_at' => $acknowledgment?->acknowledged_at?->toIso8601String(),
                    'acknowledged_version' => $acknowledgment === null
                        ? null
                        : $this->versionLabel(
                            (int) $acknowledgment->document_revision,
                            (int) $acknowledgment->fragment_revision,
                        ),
                    'document_changed_since' => $acknowledgment !== null
                        && $document !== null
                        && ((int) $acknowledgment->document_revision !== (int) $document->document_revision
                            || (int) $acknowledgment->fragment_revision !== (int) $document->fragment_revision),
                ];
            })
            ->sortBy(fn (array $row): string => Str::lower((string) $row['display_name']).'|'.$row['staff_id'])
            ->values();

        return [
            'id' => (string) $requirement->getKey(),
            'organization_id' => (string) $organization->getKey(),
            'scope_type' => $requirement->scope_type,
            'scope_id' => (string) $requirement->scope_id,
            'scope_label' => $this->scopeLabel($requirement),
            'requirement_context' => $requirement->requirement_context,
            'requirement_context_label' => $this->contextLabel($requirement->requirement_context),
            'document_type' => $requirement->document_type,
            'document_id' => (string) $requirement->document_id,
            'document_title' => $document?->title ?? 'Missing document',
            'document_version' => $document?->version(),
            'document_published' => $document?->isPublished() ?? false,
            'active' => $requirement->isActive(),
            'subject_count' => $rows->count(),
            'acknowledged_count' => $rows->filter(fn (array $row): bool => $row['acknowledged'])->count(),
            'staff' => $rows->all(),
        ];
    }

    /**
     * Everybody a requirement asks.
     *
     * The same rule `DocumentAcknowledgmentAccess` applies to one person, run
     * the other way round. Keeping both readings in one place is what makes the
     * review list and the command agree about who was ever asked.
     *
     * @return Collection<int, Staff>
     */
    private function subjectsFor(DocumentAcknowledgmentRequirement $requirement): Collection
    {
        return match ($requirement->scope_type) {
            DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION => Staff::query()
                ->whereHas('organizationStatuses', fn ($query) => $query->where('organization_id', $requirement->scope_id))
                ->get(),
            DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT => Staff::query()
                ->whereHas('departmentMemberships', fn ($query) => $query
                    ->active()
                    ->where('department_id', $requirement->scope_id))
                ->get(),
            default => collect(),
        };
    }

    /**
     * The published documents a requirement can point at.
     *
     * Published only, which is the same rule `createRequirement` enforces and
     * the same rule `DocumentAcknowledgmentService` enforces at acceptance. A
     * form that offered a draft would be a form whose choices the command
     * refuses.
     *
     * @return list<array<string, mixed>>
     */
    private function publishedDocumentOptions(Organization $organization): array
    {
        return collect([
            ...PolicyDocument::query()
                ->where('organization_id', $organization->getKey())
                ->get()
                ->map(fn (PolicyDocument $document): array => $this->documentOption($document, 'policy'))
                ->all(),
            ...ProcedureDocument::query()
                ->where('organization_id', $organization->getKey())
                ->get()
                ->map(fn (ProcedureDocument $document): array => $this->documentOption($document, 'procedure'))
                ->all(),
        ])
            ->filter(fn (array $option): bool => $option['published'])
            ->sortBy(fn (array $option): string => Str::lower((string) $option['title']))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function documentOption(PolicyDocument|ProcedureDocument $document, string $documentType): array
    {
        return [
            'document_type' => $documentType,
            'document_id' => (string) $document->getKey(),
            'title' => $document->title,
            'version' => $document->version(),
            'published' => $document->isPublished(),
        ];
    }

    /**
     * The scopes a requirement may be aimed at (POL-047).
     *
     * The organization itself, and every department in it. Team scope exists for
     * documents and is deliberately absent here: POL-047 limits acknowledgment
     * requirements to organization and department for MVP, and the domain
     * service refuses anything else.
     *
     * @return list<array<string, string>>
     */
    private function scopeOptions(Organization $organization): array
    {
        return [
            [
                'scope_type' => DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
                'scope_id' => (string) $organization->getKey(),
                'label' => 'Organization: '.$organization->name,
            ],
            ...Department::query()
                ->where('organization_id', $organization->getKey())
                ->orderBy('name')
                ->get()
                ->map(fn (Department $department): array => [
                    'scope_type' => DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT,
                    'scope_id' => (string) $department->getKey(),
                    'label' => 'Department: '.$department->name,
                ])
                ->all(),
        ];
    }

    private function documentFor(string $documentType, string $documentId): PolicyDocument|ProcedureDocument|null
    {
        $model = DocumentAcknowledgmentRequirement::documentModelForType($documentType);

        return $model === null ? null : $model::query()->find($documentId);
    }

    private function scopeLabel(DocumentAcknowledgmentRequirement $requirement): string
    {
        return match ($requirement->scope_type) {
            DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION => 'Organization: '
                .(Organization::query()->whereKey($requirement->scope_id)->value('name') ?? 'Not configured'),
            DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT => 'Department: '
                .(Department::query()->whereKey($requirement->scope_id)->value('name') ?? 'Not configured'),
            default => Str::headline($requirement->scope_type),
        };
    }

    private function contextLabel(string $context): string
    {
        return match ($context) {
            DocumentAcknowledgmentRequirement::CONTEXT_SIGNUP => 'Staff signup',
            DocumentAcknowledgmentRequirement::CONTEXT_TRAINING => 'Training',
            default => Str::headline($context),
        };
    }

    /** The same `major.minor` reading `PolicyDocument::version()` produces. */
    private function versionLabel(int $documentRevision, int $fragmentRevision): string
    {
        return sprintf('%d.%02d', $documentRevision, $fragmentRevision);
    }

    /**
     * @return array<string, mixed>
     */
    private function requirementAuditSnapshot(DocumentAcknowledgmentRequirement $requirement): array
    {
        return [
            'organization_id' => (string) $requirement->organization_id,
            'scope_type' => $requirement->scope_type,
            'scope_id' => (string) $requirement->scope_id,
            'document_type' => $requirement->document_type,
            'document_id' => (string) $requirement->document_id,
            'requirement_context' => $requirement->requirement_context,
            'active' => $requirement->isActive(),
        ];
    }

    private function reviewRefusal(): JsonResponse
    {
        return response()->json([
            'message' => 'Only organizers may maintain and review document acknowledgment requirements.',
        ], 403);
    }
}
