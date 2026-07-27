<?php

namespace App\Services\Node;

use App\Models\Event;
use App\Models\Node;
use RuntimeException;

/**
 * A write was refused by an event authority rule (technical spec 10.2).
 *
 * This is a refusal, not a failure: nothing was written, nothing is queued, and
 * there is nothing to retry here. What the writer should do instead differs by
 * reason, so each message says it. An event-scoped record refused for authority
 * belongs on the node that holds it, and the message names that node. Governance
 * content refused as frozen belongs nowhere until the window ends, and the
 * message says that instead of naming a node, because no node may make the edit.
 */
class EventAuthorityException extends RuntimeException
{
    /** The active event window is running and another node is authoritative. */
    public const REASON_READ_ONLY_DURING_ACTIVE_EVENT = 'read_only_during_active_event';

    /** The active event window is running and governance content is frozen. */
    public const REASON_GOVERNANCE_FROZEN_DURING_ACTIVE_EVENT = 'governance_frozen_during_active_event';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?string $eventId = null,
        public readonly ?string $authoritativeNodeId = null,
    ) {
        parent::__construct($message);
    }

    public static function readOnlyDuringActiveEvent(
        Event $event,
        Node $authoritativeNode,
        string $recordDescription,
    ): self {
        return new self(
            self::REASON_READ_ONLY_DURING_ACTIVE_EVENT,
            sprintf(
                'This node is read-only for %s during the active event window. '
                .'The on-site node "%s" is authoritative for event records until the window ends, '
                .'so %s must be changed there; central corrections happen after the event closes.',
                $event->name === null || $event->name === '' ? 'this event' : sprintf('"%s"', $event->name),
                $authoritativeNode->node_name,
                $recordDescription,
            ),
            (string) $event->getKey(),
            (string) $authoritativeNode->getKey(),
        );
    }

    /**
     * No node is named here. Policy/procedure and fragment edits are blocked
     * during the active event window (technical spec 10.2, 21.10), so unlike an
     * authority refusal there is no other node the same edit could be made on.
     */
    public static function governanceFrozenDuringActiveEvent(Event $event, string $recordDescription): self
    {
        return new self(
            self::REASON_GOVERNANCE_FROZEN_DURING_ACTIVE_EVENT,
            sprintf(
                'Policy, procedure, and fragment edits are blocked while %s is in its active event window, '
                .'so %s cannot be changed on any node until the window ends. Editing governance content '
                .'mid-event would bump the version of documents staff have already acknowledged.',
                $event->name === null || $event->name === '' ? 'this event' : sprintf('"%s"', $event->name),
                $recordDescription,
            ),
            (string) $event->getKey(),
        );
    }
}
