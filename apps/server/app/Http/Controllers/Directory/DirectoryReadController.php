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
    public function __construct(private readonly DirectoryChartService $chart) {}

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

    private function respond(Request $request, DirectoryContext $context): JsonResponse
    {
        abort_unless($context->organization->directoryEnabled(), 404);

        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $this->holdsStanding($user, $context->organization)) {
            return response()->json([
                'message' => 'Access to the Directory is restricted.',
            ], 403);
        }

        $chart = $this->chart->chart($user, $context);

        return response()->json([
            'context' => [
                'scope' => $context->isEventContext() ? 'event' : 'organization',
                'organization_id' => (string) $context->organization->getKey(),
                'organization_label' => $context->organization->name,
                'event_id' => $context->event !== null ? (string) $context->event->getKey() : null,
                'event_label' => $context->event?->name,
            ],
            'departments' => $chart['departments'],
            'people' => $chart['people'],
        ]);
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
