<?php

namespace App\Services\Shift;

use App\Models\AuditEvent;
use App\Models\DepartmentMembership;
use App\Models\EventDepartmentPresence;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Attendance\AttendanceCheckInAccess;
use App\Services\Audit\AuditService;
use App\Services\Credential\CredentialEligibilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Adds eligible unscheduled staff to an in-progress shift (SLB-008, SHIFT-016).
 *
 * An Alpha 1 offline write since M18.54 (technical spec 9.4, data/API 7.2), so
 * this service is now reached by a queue as well as by a person standing at a
 * connected desk. Two things follow, and neither of them is a relaxed rule.
 *
 * **Nothing about eligibility moves.** Do Not Staff, department membership,
 * team eligibility, required trainings and waivers, and the on-site check are
 * decided here, against what the node holds when the command arrives, exactly
 * as they were when the only caller was an online desk. A queued addition for
 * somebody who may not work the shift is refused on replay and the operator is
 * shown the node's sentence — which is the trade M18.54 accepts, because the
 * work otherwise went onto paper and was never recorded at all.
 *
 * **A replay is the same command.** The device mints an operation UUID before
 * it has a node to ask, and an addition made under a UUID this service has
 * already applied returns that assignment rather than refusing it as a
 * duplicate. Without that, a reply lost on a field network would come back to
 * the operator as "already assigned to the shift" — a refusal reporting a
 * success, which is the one answer worse than either.
 */
class UnscheduledShiftAdditionService
{
    public function __construct(
        private readonly AttendanceCheckInAccess $access,
        private readonly AuditService $audit,
        private readonly ShiftEligibilityService $eligibility,
        private readonly ShiftOverlapService $overlaps,
        private readonly CredentialEligibilityService $credentials,
    ) {}

