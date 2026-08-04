<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Staff;
use App\Models\User;

/**
 * Who a notification is addressed to, and how the address was arrived at
 * (NOTIFY-005).
 *
 * Two shapes only: a user account with a verified primary email address, and
 * an application email address for somebody who has no account yet. There is
 * deliberately no third shape reading an address off a staff record. A staff
 * record's email is whatever was typed into the form that created it, and
 * NOTIFY-005 is explicit that an unverified address does not receive
 * notification email — a rule with an exception for "the address we happen to
 * hold" would be no rule.
 */
final class NotificationRecipient
{
    private function __construct(
        public readonly string $email,
        public readonly string $name,
        public readonly ?User $user,
        public readonly ?Staff $staff,
    ) {}

    public static function forUser(User $user, ?Staff $staff = null): self
    {
        return new self(
            email: (string) $user->email,
            name: trim((string) $user->name) !== '' ? (string) $user->name : (string) $user->email,
            user: $user,
            staff: $staff,
        );
    }

    /**
     * An applicant with no user account, addressed at the address they applied
     * with (NOTIFY-005).
     */
    public static function forApplicationEmail(string $email, string $name, ?Staff $staff = null): self
    {
        return new self(
            email: $email,
            name: trim($name) !== '' ? $name : $email,
            user: null,
            staff: $staff,
        );
    }
}
