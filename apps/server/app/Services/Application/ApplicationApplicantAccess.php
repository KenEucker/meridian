<?php

namespace App\Services\Application;

use App\Models\EventApplication;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Applicant identity checks for application withdrawal (APP-004).
 */
class ApplicationApplicantAccess
{
    public function canWithdrawApplication(
        ?User $user,
        EventApplication $application,
        ?string $sessionApplicationId = null,
    ): bool {
        if (! $application->isSubmitted()) {
            return false;
        }

        if ($sessionApplicationId !== null && $sessionApplicationId === $application->id) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $this->userMatchesApplicantEmail($user, $application->applicant_email);
    }

    public function userMatchesApplicantEmail(User $user, string $applicantEmail): bool
    {
        $normalizedApplicantEmail = Str::lower(trim($applicantEmail));
        $userEmail = Str::lower(trim((string) $user->email));

        if ($userEmail !== '' && $userEmail === $normalizedApplicantEmail) {
            return true;
        }

        return $user->staffProfiles()
            ->whereRaw('LOWER(email) = ?', [$normalizedApplicantEmail])
            ->exists();
    }
}
