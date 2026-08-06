<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * A shared-workstation login code could not be generated, or a code entry was
 * refused (AUTH-026 through AUTH-029; technical spec 13.2; data/API 12.4).
 *
 * Each instance carries a stable reason code, for the same reason the API login
 * refusals do: the surface explaining it is a client application — a phone in
 * someone's hand or the kiosk in front of them — and it should not have to parse
 * prose to say what happened.
 */
class SharedWorkstationLoginException extends RuntimeException
{
    /** No shared workstation on this node matches the request. */
    public const REASON_WORKSTATION_UNKNOWN = 'workstation_unknown';

    /** The workstation is on record but is not a trusted shared workstation. */
    public const REASON_WORKSTATION_UNTRUSTED = 'workstation_untrusted';

    /** The workstation has no pinned event, so a code could not be scoped to one. */
    public const REASON_WORKSTATION_CONTEXT_UNPINNED = 'workstation_context_unpinned';

    /** A user tried to generate a code for somebody else (AUTH-028). */
    public const REASON_SELF_SERVICE_SCOPE = 'self_service_scope';

    /** The account the code would be for is disabled. */
    public const REASON_ACCOUNT_DISABLED = 'account_disabled';

    /** Generation or entry has been rate limited (AUTH-029). */
    public const REASON_RATE_LIMITED = 'login_code_rate_limited';

    /** The submitted code is unknown, already used, revoked, or expired. */
    public const REASON_INVALID_CODE = 'invalid_login_code';

    /** A re-authentication code belongs to somebody other than the active user. */
    public const REASON_REAUTHENTICATION_MISMATCH = 'reauthentication_user_mismatch';

    /** There was no live session at the workstation to re-authenticate. */
    public const REASON_NO_ACTIVE_SESSION = 'no_active_workstation_session';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public static function workstationUnknown(): self
    {
        return new self(
            self::REASON_WORKSTATION_UNKNOWN,
            'That shared workstation is not on record on this node.',
            404,
        );
    }

    /**
     * Technical spec 13.2: codes are usable only on trusted shared workstations,
     * so an untrusted or revoked one is refused at generation rather than
     * handed a code that could never be entered.
     */
    public static function workstationUntrusted(): self
    {
        return new self(
            self::REASON_WORKSTATION_UNTRUSTED,
            'That workstation is not a trusted shared workstation.',
            403,
        );
    }

    /**
     * Technical spec 13.1: a Kiosk workstation with no pinned organization and
     * event is in setup, and a code is scoped to one event. A workstation pinned
     * to an event this node does not hold is in the same position — there is no
     * event to scope a code to.
     */
    public static function workstationContextUnpinned(): self
    {
        return new self(
            self::REASON_WORKSTATION_CONTEXT_UNPINNED,
            'That workstation has no pinned event on this node. Pin its Kiosk context before generating login codes for it.',
            409,
        );
    }

    public static function selfServiceScope(): self
    {
        return new self(
            self::REASON_SELF_SERVICE_SCOPE,
            'A login code can only be generated for yourself. Ask a God Mode operator to generate one for someone else.',
            403,
        );
    }

    public static function accountDisabled(): self
    {
        return new self(
            self::REASON_ACCOUNT_DISABLED,
            'This account is disabled and cannot be given a login code.',
            403,
        );
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(
            self::REASON_RATE_LIMITED,
            'Too many login code attempts. Try again in '.max(1, $retryAfterSeconds).' seconds.',
            429,
            max(1, $retryAfterSeconds),
        );
    }

    /**
     * Answered the same way for a code that never existed, one already used, one
     * revoked, and one expired, so a wrong guess learns nothing about which.
     */
    public static function invalidCode(): self
    {
        return new self(
            self::REASON_INVALID_CODE,
            'That login code is not valid for this workstation. Codes are single use and expire.',
            401,
        );
    }

    /**
     * A valid code, for the wrong person (M18.32; UI contract 18.2).
     *
     * Re-authentication confirms the user who is already signed in; it is not a
     * quiet way to hand the workstation to somebody else. Technical spec 13.3
     * requires a session to be ended explicitly before another user signs in, so
     * the refusal names the surface that does that rather than doing it here.
     *
     * The code is spent by the time this is thrown, for the same reason a code
     * entered against a disabled account is: possession was proved, and handing
     * the credential back for another attempt is what a single-use code exists
     * to prevent.
     */
    public static function reauthenticationMismatch(): self
    {
        return new self(
            self::REASON_REAUTHENTICATION_MISMATCH,
            'That code belongs to a different user. To hand this workstation over, end the current session and sign in again.',
            403,
        );
    }

    public static function noActiveSession(): self
    {
        return new self(
            self::REASON_NO_ACTIVE_SESSION,
            'This workstation has no live session to confirm.',
            401,
        );
    }
}
