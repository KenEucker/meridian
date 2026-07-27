<?php

namespace App\Services\Node;

use App\Models\NodeOperation;
use App\Models\SyncConflict;
use RuntimeException;

/**
 * A God-mode conflict resolution could not be carried out (technical spec 10.3;
 * data/API 7.5, 14.2).
 *
 * Resolution is a decision about which of two nodes' versions survives, so it
 * fails for reasons that are about the decision rather than about the entity:
 * the conflict was already decided, the requested side is not one of the two
 * documented choices, neither side can be identified as central, or the chosen
 * side could not actually be written. Each instance carries a stable reason code
 * so God mode can explain a refusal without parsing message text, the same way
 * {@see NodeOperationRejectedException} does for refused operations.
 *
 * Nothing here is a correction path. Conflicts are not manually corrected inside
 * the resolver (technical spec 10.3), so a refusal leaves the conflict open for
 * the other choice rather than offering a way to edit values.
 */
class SyncConflictResolutionException extends RuntimeException
{
    /** The conflict has already been reviewed and carries a resolution. */
    public const REASON_ALREADY_RESOLVED = 'conflict_already_resolved';

    /** The requested resolution is not accept on-site or accept central. */
    public const REASON_UNKNOWN_RESOLUTION = 'unknown_resolution';

    /** This install has no configured node, so it has no side of its own. */
    public const REASON_NODE_NOT_CONFIGURED = 'node_not_configured';

    /** The conflict's operation is missing or names an unknown origin node. */
    public const REASON_UNKNOWN_ORIGIN_NODE = 'unknown_origin_node';

    /** Both sides of the conflict sit on the same side of the central split. */
    public const REASON_SIDES_NOT_DISTINGUISHABLE = 'sides_not_distinguishable';

    /** Accepting the remote version ran the applier and it did not apply. */
    public const REASON_NOT_APPLIED = 'resolution_not_applied';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function alreadyResolved(SyncConflict $conflict): self
    {
        return new self(
            self::REASON_ALREADY_RESOLVED,
            sprintf(
                'This sync conflict was already resolved as "%s" and cannot be resolved again.',
                $conflict->resolutionLabel(),
            ),
        );
    }

    public static function unknownResolution(string $resolution): self
    {
        return new self(
            self::REASON_UNKNOWN_RESOLUTION,
            sprintf(
                'A sync conflict is resolved by accepting on-site or accepting central; "%s" is neither.',
                $resolution,
            ),
        );
    }

    public static function nodeNotConfigured(): self
    {
        return new self(
            self::REASON_NODE_NOT_CONFIGURED,
            'This Meridian install has no configured node, so it cannot tell which side of the conflict is its own.',
        );
    }

    public static function unknownOriginNode(SyncConflict $conflict): self
    {
        return new self(
            self::REASON_UNKNOWN_ORIGIN_NODE,
            sprintf(
                'Sync conflict %s has no stored operation origin node, so the remote side of the conflict cannot be identified.',
                (string) $conflict->getKey(),
            ),
        );
    }

    /**
     * Accept on-site and accept central only mean something when one of the two
     * nodes is central and the other is not. Two non-central nodes, or an
     * install comparing itself against itself, leave both choices naming the
     * same version.
     */
    public static function sidesNotDistinguishable(string $localRole, string $remoteRole): self
    {
        return new self(
            self::REASON_SIDES_NOT_DISTINGUISHABLE,
            sprintf(
                'Accepting on-site or central needs one central node and one on-site node; '
                .'this conflict is between a "%s" node and a "%s" node.',
                $localRole,
                $remoteRole,
            ),
        );
    }

    /**
     * Accepting the remote version means the stored operation is applied. If the
     * applier still cannot write it, the conflict stays open rather than being
     * recorded as resolved, because a resolution that changed nothing would
     * claim the disagreement was settled when local state never moved.
     */
    public static function notApplied(NodeOperation $operation): self
    {
        return new self(
            self::REASON_NOT_APPLIED,
            sprintf(
                'Node operation %s could not be applied, so the conflict is still open: %s',
                (string) $operation->uuid,
                $operation->failure_reason ?? 'no applier reported a reason.',
            ),
        );
    }
}
