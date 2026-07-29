<?php

namespace App\Services\Auth;

use App\Models\Device;

/**
 * A device identity a client has offered, checked but not yet written
 * (AUTH-021).
 *
 * This exists so the two halves of resolving a device can happen either side of
 * the login code being spent: the request is checked for a usable device before
 * the code is redeemed, so a correctable device refusal does not cost the person
 * their single-use code, and the `devices` row is written only after the code
 * proved good, so a failed login cannot register hardware.
 */
final class DeviceIdentity
{
    public function __construct(
        public readonly string $id,
        public readonly ?Device $existing = null,
        public readonly ?string $label = null,
        public readonly ?string $platform = null,
        public readonly ?string $publicKey = null,
    ) {}

    public function isRegistered(): bool
    {
        return $this->existing instanceof Device;
    }
}
