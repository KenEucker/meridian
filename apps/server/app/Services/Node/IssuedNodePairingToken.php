<?php

namespace App\Services\Node;

use App\Models\NodePairingToken;

/**
 * The result of issuing a pairing token. The plaintext token exists only in
 * this object and in the one response that displays it; the stored record keeps
 * the hash alone.
 */
class IssuedNodePairingToken
{
    public function __construct(
        public readonly NodePairingToken $token,
        public readonly string $plaintext,
    ) {}
}
