<?php

namespace App\Services\Shift;

use App\Models\Shift;
use App\Models\ShiftTrainingRequirement;
use App\Models\ShiftWaiverRequirement;
use App\Models\Training;
use App\Models\Waiver;
use Illuminate\Support\Carbon;

class ShiftRequirementService
{
    /**
     * Attach a required training to a shift (SHIFT-005).
     */
    public function addTrainingRequirement(Shift $shift, Training $training): ShiftTrainingRequirement
    {
        $this->assertSameOrganization($shift, (string) $training->organization_id);

        $alreadyExists = ShiftTrainingRequirement::query()
            ->where('shift_id', $shift->id)
            ->where('training_id', $training->id)
            ->exists();

        if ($alreadyExists) {
            throw ShiftRequirementException::duplicateTraining();
        }

        return ShiftTrainingRequirement::query()->create([
            'shift_id' => $shift->id,
            'training_id' => $training->id,
        ]);
    }

    /**
     * Attach a required waiver to a shift (SHIFT-006).
     */
    public function addWaiverRequirement(Shift $shift, Waiver $waiver): ShiftWaiverRequirement
    {
        $this->assertSameOrganization($shift, (string) $waiver->organization_id);

        $alreadyExists = ShiftWaiverRequirement::query()
            ->where('shift_id', $shift->id)
            ->where('waiver_id', $waiver->id)
            ->exists();

        if ($alreadyExists) {
            throw ShiftRequirementException::duplicateWaiver();
        }

        return ShiftWaiverRequirement::query()->create([
            'shift_id' => $shift->id,
            'waiver_id' => $waiver->id,
        ]);
    }

    /**
     * Configure signup availability dates for a shift (SHIFT-008).
     *
     * Either date may remain null to leave that side of the window open-ended.
     */
    public function setSignupWindow(
        Shift $shift,
        ?Carbon $opensAt = null,
        ?Carbon $closesAt = null,
    ): Shift {
        if ($opensAt !== null && $closesAt !== null && $closesAt->lessThanOrEqualTo($opensAt)) {
            throw ShiftRequirementException::invalidSignupWindow();
        }

        $shift->signup_opens_at = $opensAt;
        $shift->signup_closes_at = $closesAt;
        $shift->save();

        return $shift->refresh();
    }

    /**
     * Configure schedule lock/cutoff for a shift (SHIFT-009).
     *
     * A null lock clears the cutoff and leaves self-service schedule changes governed by other rules.
     */
    public function setScheduleLock(Shift $shift, ?Carbon $lockAt = null): Shift
    {
        $shift->schedule_lock_at = $lockAt;
        $shift->save();

        return $shift->refresh();
    }

    private function assertSameOrganization(Shift $shift, string $organizationId): void
    {
        $shift->loadMissing('event');

        if ((string) $shift->event->organization_id !== $organizationId) {
            throw ShiftRequirementException::differentOrganization();
        }
    }
}
