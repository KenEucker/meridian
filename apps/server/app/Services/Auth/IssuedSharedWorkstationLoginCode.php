<?php

namespace App\Services\Auth;

use App\Models\SharedWorkstationLoginCode;

/**
 * A freshly generated shared-workstation login code and its plaintext value
 * (AUTH-026; technical spec 13.2; data/API 12.4).
 *
 * The plaintext exists for the length of the request that generated it: it is
 * shown once, on the device that asked for it, and is never stored, logged,
 * audited, mailed, or retrievable again. Only the keyed hash on
 * {@see SharedWorkstationLoginCode::$code_hash} survives the request, which is
 * also why a code cannot be printed as an event prep sheet after the fact
 * (technical spec 13.2).
 */
final class IssuedSharedWorkstationLoginCode
{
    public function __construct(
        public readonly SharedWorkstationLoginCode $record,
        public readonly string $plaintextCode,
    ) {}

    /** The code as the person generating it is shown it. */
    public function formattedCode(): string
    {
        return SharedWorkstationLoginCodeGenerator::format($this->plaintextCode);
    }
}
