<?php

declare(strict_types=1);

namespace App\Services\Console;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\StaffOrganizationStatus;
use App\Models\TeamGrant;
use App\Services\Permissions\EffectiveRoleResolver;
use Illuminate\Support\Collection;

/**
 * Organizational data gaps for the God Mode landing screen (GOD-007; technical
 * spec 22.5.2).
 *
 * These are configuration holes that do not stop the server from starting but
 * do stop operations from working: a department nobody was assigned to, an
 * Incident Command Department that cannot be resolved, an organization with
 * nobody able to act as Lead Organizer. Each one is discovered at the worst
 * possible moment — during an event — unless somebody sees it first.
 *
 * Archived organizations, departments, and events are skipped. An archived
 * record is not a gap; it is a decision.
 *
 * Nothing here writes (GOD-010). The landing screen reports the gap and links to
 * the screen that closes it.
 */
final class OrganizationalDataGapCheck
{
    public const NO_DEPARTMENTS = 'data_gap.organization_without_departments';

    public const NO_ORGANIZERS_DEPARTMENT = 'data_gap.organization_without_organizers_department';

    public const NO_IC_DEPARTMENT = 'data_gap.organization_without_ic_department';

    public const EVENT_NO_IC_DEPARTMENT = 'data_gap.event_without_ic_department';

    public const NO_ACTIVE_LEAD_ORGANIZER = 'data_gap.organization_without_active_lead_organizer';

    public const EVENT_NO_DEPARTMENTS = 'data_gap.event_without_departments';

    public function group(): AttentionGroup
    {
        return new AttentionGroup(
            key: AttentionGroup::DATA_GAPS,
            label: 'Organizational data gaps',
            description: 'Organization and event records that are missing something operations depend on.',
            items: $this->items(),
        );
    }

    /**
     * @return list<AttentionItem>
     */
    public function items(): array
    {
        $items = [];

        $organizations = Organization::query()
            ->active()
            ->orderBy('name')
            ->get();

        foreach ($organizations as $organization) {
            $items = [...$items, ...$this->organizationItems($organization)];
        }

        foreach ($this->activeEvents($organizations) as $event) {
            $items = [...$items, ...$this->eventItems($event)];
        }

        return $items;
    }

    /**
     * @return list<AttentionItem>
     */
    private function organizationItems(Organization $organization): array
    {
        $items = [];
        $name = (string) $organization->name;

        $hasActiveDepartments = Department::query()
            ->active()
            ->where('organization_id', $organization->getKey())
            ->exists();

        if (! $hasActiveDepartments) {
            // Every other organization-level gap is downstream of this one, so
            // reporting them all would bury the single thing to do first.
            return [
                new AttentionItem(
                    key: self::NO_DEPARTMENTS,
                    label: sprintf('%s has no departments', $name),
                    detail: 'Staff, teams, shifts, and event assignments all hang off departments, so nothing can be staffed until at least one exists.',
                    resolveRoute: 'platform.departments',
                    resolveLabel: 'Departments',
                ),
            ];
        }

        if (! $this->departmentIsUsable($organization->organizers_department_id, $organization)) {
            $items[] = new AttentionItem(
                key: self::NO_ORGANIZERS_DEPARTMENT,
                label: sprintf('%s has no Organizers Department', $name),
                detail: 'Organizer and Lead Organizer authority is organization-scoped through the Organizers Department (ORG-007, ORG-008), so it must point at an active department of this organization.',
                resolveRoute: 'platform.organizations.edit',
                resolveRouteParameters: ['organization' => $organization->getKey()],
                resolveLabel: $name,
            );
        }

        if (! $this->departmentIsUsable($organization->default_ic_department_id, $organization)) {
            $items[] = new AttentionItem(
                key: self::NO_IC_DEPARTMENT,
                label: sprintf('%s has no default Incident Command Department', $name),
                detail: 'Incident Command roles resolve against the effective IC department (ORG-005). Without an organization default, every event of this organization must set its own override.',
                resolveRoute: 'platform.organizations.edit',
                resolveRouteParameters: ['organization' => $organization->getKey()],
                resolveLabel: $name,
            );
        }

        if (! $this->hasActiveLeadOrganizer($organization)) {
            $items[] = new AttentionItem(
                key: self::NO_ACTIVE_LEAD_ORGANIZER,
                label: sprintf('%s has no active Lead Organizer', $name),
                detail: 'No active staff member of this organization holds the Lead Organizer role through an active team membership and team grant, so nobody can exercise organization-level authority.',
                resolveRoute: 'platform.teams',
                resolveLabel: 'Teams',
            );
        }

        return $items;
    }

