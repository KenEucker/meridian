<?php

namespace App\Services\Auth;

use App\Models\ApiLoginCode;

/**
 * A freshly issued API login code and its plaintext value.
 *
 * The plaintext exists only for the length of the request that mails it. It is
 * never returned to the caller of the request endpoint, because the whole point
 * of mailing it is that possession of the address is what is being proven.
 */
final class IssuedApiLoginCode
{
    public function __construct(
        public readonly ApiLoginCode $record,
        public readonly string $plaintextCode,
    ) {}

    /** The code as a person is shown it in their email. */
    public function formattedCode(): string
    {
        return ApiLoginCodeGenerator::format($this->plaintextCode);
    }
}
