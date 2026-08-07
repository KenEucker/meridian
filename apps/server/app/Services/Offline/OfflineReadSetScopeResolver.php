<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventCredential;
use App\Models\EventDepartmentAssignment;
use App\Models\Node;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Node\NodeSetupService;
use App\Services\Permissions\EffectiveRole;
use App\Services\Permissions\EffectiveRoleResolver;
use App\Services\Session\SessionContextException;
use Illuminate\Support\Collection;

/**
 * Resolves the caller's scope for one composition of the offline read set
 * (technical spec 9.5, 11A.7; CLIENT-021, CLIENT-022).
 *
 * The associations are read from the database on every composition and nothing
 * is carried in the caller's token, which is what makes CLIENT-022 hold: a
 * revoked grant, an archived membership, or a withdrawn lead designation
 * changes the next set the device is handed without waiting for a sign-in.
 *
 * Roles come from {@see EffectiveRoleResolver}, the resolver every other API
 * read answers from, so there is one authorization model rather than a second
 * one written for replication. That was the whole of ADR-0003's case against
 * the sync rules: `sync-config.yaml` expressed the same rules in a restricted
 * SQL dialect, and the two had to agree forever.
 *
 * Context resolution follows technical spec 11A.3 and matches
 * {@see \App\Services\Session\SessionResolver}: a locked node decides, then an
 * explicit request among the caller's own events, then a caller with exactly
 * one event. The context event is what bounds the set's staleness under 11A.4;
 * it does not narrow the set, because a device that walks out of signal keeps
 * what it holds for every event it holds it for.
 */
class OfflineReadSetScopeResolver
{
    public function __construct(
        private readonly EffectiveRoleResolver $roles,
        private readonly NodeSetupService $nodes,
    ) {}

