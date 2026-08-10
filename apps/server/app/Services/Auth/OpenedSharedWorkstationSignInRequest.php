<?php

namespace App\Services\Auth;

use App\Models\SharedWorkstationSignInRequest;

/**
 * A freshly opened sign-in request and its raw pickup secret (AUTH-032,
 * AUTH-037; technical spec 13.4; data/API 12.4A).
 *
 * The pickup secret exists for the length of the request that opened it, in
 * the response to the opening workstation and nowhere else. Only the keyed
 * hash on {@see SharedWorkstationSignInRequest::$pickup_secret_hash} survives,
 * so nothing but the machine that opened the request can ever collect it.
 */
final class OpenedSharedWorkstationSignInRequest
{
    public function __construct(
        public readonly SharedWorkstationSignInRequest $record,
        public readonly string $pickupSecret,
    ) {}
}
