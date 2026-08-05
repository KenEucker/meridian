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
 * organizer cannot either. At this milestone only the two IC roles hold it;
 * `department_operator` waits on M18.10A, which owns the Operator capability
 * set (requirements 4.8A; TEAM-012).
 *
 * **For whom?** FR-017: "staff the creating user is already permitted to see",
 * and no wider. One rule answers it for every holder, because taking a report
 * is a console function and a console serves the department it sits in:
 * the active membership of the department the granting team belongs to.
 * Requirements 4.8A says it in as many words — "on behalf of another staff
 * member of the department" — so a console in Rangers discloses nobody in Gate.
 *
 * An IC role resolves only through the event's Incident Command Department
 * (TEAM-012A), which is that department and not a fourth one standing beside
 * the others: the designation is set per event and defaults at the
 * organization, and it lands on an ordinary operational department. So an
 * `ic_operator` reaches the staff of whichever department is carrying it. What
 * the IC designation adds is incident authority on top of a console, not a
 * wider roster for it, and every other department's radio watch reaches its own
 * people through its own Operator designation rather than through this one.
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
        return $this->reachableDepartmentIds($user, $event) !== [];
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
        return $this->staffInDepartments(
            $this->reachableDepartmentIds($user, $event),
            [$author->id],
        )->isNotEmpty();
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
     * The departments this user's taking authority reaches for this event, empty
     * when they hold none.
     *
     * Authority reaches a staff member through the team that carries the grant
     * (TEAM-010), so the department that team belongs to is the department the
     * console serves — requirements 4.8A, "another staff member of the
     * department". One reading for every holder of the capability: an IC role
     * resolves only through the event's Incident Command Department (TEAM-012A)
     * and so reaches that department, and M18.10A adds the per-department
     * Operator as a second holder of the same rule rather than a different one.
     *
     * @return list<string>
     */
    private function reachableDepartmentIds(User $user, Event $event): array
    {
        $departmentIds = [];

        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff, $event) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_FIELD_REPORTS_CREATE_ON_BEHALF,
                )) {
                    continue;
                }

                $team = Team::query()->find($role->teamId);

                if ($team?->department_id !== null) {
                    $departmentIds[] = (string) $team->department_id;
                }
            }
        }

        return array_values(array_unique($departmentIds));
    }

    public function displayName(Staff $staff): string
    {
        return $staff->preferred_name !== null && $staff->preferred_name !== ''
            ? $staff->preferred_name
            : (string) $staff->legal_name;
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
