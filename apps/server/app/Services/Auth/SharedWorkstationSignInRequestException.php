<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * A workstation sign-in request could not be opened, granted, or collected
 * (AUTH-032 through AUTH-037; technical spec 13.4; data/API 12.4A).
 *
 * Stable reason codes for the same reason the login-code refusals carry them:
 * both surfaces explaining a refusal are client applications — the locked Kiosk
 * and the phone pointed at it — and neither should parse prose to say what
 * happened.
 */
class SharedWorkstationSignInRequestException extends RuntimeException
{
    /** No shared workstation on this node matches the request. */
    public const REASON_WORKSTATION_UNKNOWN = 'workstation_unknown';

    /** The workstation is on record but is not a trusted shared workstation. */
    public const REASON_WORKSTATION_UNTRUSTED = 'workstation_untrusted';

    /** The workstation has no pinned event, so a request cannot be scoped. */
    public const REASON_WORKSTATION_CONTEXT_UNPINNED = 'workstation_context_unpinned';

    /**
     * The request does not exist, does not belong to this workstation, or the
     * pickup secret is wrong. Answered identically for all three, so a guess
     * learns nothing about which (AUTH-032).
     */
    public const REASON_REQUEST_UNKNOWN = 'sign_in_request_unknown';

    /** The request's expiry has passed; it can be neither granted nor collected. */
    public const REASON_REQUEST_EXPIRED = 'sign_in_request_expired';

    /** The request was already granted; a grant is accepted once (AUTH-032). */
    public const REASON_ALREADY_GRANTED = 'sign_in_request_already_granted';

    /** The grant names a node other than the one that issued the request (AUTH-034). */
    public const REASON_FOREIGN_NODE = 'sign_in_request_foreign_node';

    /** A grant may only be made for the granting user themselves (AUTH-033, AUTH-036). */
    public const REASON_GRANT_SCOPE = 'sign_in_request_grant_scope';

    /** A request opened for one purpose cannot be collected as the other (AUTH-036). */
    public const REASON_PURPOSE_MISMATCH = 'sign_in_request_purpose_mismatch';

    /** The account that granted, or would grant, is disabled. */
    public const REASON_ACCOUNT_DISABLED = 'account_disabled';

    /** The session a re-authentication request was bound to is over (AUTH-036). */
    public const REASON_SESSION_GONE = 'no_active_workstation_session';

    /** Opening or granting has been rate limited (AUTH-037). */
    public const REASON_RATE_LIMITED = 'sign_in_request_rate_limited';

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

    public static function workstationUntrusted(): self
    {
        return new self(
            self::REASON_WORKSTATION_UNTRUSTED,
            'That workstation is not a trusted shared workstation.',
            403,
        );
    }

    public static function workstationContextUnpinned(): self
    {
        return new self(
            self::REASON_WORKSTATION_CONTEXT_UNPINNED,
            'That workstation has no pinned event on this node, so it cannot open sign-in requests.',
            409,
        );
    }

    public static function requestUnknown(): self
    {
        return new self(
            self::REASON_REQUEST_UNKNOWN,
            'That sign-in request is not open on this node.',
            404,
        );
    }

    public static function requestExpired(): self
    {
        return new self(
            self::REASON_REQUEST_EXPIRED,
            'That sign-in request has expired. Ask the workstation for a fresh code.',
            410,
        );
    }

    public static function alreadyGranted(): self
    {
        return new self(
            self::REASON_ALREADY_GRANTED,
            'That sign-in request was already granted. Ask the workstation for a fresh code.',
            409,
        );
    }

    /**
     * AUTH-034, from the node's side: the grant claims a node this is not. The
     * refusal names this node, and the claimed one where pairing has taught us
     * its name, because the person holding the phone has to decide which device
     * is pointed at the wrong place.
     */
    public static function foreignNode(string $thisNode, ?string $claimedNode): self
    {
        $claimed = $claimedNode === null || $claimedNode === ''
            ? 'a different node'
            : '"'.$claimedNode.'"';

        return new self(
            self::REASON_FOREIGN_NODE,
            'That sign-in request belongs to '.$claimed.', but this device is talking to "'.$thisNode.'". The grant was refused so nothing is issued into the wrong database.',
            409,
        );
    }

    public static function grantScope(): self
    {
        return new self(
            self::REASON_GRANT_SCOPE,
            'A sign-in request can only be granted for yourself.',
            403,
        );
    }

    public static function purposeMismatch(): self
    {
        return new self(
            self::REASON_PURPOSE_MISMATCH,
            'That request was opened for a different purpose and cannot be collected this way.',
            409,
        );
    }

    public static function accountDisabled(): self
    {
        return new self(
            self::REASON_ACCOUNT_DISABLED,
            'This account is disabled and cannot sign in.',
            403,
        );
    }

    public static function sessionGone(): self
    {
        return new self(
            self::REASON_SESSION_GONE,
            'The session this confirmation was for has ended. Sign in again instead.',
            401,
        );
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(
            self::REASON_RATE_LIMITED,
            'Too many sign-in request attempts. Try again in '.max(1, $retryAfterSeconds).' seconds.',
            429,
            max(1, $retryAfterSeconds),
        );
    }
}
