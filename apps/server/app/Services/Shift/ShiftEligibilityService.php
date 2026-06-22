<?php

namespace App\Services\Shift;

use App\Models\DepartmentMembership;
use App\Models\Shift;
use App\Models\Staff;
use App\Services\Status\StaffStatusService;
use Illuminate\Support\Carbon;

/**
 * Shared shift assignment eligibility checks (TRAIN-008, WAIVER-005, SHIFT-012, SHIFT-016).
 *
 * Self-signup, lead assignment, and unscheduled additions reuse these checks from their
 * respective command surfaces.
 */
class ShiftEligibilityService
{
    public function __construct(private readonly StaffStatusService $staffStatus) {}

    /**
     * @throws ShiftSignupException
     */
    public function assertMeetsAssignmentRequirements(
        Shift $shift,
        Staff $staff,
        DepartmentMembership $departmentMembership,
        ?Carbon $moment = null,
    ): void {
        $moment ??= Carbon::now();

        if ($this->staffStatus->effectiveDepartmentStatus($departmentMembership) === DepartmentMembership::STATUS_INELIGIBLE) {
            throw ShiftSignupException::departmentIneligible();
        }

        $shift->loadMissing(['requiredTrainings', 'requiredWaivers']);

        foreach ($shift->requiredTrainings as $training) {
            if (! $training->isCompleteFor($staff, $moment)) {
                throw ShiftSignupException::missingRequiredTraining();
            }
        }

        foreach ($shift->requiredWaivers as $waiver) {
            if (! $waiver->isCompleteFor($staff, $moment)) {
                throw ShiftSignupException::missingRequiredWaiver();
            }
        }
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
