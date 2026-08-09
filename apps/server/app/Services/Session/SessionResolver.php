<?php

declare(strict_types=1);

namespace App\Services\Session;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\Event;
use App\Models\EventCredential;
use App\Models\EventDepartmentAssignment;
use App\Models\Node;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Node\NodeSetupService;
use App\Services\Permissions\EffectiveRole;
use App\Services\Permissions\EffectiveRoleResolver;
use Illuminate\Support\Collection;

/**
 * Resolves the session document a client reads to learn who its user is and what
 * that user may do (CLIENT-001 through CLIENT-003; technical spec 11A.2;
 * data/API 5.5).
 *
 * The document carries codes and associations. It carries no navigation
 * decision, screen list, or menu structure: a client that needs to know whether
 * to render a surface answers that from the capabilities it holds, which keeps
 * {@see PermissionCatalog} the single source of truth rather than growing a
 * second permission model inside a response shape.
 *
 * Capabilities appear twice, and deliberately. The flat `capabilities` list is
 * the one data/API 5.5 specifies, and it answers "may this user do X at all".
 * Each role entry also carries the codes that role alone brings, because
 * authority in Meridian is scoped — a person may run logistics for one
 * department and be ordinary staff in another — and the client holds no copy of
 * the role-to-capability mapping to work that out for itself. Both lists are
 * read from the catalog the server enforces from, so neither can disagree with
 * it.
 *
 * Context follows the node (technical spec 11A.3). An on-site node locked to an
 * event determines the event, read from the same place the branding resolver
 * already reads it; a node with no lock resolves the event the client asks for,
 * or the single event its user is associated with. Roles are then resolved at
 * that event, so event-scoped grants — Incident Command in particular — appear
 * only where they apply.
 */
class SessionResolver
{
    public function __construct(
        private readonly EffectiveRoleResolver $roles,
        private readonly NodeSetupService $nodes,
    ) {}

