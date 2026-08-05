<?php

namespace App\Services\FieldReports;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;
use Illuminate\Support\Collection;

/**
 * Who may take a Field Report for somebody else, and for whom (M18.24A;
 * FR-015, FR-016, FR-017; requirements 4.8A; technical spec 17.3, 17.4).
 *
 * The client has had a dictation surface since M18.9. It had no requirement
 * behind it, no server authorization, and a staff picker that offered whoever
 * the department read happened to return. This is the server side it was
 * missing, and it answers two separate questions rather than one.
 *
 * **May this user take reports at all?** `field_reports.create_on_behalf`, which
 * FR-015 puts in exactly three pairs of hands: a Department Operator, an
 * `ic_operator`, and an `ic_lead`. Nobody else, however senior — a department
 * lead cannot file a report under one of their staff members' names, and an
 * organizer cannot either.
 *
 * **For whom?** FR-017: "staff the creating user is already permitted to see",
 * and no wider. The two authorities reach different sets and it matters which:
 *
 *  - A Department Operator sits at their own department's console and takes
 *    reports from their own department's people. Requirements 4.8A says so in
 *    as many words — "on behalf of another staff member of the department" — so
 *    the set is the active membership of the departments where they hold the
 *    role, and holding it in Rangers discloses nobody in Gate.
 *  - An `ic_operator` or `ic_lead` works the event. They already read every
 *    Field Report in it (FR-005), so the set is the event's participating
 *    departments' staff. Narrowing them to one department would leave Incident
 *    Command unable to take a report from the person actually on the radio.
 *
 * The set is computed rather than filtered on the way out. A picker that lists
 * everybody and refuses on submit has already disclosed the roster, which is
 * the disclosure FR-017 exists to prevent.
 */
