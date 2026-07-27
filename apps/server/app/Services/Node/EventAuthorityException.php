<?php

namespace App\Services\Node;

use App\Models\Event;
use App\Models\Node;
use RuntimeException;

/**
 * A write to an event-scoped record was refused because another node holds
 * authority for that event (technical spec 10.2).
 *
 * This is a refusal, not a failure: nothing was written, nothing is queued, and
 * there is nothing to retry here. The same change belongs on the node that holds
 * authority, so the message names that node rather than only reporting that the
 * attempt was denied.
 */
class EventAuthorityException extends RuntimeException
{
    /** The active event window is running and another node is authoritative. */
    public const REASON_READ_ONLY_DURING_ACTIVE_EVENT = 'read_only_during_active_event';

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
}
