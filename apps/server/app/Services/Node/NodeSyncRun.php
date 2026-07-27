<?php

namespace App\Services\Node;

/**
 * What one sync run moved (technical spec 10.1, 25.3).
 *
 * A run is several exchanges, so these are totals across all of them. The
 * counts are kept apart rather than summed into one number because they answer
 * different operational questions: whether this node's own work reached the
 * peer, whether the peer's work arrived, and whether anything was refused or
 * could not be applied. An operator looking at a node that "synced fine" while
 * every operation failed to apply is exactly the situation these separate
 * counts exist to prevent.
 */
final class NodeSyncRun
{
    public function __construct(
        public readonly string $peerNodeName,
        public readonly int $exchanges = 0,
        /** Operations this node handed to the peer. */
        public readonly int $pushed = 0,
        /** Pushed operations the peer confirmed it holds. */
        public readonly int $accepted = 0,
        /** Pushed operations the peer refused; parked as failed here. */
        public readonly int $refusedByPeer = 0,
        /** Operations the peer handed to this node. */
        public readonly int $pulled = 0,
        /** Pulled operations this node stored for the first time. */
        public readonly int $stored = 0,
        /** Pulled operations that were stored and applied. */
        public readonly int $applied = 0,
        /** Pulled operations stored but not applied; recoverable here. */
        public readonly int $unapplied = 0,
        /** Pulled operations this node refused; reported back to the peer. */
        public readonly int $refusedHere = 0,
    ) {}

    public function with(
        int $exchanges = 0,
        int $pushed = 0,
        int $accepted = 0,
        int $refusedByPeer = 0,
        int $pulled = 0,
        int $stored = 0,
        int $applied = 0,
        int $unapplied = 0,
        int $refusedHere = 0,
    ): self {
        return new self(
            peerNodeName: $this->peerNodeName,
            exchanges: $this->exchanges + $exchanges,
            pushed: $this->pushed + $pushed,
            accepted: $this->accepted + $accepted,
            refusedByPeer: $this->refusedByPeer + $refusedByPeer,
            pulled: $this->pulled + $pulled,
            stored: $this->stored + $stored,
            applied: $this->applied + $applied,
            unapplied: $this->unapplied + $unapplied,
            refusedHere: $this->refusedHere + $refusedHere,
        );
    }

    /**
     * Whether anything about this run needs a human. A run that moved nothing
     * is normal; a run that had operations refused on either side is not.
     */
    public function needsAttention(): bool
    {
        return $this->refusedByPeer > 0 || $this->refusedHere > 0 || $this->unapplied > 0;
    }

    public function summary(): string
    {
        return sprintf(
            'Synced with %s in %d exchange(s): sent %d (%d accepted, %d refused), received %d (%d applied, %d unapplied, %d refused).',
            $this->peerNodeName,
            $this->exchanges,
            $this->pushed,
            $this->accepted,
            $this->refusedByPeer,
            $this->pulled,
            $this->applied,
            $this->unapplied,
            $this->refusedHere,
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'peer_node_name' => $this->peerNodeName,
            'exchanges' => $this->exchanges,
            'pushed' => $this->pushed,
            'accepted' => $this->accepted,
            'refused_by_peer' => $this->refusedByPeer,
            'pulled' => $this->pulled,
            'stored' => $this->stored,
            'applied' => $this->applied,
            'unapplied' => $this->unapplied,
            'refused_here' => $this->refusedHere,
        ];
    }
}
