<?php

declare(strict_types=1);

namespace App\Services\Offline;

/**
 * One role the caller holds, at one event, over one team and its department
 * (TEAM-009; technical spec 11A.7).
 *
 * The regular-staff sections of technical spec 9.3 are membership-scoped and
 * need none of this. The role-additive sections are the opposite: a Logistics
 * index is about a *department at an event*, and a shift-lead roster is about a
 * *team at an event*, so a role code on its own cannot say which records travel.
 *
 * The event is the event in the caller's scope the role was resolved at, not the
 * grant's own `event_id`. An unscoped grant produces one of these per event the
 * caller holds; an event-scoped grant produces exactly one. That is the
 * narrowing M16.13 asserted, expressed as data rather than as a condition each
 * section would have to remember to apply.
 */
final class OfflineReadSetRoleGrant
{
    public function __construct(
        public readonly string $roleCode,
        public readonly string $eventId,
        public readonly string $departmentId,
        public readonly string $teamId,
        public readonly string $organizationId,
    ) {}
}
