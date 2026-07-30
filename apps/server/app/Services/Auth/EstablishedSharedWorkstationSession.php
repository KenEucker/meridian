<?php

namespace App\Services\Auth;

use App\Models\SharedWorkstationSession;

/**
 * A freshly established shared-workstation session and its raw session key
 * (AUTH-030; technical spec 13.3).
 *
 * The key exists for the length of the request that established it, in the
 * response to the code entry and nowhere else. Only the keyed hash on
 * {@see SharedWorkstationSession::$session_key_hash} survives the request, so a
 * Kiosk that loses the key in memory cannot recover it — which is exactly the
 * "locks immediately if the Electron app restarts" rule, expressed as something
 * the server cannot undo rather than as something the client promises.
 */
final class EstablishedSharedWorkstationSession
{
    public function __construct(
        public readonly SharedWorkstationSession $record,
        public readonly string $sessionKey,
    ) {}
}
