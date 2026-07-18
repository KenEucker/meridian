<?php

namespace App\Services\EventMode;

use RuntimeException;

/**
 * Thrown when event mode is entered while a required fail-closed check fails
 * (technical spec 8.6, 26.2). The message aggregates every failure reason so
 * callers can surface an honest explanation instead of starting insecurely.
 */
class EventModeNotReadyException extends RuntimeException
{
    public function __construct(public readonly EventModeReadiness $readiness)
    {
        $reasons = $readiness->reasons();

        $message = $reasons === []
            ? 'Event mode failed a required safeguard check.'
            : 'Event mode failed a required safeguard check: '.implode(' ', $reasons);

        parent::__construct($message);
    }
}
