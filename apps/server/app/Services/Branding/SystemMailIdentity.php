<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Models\Organization;

/**
 * The identity a system-generated email signs itself with (M15A.8; BRAND-002,
 * BRAND-003).
 *
 * BRAND-002 lists system email among the surfaces organization identity
 * replaces Meridian on, and BRAND-003 keeps Meridian's identity on login and
 * the magic-link landing. Those two requirements meet in the middle of the one
 * email Alpha 1 currently sends, so the boundary is drawn here explicitly
 * rather than left to whoever writes the next mailable:
 *
 *   - An email sent **about an organization's operations** — to someone whose
 *     membership is already known — carries that organization's name. That is
 *     {@see forOrganization()}.
 *   - An email sent **to an address, before anyone is signed in** carries
 *     Meridian's. That is {@see meridian()}, and it is what the magic-link
 *     login email uses. Two reasons, and either alone would be sufficient: the
 *     login email is part of the login flow BRAND-003 protects, and at the
 *     moment it is sent the recipient may have no account at all, so there is
 *     no organization whose identity would be truthful to use.
 *
 * A mailable states which of the two it is. Guessing from the recipient's
 * memberships would send a differently branded email to a staff member who
 * happens to belong to two organizations, which is worse than being plainly
 * Meridian.
 */
final class SystemMailIdentity
{
    private function __construct(
        public readonly string $displayName,
        public readonly bool $isOrganizationIdentity,
    ) {}

    public static function meridian(): self
    {
        return new self('Meridian', false);
    }

    public static function forOrganization(Organization $organization): self
    {
        $profile = BrandingProfile::forOrganization($organization);

        // An organization with no branding profile is not asking to be
        // presented as itself yet, so the email stays Meridian rather than
        // suddenly using a legal entity name staff may not recognize.
        return $profile->isBranded
            ? new self($profile->identityName(), true)
            : self::meridian();
    }

    /** The subject-line product name. */
    public function subjectFor(string $subject): string
    {
        return sprintf('%s %s', $this->displayName, $subject);
    }
}
