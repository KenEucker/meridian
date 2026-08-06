<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationSession;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\SharedWorkstationSessionKey;
use App\Services\DepartmentOps\DepartmentOperationsAccess;
use App\Services\FieldReports\FieldReportVisibilityAccess;
use App\Services\Incidents\IncidentReadAccess;
use App\Services\Permissions\DepartmentOperationalAccess;
use App\Services\Permissions\EffectiveRoleResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Resolves the {@see DashboardAudience} for one request (M18.28).
 *
 * Nothing new is decided here. Every question is forwarded to the service that
 * already answers it for the surface behind the widget, so a dashboard cannot
 * become a second, more generous, permission model:
 *
 *  - department standing → `DepartmentOperationsAccess`, which the Logistics
 *    Window, Overview, Operations Center, and Planning Table all resolve from;
 *  - IC standing → `IncidentReadAccess`, the same gate `GET /incidents` runs;
 *  - Field Report reach → `FieldReportVisibilityAccess` (FR-005, FR-006);
 *  - organizer standing → the effective roles the session response publishes,
 *    narrowed to the event's own organization the way `ReportingExportAccess`
 *    narrows them, so an organizer of one organization is not an organizer of
 *    another organization's event.
 */
final class DashboardAudienceResolver
{
    public function __construct(
        private readonly DepartmentOperationsAccess $departments,
        private readonly DepartmentOperationalAccess $operational,
        private readonly EffectiveRoleResolver $roles,
        private readonly IncidentReadAccess $incidents,
        private readonly FieldReportVisibilityAccess $fieldReports,
    ) {}

    public function resolve(Request $request, User $user, Event $event, ?Department $requested): DashboardAudience
    {
        $staff = $user->staffProfiles()->get();
        $workstation = $this->workstationFor($request);
        $department = $requested ?? $this->pinnedDepartment($workstation, $event);

        return new DashboardAudience(
            staffIds: $staff->map(fn (Staff $profile): string => (string) $profile->getKey())->all(),
            isEventStaff: $this->isEventStaff($staff, $event),
            department: $department,
            departmentAuthority: $department === null
                ? null
                : $this->departments->resolve($user, $event, $department),
            isDepartmentLogistics: $department !== null && $this->operational->hasDepartmentRole(
                $user,
                $event,
                $department,
                [PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS],
            ),
            isOrganizer: $this->isOrganizer($user, $event),
            hasIncidentCommand: $this->incidents->canViewIncidents($user, $event),
            canViewEventFieldReports: $this->fieldReports->canViewEventFieldReports($user, $event),
            workstation: $workstation,
        );
    }

    /**
     * Whether this login is staff of the event's organization.
     *
     * UI contract 13.1 permits the staff group to "authenticated staff", which
     * is a fact about belonging rather than a capability: holding a status with
     * the organization, or an active membership of one of its departments. An
     * authenticated user who is neither — a God Mode operator, say — is not
     * staff of this event and reads no staff dashboard, because every widget in
     * the group is about their own shifts and their own departments and they
     * have none.
     *
     * @param  Collection<int, Staff>  $staff
     */
    private function isEventStaff($staff, Event $event): bool
    {
        foreach ($staff as $profile) {
            $holdsStatus = $profile->organizationStatuses()
                ->where('organization_id', $event->organization_id)
                ->exists();

            if ($holdsStatus) {
                return true;
            }

            $belongsToDepartment = $profile->departmentMemberships()
                ->active()
                ->where('status', DepartmentMembership::STATUS_ACTIVE)
                ->whereHas('department', fn ($query) => $query
                    ->where('organization_id', $event->organization_id))
                ->exists();

            if ($belongsToDepartment) {
                return true;
            }
        }

        return false;
    }

    /**
     * Organizer or Lead Organizer standing in the event's own organization.
     *
     * The role has to be carried by a team in a department of that organization.
     * Without that narrowing an organizer would read every organization's
     * dashboard, which is the mistake `ReportingExportAccess` documents at
     * length and this would repeat.
     */
    private function isOrganizer(User $user, Event $event): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff, $event) as $role) {
                if (! in_array($role->roleCode, [
                    PermissionCatalog::ROLE_ORGANIZER,
                    PermissionCatalog::ROLE_LEAD_ORGANIZER,
                ], true)) {
                    continue;
                }

                $department = Team::query()->with('department')->find($role->teamId)?->department;

                if ($department !== null
                    && (string) $department->organization_id === (string) $event->organization_id) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The trusted workstation this request came from, if it came from one.
     *
     * Read without sliding the session's inactivity window. The guard already
     * slid it when it authenticated the request; sliding it a second time here
     * would mean the dashboard read alone could hold a Kiosk signed in, which is
     * the opposite of what the five-minute timeout is for.
     */
    private function workstationFor(Request $request): ?SharedWorkstation
    {
        $key = trim((string) $request->header(SharedWorkstationSessionKey::HEADER));

        if ($key === '') {
            return null;
        }

        $session = SharedWorkstationSession::query()
            ->where('session_key_hash', SharedWorkstationSessionKey::hash($key))
            ->open()
            ->first();

        if (! $session instanceof SharedWorkstationSession || $session->hasTimedOut()) {
            return null;
        }

        $workstation = $session->sharedWorkstation()->first();

        return $workstation instanceof SharedWorkstation && $workstation->isTrusted()
            ? $workstation
            : null;
    }

    /**
     * The department a Kiosk is pinned to, when the request named none.
     *
     * A shared workstation's context is pinned by somebody who set it up, and
     * that pin is the honest scope for a kiosk dashboard: the machine is at one
     * department's desk. A pin naming a department of another organization, or
     * another event, is ignored rather than followed.
     */
    private function pinnedDepartment(?SharedWorkstation $workstation, Event $event): ?Department
    {
        if ($workstation?->department_id === null) {
            return null;
        }

        if ($workstation->event_id !== null
            && (string) $workstation->event_id !== (string) $event->getKey()) {
            return null;
        }

        $department = Department::query()->find($workstation->department_id);

        return $department !== null
            && (string) $department->organization_id === (string) $event->organization_id
            ? $department
            : null;
    }
}