    /**
     * @param  Carbon|null  $moment  The clock every rule is decided against: the
     *                               node's own, not the device's. A queued
     *                               addition is weighed as the node finds things
     *                               when it arrives.
     * @param  string|null  $operationUuid  The device-generated key this addition
     *                                      was queued under, when it came from a
     *                                      queue (data/API 5.3).
     * @param  Carbon|null  $recordedAt  When the operator recorded it, which is
     *                                   what the assignment is stamped with. An
     *                                   addition made at 02:10 and delivered at
     *                                   06:00 happened at 02:10, and SLB-008
     *                                   reads that timestamp to tell an
     *                                   unscheduled addition from a signup.
     *
     * @throws UnscheduledShiftAdditionException
     */
    public function addStaffToShift(
        Shift $shift,
        Staff $staff,
        User $actor,
        ?Carbon $moment = null,
        ?string $operationUuid = null,
        ?Carbon $recordedAt = null,
    ): ShiftAssignmentOutcome {
        $moment ??= Carbon::now();
        $recordedAt ??= $moment;

        return DB::transaction(function () use ($shift, $staff, $actor, $moment, $operationUuid, $recordedAt): ShiftAssignmentOutcome {
            $shift = Shift::query()
                ->with(['event', 'department'])
                ->whereKey($shift->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * The same command arriving twice, before anything else is weighed.
             * Ahead of the authorization check on purpose: the decision was made
             * once, by this actor, and re-deciding it would let a grant withdrawn
             * between the two deliveries turn an applied addition into a refusal
             * of work that is already on the roster.
             */
            $replayed = $operationUuid === null
                ? null
                : ShiftAssignment::query()
                    ->where('unscheduled_operation_uuid', $operationUuid)
                    ->first();

            if ($replayed !== null) {
                return new ShiftAssignmentOutcome(
                    assignment: $replayed->load(['shift', 'staff']),
                    warnings: $this->overlaps->warningsFor($staff, $shift),
                    replayed: true,
                );
            }

            if (! $this->access->canCheckInForShift($actor, $shift)) {
                throw UnscheduledShiftAdditionException::unauthorized();
            }

            if ($shift->isCancelled()) {
                throw UnscheduledShiftAdditionException::cancelledShift();
            }

            if ($moment->lt($shift->starts_at)) {
                throw UnscheduledShiftAdditionException::shiftNotStarted();
            }

            $this->assertStaffEligibleForUnscheduledAddition($shift, $staff, $moment);

            $existingAssignment = ShiftAssignment::query()
                ->where('shift_id', $shift->id)
                ->where('staff_id', $staff->id)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->first();

            if ($existingAssignment !== null) {
                throw UnscheduledShiftAdditionException::alreadyAssigned();
            }

            $warnings = $this->overlaps->warningsFor($staff, $shift);

            $assignment = new ShiftAssignment([
                'shift_id' => $shift->id,
                'staff_id' => $staff->id,
                'assigned_by_user_id' => $actor->id,
                'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
                'removed_at' => null,
                'unscheduled_operation_uuid' => $operationUuid,
            ]);
            $assignment->created_at = $recordedAt;
            $assignment->updated_at = $recordedAt;
            $assignment->save();

            $this->audit->recordForEntity(
                entity: $assignment,
                action: 'shift_assignment.unscheduled_added',
                actorUser: $actor,
                organizationId: $shift->event?->organization_id,
                eventId: $shift->event_id,
                departmentId: $shift->department_id,
                after: $this->auditSnapshot($assignment, $warnings),
                sourceContext: AuditEvent::SOURCE_API,
            );

            $this->credentials->recalculate($shift->event, $staff, $moment, $actor);

            return new ShiftAssignmentOutcome(
                assignment: $assignment->load(['shift', 'staff']),
                warnings: $warnings,
            );
        });
    }

    /**
     * @throws UnscheduledShiftAdditionException
     */
    private function assertStaffEligibleForUnscheduledAddition(Shift $shift, Staff $staff, Carbon $moment): void
    {
        $organizationId = $shift->event?->organization_id;

        if ($organizationId !== null) {
            $organizationStatus = StaffOrganizationStatus::query()
                ->where('organization_id', $organizationId)
                ->where('staff_id', $staff->id)
                ->first();

            if ($organizationStatus?->status === StaffOrganizationStatus::STATUS_DO_NOT_STAFF) {
                throw UnscheduledShiftAdditionException::doNotStaff();
            }
        }

        $departmentMembership = DepartmentMembership::query()
            ->active()
            ->where('department_id', $shift->department_id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($departmentMembership === null) {
            throw UnscheduledShiftAdditionException::noDepartmentMembership();
        }

        $isOnSite = EventDepartmentPresence::query()
            ->where('event_id', $shift->event_id)
            ->where('department_id', $shift->department_id)
            ->where('staff_id', $staff->id)
            ->where('current_state', EventDepartmentPresence::STATE_ON_SITE)
            ->exists();

        if (! $isOnSite) {
            throw UnscheduledShiftAdditionException::staffNotOnSite();
        }

        try {
            $this->eligibility->assertMeetsAssignmentRequirements($shift, $staff, $departmentMembership, $moment);
        } catch (ShiftSignupException $exception) {
            throw $this->unscheduledExceptionFor($exception);
        }

        // The same rule the Logistics Desk decides whether to offer the addition
        // by, asked from one place so the two cannot drift (SLB-008).
        $hasEligibleTeamMembership = TeamMembership::query()
            ->onEligibleShiftTeam($departmentMembership)
            ->where('team_id', $shift->eligible_team_id)
            ->where('staff_id', $staff->id)
            ->exists();

        if (! $hasEligibleTeamMembership) {
            throw UnscheduledShiftAdditionException::notEligibleTeamMember();
        }
    }

    private function unscheduledExceptionFor(ShiftSignupException $exception): UnscheduledShiftAdditionException
    {
        return match ($exception->getMessage()) {
            ShiftSignupException::departmentIneligible()->getMessage() => UnscheduledShiftAdditionException::departmentIneligible(),
            ShiftSignupException::missingRequiredTraining()->getMessage() => UnscheduledShiftAdditionException::missingRequiredTraining(),
            ShiftSignupException::missingRequiredWaiver()->getMessage() => UnscheduledShiftAdditionException::missingRequiredWaiver(),
            default => new UnscheduledShiftAdditionException($exception->getMessage()),
        };
    }

    /**
     * @param  list<ShiftOverlapWarning>  $warnings
     * @return array<string, mixed>
     */
    private function auditSnapshot(ShiftAssignment $assignment, array $warnings): array
    {
        return [
            'shift_id' => $assignment->shift_id,
            'staff_id' => $assignment->staff_id,
            'assigned_by_user_id' => $assignment->assigned_by_user_id,
            'assignment_status' => $assignment->assignment_status,
            'unscheduled' => true,
            'added_at' => $assignment->created_at?->toIso8601String(),
            /*
             * Null for an addition made at a connected desk, and the device's
             * key for one that came out of a queue — which is what tells a
             * reviewer, months later, that the timestamp above is when an
             * operator recorded it rather than when the node heard about it.
             */
            'operation_uuid' => $assignment->unscheduled_operation_uuid,
            'overlap_warning_count' => count($warnings),
        ];
    }
}
