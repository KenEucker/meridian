<?php

declare(strict_types=1);

namespace App\Services\Branding;

use RuntimeException;

/**
 * A branding edit attempted on a node that does not hold branding authority
 * (BRAND-021).
 *
 * Separate from {@see BrandingValidationException} because the two say
 * different things to the person who hit them. A validation failure is "these
 * colors are wrong, fix them"; this is "these colors may be right, but not from
 * here" — the same submission would succeed on central.
 */
class BrandingAuthorityException extends RuntimeException
{
    public static function notCentral(string $nodeRole, string $recordDescription): self
    {
        return new self(sprintf(
            'Branding is organization governance data and is edited on the central node. '
            .'This node is running as "%s", so it cannot change %s. Make the change on central; '
            .'it syncs down from there.',
            $nodeRole === '' ? 'unknown' : $nodeRole,
            $recordDescription,
        ));
    }
}
