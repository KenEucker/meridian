<?php

namespace App\Services\Presence;

use App\Models\EventDepartmentPresence;

final readonly class DepartmentPresenceResult
{
    public function __construct(
        public EventDepartmentPresence $presence,
        public bool $createdStateChange,
    ) {}
}
