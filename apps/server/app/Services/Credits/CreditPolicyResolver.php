<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Models\CreditPolicy;
use App\Models\Shift;

/**
 * Decides which credit policy governs a shift (CREDIT-002, CREDIT-003;
 * ORG-009, ORG-010, SHIFT-010).
 *
 * There are exactly two candidates, in order: the policy the shift names, then
 * the organization default. ORG-010 rules out a department default, so no third
 * step exists and a department cannot quietly reprice its own work.
 *
 * Archived policies still resolve. Archiving withdraws a policy from future
 * selection; it does not restate what a shift was worked under. A shift that
 * still points at an archived policy is credited at that policy's rate rather
 * than silently falling through to the organization default, because falling
 * through would change the price of work already done.
 */
final class CreditPolicyResolver
{
    public function resolve(Shift $shift): ?CreditPolicyResolution
    {
        $shiftPolicy = $this->shiftPolicy($shift);

        if ($shiftPolicy !== null) {
            return new CreditPolicyResolution($shiftPolicy, CreditPolicyResolution::SOURCE_SHIFT);
        }

        $organizationDefault = $this->organizationDefaultPolicy($shift);

        if ($organizationDefault !== null) {
            return new CreditPolicyResolution($organizationDefault, CreditPolicyResolution::SOURCE_ORGANIZATION);
        }

        // An organization that has configured no policy at all is not running
        // credits. The caller reports the shortfall rather than inventing a
        // rate: a guessed multiplier would be indistinguishable from a
        // configured one once it is frozen into the ledger.
        return null;
    }

    private function shiftPolicy(Shift $shift): ?CreditPolicy
    {
        if ($shift->credit_policy_id === null) {
            return null;
        }

        return $shift->relationLoaded('creditPolicy')
            ? $shift->creditPolicy
            : CreditPolicy::query()->find($shift->credit_policy_id);
    }

    private function organizationDefaultPolicy(Shift $shift): ?CreditPolicy
    {
        $organization = $shift->event?->organization;

        if ($organization?->default_credit_policy_id === null) {
            return null;
        }

        return CreditPolicy::query()->find($organization->default_credit_policy_id);
    }
}
