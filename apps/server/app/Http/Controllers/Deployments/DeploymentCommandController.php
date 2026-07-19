<?php

namespace App\Http\Controllers\Deployments;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\Shift;
use App\Models\Staff;
use App\Services\Deployments\DeploymentAssignmentException;
use App\Services\Deployments\DeploymentAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DeploymentCommandController extends Controller
{
    public function setCurrent(
        Request $request,
        DeploymentAssignmentService $deploymentAssignments,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'shift_id' => ['required', 'uuid', 'exists:shifts,id'],
            'staff_id' => ['required', 'uuid', 'exists:staff,id'],
            'deployment_id' => ['required', 'uuid', 'exists:deployments,id'],
            'assigned_at' => ['nullable', 'date'],
        ]);

        try {
            $result = $deploymentAssignments->setCurrentDeployment(
                shift: Shift::query()->findOrFail((string) $validated['shift_id']),
                staff: Staff::query()->findOrFail((string) $validated['staff_id']),
                deployment: Deployment::query()->findOrFail((string) $validated['deployment_id']),
                actor: $user,
                assignedAt: isset($validated['assigned_at'])
                    ? Carbon::parse((string) $validated['assigned_at'])
                    : null,
            );
        } catch (DeploymentAssignmentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'current_deployment_assignment_id' => $result->assignment->id,
            'event_id' => $result->assignment->event_id,
            'department_id' => $result->assignment->department_id,
            'shift_id' => $result->assignment->shift_id,
            'staff_id' => $result->assignment->staff_id,
            'deployment_id' => $result->assignment->deployment_id,
            'assigned_by_user_id' => $result->assignment->assigned_by_user_id,
            'assigned_at' => optional($result->assignment->assigned_at)?->toIso8601String(),
            'created_state_change' => $result->createdStateChange,
        ], 201);
    }
}
