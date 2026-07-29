<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * An API login attempt was refused (AUTH-019; data/API 5.4).
 *
 * Each instance carries a stable reason code so the web client, the mobile
 * Field application, and the desktop application can all explain the same
 * refusal without parsing prose.
 *
 * Messages describe the state of the attempt, never whether the address is
 * known to Meridian. Requesting a code answers the same way for every valid
 * address, so the request endpoint cannot be used to enumerate accounts.
 */
class ApiLoginException extends RuntimeException
{
    /** The submitted code is unknown, already used, expired, or out of attempts. */
    public const REASON_INVALID_CODE = 'invalid_login_code';

    /** The account exists but is disabled. */
    public const REASON_ACCOUNT_DISABLED = 'account_disabled';

    /** The address resolves to no user and magic-link account creation is off. */
    public const REASON_ACCOUNT_CREATION_DISABLED = 'account_creation_disabled';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function invalidCode(): self
    {
        return new self(
            self::REASON_INVALID_CODE,
            'That login code is not valid. Codes are single use and expire; request a new one.',
            401,
        );
    }

    public static function accountDisabled(): self
    {
        return new self(
            self::REASON_ACCOUNT_DISABLED,
            'This account is disabled.',
            403,
        );
    }

    public static function accountCreationDisabled(): self
    {
        return new self(
            self::REASON_ACCOUNT_CREATION_DISABLED,
            'This email cannot be used to sign in yet.',
            403,
        );
    }
}