final class FieldReportOnBehalfAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    /**
     * Whether this user may take a Field Report for anybody in this event.
     */
    public function canTakeReports(User $user, Event $event): bool
    {
        return $this->scope($user, $event) !== null;
    }

    /**
     * Whether this user may take a Field Report naming this staff member as the
     * author.
     *
     * A refusal here reads the same whether the staff member is outside scope or
     * does not exist, which is the point: a caller must not be able to probe the
     * roster by watching which names produce a different answer.
     */
    public function canTakeReportFor(User $user, Event $event, Staff $author): bool
    {
        $scope = $this->scope($user, $event);

        if ($scope === null) {
            return false;
        }

        if ($scope['event_wide']) {
            return $this->staffInDepartments($this->eventDepartmentIds($event), [$author->id])->isNotEmpty();
        }

        return $this->staffInDepartments($scope['department_ids'], [$author->id])->isNotEmpty();
    }

    /**
     * Whether this report was taken rather than written (FR-015).
     *
     * True when the submitting user is not one of the author staff record's own
     * users. Derived rather than flagged: the two identifiers already on the
     * record say it, and a boolean beside them could disagree with them.
     */
    public function wasTakenOnBehalf(Staff $author, User $submitter): bool
    {
        return ! $author->users()->whereKey($submitter->getKey())->exists();
    }

    /**
     * The staff a picker may offer this user, sorted by display name.
     *
     * Empty for a user with no taking authority, which is the same answer as a
     * user whose departments hold nobody. Both are "there is nobody here for
     * you to name", and neither says why.
     *
     * @return Collection<int, Staff>
     */
    public function selectableStaff(User $user, Event $event): Collection
    {
        return $this->staffInDepartments($this->reachableDepartmentIds($user, $event))
            ->sortBy(fn (Staff $staff): string => mb_strtolower($this->displayName($staff)))
            ->values();
    }

    /**
     * The picker's rows: an id, a name, and just enough to tell two similar
     * names apart.
     *
     * Deliberately narrow. A dictation picker needs to identify a person, and
     * anything wider than that is a staff directory reachable from a Field
     * Report form — which is how a surface that respects its scope still ends
     * up disclosing more than the scope was about.
     *
     * @return list<array{staff_id: string, display_name: string, handle: string|null, department_label: string|null}>
     */
    public function selectableStaffOptions(User $user, Event $event): array
    {
        $departmentIds = $this->reachableDepartmentIds($user, $event);

        if ($departmentIds === []) {
            return [];
        }

        $departmentNames = Department::query()
            ->whereIn('id', $departmentIds)
            ->pluck('name', 'id');

        return $this->staffInDepartments($departmentIds)
            ->load(['departmentMemberships' => fn ($query) => $query->active()->whereIn('department_id', $departmentIds)])
            ->sortBy(fn (Staff $staff): string => mb_strtolower($this->displayName($staff)))
            ->map(function (Staff $staff) use ($departmentNames): array {
                $membership = $staff->departmentMemberships->first();

                return [
                    'staff_id' => (string) $staff->id,
                    'display_name' => $this->displayName($staff),
                    'handle' => $staff->handle,
                    'department_label' => $membership === null
                        ? null
                        : ($departmentNames[$membership->department_id] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function reachableDepartmentIds(User $user, Event $event): array
    {
        $scope = $this->scope($user, $event);

        if ($scope === null) {
            return [];
        }

        return $scope['event_wide']
            ? $this->eventDepartmentIds($event)
            : $scope['department_ids'];
    }

    public function displayName(Staff $staff): string
    {
        return $staff->preferred_name !== null && $staff->preferred_name !== ''
            ? $staff->preferred_name
            : (string) $staff->legal_name;
    }

    /**
     * How far this user's taking authority reaches for this event, or null when
     * they hold none.
     *
     * `event_wide` is the Incident Command answer; `department_ids` is the
     * Department Operator one. A user holding both gets the wider of the two,
     * because a person who may already read the whole event's Field Reports is
     * not disclosed anything by a picker that lists it.
     *
     * @return array{event_wide: bool, department_ids: list<string>}|null
     */
    private function scope(User $user, Event $event): ?array
    {
        $eventWide = false;
        $departmentIds = [];

        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff, $event) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_FIELD_REPORTS_CREATE_ON_BEHALF,
                )) {
                    continue;
                }

                if (in_array($role->roleCode, [
                    PermissionCatalog::ROLE_IC_OPERATOR,
                    PermissionCatalog::ROLE_IC_LEAD,
                ], true)) {
                    $eventWide = true;

                    continue;
                }

                $team = Team::query()->find($role->teamId);

                if ($team?->department_id !== null) {
                    $departmentIds[] = (string) $team->department_id;
                }
            }
        }

        if (! $eventWide && $departmentIds === []) {
            return null;
        }

        return [
            'event_wide' => $eventWide,
            'department_ids' => array_values(array_unique($departmentIds)),
        ];
    }

    /**
     * The departments taking part in this event.
     *
     * Incident Command's reach is the event, and the event is the departments
     * assigned to it — not the whole organization, which would include a
     * department that is not working this event at all.
     *
     * @return list<string>
     */
    private function eventDepartmentIds(Event $event): array
    {
        return $event->departmentAssignments()
            ->whereNull('archived_at')
            ->pluck('department_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * Active staff holding an active membership in any of these departments.
     *
     * @param  list<string>  $departmentIds
     * @param  list<mixed>|null  $staffIds
     * @return Collection<int, Staff>
     */
    private function staffInDepartments(array $departmentIds, ?array $staffIds = null): Collection
    {
        if ($departmentIds === []) {
            return collect();
        }

        return Staff::query()
            ->whereHas('departmentMemberships', fn ($query) => $query
                ->active()
                ->whereIn('department_id', $departmentIds)
                ->where('status', DepartmentMembership::STATUS_ACTIVE))
            ->when($staffIds !== null, fn ($query) => $query->whereIn('id', $staffIds))
            ->get();
    }
}
