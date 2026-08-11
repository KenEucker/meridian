<?php

declare(strict_types=1);

namespace App\Services\Directory;

use App\Models\Event;
use App\Models\Organization;

/**
 * The resolved context one Directory read is about (technical spec 21E.2).
 *
 * Resolved to an event, the population follows participation in that event;
 * resolved to the organization, it follows persistent organization membership
 * (DIR-006, DIR-007). The two are different questions with different answers,
 * which is why the context is an explicit value handed to the rule rather than
 * something each consumer works out for itself.
 */
final readonly class DirectoryContext
{
    public function __construct(
        public Organization $organization,
        public ?Event $event = null,
    ) {}

    public function isEventContext(): bool
    {
        return $this->event !== null;
    }
}
