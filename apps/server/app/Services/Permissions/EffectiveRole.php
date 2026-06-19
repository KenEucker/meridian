<?php

namespace App\Services\Permissions;

/**
 * A resolved effective role for a staff member, including a human-readable
 * reason so the authority can be explained in the UI (technical spec 15.2).
 */
final readonly class EffectiveRole
{
    public function __construct(
        public string $roleCode,
        public string $roleName,
        public int $teamId,
        public string $teamName,
        public int $teamGrantId,
        public ?string $eventId,
        public string $reason,
    ) {}
}
