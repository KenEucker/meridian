<?php

namespace App\Services\FieldReports;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Field Report event-wide visibility for IC roles (FR-005, FR-006; technical
 * spec 17.6).
 *
 * Users with an effective role that grants `field_reports.view_event` for the
 * report's event may view all Field Reports for that event. Department leads,
 * organizers, and shift leads do not receive this permission by default.
 */
class FieldReportVisibilityAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canViewEventFieldReports(User $user, Event $event): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            $effectiveRoles = $this->roles->resolveForStaff($staff, $event);

            foreach ($effectiveRoles as $role) {
                if (PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_FIELD_REPORTS_VIEW_EVENT,
                )) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Narrow a Field Report query to the reports this user may read (M18.34).
     *
     * The list form of {@see \App\Policies\FieldReportPolicy::view()}, and
     * deliberately the same two clauses in the same order: the reports they
     * authored or took, plus every report of an event they hold
     * `field_reports.view_event` for. It exists so the God Mode repair screen
     * can ask this class the question rather than inventing a second answer —
     * console access is not staff standing, and FR-005 and FR-006 are rules
     * about a personal account of something that happened to somebody, not
     * rules about what *organizing* reaches. The audit trail is where the
     * repair boundary is lifted, because a row saying a report was submitted is
     * not the report.
     *
     * Event-wide visibility is resolved event by event, over the events that
     * actually have reports, because that is the granularity the permission is
     * granted at. A node holds a handful of events; the alternative is a second
     * expression of the grant rule in SQL that could disagree with this one.
     *
     * @param  Builder<FieldReport>  $query
     * @return Builder<FieldReport>
     */
    public function constrainToVisible(Builder $query, User $user): Builder
    {
        $eventIds = $this->eventIdsVisibleTo($user);

        return $query->where(function (Builder $visible) use ($user, $eventIds): void {
            $visible->forAuthor($user);

            if ($eventIds !== []) {
                $visible->orWhereIn('event_id', $eventIds);
            }
        });
    }

    /**
     * The events with Field Reports this user may read every report of.
     *
     * @return list<string>
     */
    public function eventIdsVisibleTo(User $user): array
    {
        return Event::query()
            ->whereIn('id', FieldReport::query()->select('event_id')->distinct())
            ->get()
            ->filter(fn (Event $event): bool => $this->canViewEventFieldReports($user, $event))
            ->map(fn (Event $event): string => (string) $event->getKey())
            ->values()
            ->all();
    }
}
