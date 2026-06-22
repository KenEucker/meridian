<?php

namespace App\Services\Shift;

use App\Models\AuditEvent;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Credential\CredentialEligibilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shift assignment removal for leads (SHIFT-013) and self-withdrawal before schedule lock.
 */
class ShiftRemovalService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ShiftAssignmentAccess $access,
        private readonly CredentialEligibilityService $credentials,
    ) {}

    /**
     * @throws ShiftRemovalException when removal is not permitted
     */
    public function removeStaffFromShift(
        ShiftAssignment $assignment,
        User $remover,
        ?Carbon $moment = null,
    ): ShiftAssignment {
        $moment ??= Carbon::now();

        return DB::transaction(function () use ($assignment, $remover, $moment): ShiftAssignment {
            $assignment = ShiftAssignment::query()
                ->with(['shift.event', 'shift.department', 'staff'])
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->access->canAssignToShift($remover, $assignment->shift)) {
                throw ShiftRemovalException::unauthorized();
            }

            return $this->markRemoved(
                assignment: $assignment,
                actor: $remover,
                action: 'shift_assignment.removed',
                moment: $moment,
            );
        });
    }

    /**
     * @throws ShiftRemovalException when withdrawal is not permitted
     */
    public function withdrawFromShift(
        ShiftAssignment $assignment,
        Staff $staff,
        User $user,
        ?Carbon $moment = null,
    ): ShiftAssignment {
        $moment ??= Carbon::now();

        if (! $user->staffProfiles()->whereKey($staff->getKey())->exists()) {
            throw ShiftRemovalException::staffNotLinkedToUser();
        }

        return DB::transaction(function () use ($assignment, $staff, $user, $moment): ShiftAssignment {
            $assignment = ShiftAssignment::query()
                ->with(['shift.event', 'shift.department', 'staff'])
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $assignment->staff_id !== (string) $staff->id) {
                throw ShiftRemovalException::assignmentMismatch();
            }

            if ($assignment->shift->isScheduleLockedAt($moment)) {
                throw ShiftRemovalException::scheduleLocked();
            }

            return $this->markRemoved(
                assignment: $assignment,
                actor: $user,
                action: 'shift_assignment.withdrawn',
                moment: $moment,
            );
        });
    }

    /**
     * @throws ShiftRemovalException
     */
    private function markRemoved(
        ShiftAssignment $assignment,
        User $actor,
        string $action,
        Carbon $moment,
    ): ShiftAssignment {
        if ($assignment->removed_at !== null) {
            throw ShiftRemovalException::alreadyRemoved();
        }

        $before = $this->auditSnapshot($assignment);

        $assignment->forceFill(['removed_at' => $moment])->save();

        $this->audit->recordForEntity(
            entity: $assignment,
            action: $action,
            actorUser: $actor,
            organizationId: $assignment->shift?->event?->organization_id,
            eventId: $assignment->shift?->event_id,
            departmentId: $assignment->shift?->department_id,
            before: $before,
            after: $this->auditSnapshot($assignment->refresh()),
            sourceContext: AuditEvent::SOURCE_API,
        );

        $event = $assignment->shift?->event;
        $staff = $assignment->staff;

        if ($event !== null && $staff !== null) {
            $this->credentials->recalculate($event, $staff, $moment, $actor);
        }

        return $assignment;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(ShiftAssignment $assignment): array
    {
        return [
            'shift_id' => $assignment->shift_id,
            'staff_id' => $assignment->staff_id,
            'assigned_by_user_id' => $assignment->assigned_by_user_id,
            'assignment_status' => $assignment->assignment_status,
            'removed_at' => $assignment->removed_at?->toIso8601String(),
        ];
    }
}
