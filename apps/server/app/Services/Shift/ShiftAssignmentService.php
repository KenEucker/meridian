<?php

namespace App\Services\Shift;

use App\Models\AuditEvent;
use App\Models\DepartmentMembership;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Credential\CredentialEligibilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lead-driven shift assignment with elevated overlap authority (SHIFT-015).
 *
 * Two callers reach the same write: a department lead through
 * {@see self::assignStaffToShift}, and the God Mode CSV import through
 * {@see self::assignStaffToShiftFromImport}. They differ only in where their
 * authority comes from and whether the signup window applies; the eligibility
 * rules, the record written, the audit entry, and the credential recalculation
 * are one implementation, so an imported assignment cannot drift from a
 * lead's.
 */
class ShiftAssignmentService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ShiftAssignmentAccess $access,
        private readonly ShiftEligibilityService $eligibility,
        private readonly ShiftOverlapService $overlaps,
        private readonly CredentialEligibilityService $credentials,
    ) {}

    /**
     * @throws ShiftAssignmentException when assignment is not permitted
     */
    public function assignStaffToShift(
        Shift $shift,
        Staff $staff,
        User $assigner,
        ?Carbon $moment = null,
    ): ShiftAssignmentOutcome {
        if (! $this->access->canAssignToShift($assigner, $shift)) {
            throw ShiftAssignmentException::unauthorized();
        }

        return $this->assign($shift, $staff, $assigner, $moment, true, AuditEvent::SOURCE_API);
    }

    /**
     * Assignment from the God Mode CSV import (technical spec 22.2).
     *
     * Authority comes from the `platform.imports` console permission instead of
     * a department lead role, and the signup window is not a gate, because an
     * import records rostering decisions that were already made off-system —
     * usually after signup closed, which is exactly when a schedule arrives as
     * a spreadsheet.
     *
     * Nothing else is relaxed. Every rule that protects the person being
     * assigned still applies: Do Not Staff, department membership, eligible team
     * membership, department Ineligible status, and required trainings and
     * waivers. A file cannot put someone on a shift they are not allowed to
     * work, and the assignment is written, audited, and credential-recalculated
     * exactly like a lead's.
     *
     * @throws ShiftAssignmentException when assignment is not permitted
     */
    public function assignStaffToShiftFromImport(
        Shift $shift,
        Staff $staff,
        User $actor,
        ?Carbon $moment = null,
    ): ShiftAssignmentOutcome {
        if (! $actor->hasAccess('platform.imports')) {
            throw ShiftAssignmentException::unauthorized();
        }

        return $this->assign($shift, $staff, $actor, $moment, false, AuditEvent::SOURCE_ORCHID);
    }

    /**
     * @param  bool  $enforceSignupWindow  Whether a closed signup window refuses the assignment.
     *
     * @throws ShiftAssignmentException when assignment is not permitted
     */
    private function assign(
        Shift $shift,
        Staff $staff,
        User $assigner,
        ?Carbon $moment,
        bool $enforceSignupWindow,
        string $sourceContext,
    ): ShiftAssignmentOutcome {
        $moment ??= Carbon::now();

        return DB::transaction(function () use ($shift, $staff, $assigner, $moment, $enforceSignupWindow, $sourceContext): ShiftAssignmentOutcome {
            $shift = Shift::query()
                ->with(['event', 'department'])
                ->whereKey($shift->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertShiftAcceptsAssignment($shift, $moment, $enforceSignupWindow);
            $this->assertStaffEligibleForAssignment($shift, $staff, $moment);

            $existingAssignment = ShiftAssignment::query()
                ->where('shift_id', $shift->id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($existingAssignment !== null && $existingAssignment->removed_at === null) {
                throw ShiftAssignmentException::alreadyAssigned();
            }

            $warnings = $this->overlaps->warningsFor($staff, $shift);

            $assignment = ShiftAssignment::query()->create([
                'shift_id' => $shift->id,
                'staff_id' => $staff->id,
                'assigned_by_user_id' => $assigner->id,
                'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            ]);

            $this->audit->recordForEntity(
                entity: $assignment,
                action: 'shift_assignment.assigned',
                actorUser: $assigner,
                organizationId: $shift->event?->organization_id,
                eventId: $shift->event_id,
                departmentId: $shift->department_id,
                after: $this->auditSnapshot($assignment),
                sourceContext: $sourceContext,
            );

            $shift->loadMissing('event');
            $this->credentials->recalculate($shift->event, $staff, $moment, $assigner);

            return new ShiftAssignmentOutcome(
                assignment: $assignment->load(['shift', 'staff']),
                warnings: $warnings,
            );
        });
    }

    /**
     * @throws ShiftAssignmentException
     */
    private function assertShiftAcceptsAssignment(Shift $shift, Carbon $moment, bool $enforceSignupWindow): void
    {
        if ($shift->isCancelled()) {
            throw ShiftAssignmentException::cancelledShift();
        }

        if ($enforceSignupWindow && ! $shift->isSignupOpenAt($moment)) {
            throw ShiftAssignmentException::signupClosed();
        }
    }

    /**
     * @throws ShiftAssignmentException
     */
    private function assertStaffEligibleForAssignment(Shift $shift, Staff $staff, Carbon $moment): void
    {
        $organizationId = $shift->event?->organization_id;

        if ($organizationId !== null) {
            $organizationStatus = StaffOrganizationStatus::query()
                ->where('organization_id', $organizationId)
                ->where('staff_id', $staff->id)
                ->first();

            if ($organizationStatus?->status === StaffOrganizationStatus::STATUS_DO_NOT_STAFF) {
                throw ShiftAssignmentException::doNotStaff();
            }
        }

        $departmentMembership = DepartmentMembership::query()
            ->active()
            ->where('department_id', $shift->department_id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($departmentMembership === null) {
            throw ShiftAssignmentException::noDepartmentMembership();
        }

        $this->eligibility->assertMeetsAssignmentRequirements($shift, $staff, $departmentMembership, $moment);

        $hasEligibleTeamMembership = TeamMembership::query()
            ->active()
            ->where('team_id', $shift->eligible_team_id)
            ->where('staff_id', $staff->id)
            ->where('department_membership_id', $departmentMembership->id)
            ->exists();

        if (! $hasEligibleTeamMembership) {
            throw ShiftAssignmentException::notEligibleTeamMember();
        }
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
        ];
    }
}
