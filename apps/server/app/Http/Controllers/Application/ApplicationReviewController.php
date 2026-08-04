<?php

declare(strict_types=1);

namespace App\Http\Controllers\Application;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\EventApplication;
use App\Models\User;
use App\Services\Application\ApplicationApprovalException;
use App\Services\Application\ApplicationReviewAccess;
use App\Services\Application\ApplicationReviewException;
use App\Services\Application\EventApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Application review as a product surface (M18.21A; APP-005, APP-011,
 * APP-019).
 *
 * Review existed only in the God Mode console until now, which made a routine
 * organizer job reachable only through the repair interface. This is the same
 * decisions through the same domain service, answering to the catalog instead
 * of to `platform.applications`.
 *
 * Scope is the node's answer and the client cannot widen it.
 * {@see ApplicationReviewAccess::scopeVisibleApplications()} already resolves
 * two populations at once — reviewers see the organizations they hold
 * `organization.applications.review` in, and department leads see submitted
 * applications naming a department they lead — so the list is one read and the
 * client never asks for "all applications" and filters. The same object decides
 * whether the decision buttons may be used at all, which is what keeps
 * department-lead visibility read-only (APP-011).
 */
class ApplicationReviewController extends Controller
{
    public function __construct(
        private readonly ApplicationReviewAccess $access,
        private readonly EventApplicationService $applications,
    ) {}

    /**
     * The applications this caller may see (APP-005, APP-011).
     *
     * A caller with neither review authority nor department-lead visibility is
     * refused rather than shown an empty list, because "nothing is waiting" and
     * "you do not review applications" are different facts and only one of them
     * is true for them.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $canReview = $this->access->canReviewApplications($user)
            || $this->access->reviewableOrganizationIds($user)->isNotEmpty();
        $hasLeadVisibility = $this->access->hasDepartmentLeadVisibility($user);

        abort_unless($canReview || $hasLeadVisibility, 403);

        $query = EventApplication::query()
            ->with(['event', 'organization', 'departmentInterests', 'reviewedBy'])
            ->latest('submitted_at');

        $applications = $this->access
            ->scopeVisibleApplications($query, $user)
            ->get();

        return response()->json([
            'can_review' => $canReview,
            'has_department_lead_visibility' => $hasLeadVisibility,
            'applications' => $applications
                ->map(fn (EventApplication $application): array => $this->row($application, $user))
                ->values()
                ->all(),
        ]);
    }

    public function show(Request $request, EventApplication $application): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($this->access->canViewApplication($user, $application), 403);

        $application->load(['event', 'organization', 'departmentInterests', 'reviewedBy', 'staff']);

        return response()->json([
            'application' => $this->row($application, $user),
        ]);
    }

    /** APP-005: approve, at the organization level, whatever the scope. */
    public function approve(Request $request): JsonResponse
    {
        return $this->decide($request, 'approve');
    }

    public function reject(Request $request): JsonResponse
    {
        return $this->decide($request, 'reject');
    }

    public function defer(Request $request): JsonResponse
    {
        return $this->decide($request, 'defer');
    }

    private function decide(Request $request, string $decision): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'application_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $application = EventApplication::query()->findOrFail($validated['application_id']);

        // The same refusal a department lead's read-only visibility earns them,
        // stated by the node rather than implied by a hidden button.
        abort_unless($this->access->canReviewApplication($user, $application), 403);

        $reason = trim((string) ($validated['reason'] ?? ''));
        $reason = $reason === '' ? null : $reason;

        try {
            $application = match ($decision) {
                'approve' => $this->applications->approve($application, $user, $reason),
                'reject' => $this->applications->reject($application, $user, $reason),
                default => $this->applications->defer($application, $user, $reason),
            };
        } catch (ApplicationApprovalException|ApplicationReviewException $exception) {
            throw ValidationException::withMessages([
                'application_id' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'application' => $this->row($application->refresh()->load([
                'event', 'organization', 'departmentInterests', 'reviewedBy',
            ]), $user),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(EventApplication $application, User $user): array
    {
        return [
            'id' => (string) $application->id,
            // APP-001: what the application is about, said in one field rather
            // than left to be inferred from a null event.
            'scope' => $application->isOrganizationScoped() ? 'organization' : 'event',
            'organization_id' => (string) $application->organization_id,
            'organization_name' => $application->organization?->name,
            'event_id' => $application->event_id !== null ? (string) $application->event_id : null,
            'event_name' => $application->event?->name,
            'applicant_legal_name' => (string) $application->applicant_legal_name,
            'applicant_email' => (string) $application->applicant_email,
            'status' => (string) $application->status,
            'status_label' => $application->statusLabel(),
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'reviewed_at' => $application->reviewed_at?->toIso8601String(),
            'reviewed_by' => $application->reviewedBy?->name,
            'decision_reason' => $application->decision_reason,
            // APP-011: interest, labelled as interest, never as assignment.
            'department_interests' => $application->departmentInterests
                ->map(fn (Department $department): array => [
                    'id' => (string) $department->id,
                    'name' => (string) $department->name,
                    'archived' => $department->archived_at !== null,
                ])
                ->values()
                ->all(),
            // Per row rather than per surface: a reviewer for one organization
            // may hold only lead visibility in another, and the same list can
            // carry both.
            'can_review' => $this->access->canReviewApplication($user, $application),
        ];
    }
}
