<?php

declare(strict_types=1);

namespace App\Services\Session;

use RuntimeException;

/**
 * A session could not be resolved at the event context the client asked for
 * (technical spec 11A.3).
 *
 * Each case carries the status and reason code the client is answered with, so
 * the endpoint reports the refusal without restating the rule that produced it.
 */
final class SessionContextException extends RuntimeException
{
    public const REASON_EVENT_NOT_AVAILABLE = 'event_context_unavailable';

    public const REASON_NODE_LOCKED = 'node_locked_to_event';

    private function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $status,
        public readonly ?string $lockedEventId = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The caller holds no association with the requested event.
     *
     * An event that does not exist is refused with this same answer. Whether
     * some other organization is running an event under an identifier a client
     * guessed at is not that client's business, and distinguishing the two would
     * turn this endpoint into a way to ask.
     */
    public static function eventNotAvailable(): self
    {
        return new self(
            'You hold no association with that event.',
            self::REASON_EVENT_NOT_AVAILABLE,
            404,
        );
    }

    /**
     * This node is locked to an event, so it is not the node to resolve a
     * different one on: it holds that event's records and no others.
     */
    public static function nodeLocked(string $lockedEventId): self
    {
        return new self(
            'This node is locked to an event, and session context cannot be resolved at another one.',
            self::REASON_NODE_LOCKED,
            409,
            $lockedEventId,
        );
    }
}
