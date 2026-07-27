<?php

namespace App\Services\Node;

use App\Models\Node;

/**
 * The outcome of redeeming a pairing token on central. `replayed` marks a
 * repeat of an already-completed pairing rather than a new one.
 */
class NodePairingResult
{
    public function __construct(
        public readonly Node $centralNode,
        public readonly Node $pairedNode,
        public readonly bool $replayed = false,
    ) {}

    /**
     * The pairing response the on-site node needs: the identity of central,
     * plus confirmation of how this node was registered.
     *
     * @return array<string, mixed>
     */
    public function toResponseArray(): array
    {
        return [
            'central_node' => [
                'id' => $this->centralNode->getKey(),
                'node_name' => $this->centralNode->node_name,
                'node_role' => $this->centralNode->node_role,
                'public_key' => $this->centralNode->public_key,
            ],
            'paired_node' => [
                'id' => $this->pairedNode->getKey(),
                'node_name' => $this->pairedNode->node_name,
                'node_role' => $this->pairedNode->node_role,
            ],
            'paired_at' => $this->pairedNode->paired_at?->toIso8601String(),
            'replayed' => $this->replayed,
        ];
    }
}
