<?php

namespace App\Services\Shift;

use App\Domain\Modules\ModuleKey;
use App\Models\DepartmentMembership;
use App\Models\Shift;
use App\Models\Staff;
use App\Services\Modules\ActiveModuleResolver;
use App\Services\Status\StaffStatusService;
use Illuminate\Support\Carbon;

/**
 * Shared shift assignment eligibility checks (TRAIN-008, WAIVER-005, SHIFT-012, SHIFT-016).
 *
 * Self-signup, lead assignment, and unscheduled additions reuse these checks from their
 * respective command surfaces.
 *
 * Two of the three checks are owned by modules the organization may not run
 * (MOD-018, technical spec 15A.7). A shift's training requirements belong to
 * Qualifications and its waiver requirements to Documents, and where the owning
 * module is inactive the requirement evaluates as *satisfied* rather than as
 * blocking: a module's absence is never an error condition in another module,
 * and refusing a signup for a training nobody in the organization can complete
 * would make Scheduling unusable on its own. The requirement rows are read past,
 * never deleted, so activating the module restores the gate exactly as it stood.
 */
class ShiftEligibilityService
{
    public function __construct(
        private readonly StaffStatusService $staffStatus,
        private readonly ActiveModuleResolver $modules,
    ) {}

    /**
     * @throws ShiftSignupException
     */
    public function assertMeetsAssignmentRequirements(
        Shift $shift,
        Staff $staff,
        DepartmentMembership $departmentMembership,
        ?Carbon $moment = null,
    ): void {
        $failures = $this->assignmentRequirementFailures($shift, $staff, $departmentMembership, $moment);

        if ($failures !== []) {
            throw $failures[0];
        }
    }

    /**
     * Every assignment requirement this staff member does not meet, in check
     * order (M18.55).
     *
     * The same rules the assertion above enforces, reported rather than thrown.
     * `UnscheduledShiftAdditionService` needs the whole list because M18.55 lets
     * an authority waive exactly one named refusal: asked as an assertion, a
     * waived missing training would hide a missing waiver behind it, and the
     * override would apply an addition for somebody with no waiver on record.
     * The assertion is written in terms of this rather than beside it, so the
     * two answers cannot drift.
     *
     * @return list<ShiftSignupException>
     */
    public function assignmentRequirementFailures(
        Shift $shift,
        Staff $staff,
        DepartmentMembership $departmentMembership,
        ?Carbon $moment = null,
    ): array {
        $moment ??= Carbon::now();
        $failures = [];

        if ($this->staffStatus->effectiveDepartmentStatus($departmentMembership) === DepartmentMembership::STATUS_INELIGIBLE) {
            $failures[] = ShiftSignupException::departmentIneligible();
        }

        $shift->loadMissing(['requiredTrainings', 'requiredWaivers', 'department']);
        // A shift belongs to exactly one department and a department to one
        // organization (technical spec 20.5), so this resolves. An id that does
        // not resolve reads as an organization with no stored module rows, which
        // is every module active — the gate stays rather than silently lifting.
        $organizationId = (string) ($shift->department?->organization_id ?? '');

        if ($this->modules->isActive($organizationId, ModuleKey::Qualifications)) {
            foreach ($shift->requiredTrainings as $training) {
                if (! $training->isCompleteFor($staff, $moment)) {
                    $failures[] = ShiftSignupException::missingRequiredTraining();

                    // One entry per kind, not one per record. The refusal names what
                    // is missing, and a staff member short three trainings is short
                    // training — a caller reading the list is choosing what to do
                    // about a category, not counting rows.
                    break;
                }
            }
        }

        if ($this->modules->isActive($organizationId, ModuleKey::Documents)) {
            foreach ($shift->requiredWaivers as $waiver) {
                if (! $waiver->isCompleteFor($staff, $moment)) {
                    $failures[] = ShiftSignupException::missingRequiredWaiver();

                    break;
                }
            }
        }

        return $failures;
    }

    /**
     * @throws ShiftSignupException
     */
    public function assertCapacityForSelfSignup(Shift $shift): void
    {
        if ($shift->isAtCapacity()) {
            throw ShiftSignupException::shiftFull();
        }
    }
}
