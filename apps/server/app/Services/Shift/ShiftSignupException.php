<?php

namespace App\Services\Shift;

use RuntimeException;

/**
 * Why a shift will not accept this staff member (requirements 3.12).
 *
 * Each refusal carries a stable reason code alongside its sentence. The sentence
 * is what a person reads; the code is what the shift board renders a row's state
 * from, so a surface can group, sort, or badge a denial without matching on
 * prose. Both come from the same factory, which is what keeps the reason a shift
 * board shows and the reason signup refuses with the same reason (M18.2).
 */
class ShiftSignupException extends RuntimeException
{
    public const REASON_CANCELLED_SHIFT = 'cancelled_shift';

    public const REASON_SIGNUP_CLOSED = 'signup_closed';

    public const REASON_SCHEDULE_LOCKED = 'schedule_locked';

    public const REASON_NOT_ELIGIBLE_TEAM_MEMBER = 'not_eligible_team_member';

    public const REASON_NO_DEPARTMENT_MEMBERSHIP = 'no_department_membership';

    public const REASON_DO_NOT_STAFF = 'do_not_staff';

    public const REASON_ALREADY_SIGNED_UP = 'already_signed_up';

    public const REASON_STAFF_NOT_LINKED_TO_USER = 'staff_not_linked_to_user';

    public const REASON_DEPARTMENT_INELIGIBLE = 'department_ineligible';

    public const REASON_MISSING_REQUIRED_TRAINING = 'missing_required_training';

    public const REASON_MISSING_REQUIRED_WAIVER = 'missing_required_waiver';

    public const REASON_SHIFT_FULL = 'shift_full';

    public function __construct(
        string $message,
        public readonly string $reasonCode,
    ) {
        parent::__construct($message);
    }

    public static function cancelledShift(): self
    {
        return new self('Cancelled shifts do not accept signup.', self::REASON_CANCELLED_SHIFT);
    }

    public static function signupClosed(): self
    {
        return new self('Shift signup is not currently open.', self::REASON_SIGNUP_CLOSED);
    }

    public static function scheduleLocked(): self
    {
        return new self('The schedule is locked and cannot be changed.', self::REASON_SCHEDULE_LOCKED);
    }

    public static function notEligibleTeamMember(): self
    {
        return new self(
            'Staff must belong to the shift eligible team before signup.',
            self::REASON_NOT_ELIGIBLE_TEAM_MEMBER,
        );
    }

    public static function noDepartmentMembership(): self
    {
        return new self(
            'Staff must belong to the shift department before signup.',
            self::REASON_NO_DEPARTMENT_MEMBERSHIP,
        );
    }

    public static function doNotStaff(): self
    {
        return new self('Do Not Staff records cannot sign up for shifts.', self::REASON_DO_NOT_STAFF);
    }

    public static function alreadySignedUp(): self
    {
        return new self(
            'This staff member is already signed up for the shift.',
            self::REASON_ALREADY_SIGNED_UP,
        );
    }

    public static function staffNotLinkedToUser(): self
    {
        return new self(
            'The staff profile must belong to the signing-up user.',
            self::REASON_STAFF_NOT_LINKED_TO_USER,
        );
    }

    public static function departmentIneligible(): self
    {
        return new self(
            'Ineligible department status prevents shift signup.',
            self::REASON_DEPARTMENT_INELIGIBLE,
        );
    }

    public static function missingRequiredTraining(): self
    {
        return new self(
            'Required training must be complete before shift signup.',
            self::REASON_MISSING_REQUIRED_TRAINING,
        );
    }

    public static function missingRequiredWaiver(): self
    {
        return new self(
            'Required waiver must be complete before shift signup.',
            self::REASON_MISSING_REQUIRED_WAIVER,
        );
    }

    public static function shiftFull(): self
    {
        return new self(
            'This shift is full and cannot accept additional signup.',
            self::REASON_SHIFT_FULL,
        );
    }
}
