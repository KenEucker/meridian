<?php

namespace App\Services\Node;

use App\Models\Node;

/**
 * Resolves the key material a node signs and verifies with (technical spec
 * 7.3, 10.4).
 *
 * The node private key is node config, so it resolves file-first and
 * database-second like every other config value. Public keys come from the
 * `nodes` record, which is what pairing registers on both sides (data/API
 * 13.1, 13.5).
 *
 * Private keys are only ever resolved for this install's own node. Peer node
 * records learned through pairing carry a public key and nothing else, so
 * asking for a peer's private key is refused rather than answered with this
 * install's file-config value.
 */
class NodeKeyProvider
{
    public const CONFIG_PRIVATE_KEY = 'node_private_key';

    public function __construct(private readonly NodeConfigResolver $configResolver) {}

    /**
     * @throws NodeSigningException
     */
    public function privateKeyFor(Node $node): string
    {
        if (! $node->is_local) {
            throw NodeSigningException::notALocalNode($node->node_name);
        }

        $privateKey = $this->configuredPrivateKey($node);

        if ($privateKey === null) {
            throw NodeSigningException::privateKeyUnavailable($node->node_name);
        }

        return $privateKey;
    }

    public function hasPrivateKey(Node $node): bool
    {
        return $node->is_local && $this->configuredPrivateKey($node) !== null;
    }

    /**
     * The public key a signature from this node is checked against. Returns
     * null when the node has none, so verification can fail closed instead of
     * raising on a peer we cannot check.
     */
    public function publicKeyFor(Node $node): ?string
    {
        $publicKey = trim((string) $node->public_key);

        return $publicKey === '' ? null : $publicKey;
    }

    private function configuredPrivateKey(Node $node): ?string
    {
        foreach ($this->configResolver->valuesFor($node) as $value) {
            if ($value['key'] !== self::CONFIG_PRIVATE_KEY) {
                continue;
            }

            $privateKey = is_string($value['value']) ? trim($value['value']) : '';

            return $privateKey === '' ? null : $privateKey;
        }

        return null;
    }
}
