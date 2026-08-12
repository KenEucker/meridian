<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Domain\Modules\ModuleKey;
use RuntimeException;

/**
 * An organizer tried to change enablement for a module the platform has not
 * made available to the organization (MOD-008).
 *
 * The configuration surface offers only entitled modules, so this is not the
 * ordinary path: it is a stale form, a hand-written request, or an entitlement
 * revoked while somebody had the page open. It is refused rather than ignored
 * because the alternative is worse in both directions — silently accepting the
 * write would let an organizer erase the enabled choice MOD-007 exists to
 * preserve, and silently dropping it would report a save that did not happen.
 *
 * A submission that merely restates an unentitled module's current enablement
 * changes nothing and never reaches here, so the race above costs an organizer
 * a refusal only when they actually moved the control.
 */
class ModuleEnablementException extends RuntimeException
{
    public static function notEntitled(ModuleKey $module): self
    {
        return new self(sprintf(
            'Meridian does not make %s available to this organization, so it cannot be turned on or off here. '
            .'Whether a module is offered to an organization at all is a platform decision.',
            $module->label(),
        ));
    }
}
