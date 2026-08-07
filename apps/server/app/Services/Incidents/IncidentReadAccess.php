<?php

namespace App\Services\Incidents;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Event;
use App\Models\Incident;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Permission gate for restricted IMS incident list/detail reads (M11.5).
 *
 * Incident visibility is event-scoped through the configured IC department and
 * team-granted `incidents.view` capability. Organizer or department-lead status
 * alone never grants IMS incident access.
 */
final class IncidentReadAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canViewIncidents(User $user, Event $event): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff, $event) as $role) {
                if (PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_INCIDENTS_VIEW,
                )) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Narrow an incident query to the incidents this user may read (M18.34).
     *
     * The list form of {@see canViewIncidents()}, for the God Mode repair
     * screen. There is no author clause to go beside it, and that asymmetry
     * with Field Reports is the rule rather than an omission: a Field Report is
     * somebody's own account and FR-004 gives it back to them, while an
     * incident belongs to Incident Command and is reached through
     * `incidents.view` or not at all. A user who opened one holds no standing
     * from having done so.
     *
     * An empty grant produces an empty list rather than an unfiltered one,
     * which is why the `whereIn` is applied unconditionally.
     *
     * @param  Builder<Incident>  $query
     * @return Builder<Incident>
     */
    public function constrainToVisible(Builder $query, User $user): Builder
    {
        return $query->whereIn('event_id', $this->eventIdsVisibleTo($user));
    }

    /**
     * The events with incidents this user may read the incidents of.
     *
     * @return list<string>
     */
    public function eventIdsVisibleTo(User $user): array
    {
        return Event::query()
            ->whereIn('id', Incident::query()->select('event_id')->distinct())
            ->get()
            ->filter(fn (Event $event): bool => $this->canViewIncidents($user, $event))
            ->map(fn (Event $event): string => (string) $event->getKey())
            ->values()
            ->all();
    }
}