    /**
     * @return list<AttentionItem>
     */
    private function eventItems(Event $event): array
    {
        $items = [];
        $name = (string) $event->name;

        $hasDepartments = $event->departmentAssignments()
            ->whereNull('archived_at')
            ->exists();

        if (! $hasDepartments) {
            $items[] = new AttentionItem(
                key: self::EVENT_NO_DEPARTMENTS,
                label: sprintf('%s has no assigned departments', $name),
                detail: 'An event with no participating departments has no shifts, no rosters, and nothing for staff to apply to.',
                resolveRoute: 'platform.events.edit',
                resolveRouteParameters: ['event' => $event->getKey()],
                resolveLabel: $name,
            );
        }

        // "Resolvable" follows the existing effective-department resolution:
        // the event override when set, otherwise the organization default
        // (ORG-005, ORG-006). An event is only reported once the organization
        // default is also unusable, so a single organization-level fix does not
        // produce one item per event.
        $effectiveIcDepartmentId = $event->ic_department_id ?? $event->organization?->default_ic_department_id;

        if (! $this->departmentIsUsable($effectiveIcDepartmentId, $event->organization)) {
            $items[] = new AttentionItem(
                key: self::EVENT_NO_IC_DEPARTMENT,
                label: sprintf('%s has no resolvable Incident Command Department', $name),
                detail: 'Neither the event override nor the organization default resolves to an active department, so Incident Command roles cannot be granted for this event.',
                resolveRoute: 'platform.events.edit',
                resolveRouteParameters: ['event' => $event->getKey()],
                resolveLabel: $name,
            );
        }

        return $items;
    }

    /**
     * @param  Collection<int, Organization>  $organizations
     * @return Collection<int, Event>
     */
    private function activeEvents($organizations)
    {
        if ($organizations->isEmpty()) {
            return Event::query()->whereRaw('1 = 0')->get();
        }

        return Event::query()
            ->active()
            ->whereIn('organization_id', $organizations->modelKeys())
            ->with('organization')
            ->orderBy('name')
            ->get();
    }

    /**
     * A department reference is usable when it points at an active department
     * of the same organization. A pointer at an archived or foreign department
     * is a gap, not a configuration.
     */
    private function departmentIsUsable(mixed $departmentId, ?Organization $organization): bool
    {
        if ($departmentId === null || $organization === null) {
            return false;
        }

        return Department::query()
            ->active()
            ->whereKey($departmentId)
            ->where('organization_id', $organization->getKey())
            ->exists();
    }

    /**
     * An organization has an active Lead Organizer when some staff member who
     * is active in that organization sits on a team that carries an active
     * Lead Organizer grant, and that team belongs to a department of the same
     * organization. That is the same path {@see EffectiveRoleResolver}
     * walks per staff member, asked as one existence question.
     */
    private function hasActiveLeadOrganizer(Organization $organization): bool
    {
        return TeamGrant::query()
            ->active()
            ->whereHas(
                'permissionRole',
                fn ($role) => $role->where('code', PermissionCatalog::ROLE_LEAD_ORGANIZER),
            )
            ->whereHas(
                'team',
                fn ($team) => $team
                    ->whereNull('archived_at')
                    ->whereHas(
                        'department',
                        fn ($department) => $department
                            ->whereNull('archived_at')
                            ->where('organization_id', $organization->getKey()),
                    )
                    ->whereHas(
                        'memberships',
                        fn ($membership) => $membership
                            ->whereNull('archived_at')
                            ->whereHas(
                                'staff.organizationStatuses',
                                fn ($status) => $status
                                    ->where('organization_id', $organization->getKey())
                                    ->where('status', StaffOrganizationStatus::STATUS_ACTIVE),
                            ),
                    ),
            )
            ->exists();
    }
}
