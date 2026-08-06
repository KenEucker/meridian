<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployments;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Deployment;
use App\Models\Event;
use App\Models\User;
use App\Services\Deployments\DeploymentAdminAccess;
use App\Services\Deployments\DeploymentAdminException;
use App\Services\Deployments\DeploymentAdminService;
use App\Services\Node\EventAuthorityException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `department.deployments` — maintaining a department's deployment options
 * (M18.30; UI contract 12.4; SLB-009, SLB-010; data/API section 10.14).
 *
 * One read and four commands. The read carries the archived options beside the
 * active ones, because restoring a location a department used last year is half
 * of why somebody opens this page before an event, and it carries how many
 * staff are standing at each one, because that is the number that decides
 * whether an option can be archived at all.
 *
 * The commands are scoped by the deployment's own event and department rather
 * than by anything the client sends: a request names a deployment, and the
 * authority check resolves the department that owns it. A caller who leads one
 * department cannot rename another's gate by knowing its id.
 */
final class DeploymentAdminController extends Controller
{
    public function index(
        Request $request,
        Event $event,
        Department $department,
        DeploymentAdminAccess $access,
        DeploymentAdminService $deployments,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if ((string) $department->organization_id !== (string) $event->organization_id) {
            return response()->json(['message' => 'Department not found for this event.'], 404);
        }

        if (! $access->canManageDeployments($user, $event, $department)) {
            return $this->refusal();
        }

        $rows = Deployment::query()
            ->where('event_id', $event->getKey())
            ->where('department_id', $department->getKey())
            ->orderBy('name')
            ->get();

        $assigned = $deployments->currentAssignmentCounts($rows->modelKeys());

        return response()->json([
            'context' => [
                'event_id' => (string) $event->getKey(),
                'event_label' => $event->name,
                'department_id' => (string) $department->getKey(),
                'department_label' => $department->name,
            ],
            'deployments' => $rows
                ->map(fn (Deployment $deployment): array => [
                    'id' => (string) $deployment->getKey(),
                    'name' => (string) $deployment->name,
                    'description' => $deployment->description,
                    'location_details' => $deployment->location_details,
                    'archived_at' => $deployment->archived_at?->toIso8601String(),
                    'assigned_staff_count' => $assigned[(string) $deployment->getKey()] ?? 0,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function create(
        Request $request,
        DeploymentAdminAccess $access,
        DeploymentAdminService $deployments,
    ): JsonResponse {
        $validated = $request->validate([
            'event_id' => ['required', 'uuid', 'exists:events,id'],
            'department_id' => ['required', 'uuid', 'exists:departments,id'],
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'location_details' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $event = Event::query()->findOrFail((string) $validated['event_id']);
        $department = Department::query()->findOrFail((string) $validated['department_id']);

        if ((string) $department->organization_id !== (string) $event->organization_id) {
            return response()->json(['message' => 'Department not found for this event.'], 404);
        }

        if (! $access->canManageDeployments($user, $event, $department)) {
            return $this->refusal();
        }

        try {
            $deployment = $deployments->create(
                $event,
                $department,
                (string) $validated['name'],
                $validated['description'] ?? null,
                $validated['location_details'] ?? null,
                $user,
                AuditEvent::SOURCE_API,
            );
        } catch (EventAuthorityException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (DeploymentAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['id' => (string) $deployment->getKey()], 201);
    }

    public function update(
        Request $request,
        DeploymentAdminAccess $access,
        DeploymentAdminService $deployments,
    ): JsonResponse {
        return $this->mutate(
            $request,
            $access,
            [
                'name' => ['required', 'string'],
                'description' => ['nullable', 'string'],
                'location_details' => ['nullable', 'string'],
            ],
            fn (Deployment $deployment, User $user, array $validated) => $deployments->update(
                $deployment,
                (string) $validated['name'],
                $validated['description'] ?? null,
                $validated['location_details'] ?? null,
                $user,
                AuditEvent::SOURCE_API,
            ),
        );
    }

    public function archive(
        Request $request,
        DeploymentAdminAccess $access,
        DeploymentAdminService $deployments,
    ): JsonResponse {
        return $this->mutate(
            $request,
            $access,
            [],
            fn (Deployment $deployment, User $user) => $deployments->archive(
                $deployment,
                $user,
                AuditEvent::SOURCE_API,
            ),
        );
    }

    public function restore(
        Request $request,
        DeploymentAdminAccess $access,
        DeploymentAdminService $deployments,
    ): JsonResponse {
        return $this->mutate(
            $request,
            $access,
            [],
            fn (Deployment $deployment, User $user) => $deployments->restore(
                $deployment,
                $user,
                AuditEvent::SOURCE_API,
            ),
        );
    }

    /**
     * The three commands that name an existing deployment, which differ only in
     * what they validate and what they call.
     *
     * @param  array<string, list<string>>  $rules
     * @param  Closure(Deployment, User, array<string, mixed>): Deployment  $apply
     */
    private function mutate(
        Request $request,
        DeploymentAdminAccess $access,
        array $rules,
        Closure $apply,
    ): JsonResponse {
        $validated = $request->validate([
            'deployment_id' => ['required', 'uuid', 'exists:deployments,id'],
            ...$rules,
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $deployment = Deployment::query()
            ->with(['event', 'department'])
            ->findOrFail((string) $validated['deployment_id']);

        $event = $deployment->event;
        $department = $deployment->department;

        if (! $event instanceof Event || ! $department instanceof Department) {
            return response()->json(['message' => 'This deployment is not attached to an event and department.'], 422);
        }

        if (! $access->canManageDeployments($user, $event, $department)) {
            return $this->refusal();
        }

        try {
            $applied = $apply($deployment, $user, $validated);
        } catch (EventAuthorityException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (DeploymentAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'id' => (string) $applied->getKey(),
            'archived_at' => $applied->archived_at?->toIso8601String(),
        ]);
    }

    private function refusal(): JsonResponse
    {
        return response()->json([
            'message' => 'You do not have permission to maintain deployments for this department.',
        ], 403);
    }
}
