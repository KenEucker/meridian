<?php

declare(strict_types=1);

namespace App\Services\Events;

use RuntimeException;

/**
 * A refused event administration edit (M18.29).
 *
 * Value refusals only. The node-authority refusal belongs to
 * {@see \App\Services\Node\EventAuthorityException}, which already words the
 * one sentence that matters — which node the edit belongs on — and wording it
 * a second time here would be a second answer to the same question.
 */
final class EventAdministrationException extends RuntimeException
{
    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
