<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\EventApplication;
use App\Models\Staff;
use App\Models\User;

/**
 * Resolves the address a notification may be sent to, or nothing (NOTIFY-005).
 *
 * "Or nothing" is the important half. Every caller in this milestone is a
 * domain operation that has already happened, so a recipient that cannot be
 * addressed must not be an error and must not be silently forgotten either: it
 * is recorded as a delivery that was considered and had nowhere to go, which is
 * what lets an operator answer NOTIFY-007's question for the staff member who
 * never verified their address.
 */
class NotificationRecipientResolver
{
    /**
     * The staff member's own verified primary address.
     *
     * A staff record may be linked to more than one user account — the same
     * person signing in with a password and with Google resolves to one user,
     * but a shared or migrated record may not have. The oldest verified account
     * wins, because it is the one the person has been reachable at longest, and
     * an arbitrary order would mail different accounts on different nodes.
     */
    public function forStaff(Staff $staff): ?NotificationRecipient
    {
        $user = $staff->users()
            ->whereNotNull('email_verified_at')
            ->whereNull('disabled_at')
            ->orderBy('email_verified_at')
            ->orderBy('id')
            ->first();

        if (! $user instanceof User) {
            return null;
        }

        return NotificationRecipient::forUser($user, $staff);
    }

    /**
     * The applicant, at their account address where they have one and at the
     * address they applied with where they do not (NOTIFY-005).
     *
     * An approved application has a staff record, and that staff record may
     * already be linked to a verified account — somebody who staffed last year
     * and applied again. Preferring the account is what keeps a person who has
     * since changed address from being mailed at the stale one they typed into
     * the form.
     */
    public function forApplication(EventApplication $application): ?NotificationRecipient
    {
        $application->loadMissing('staff');

        if ($application->staff instanceof Staff) {
            $accountRecipient = $this->forStaff($application->staff);

            if ($accountRecipient instanceof NotificationRecipient) {
                return $accountRecipient;
            }
        }

        $email = trim((string) $application->applicant_email);

        if ($email === '') {
            return null;
        }

        return NotificationRecipient::forApplicationEmail(
            email: $email,
            name: trim((string) $application->applicant_legal_name),
            staff: $application->staff,
        );
    }
}