    /**
     * The session document for the calling user.
     *
     * Every association in it is the caller's own. There is no parameter for
     * whose session to resolve, because there is no answer to that question
     * other than "the caller's".
     *
     * @param  string|null  $requestedEventId  the event the client wants its
     *                                         roles resolved at, which must be
     *                                         one of the caller's own
     * @param  Device|null  $device  the device this request came from, when the
     *                               credential names one. Only its trust for
     *                               this user is reported, and only to that
     *                               user's own client.
     * @return array<string, mixed>
     *
     * @throws SessionContextException when the requested event is not a context
     *                                 this caller may resolve on this node
     */
    public function resolve(
        User $user,
        ?string $requestedEventId = null,
        ?Device $device = null,
    ): array
    {
        $staff = $user->staffProfiles()->get();
        $staffIds = $staff->map(fn (Staff $profile): string => (string) $profile->getKey())->all();

        $departments = $this->departments($this->departmentMemberships($staffIds));
        $teams = $this->teams($this->teamMemberships($staffIds));

        $events = $this->events($staffIds, $departments->keys()->all(), $teams->keys()->all());
        $lockedEventId = $this->lockedEventId();
        $contextEvent = $this->contextEvent($requestedEventId, $lockedEventId, $events);

        $organizations = $this->organizations($staffIds, $departments, $events);
        $roles = $this->roles($staff, $contextEvent, $teams);

        return [
            'user' => [
                'id' => (string) $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                // The staff records this login speaks for. A client needs them
                // to recognise its own user in a roster it has been handed.
                'staff_ids' => $staffIds,
            ],
            'roles' => $roles->all(),
            'capabilities' => $this->capabilities($roles),
            'organizations' => $organizations->values()->all(),
            'events' => $events
                ->map(fn (Event $event): array => $this->eventPayload($event, $lockedEventId))
                ->values()
                ->all(),
            'departments' => $departments->values()->all(),
            'teams' => $teams->values()->all(),
            'context' => $this->context($contextEvent, $lockedEventId, $organizations, $departments, $events),
            'device' => $this->device($user, $device),
            // Server time of resolution, which is what a client operating from
            // cache displays as its last refresh (CLIENT-009).
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Whether this request's device is trusted for this user (AUTH-021,
     * AUTH-024; technical spec 12.1, 12.2, 14; data/API 12.2).
     *
     * Device trust is a `device_trusts` row per user/device pair, and until now
     * no endpoint published it — so the readiness checklist, which lists
     * "device trusted" as one of its eight items, had nothing to answer with and
     * reported it as unavailable on every personal device.
     *
     * It belongs on the session document rather than on a route of its own for
     * two reasons. The document is already per-caller and already cached
     * durably, so a device in a field with no signal reads its own trust from
     * the last answer the node gave rather than losing the item the moment it
     * needs it most. And the question is about the credential this request
     * arrived on: a token is bound to a device (AUTH-021), so the caller's own
     * token names the only device there is an answer for. There is no parameter
     * for whose device to report, for the same reason there is none for whose
     * session.
     *
     * Null when the credential names no device — a shared-workstation session
     * key is a machine's credential rather than a device-bound token, and the
     * checklist answers for a workstation from the pinned-context read instead
     * (M18.32). Null is "this session names no device", not "not trusted".
     *
     * `trusted` is the same predicate the Field Report services enforce with,
     * asked from `DeviceTrust::isActive()` so a client and the writes it will
     * attempt cannot disagree about what trust means. `expires_at` travels with
     * it because AUTH-024 gives trust a six-week life the person can see coming.
     *
     * @return array<string, mixed>|null
     */
    private function device(User $user, ?Device $device): ?array
    {
        if (! $device instanceof Device) {
            return null;
        }

        $trust = DeviceTrust::query()
            ->with('device')
            ->where('user_id', $user->getKey())
            ->where('device_id', $device->getKey())
            ->first();

        return [
            'id' => (string) $device->getKey(),
            'label' => $device->device_label,
            'trusted' => $trust instanceof DeviceTrust && $trust->isActive(),
            /*
             * Why it is not trusted, when it is not, in the three shapes that
             * differ for the person reading it: nothing on record, a lapsed
             * window they can renew by signing in, and a revocation they cannot.
             */
            'trust_state' => $this->trustState($trust),
            'trusted_until' => $trust?->expires_at?->toIso8601String(),
        ];
    }

    private function trustState(?DeviceTrust $trust): string
    {
        if (! $trust instanceof DeviceTrust) {
            return 'untrusted';
        }

        if ($trust->isRevoked()) {
            return 'revoked';
        }

        return $trust->isActive() ? 'trusted' : 'expired';
    }

    /**
     * @param  list<string>  $staffIds
     * @return Collection<int, DepartmentMembership>
     */
    private function departmentMemberships(array $staffIds): Collection
    {
        if ($staffIds === []) {
            return collect();
        }

        return DepartmentMembership::query()
            ->active()
            ->whereIn('staff_id', $staffIds)
            ->with('department')
            ->get();
    }

    /**
     * @param  list<string>  $staffIds
     * @return Collection<int, TeamMembership>
     */
    private function teamMemberships(array $staffIds): Collection
    {
        if ($staffIds === []) {
            return collect();
        }

        return TeamMembership::query()
            ->active()
            ->whereIn('staff_id', $staffIds)
            ->with('team.department')
            ->get();
    }

    /**
     * The departments the caller belongs to, keyed by id.
     *
     * A membership that is not archived counts as an association even when its
     * status is prospective or inactive: the client is told the status and
     * decides what to say about it, and dropping the department entirely would
     * leave a staff member unable to see what they are waiting on.
     *
     * @param  Collection<int, DepartmentMembership>  $memberships
     * @return Collection<string, array<string, mixed>>
     */
    private function departments(Collection $memberships): Collection
    {
        return $memberships
            ->filter(fn (DepartmentMembership $membership): bool => $membership->department instanceof Department)
            ->sortBy(fn (DepartmentMembership $membership): string => (string) $membership->department->name)
            ->mapWithKeys(function (DepartmentMembership $membership): array {
                $department = $membership->department;

                return [(string) $department->getKey() => [
                    'id' => (string) $department->getKey(),
                    'organization_id' => (string) $department->organization_id,
                    'name' => $department->name,
                    'code' => $department->code,
                    'membership_status' => $membership->status,
                    'archived_at' => $department->archived_at?->toIso8601String(),
                ]];
            });
    }

    /**
     * The teams the caller belongs to, keyed by id.
     *
     * `is_lead` reports the designation the team-scoped roles hang off
     * (TEAM-009), not an authority of its own. Memberships are ordered so a lead
     * designation wins where one login reaches the same team through two staff
     * profiles.
     *
     * @param  Collection<int, TeamMembership>  $memberships
     * @return Collection<string, array<string, mixed>>
     */
    private function teams(Collection $memberships): Collection
    {
        return $memberships
            ->filter(fn (TeamMembership $membership): bool => $membership->team instanceof Team)
            ->sortBy(fn (TeamMembership $membership): string => (string) $membership->team->name
                .'|'.($this->isTeamLead($membership) ? '1' : '0'))
            ->mapWithKeys(function (TeamMembership $membership): array {
                $team = $membership->team;
                $organizationId = $team->department?->organization_id;

                return [(string) $team->getKey() => [
                    'id' => (string) $team->getKey(),
                    'department_id' => (string) $team->department_id,
                    'organization_id' => $organizationId === null ? null : (string) $organizationId,
                    'name' => $team->name,
                    'code' => $team->code,
                    'is_default' => (bool) $team->is_default,
                    'is_lead' => $this->isTeamLead($membership),
                    'archived_at' => $team->archived_at?->toIso8601String(),
                ]];
            });
    }

    private function isTeamLead(TeamMembership $membership): bool
    {
        return $membership->membership_role === 'lead';
    }

    /**
     * The events the caller holds an association with.
     *
     * Three things make an event theirs: one of their departments is assigned to
     * it, a team they belong to carries a grant scoped to it, or they hold a
     * credential for it. The first is the ordinary case, the second is how
     * Incident Command standing arrives, and the third covers a person
     * credentialed for an event their department is not running.
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

        $fromCredentials = $staffIds === []
            ? collect()
            : EventCredential::query()
                ->whereIn('staff_id', $staffIds)
                ->whereNull('revoked_at')
                ->pluck('event_id');

        $eventIds = $fromDepartments
            ->concat($fromGrants)
            ->concat($fromCredentials)
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
     * The organizations the caller holds an association with, keyed by id.
     *
     * Organization standing is recorded on `staff_organization_statuses`, and
     * that row is where `status` comes from. The organizations of the caller's
     * departments and events are unioned in so the document closes over itself:
     * every `organization_id` a department or event in it names is also listed
     * here, and a client is never left displaying an organization it was not
     * told about.
     *
     * @param  list<string>  $staffIds
     * @param  Collection<string, array<string, mixed>>  $departments
     * @param  Collection<int, Event>  $events
     * @return Collection<string, array<string, mixed>>
     */
    private function organizations(array $staffIds, Collection $departments, Collection $events): Collection
    {
        $statuses = $staffIds === []
            ? collect()
            : StaffOrganizationStatus::query()
                ->whereIn('staff_id', $staffIds)
                ->get()
                ->keyBy(fn (StaffOrganizationStatus $status): string => (string) $status->organization_id);

        $organizationIds = $statuses->keys()
            ->concat($departments->pluck('organization_id'))
            ->concat($events->map(fn (Event $event): string => (string) $event->organization_id))
            ->filter(fn (mixed $organizationId): bool => is_string($organizationId) && $organizationId !== '')
            ->unique()
            ->values();

        if ($organizationIds->isEmpty()) {
            return collect();
        }

        return Organization::query()
            ->whereKey($organizationIds->all())
            ->orderBy('name')
            ->get()
            ->mapWithKeys(function (Organization $organization) use ($statuses): array {
                $id = (string) $organization->getKey();

                return [$id => [
                    'id' => $id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'status' => $statuses->get($id)?->status,
                    'archived_at' => $organization->archived_at?->toIso8601String(),
                ]];
            });
    }

    /**
     * The caller's effective roles at the resolved context.
     *
     * A user may hold more than one staff profile, and two profiles can reach
     * the same grant, so entries are deduplicated by role, team, and event
     * rather than by profile. The scope a role was resolved at is reported
     * alongside it, because "department logistics" says nothing without the
     * department it applies to.
     *
     * @param  Collection<int, Staff>  $staff
     * @param  Collection<string, array<string, mixed>>  $teams
     * @return Collection<int, array<string, mixed>>
     */
    private function roles(Collection $staff, ?Event $event, Collection $teams): Collection
    {
        $catalog = PermissionCatalog::roles();
        $rolePermissions = PermissionCatalog::rolePermissions();

        return $staff
            ->flatMap(fn (Staff $profile): Collection => $this->roles->resolveForStaff($profile, $event))
            ->keyBy(fn (EffectiveRole $role): string => implode('|', [
                $role->roleCode,
                $role->teamId,
                (string) $role->eventId,
            ]))
            ->sortBy(fn (EffectiveRole $role): string => $role->roleCode.'|'.$role->teamName)
            ->map(function (EffectiveRole $role) use ($catalog, $rolePermissions, $teams): array {
                $team = $teams->get($role->teamId);

                return [
                    'role_code' => $role->roleCode,
                    'role_name' => $role->roleName,
                    'scope_type' => $catalog[$role->roleCode]['scope_type'] ?? null,
                    'organization_id' => $team['organization_id'] ?? null,
                    'department_id' => $team['department_id'] ?? null,
                    'team_id' => $role->teamId,
                    'team_name' => $role->teamName,
                    'event_id' => $role->eventId,
                    'team_grant_id' => $role->teamGrantId,
                    // Why the user holds it, so an elevated user reaching a
                    // denied surface can be told what their authority is
                    // (technical spec 15.2; CLIENT-004).
                    'reason' => $role->reason,
                    'capabilities' => $rolePermissions[$role->roleCode] ?? [],
                ];
            })
            ->values();
    }

    /**
     * Every capability code the caller's roles carry, once each.
     *
     * @param  Collection<int, array<string, mixed>>  $roles
     * @return list<string>
     */
    private function capabilities(Collection $roles): array
    {
        /** @var list<string> $capabilities */
        $capabilities = $roles
            ->flatMap(fn (array $role): array => $role['capabilities'])
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $capabilities;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(Event $event, ?string $lockedEventId): array
    {
        return [
            'id' => (string) $event->getKey(),
            'organization_id' => (string) $event->organization_id,
            'name' => $event->name,
            'slug' => $event->slug,
            'status' => $event->status,
            'timezone' => $event->timezone,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            // The window a cached session stays usable for (CLIENT-008).
            'active_event_window_starts_at' => $event->active_event_window_starts_at?->toIso8601String(),
            'active_event_window_ends_at' => $event->active_event_window_ends_at?->toIso8601String(),
            'is_node_locked' => $lockedEventId !== null && (string) $event->getKey() === $lockedEventId,
        ];
    }

    /**
     * The event the client's roles are resolved at.
     *
     * With nothing requested, the node's lock decides, because the node is what
     * knows which event it is running; failing that, a user associated with
     * exactly one event has an unambiguous context and is given it. A node
     * locked to an event will not resolve a different one: it holds that event's
     * records and no others.
     *
     * The locked event is used whether or not the caller is associated with it.
     * Context is a property of the install, not of the person standing at it, and
     * a user with no standing at the locked event resolves the roles that are
     * true there — which is none of the event-scoped ones.
     *
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

        if ($lockedEventId !== null && $requestedEventId !== $lockedEventId) {
            throw SessionContextException::nodeLocked($lockedEventId);
        }

        $requested = $events->first(
            fn (Event $event): bool => (string) $event->getKey() === $requestedEventId,
        );

        if ($requested instanceof Event) {
            return $requested;
        }

        // A locked node answers for its own event even when the caller holds no
        // association with it, which is the one case a requested id can be
        // honored without appearing in the caller's own list.
        if ($requestedEventId === $lockedEventId) {
            $lockedEvent = $this->lockedEvent($lockedEventId);

            if ($lockedEvent instanceof Event) {
                return $lockedEvent;
            }
        }

        throw SessionContextException::eventNotAvailable();
    }

    /**
     * The organization, event, and department the client is operating within,
     * and whether it may move (technical spec 11A.3).
     *
     * A department is resolved only when there is one to resolve: a staff member
     * of a single department in the context organization has an unambiguous one,
     * and a department lead of three does not, so the client asks rather than
     * being told.
     *
     * Switching is offered only on a node with no event lock. A node locked to an
     * event holds that event's records and no others, so offering a switch it
     * could not serve would promise data that is not there. The lock is reported
     * as `node_locked_event_id` so a client can say why the switcher is absent
     * rather than appearing to have lost it.
     *
     * @param  Collection<string, array<string, mixed>>  $organizations
     * @param  Collection<string, array<string, mixed>>  $departments
     * @param  Collection<int, Event>  $events
     * @return array<string, mixed>
     */
    private function context(
        ?Event $contextEvent,
        ?string $lockedEventId,
        Collection $organizations,
        Collection $departments,
        Collection $events,
    ): array {
        $organizationId = $contextEvent instanceof Event
            ? (string) $contextEvent->organization_id
            : ($organizations->count() === 1 ? (string) $organizations->keys()->first() : null);

        $contextDepartments = $organizationId === null
            ? $departments
            : $departments->filter(
                fn (array $department): bool => $department['organization_id'] === $organizationId,
            );

        return [
            'organization_id' => $organizationId,
            'event_id' => $contextEvent instanceof Event ? (string) $contextEvent->getKey() : null,
            'department_id' => $contextDepartments->count() === 1
                ? (string) $contextDepartments->keys()->first()
                : null,
            'node_locked' => $lockedEventId !== null,
            'node_locked_event_id' => $lockedEventId,
            'switching_available' => $lockedEventId === null
                && ($organizations->count() > 1 || $events->count() > 1),
        ];
    }

    /**
     * The event this node is locked to, when it is locked to one.
     */
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
