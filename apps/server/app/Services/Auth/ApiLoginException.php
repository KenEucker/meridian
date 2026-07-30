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

    /** The request carries no device identity a token could be bound to. */
    public const REASON_DEVICE_UNRESOLVABLE = 'device_unresolvable';

    /** The named device is on record and revoked. */
    public const REASON_DEVICE_REVOKED = 'device_revoked';

    /** Login was requested through a provider Meridian does not support. */
    public const REASON_UNKNOWN_PROVIDER = 'unknown_provider';

    /** The handoff named a client target that is not one Meridian returns to. */
    public const REASON_UNKNOWN_CLIENT_TARGET = 'unknown_client_target';

    /** The client target is known but this node has no return address for it. */
    public const REASON_CLIENT_TARGET_NOT_CONFIGURED = 'client_target_not_configured';

    /** The provider itself is not configured on this node. */
    public const REASON_PROVIDER_NOT_CONFIGURED = 'provider_not_configured';

    /** The submitted exchange code is unknown, already spent, or expired. */
    public const REASON_INVALID_HANDOFF = 'invalid_handoff';

    /** The exchange code is live but the caller did not prove it started it. */
    public const REASON_INVALID_CODE_VERIFIER = 'invalid_code_verifier';

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

    /**
     * AUTH-021: a token that cannot be associated with a device is not issued.
     * The refusal names what is missing, because the client can supply it and
     * retry — unlike the refusals above, which are about the account.
     */
    public static function deviceUnresolvable(string $detail): self
    {
        return new self(
            self::REASON_DEVICE_UNRESOLVABLE,
            $detail,
            422,
        );
    }

    public static function deviceRevoked(): self
    {
        return new self(
            self::REASON_DEVICE_REVOKED,
            'This device has been revoked and cannot sign in.',
            403,
        );
    }

    /**
     * AUTH-020 refusals below. The provider handoff is started by a client
     * application, so each of these is answered to the client rather than shown
     * in the system browser.
     */
    public static function unknownProvider(string $provider): self
    {
        return new self(
            self::REASON_UNKNOWN_PROVIDER,
            'Meridian does not support signing in with '.$provider.'.',
            404,
        );
    }

    public static function unknownClientTarget(): self
    {
        return new self(
            self::REASON_UNKNOWN_CLIENT_TARGET,
            'This client did not identify itself as a client target Meridian returns a provider login to.',
            422,
        );
    }

    /**
     * An operator decision rather than a client error: a node with no return
     * address configured for a client target has not enabled provider login for
     * it. The refusal says so, because the client cannot correct it.
     */
    public static function clientTargetNotConfigured(string $clientTarget): self
    {
        return new self(
            self::REASON_CLIENT_TARGET_NOT_CONFIGURED,
            'This node has no provider sign-in return address configured for the '.$clientTarget.' client.',
            503,
        );
    }

    public static function providerNotConfigured(string $providerName): self
    {
        return new self(
            self::REASON_PROVIDER_NOT_CONFIGURED,
            $providerName.' sign-in is not configured on this node.',
            503,
        );
    }

    public static function invalidHandoff(): self
    {
        return new self(
            self::REASON_INVALID_HANDOFF,
            'That sign-in could not be completed. Handoff codes are single use and expire; start the sign-in again.',
            401,
        );
    }

    /**
     * The exchange code was real but the verifier did not match its challenge,
     * so whoever is asking is not the client that started the handoff. Answered
     * exactly like an unknown code, in language that does not confirm the code
     * existed.
     */
    public static function invalidCodeVerifier(): self
    {
        return new self(
            self::REASON_INVALID_CODE_VERIFIER,
            'That sign-in could not be completed. Start the sign-in again.',
            401,
        );
    }
}
