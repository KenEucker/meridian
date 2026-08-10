<?php

namespace App\Services\Shift;

use App\Domain\Commands\ShiftAdditionRefusalReason;
use RuntimeException;

/**
 * A refusal of the Logistics unscheduled addition, carrying both halves of what
 * a refusal is (M18.55; CLIENT-017, CLIENT-017A).
 *
 * The sentence is what a person reads and has been since M16.21. The reason code
 * is what a rule is written about, and it is what M18.55 needed: an override is
 * allowed for some refusals and not for others, and deciding that by matching on
 * message text would be a rule that breaks the day somebody rewords a sentence.
 */
class UnscheduledShiftAdditionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?ShiftAdditionRefusalReason $reason = null,
    ) {
        parent::__construct($message);
    }

    /** The code the client holds against the override allowlist, if any. */
    public function reasonCode(): ?string
    {
        return $this->reason?->value;
    }

    public static function unauthorized(): self
    {
        return new self(
            'You are not authorized to add unscheduled staff to this shift.',
            ShiftAdditionRefusalReason::Unauthorized,
        );
    }

    public static function cancelledShift(): self
    {
        return new self(
            'Cancelled shifts do not accept unscheduled staff additions.',
            ShiftAdditionRefusalReason::CancelledShift,
        );
    }

    public static function shiftNotStarted(): self
    {
        return new self(
            'Unscheduled staff can only be added after shift operations have started.',
            ShiftAdditionRefusalReason::ShiftNotStarted,
        );
    }

    public static function notEligibleTeamMember(): self
    {
        return new self(
            'Staff must belong to the shift eligible team before unscheduled shift addition.',
            ShiftAdditionRefusalReason::NotEligibleTeamMember,
        );
    }

    public static function noDepartmentMembership(): self
    {
        return new self(
            'Staff must belong to the shift department before unscheduled shift addition.',
            ShiftAdditionRefusalReason::NoDepartmentMembership,
        );
    }

    public static function doNotStaff(): self
    {
        return new self(
            'Do Not Staff records cannot be added to shifts.',
            ShiftAdditionRefusalReason::DoNotStaff,
        );
    }

    public static function departmentIneligible(): self
    {
        return new self(
            'Ineligible department status prevents unscheduled shift addition.',
            ShiftAdditionRefusalReason::DepartmentIneligible,
        );
    }

    public static function missingRequiredTraining(): self
    {
        return new self(
            'Required training must be complete before unscheduled shift addition.',
            ShiftAdditionRefusalReason::MissingRequiredTraining,
        );
    }

    public static function missingRequiredWaiver(): self
    {
        return new self(
            'Required waiver must be complete before unscheduled shift addition.',
            ShiftAdditionRefusalReason::MissingRequiredWaiver,
        );
    }

    public static function alreadyAssigned(): self
    {
        return new self(
            'This staff member is already assigned to the shift.',
            ShiftAdditionRefusalReason::AlreadyAssigned,
        );
    }

    public static function staffNotOnSite(): self
    {
        return new self(
            'Staff must be marked on-site with this department before unscheduled shift addition.',
            ShiftAdditionRefusalReason::StaffNotOnSite,
        );
    }

    /**
     * The override was asked for on a refusal that is not on the allowlist.
     *
     * Refused whatever the caller holds, which is what "not overridable at any
     * authority" means for `do_not_staff` and the two beside it. Named as its
     * own refusal rather than reported as `unauthorized`, because the caller's
     * authority is not what was wrong.
     */
    public static function reasonNotOverridable(ShiftAdditionRefusalReason $reason): self
    {
        return new self(sprintf(
            'A refusal for `%s` cannot be overridden. It is not a decision this desk makes.',
            $reason->value,
        ));
    }

    /** The caller does not hold `department.shift_additions.override`. */
    public static function overrideUnauthorized(): self
    {
        return new self(
            'You are not authorized to override a refused shift addition for this department.',
            ShiftAdditionRefusalReason::Unauthorized,
        );
    }

    /**
     * The override named a reason the addition would not have been refused for.
     *
     * A caller overriding `staff_not_on_site` for somebody who is on-site and
     * has no required training is overriding nothing; the addition is made by
     * the ordinary path instead, and answering that here rather than silently
     * accepting keeps an override entry in the audit trail meaning what it says.
     */
    public static function nothingToOverride(ShiftAdditionRefusalReason $reason): self
    {
        return new self(sprintf(
            'This addition is not refused for `%s`, so there is nothing to override.',
            $reason->value,
        ));
    }
}
