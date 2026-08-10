<?php

namespace App\Services\Shift;

use App\Domain\Commands\ShiftAdditionRefusalReason;

/**
 * What an override of a refused shift addition is, as one value (M18.55;
 * CLIENT-017A).
 *
 * The two fields travel together everywhere and mean nothing apart: a reason
 * with no refused command behind it is an assertion about nobody's decision, and
 * a refused command with no reason names something without saying what about it
 * was wrong. Passing them as one parameter is also what lets the addition path
 * and the override path share a body — `null` is "this is an ordinary addition"
 * and there is no half-set state to check for.
 */
final readonly class ShiftAdditionOverride
{
    public function __construct(
        /** The one reason being waived; every other rule still applies. */
        public ShiftAdditionRefusalReason $reason,
        /**
         * The refused command's idempotency key. The only identifier the
         * refusal has: the node wrote no row for a command it refused.
         */
        public string $overriddenOperationUuid,
    ) {}
}
