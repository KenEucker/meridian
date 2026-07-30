<?php

namespace App\Services\Auth;

use App\Models\ApiAuthHandoff;

/**
 * A provider handoff that has been started and is waiting on the system browser.
 *
 * The plaintext `state` is returned to the client that started the handoff so it
 * can recognize its own sign-in coming back — the return leg carries the same
 * value, and on a shared custom scheme a client may see a return that belongs to
 * a handoff it did not start. Only a keyed hash of it is stored.
 */
final class StartedApiAuthHandoff
{
    public function __construct(
        public readonly ApiAuthHandoff $record,
        public readonly string $state,
        public readonly string $authorizationUrl,
    ) {}
}
