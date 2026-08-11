<?php

declare(strict_types=1);

namespace App\Http\Controllers\Directory;

use App\Http\Controllers\Controller;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Organization;
use App\Models\StaffOrganizationStatus;
use App\Models\User;
use App\Services\Directory\DirectoryChartService;
use App\Services\Directory\DirectoryContext;
use App\Services\Directory\DirectorySearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Directory chart read (M18.73; DIR-001 through DIR-015, DIR-027 through
 * DIR-030; technical spec 21E.2, 21E.3, 21E.6).
 *
 * Two routes, one composition: the organization Directory and the event
 * Directory are the same chart over different populations (DIR-006, DIR-007),
 * so they differ only in the context handed to the service.
 *
 * Where the organization has disabled the Directory the answer is 404, before
 * any authorization question is asked (DIR-005). A disabled Directory is
 * absent, not refused: a 403 would confirm the feature exists and is switched
 * off, so a permitted and an unpermitted viewer receive the same not-found
 * answer.
 *
 * Standing is the only authorization: the viewer must be staff of the
 * organization. No capability is required (DIR-024) — what the chart contains
 * for this viewer is entirely the visibility rule's answer, and an ordinary
 * member receives the organization's leadership rather than a refusal.
 */
final class DirectoryReadController extends Controller
{
    public function __construct(
        private readonly DirectoryChartService $chart,
        private readonly DirectorySearchService $search,
    ) {}

    public function organization(Request $request, Organization $organization): JsonResponse
    {
        return $this->respond($request, new DirectoryContext($organization));
    }

    public function event(Request $request, Event $event): JsonResponse
    {
        $organization = $event->organization;
        abort_if($organization === null, 404);

        return $this->respond($request, new DirectoryContext($organization, $event));
    }

    /**
     * Handle search over the authorized set (M18.74; DIR-031 through
     * DIR-034). The same gates as the chart, because search reaches exactly
     * what the chart reaches and nothing else.
     */
    public function organizationSearch(Request $request, Organization $organization): JsonResponse
    {
        return $this->respondToSearch($request, new DirectoryContext($organization));
    }

    public function eventSearch(Request $request, Event $event): JsonResponse
    {
        $organization = $event->organization;
        abort_if($organization === null, 404);

        return $this->respondToSearch($request, new DirectoryContext($organization, $event));
    }

    private function respond(Request $request, DirectoryContext $context): JsonResponse
    {
        $user = $this->gate($request, $context);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $chart = $this->chart->chart($user, $context);

        return response()->json([
            'context' => $this->contextPayload($context),
            'departments' => $chart['departments'],
            'people' => $chart['people'],
        ]);
    }

    private function respondToSearch(Request $request, DirectoryContext $context): JsonResponse
    {
        $user = $this->gate($request, $context);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = (string) ($validated['q'] ?? '');

        return response()->json([
            'context' => $this->contextPayload($context),
            'query' => $query,
            'results' => $this->search->search($user, $context, $query),
        ]);
    }

    /**
     * The shared gate, in its one deliberate order: availability first
     * (DIR-005), then authentication, then standing. A disabled Directory is
     * 404 to everyone before any authorization question is asked, so the
     * answer cannot disclose that the feature exists.
     */
    private function gate(Request $request, DirectoryContext $context): User|JsonResponse
    {
        abort_unless($context->organization->directoryEnabled(), 404);

        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $this->holdsStanding($user, $context->organization)) {
            return response()->json([
                'message' => 'Access to the Directory is restricted.',
            ], 403);
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function contextPayload(DirectoryContext $context): array
    {
        return [
            'scope' => $context->isEventContext() ? 'event' : 'organization',
            'organization_id' => (string) $context->organization->getKey(),
            'organization_label' => $context->organization->name,
            'event_id' => $context->event !== null ? (string) $context->event->getKey() : null,
            'event_label' => $context->event?->name,
        ];
    }

    /**
     * Whether this login is staff of the organization at all: an organization
     * status row, or an active membership of one of its departments — the
     * same standing question the Event Horizon viewer resolver asks. A God
     * Mode operator or the staff of another organization is not staff *here*,
     * and reads no chart rather than an empty one.
     */
    private function holdsStanding(User $user, Organization $organization): bool
    {
        $staffIds = $user->staffProfiles()
            ->pluck('staff.id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        if ($staffIds === []) {
            return false;
        }

        $holdsStatus = StaffOrganizationStatus::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('staff_id', $staffIds)
            ->exists();

        if ($holdsStatus) {
            return true;
        }

        return DepartmentMembership::query()
            ->active()
            ->whereIn('staff_id', $staffIds)
            ->whereHas('department', fn (Builder $department) => $department
                ->where('organization_id', $organization->getKey()))
            ->exists();
    }
}
