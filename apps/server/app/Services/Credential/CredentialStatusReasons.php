<?php

namespace App\Services\Credential;

/**
 * Human labels for the recorded credential status reasons.
 *
 * The reasons themselves are domain constants written into
 * `event_credentials.status_reason`, and two places outside the domain have to
 * render them: the credential eligibility export (M13.1), which is read by an
 * operator with no copy of the constants, and the credential administration
 * surface (M18.5), where somebody is deciding whether to revoke. They were the
 * export's private business until the surface arrived and needed the same
 * sentences; a second copy would have been a second vocabulary, and the two
 * would have drifted the first time a reason was added.
 */
final class CredentialStatusReasons
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        CredentialEligibilityService::REASON_NO_SIGNED_UP_SHIFTS => 'No signed-up shifts',
        CredentialEligibilityService::REASON_ORGANIZATION_BLOCKING_STATUS => 'Organization blocking status',
        CredentialEligibilityService::REASON_DEPARTMENT_INELIGIBLE => 'Department Ineligible status',
        CredentialEligibilityService::REASON_MISSING_REQUIRED_WAIVER => 'Missing required waiver',
        CredentialEligibilityService::REASON_AGE_REQUIREMENT_NOT_SATISFIED => 'Age requirement not satisfied',
        CredentialEligibilityService::REASON_MISSING_DATE_OF_BIRTH => 'Missing date of birth',
        CredentialRevocationService::REASON_MANUAL_REVOCATION => 'Manual revocation',
    ];

    /**
     * The label for a recorded reason, or null when there is no reason to label
     * and null when the reason is one nothing here knows — an unrecognized code
     * is reported as absent rather than echoed back as though it were English.
     */
    public static function label(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        return self::LABELS[$reason] ?? null;
    }
}
