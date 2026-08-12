<?php

declare(strict_types=1);

namespace App\Services\Modules;

use RuntimeException;

/**
 * A module state change attempted on a node that does not hold authority over
 * it (MOD-010; data/API 10.1A).
 *
 * Module state is organization governance data and the central node owns it.
 * An on-site node refuses the change outright rather than queueing an
 * operation, because event authority moves *event-scoped* records to on-site
 * during the window and module state is not event-scoped: an on-site change
 * would be a second source of truth for a record central pushes down again.
 */
class ModuleAuthorityException extends RuntimeException
{
    public static function notCentral(string $nodeRole, string $organizationName): self
    {
        return new self(sprintf(
            'Module state is organization governance data and is changed on the central node. '
            .'This node is running as "%s", so it cannot change what %s is entitled to. '
            .'Make the change on central; it syncs down from there.',
            $nodeRole === '' ? 'unknown' : $nodeRole,
            $organizationName === '' ? 'this organization' : sprintf('"%s"', $organizationName),
        ));
    }
}