    /**
     * @throws SessionContextException when the requested event is not a context
     *                                 this caller may resolve on this node
     */
    public function resolve(User $user, ?string $requestedEventId = null): OfflineReadSetScope
    {
        $staff = $user->staffProfiles()->get();
        $staffIds = $staff->map(fn (Staff $profile): string => (string) $profile->getKey())
            ->unique()
            ->values()
            ->all();

        $lockedEventId = $this->lockedEventId();

        if ($staffIds === []) {
            /*
             * A login with no staff profile receives nothing. It is resolved
             * rather than refused: the client is a signed-in user whose account
             * has no standing yet, and an empty set is the true answer to what
             * it may hold offline.
             */
            $this->assertRequestedEventIsResolvable($requestedEventId, $lockedEventId, collect());

            return new OfflineReadSetScope(
                user: $user,
                staffIds: [],
                organizationIds: [],
                standingOrganizationIds: [],
                departmentIds: [],
                teamIds: [],
                eventIds: [],
                roleCodes: [],
                contextEvent: null,
                nodeLockedEventId: $lockedEventId,
            );
        }

        $departmentIds = DepartmentMembership::query()
            ->active()
            ->whereIn('staff_id', $staffIds)
            ->pluck('department_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $teamIds = TeamMembership::query()
            ->active()
            ->whereIn('staff_id', $staffIds)
            ->pluck('team_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $events = $this->events($staffIds, $departmentIds, $teamIds);
        $contextEvent = $this->contextEvent($requestedEventId, $lockedEventId, $events);
        $standingOrganizationIds = $this->standingOrganizationIds($staffIds);
        $departmentOrganizations = $this->departmentOrganizations($departmentIds);
        $roleGrants = $this->roleGrants($staff, $events);

        return new OfflineReadSetScope(
            user: $user,
            staffIds: $staffIds,
            organizationIds: $this->organizationIds($standingOrganizationIds, $departmentOrganizations, $events),
            standingOrganizationIds: $standingOrganizationIds,
            departmentIds: $departmentIds,
            teamIds: $teamIds,
            eventIds: $events->map(fn (Event $event): string => (string) $event->getKey())->all(),
            roleCodes: $this->roleCodes($staff, $contextEvent),
            contextEvent: $contextEvent,
            nodeLockedEventId: $lockedEventId,
            departmentOrganizations: $departmentOrganizations,
            teamOrganizations: $this->teamOrganizations($teamIds),
            eventOrganizations: $events
                ->mapWithKeys(fn (Event $event): array => [
                    (string) $event->getKey() => (string) $event->organization_id,
                ])
                ->all(),
            roleGrants: $roleGrants,
        );
    }

    /**
     * Every role the caller holds, resolved once per event in scope.
     *
     * The role-additive sections of technical spec 9.3 are about a department or
     * a team *at an event*, so a role code alone cannot say which records
     * travel. Resolving per event is what applies the grant's own event scope
     * without restating it: {@see EffectiveRoleResolver} already admits a grant
     * only when it is unscoped or scoped to the event it is asked about, so an
     * event-scoped grant produces exactly one of these and an unscoped one
     * produces a grant per event. That is the narrowing M16.13 asserted, and it
     * is applied by the same resolver rather than by a copy of its rule.
     *
     * The other two narrowings come along unchanged: the `shift_lead`
     * designation (TEAM-009) and the Incident Command department check are the
     * resolver's, and a revoked grant is simply not resolved.
     *
     * @param  Collection<int, Staff>  $staff
     * @param  Collection<int, Event>  $events
     * @return list<OfflineReadSetRoleGrant>
     */
    private function roleGrants(Collection $staff, Collection $events): array
    {
        if ($events->isEmpty()) {
            return [];
        }

        $teams = Team::query()
            ->whereIn('id', $staff
                ->flatMap(fn (Staff $profile): Collection => $profile->teamMemberships()->active()->pluck('team_id'))
                ->unique()
                ->values()
                ->all())
            ->with('department')
            ->get()
            ->keyBy(fn (Team $team): string => (string) $team->getKey());

        $grants = [];

        foreach ($events as $event) {
            $eventId = (string) $event->getKey();
            $organizationId = (string) $event->organization_id;

            foreach ($staff as $profile) {
                foreach ($this->roles->resolveForStaff($profile, $event) as $role) {
                    $team = $teams->get($role->teamId);

                    if (! $team instanceof Team || $team->department === null) {
                        continue;
                    }

                    /*
                     * A role is held over a department, and a department belongs
                     * to one organization. The resolver answers about a staff
                     * member's teams wherever they are, so a login with standing
                     * in two organizations would otherwise carry one
                     * organization's authority into the other's event.
                     */
                    if ((string) $team->department->organization_id !== $organizationId) {
                        continue;
                    }

                    /*
                     * Keyed rather than appended: two staff profiles of one
                     * login can hold the same grant, and the section composed
                     * from it should not be composed twice.
                     */
                    $grants[$role->roleCode.':'.$eventId.':'.$role->teamId] = new OfflineReadSetRoleGrant(
                        roleCode: $role->roleCode,
                        eventId: $eventId,
                        departmentId: (string) $team->department_id,
                        teamId: (string) $team->getKey(),
                        organizationId: $organizationId,
                    );
                }
            }
        }

        ksort($grants);

        return array_values($grants);
    }

    /**
     * The organization each of these departments belongs to.
     *
     * Kept as a map rather than resolved on demand because MOD-016 is asked per
     * organization and the read set narrows by it: a section owned by a module
     * one organization runs and another does not has to keep the first
     * organization's rows.
     *
     * @param  list<string>  $departmentIds
     * @return array<string, string>
     */
    private function departmentOrganizations(array $departmentIds): array
    {
        if ($departmentIds === []) {
            return [];
        }

        /** @var array<string, string> $map */
        $map = Department::query()
            ->whereKey($departmentIds)
            ->pluck('organization_id', 'id')
            ->map(fn (mixed $organizationId): string => (string) $organizationId)
            ->all();

        return $map;
    }

    /**
     * @param  list<string>  $teamIds
     * @return array<string, string>
     */
    private function teamOrganizations(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        /** @var array<string, string> $map */
        $map = Team::query()
            ->whereKey($teamIds)
            ->with('department')
            ->get()
            ->filter(fn (Team $team): bool => $team->department !== null)
            ->mapWithKeys(fn (Team $team): array => [
                (string) $team->getKey() => (string) $team->department->organization_id,
            ])
            ->all();

        return $map;
    }

    /**
     * The events whose basic data the caller may hold (technical spec 9.3).
     *
     * The three associations {@see \App\Services\Session\SessionResolver} uses
     * — a department assigned to the event, an event-scoped grant on a team
     * they belong to, an unrevoked credential — plus the events of the shifts
     * they are assigned to. The fourth is the sync rules' own union and it is
     * not redundant: a staff member can hold a shift at an event their
     * department was later removed from, and dropping the event would leave the
     * device holding a shift it cannot name.
     *
     * @param  list<string>  $staffIds
     * @param  list<string>  $departmentIds
     * @param  list<string>  $teamIds
     * @return Collection<int, Event>
     */
    private function events(array $staffIds, array $departmentIds, array $teamIds): Collection
    {
        $fromDepartments = $departmentIds === []
            ? collect()
            : EventDepartmentAssignment::query()
                ->whereIn('department_id', $departmentIds)
                ->whereNull('archived_at')
                ->pluck('event_id');

        $fromGrants = $teamIds === []
            ? collect()
            : TeamGrant::query()
                ->active()
                ->whereIn('team_id', $teamIds)
                ->whereNotNull('event_id')
                ->pluck('event_id');

        $fromCredentials = EventCredential::query()
            ->whereIn('staff_id', $staffIds)
            ->whereNull('revoked_at')
            ->pluck('event_id');

        $fromShifts = Shift::query()
            ->whereIn('id', ShiftAssignment::query()
                ->whereIn('staff_id', $staffIds)
                ->whereNull('removed_at')
                ->select('shift_id'))
            ->pluck('event_id');

        $eventIds = $fromDepartments
            ->concat($fromGrants)
            ->concat($fromCredentials)
            ->concat($fromShifts)
            ->map(fn (mixed $eventId): string => (string) $eventId)
            ->unique()
            ->values();

        if ($eventIds->isEmpty()) {
            return collect();
        }

        return Event::query()
            ->whereKey($eventIds->all())
            ->orderBy('starts_at')
            ->orderBy('name')
            ->get();
    }

    /**
     * The organizations the caller holds standing in.
     *
     * This is the narrower of the two organization lists and the one that
     * decides audience: an organization-scoped policy document reaches the
     * staff of that organization, which is a `staff_organization_statuses` row
     * and not a department membership that happens to point at it.
     *
     * @param  list<string>  $staffIds
     * @return list<string>
     */
    private function standingOrganizationIds(array $staffIds): array
    {
        /** @var list<string> $ids */
        $ids = StaffOrganizationStatus::query()
            ->whereIn('staff_id', $staffIds)
            ->pluck('organization_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $ids;
    }

    /**
     * The organizations the set closes over: the caller's own standing, plus
     * the organizations their departments and events belong to, so no record in
     * the set names an organization the set does not carry.
     *
     * @param  list<string>  $standingOrganizationIds
     * @param  array<string, string>  $departmentOrganizations
     * @param  Collection<int, Event>  $events
     * @return list<string>
     */
    private function organizationIds(
        array $standingOrganizationIds,
        array $departmentOrganizations,
        Collection $events,
    ): array {
        /** @var list<string> $ids */
        $ids = collect($standingOrganizationIds)
            ->concat(array_values($departmentOrganizations))
            ->concat($events->map(fn (Event $event): string => (string) $event->organization_id))
            ->map(fn (mixed $id): string => (string) $id)
            ->filter(fn (string $id): bool => $id !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $ids;
    }

    /**
     * The effective role codes the caller holds at the resolved context.
     *
     * @param  Collection<int, Staff>  $staff
     * @return list<string>
     */
    private function roleCodes(Collection $staff, ?Event $contextEvent): array
    {
        /** @var list<string> $codes */
        $codes = $staff
            ->flatMap(fn (Staff $profile): Collection => $this->roles->resolveForStaff($profile, $contextEvent))
            ->map(fn (EffectiveRole $role): string => $role->roleCode)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $codes;
    }

    /**
     * @param  Collection<int, Event>  $events
     *
     * @throws SessionContextException
     */
    private function contextEvent(?string $requestedEventId, ?string $lockedEventId, Collection $events): ?Event
    {
        if ($requestedEventId === null) {
            return $this->lockedEvent($lockedEventId)
                ?? ($events->count() === 1 ? $events->first() : null);
        }

        $this->assertRequestedEventIsResolvable($requestedEventId, $lockedEventId, $events);

        return $events->first(
            fn (Event $event): bool => (string) $event->getKey() === $requestedEventId,
        ) ?? $this->lockedEvent($lockedEventId);
    }

    /**
     * @param  Collection<int, Event>  $events
     *
     * @throws SessionContextException
     */
    private function assertRequestedEventIsResolvable(
        ?string $requestedEventId,
        ?string $lockedEventId,
        Collection $events,
    ): void {
        if ($requestedEventId === null) {
            return;
        }

        if ($lockedEventId !== null && $requestedEventId !== $lockedEventId) {
            throw SessionContextException::nodeLocked($lockedEventId);
        }

        $isOwn = $events->contains(
            fn (Event $event): bool => (string) $event->getKey() === $requestedEventId,
        );

        if ($isOwn || ($requestedEventId === $lockedEventId && $this->lockedEvent($lockedEventId) instanceof Event)) {
            return;
        }

        throw SessionContextException::eventNotAvailable();
    }

    private function lockedEventId(): ?string
    {
        $node = $this->nodes->activeNode();

        if (! $node instanceof Node || $node->event_id === null) {
            return null;
        }

        return (string) $node->event_id;
    }

    private function lockedEvent(?string $lockedEventId): ?Event
    {
        if ($lockedEventId === null) {
            return null;
        }

        return Event::query()->find($lockedEventId);
    }
}
