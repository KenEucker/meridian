<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Models\CreditPolicy;

/**
 * A resolved credit policy and where it came from (CREDIT-002, CREDIT-003).
 *
 * The source travels with the policy because a ledger entry has to be able to
 * say why it used the rate it used: "the shift defined one" and "the shift
 * defined none, so the organization default applied" produce the same number
 * when the two policies happen to match, and only the source distinguishes
 * them for a reader auditing the calculation.
 */
final readonly class CreditPolicyResolution
{
    public const SOURCE_SHIFT = 'shift';

    public const SOURCE_ORGANIZATION = 'organization';

    public function __construct(
        public CreditPolicy $policy,
        public string $source,
    ) {}

    public function isShiftSpecific(): bool
    {
        return $this->source === self::SOURCE_SHIFT;
    }
}
