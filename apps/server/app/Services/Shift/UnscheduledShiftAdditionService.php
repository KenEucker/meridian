<?php

namespace App\Services\Shift;

use App\Domain\Commands\ShiftAdditionRefusalReason;
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
 *
 * **A refusal has a second possible outcome since M18.55** (CLIENT-017A). Until
 * then the only thing a person could do with one was read it and dismiss it,
 * and the person reading it is often the one holding the authority to decide
 * the node's answer was wrong for the situation. `overrideRefusedAddition` is
 * that decision, and three properties keep it from being a way around the rules
 * above rather than a recorded exception to one of them:
 *
 *  1. **It waives exactly one named reason**, and only a reason
 *     `ShiftAdditionRefusalReason` lists as overridable. Everything else is
 *     checked as it always was, so an addition overridden past a missing
 *     training and still lacking a waiver comes back refused for the waiver.
 *  2. **It needs an authority the addition itself does not.** The caller holds
 *     the ordinary attendance authority *and* `department.shift_additions.override`,
 *     which the Logistics role that issues additions does not carry.
 *  3. **It is a distinct command that names the refused one**, so the
 *     assignment and its audit entry hold both facts rather than one.
 */
class UnscheduledShiftAdditionService
{
    public function __construct(
        private readonly AttendanceCheckInAccess $access,
        private readonly AuditService $audit,
        private readonly ShiftEligibilityService $eligibility,
        private readonly ShiftOverlapService $overlaps,
        private readonly CredentialEligibilityService $credentials,
        private readonly ShiftAdditionOverrideAccess $overrideAccess,
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
        return $this->apply($shift, $staff, $actor, $moment, $operationUuid, $recordedAt, null);
    }

    /**
     * Add the staff member despite one refusal, on a named person's authority
     * (M18.55; CLIENT-017A; technical spec 11A.5).
     *
     * A distinct command rather than a retry of the refused one, which is the
     * whole design: the refused command's key travels in as
     * `$overriddenOperationUuid` and is written onto the assignment beside the
     * reason it waived, so months later the record says the node refused this
     * and a named person then chose to proceed. Re-sending the original under
     * its own key would have produced an assignment that reads as though the
     * refusal never happened.
     *
     * Refused three ways, and each names what was actually wrong. A reason the
     * specification does not list as overridable is refused at every authority,
     * `do_not_staff` first among them — an organization's exclusion decision is
     * not a desk's to reverse. A caller without
     * `department.shift_additions.override` for the shift's department is
     * refused as unauthorized, and holding the attendance authority alone is not
     * enough, because the role that issues additions is not the role that
     * overrides their refusals. And an override of a reason this addition would
     * not have been refused for is refused as having nothing to override, so an
     * override entry in the audit trail always means one actually happened.
     *
     * @param  ShiftAdditionRefusalReason  $overriddenReason  The one reason
     *                                                        being waived.
     *                                                        Every other rule
     *                                                        is checked exactly
     *                                                        as it always was.
     * @param  string  $overriddenOperationUuid  The refused command's own
     *                                           idempotency key, which is the
     *                                           only identifier a refusal ever
     *                                           had: the node wrote no row for
     *                                           it, because it refused it.
     *
     * @throws UnscheduledShiftAdditionException
     */
    public function overrideRefusedAddition(
        Shift $shift,
        Staff $staff,
        User $actor,
        ShiftAdditionRefusalReason $overriddenReason,
        string $overriddenOperationUuid,
        ?Carbon $moment = null,
        ?string $operationUuid = null,
        ?Carbon $recordedAt = null,
    ): ShiftAssignmentOutcome {
        return $this->apply(
            $shift,
            $staff,
            $actor,
            $moment,
            $operationUuid,
            $recordedAt,
            new ShiftAdditionOverride($overriddenReason, $overriddenOperationUuid),
        );
    }

