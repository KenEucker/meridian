<?php

namespace App\Services\Deployments;

use App\Models\AuditEvent;
use App\Models\CurrentDeploymentAssignment;
use App\Models\Deployment;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\User;
use App\Services\Attendance\AttendanceCheckInAccess;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sets the current deployment/location for a rostered staff member (SLB-009,
 * SLB-010; data/API section 10.14). Alpha 1 stores only the current state; full
 * movement history remains future scope.
 */
class DeploymentAssignmentService
{
    public function __construct(
        private readonly AttendanceCheckInAccess $access,
        private readonly AuditService $audit,
    ) {}

    /**
     * @throws DeploymentAssignmentException
     */
    public function setCurrentDeployment(
        Shift $shift,
        Staff $staff,
        Deployment $deployment,
        User $actor,
        ?Carbon $assignedAt = null,
    ): DeploymentAssignmentResult {
        $assignedAt ??= Carbon::now();

        return DB::transaction(function () use ($shift, $staff, $deployment, $actor, $assignedAt): DeploymentAssignmentResult {
            $shift = Shift::query()
                ->with(['event', 'department'])
                ->whereKey($shift->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $deployment = Deployment::query()
                ->whereKey($deployment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->access->canCheckInForShift($actor, $shift)) {
                throw DeploymentAssignmentException::unauthorized();
            }

            if ($shift->isCancelled()) {
                throw DeploymentAssignmentException::cancelledShift();
            }

            $shiftAssignment = ShiftAssignment::query()
                ->where('shift_id', $shift->id)
                ->where('staff_id', $staff->id)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->first();

            if ($shiftAssignment === null) {
                throw DeploymentAssignmentException::noActiveAssignment();
            }

            if ((string) $deployment->event_id !== (string) $shift->event_id
                || (string) $deployment->department_id !== (string) $shift->department_id) {
                throw DeploymentAssignmentException::deploymentOutsideShiftScope();
            }

            if ($deployment->isArchived()) {
                throw DeploymentAssignmentException::archivedDeployment();
            }

            $current = CurrentDeploymentAssignment::query()
                ->where('shift_id', $shift->id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($current !== null && (string) $current->deployment_id === (string) $deployment->id) {
                return new DeploymentAssignmentResult(
                    assignment: $current->refresh(),
                    createdStateChange: false,
                );
            }

            $before = $current === null ? null : $this->auditSnapshot($current);

            if ($current === null) {
                $current = CurrentDeploymentAssignment::query()->create([
                    'event_id' => $shift->event_id,
                    'department_id' => $shift->department_id,
                    'shift_id' => $shift->id,
                    'staff_id' => $staff->id,
                    'deployment_id' => $deployment->id,
                    'assigned_by_user_id' => $actor->id,
                    'assigned_at' => $assignedAt,
                ]);
            } else {
                $current->forceFill([
                    'deployment_id' => $deployment->id,
                    'assigned_by_user_id' => $actor->id,
                    'assigned_at' => $assignedAt,
                ])->save();
            }

            $current = $current->refresh();

            $this->audit->recordForEntity(
                entity: $current,
                action: 'deployment.current_set',
                actorUser: $actor,
                organizationId: $shift->event?->organization_id,
                eventId: $shift->event_id,
                departmentId: $shift->department_id,
                before: $before,
                after: $this->auditSnapshot($current),
                sourceContext: AuditEvent::SOURCE_API,
            );

            return new DeploymentAssignmentResult(
                assignment: $current,
                createdStateChange: true,
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(CurrentDeploymentAssignment $assignment): array
    {
        return [
            'event_id' => $assignment->event_id,
            'department_id' => $assignment->department_id,
            'shift_id' => $assignment->shift_id,
            'staff_id' => $assignment->staff_id,
            'deployment_id' => $assignment->deployment_id,
            'assigned_by_user_id' => $assignment->assigned_by_user_id,
            'assigned_at' => $assignment->assigned_at?->toIso8601String(),
        ];
    }
}
