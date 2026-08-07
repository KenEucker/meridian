<?php

declare(strict_types=1);

namespace App\Services\EventHorizon;

use App\Models\User;

/**
 * The resolved standing one readiness compilation is about (technical spec
 * 21D.5).
 *
 * Resolved once per request and handed to every item kind, so five kinds do
 * not run five copies of the same membership queries and cannot resolve the
 * same person differently. Everything here is scoped to the event's own
 * organization: a staff profile, department, or team held somewhere else is
 * not standing in this event and compiles nothing.
 */
final class EventHorizonViewer
{
    /**
     * @param  list<string>  $staffIds  the caller's staff profiles in the event's organization
     * @param  array<string, string>  $staffIdByDepartmentId  which profile answers for each active department membership
     * @param  list<string>  $teamIds  active team memberships across those departments
     * @param  list<string>  $ledTeamIds  the subset of teams the caller leads (`membership_role = 'lead'`)
     */
    public function __construct(
        public readonly User $user,
        public readonly array $staffIds,
        public readonly array $staffIdByDepartmentId,
        public readonly array $teamIds,
        public readonly array $ledTeamIds,
    ) {}

    /**
     * Whether this login is staff of the event at all (HORIZON-002).
     *
     * The Event Horizon needs no capability, but it is a *staff* member's
     * readiness list: a login with no staff profile in the event's
     * organization has no acknowledgments, waivers, trainings, or shifts to be
     * ready with, and reads no list rather than an empty one.
     */
    public function isEventStaff(): bool
    {
        return $this->staffIds !== [];
    }

    /**
     * @return list<string>
     */
    public function departmentIds(): array
    {
        return array_keys($this->staffIdByDepartmentId);
    }
}