    /**
     * @throws UnscheduledShiftAdditionException
     */
    private function apply(
        Shift $shift,
        Staff $staff,
        User $actor,
        ?Carbon $moment,
        ?string $operationUuid,
        ?Carbon $recordedAt,
        ?ShiftAdditionOverride $override,
    ): ShiftAssignmentOutcome {
        $moment ??= Carbon::now();
        $recordedAt ??= $moment;

        return DB::transaction(function () use ($shift, $staff, $actor, $moment, $operationUuid, $recordedAt, $override): ShiftAssignmentOutcome {
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

            if ($override !== null) {
                $this->assertOverrideAllowed($shift, $actor, $override);
            }

            if ($shift->isCancelled()) {
                throw UnscheduledShiftAdditionException::cancelledShift();
            }

            if ($moment->lt($shift->starts_at)) {
                throw UnscheduledShiftAdditionException::shiftNotStarted();
            }

            $this->assertStaffEligibleForUnscheduledAddition($shift, $staff, $moment, $override?->reason);

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
                'override_of_operation_uuid' => $override?->overriddenOperationUuid,
                'overridden_reason_code' => $override?->reason->value,
            ]);
            $assignment->created_at = $recordedAt;
            $assignment->updated_at = $recordedAt;
            $assignment->save();

            $this->audit->recordForEntity(
                entity: $assignment,
                /*
                 * A different action for an override, rather than the same one
                 * with a field set (requirements 2.4). An audit trail is read by
                 * filtering it, and "show me the additions somebody overrode a
                 * refusal to make" is the question this whole path exists to be
                 * answerable.
                 */
                action: $override === null
                    ? 'shift_assignment.unscheduled_added'
                    : 'shift_assignment.unscheduled_added_by_override',
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
     * Every eligibility rule, with at most one of them waived.
     *
     * Written as a list of refusals rather than as a sequence of throws so the
     * override path can leave exactly one out and still be refused by the rest
     * (M18.55). Before that this method threw at the first problem and there was
     * no way to ask "what else is wrong" without asking it twice; the ordinary
     * path is unchanged, because throwing the first of a list is what it always
     * did.
     *
     * @param  ShiftAdditionRefusalReason|null  $waived  The reason an override
     *                                                   has authority to set
     *                                                   aside. Null on the
     *                                                   ordinary path, which
     *                                                   waives nothing.
     *
     * @throws UnscheduledShiftAdditionException
     */
    private function assertStaffEligibleForUnscheduledAddition(
        Shift $shift,
        Staff $staff,
        Carbon $moment,
        ?ShiftAdditionRefusalReason $waived = null,
    ): void {
        $refusals = $this->eligibilityRefusals($shift, $staff, $moment);

        if ($waived !== null && ! $this->refusalsInclude($refusals, $waived)) {
            /*
             * The override named a reason this addition is not refused for. It
             * is refused rather than quietly applied as an ordinary addition,
             * because an override entry that recorded a waiver of a rule that
             * was never in the way would make the audit trail a worse record
             * than no override path at all.
             */
            throw UnscheduledShiftAdditionException::nothingToOverride($waived);
        }

        foreach ($refusals as $refusal) {
            if ($waived === null || $refusal->reason !== $waived) {
                throw $refusal;
            }
        }
    }

    /**
     * The refusals this addition currently earns, in check order.
     *
     * @return list<UnscheduledShiftAdditionException>
     */
    private function eligibilityRefusals(Shift $shift, Staff $staff, Carbon $moment): array
    {
        $refusals = [];
        $organizationId = $shift->event?->organization_id;

        if ($organizationId !== null) {
            $organizationStatus = StaffOrganizationStatus::query()
                ->where('organization_id', $organizationId)
                ->where('staff_id', $staff->id)
                ->first();

            if ($organizationStatus?->status === StaffOrganizationStatus::STATUS_DO_NOT_STAFF) {
                $refusals[] = UnscheduledShiftAdditionException::doNotStaff();
            }
        }

        $departmentMembership = DepartmentMembership::query()
            ->active()
            ->where('department_id', $shift->department_id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($departmentMembership === null) {
            /*
             * The end of the road rather than one more entry on the list. Every
             * rule below is asked *of the membership* — the requirements it
             * carries, the teams it reaches — so there is nothing further to
             * evaluate, and a membership is not a refusal an override may waive
             * in any case.
             */
            $refusals[] = UnscheduledShiftAdditionException::noDepartmentMembership();

            return $refusals;
        }

        $isOnSite = EventDepartmentPresence::query()
            ->where('event_id', $shift->event_id)
            ->where('department_id', $shift->department_id)
            ->where('staff_id', $staff->id)
            ->where('current_state', EventDepartmentPresence::STATE_ON_SITE)
            ->exists();

        if (! $isOnSite) {
            $refusals[] = UnscheduledShiftAdditionException::staffNotOnSite();
        }

        /*
         * The whole list rather than the first of it, because an override waives
         * one reason and the rest still have to refuse. Asked as an assertion,
         * a waived missing training would hide a missing waiver behind it.
         */
        foreach ($this->eligibility->assignmentRequirementFailures($shift, $staff, $departmentMembership, $moment) as $failure) {
            $refusals[] = $this->unscheduledExceptionFor($failure);
        }

        // The same rule the Logistics Desk decides whether to offer the addition
        // by, asked from one place so the two cannot drift (SLB-008).
        $hasEligibleTeamMembership = TeamMembership::query()
            ->onEligibleShiftTeam($departmentMembership)
            ->where('team_id', $shift->eligible_team_id)
            ->where('staff_id', $staff->id)
            ->exists();

        if (! $hasEligibleTeamMembership) {
            $refusals[] = UnscheduledShiftAdditionException::notEligibleTeamMember();
        }

        return $refusals;
    }

    /**
     * @param  list<UnscheduledShiftAdditionException>  $refusals
     */
    private function refusalsInclude(array $refusals, ShiftAdditionRefusalReason $reason): bool
    {
        foreach ($refusals as $refusal) {
            if ($refusal->reason === $reason) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this override may be made at all, before anything about the staff
     * member is weighed.
     *
     * Two questions in a fixed order, and the order is deliberate. The
     * allowlist is asked first, so a caller attempting to override
     * `do_not_staff` is told that reason is not overridable rather than that
     * they lack authority — the second would be a true sentence that implies a
     * false one, namely that somebody more senior could.
     *
     * @throws UnscheduledShiftAdditionException
     */
    private function assertOverrideAllowed(Shift $shift, User $actor, ShiftAdditionOverride $override): void
    {
        if (! $override->reason->isOverridable()) {
            throw UnscheduledShiftAdditionException::reasonNotOverridable($override->reason);
        }

        $event = $shift->event;
        $department = $shift->department;

        if ($event === null || $department === null) {
            throw UnscheduledShiftAdditionException::overrideUnauthorized();
        }

        if (! $this->overrideAccess->canOverrideShiftAddition($actor, $event, $department)) {
            throw UnscheduledShiftAdditionException::overrideUnauthorized();
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
            /*
             * Both halves of an override, in the entry a reviewer reads
             * (requirements 2.4; CLIENT-017A). The actor and the moment are the
             * audit record's own; what it could not otherwise say is *what was
             * overridden* — the reason code the node refused on — and *which
             * command* the refusal belonged to. Null on an ordinary addition,
             * which overrode nothing.
             */
            'overridden_reason_code' => $assignment->overridden_reason_code,
            'override_of_operation_uuid' => $assignment->override_of_operation_uuid,
            'overlap_warning_count' => count($warnings),
        ];
    }
}
