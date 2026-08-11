<?php

declare(strict_types=1);

namespace App\Services\Directory;

use App\Models\Department;
use App\Models\EventDepartmentAssignment;
use App\Models\HoursWorked;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Composes the Directory chart projection for one viewer (M18.73; DIR-006
 * through DIR-015, DIR-027 through DIR-030; technical spec 21E.3, 21E.6,
 * 21E.10).
 *
 * The projection is purpose-built (DIR-028): the person set is read with only
 * the columns the Directory presents — handle, profile picture, and the ids
 * that place people — so a legal name or an email address is never hydrated,
 * serialized, or stored to be omitted at render. That is the whole privacy
 * control, and it is why this service does not reuse a staff or membership
 * payload from another surface.
 *
 * Structure and people are separate answers. Departments and teams are drawn
 * from the organization's records whether or not the viewer may see anyone
 * inside them (DIR-015); who appears within them comes from the M18.71
 * visibility rule and nowhere else. The person set is resolved once and placed
 * many times (technical spec 21E.10), because repeated placement is ordinary
 * here rather than exceptional.
 *
 * Years of service settles technical spec open question 37 as decided with the
 * product owner: the count of distinct calendar years in which the person has
 * recorded hours in this organization. A gap year does not count, and a person
 * with no recorded hours reads zero.
 */
class DirectoryChartService
{
    public function __construct(private readonly DirectoryVisibilityResolver $visibility) {}

    /**
     * @return array{
     *     departments: list<array<string, mixed>>,
     *     people: list<array<string, mixed>>,
     * }
     */
    public function chart(User $viewer, DirectoryContext $context): array
    {
        $visibility = $this->visibility->resolve($viewer, $context);

        $departments = $this->scopeDepartments($context);
        $teams = $this->scopeTeams($departments);

        return [
            'departments' => $this->departmentsPayload($context, $departments, $teams, $visibility),
            'people' => $this->peoplePayload($context, $visibility),
        ];
    }

    /**
     * The departments the chart draws (DIR-009, DIR-015): the organization's
     * active departments, narrowed in event context to the ones actively
     * assigned to the event — the same scope the visibility rule's population
     * follows, so the chart never draws a department whose people were never
     * in the population.
     *
     * @return Collection<int, Department>
     */
    private function scopeDepartments(DirectoryContext $context): Collection
    {
        $query = Department::query()
            ->active()
            ->where('organization_id', $context->organization->getKey());

        if ($context->event !== null) {
            $query->whereIn('id', EventDepartmentAssignment::query()
                ->active()
                ->where('event_id', $context->event->getKey())
                ->select('department_id'));
        }

        $organizersDepartmentId = $context->organization->organizers_department_id !== null
            ? (string) $context->organization->organizers_department_id
            : null;

        /*
         * The Organizers Department first, then every other department
         * (DIR-009), alphabetically so two reads of unchanged data draw the
         * same chart.
         */
        return $query
            ->get(['id', 'name'])
            ->sortBy(fn (Department $department): array => [
                (string) $department->getKey() === $organizersDepartmentId ? 0 : 1,
                Str::lower($department->name),
                (string) $department->getKey(),
            ])
            ->values();
    }

    /**
     * Active teams per department, drawn whether or not anyone visible is in
     * them (DIR-015).
     *
     * @param  Collection<int, Department>  $departments
     * @return Collection<string, Collection<int, Team>> keyed by department id
     */
    private function scopeTeams(Collection $departments): Collection
    {
        if ($departments->isEmpty()) {
            return collect();
        }

        return Team::query()
            ->active()
            ->whereIn('department_id', $departments->map(
                fn (Department $department): string => (string) $department->getKey(),
            )->all())
            ->get(['id', 'department_id', 'name'])
            ->sortBy(fn (Team $team): array => [Str::lower($team->name), (string) $team->getKey()])
            ->groupBy(fn (Team $team): string => (string) $team->department_id);
    }

    /**
     * The tree: per department its heading, its department leads, its teams
     * with their leads and members, and its Prospectives section, in that
     * order (DIR-011, DIR-012, DIR-013).
     *
     * People appear as staff ids referencing the people list, resolved once
     * and placed many times (technical spec 21E.10).
     *
     * @param  Collection<int, Department>  $departments
     * @param  Collection<string, Collection<int, Team>>  $teams
     * @return list<array<string, mixed>>
     */
    private function departmentsPayload(
        DirectoryContext $context,
        Collection $departments,
        Collection $teams,
        DirectoryVisibility $visibility,
    ): array {
        $organizersDepartmentId = $context->organization->organizers_department_id !== null
            ? (string) $context->organization->organizers_department_id
            : null;

        $byDepartment = collect($visibility->placements)
            ->groupBy(fn (DirectoryPlacement $placement): string => $placement->departmentId);

        return $departments
            ->map(function (Department $department) use ($byDepartment, $teams, $organizersDepartmentId): array {
                $departmentId = (string) $department->getKey();
                $placements = $byDepartment->get($departmentId, collect());

                $teamsPayload = ($teams->get($departmentId) ?? collect())
                    ->map(function (Team $team) use ($placements): array {
                        $teamId = (string) $team->getKey();
                        $inTeam = $placements->filter(
                            fn (DirectoryPlacement $placement): bool => $placement->teamId === $teamId,
                        );

                        return [
                            'id' => $teamId,
                            'name' => $team->name,
                            'leads' => $this->staffIds($inTeam, DirectoryPlacement::KIND_TEAM_LEAD),
                            'members' => $this->staffIds($inTeam, DirectoryPlacement::KIND_TEAM_MEMBER),
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'id' => $departmentId,
                    'name' => $department->name,
                    'is_organizers' => $departmentId === $organizersDepartmentId,
                    'leads' => $this->staffIds($placements, DirectoryPlacement::KIND_DEPARTMENT_LEAD),
                    'teams' => $teamsPayload,
                    'prospectives' => $this->staffIds($placements, DirectoryPlacement::KIND_PROSPECTIVE),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int|string, DirectoryPlacement>  $placements
     * @return list<string>
     */
    private function staffIds(Collection $placements, string $kind): array
    {
        return $placements
            ->filter(fn (DirectoryPlacement $placement): bool => $placement->kind === $kind)
            ->map(fn (DirectoryPlacement $placement): string => $placement->staffId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The person set (DIR-028, DIR-029): profile picture reference, handle,
     * authorized locations, years of service, and nothing else. Only these
     * columns are selected, so no personally identifying field is hydrated to
     * be dropped later.
     *
     * @return list<array<string, mixed>>
     */
    private function peoplePayload(DirectoryContext $context, DirectoryVisibility $visibility): array
    {
        $staffIds = $visibility->staffIds();

        if ($staffIds === []) {
            return [];
        }

        $people = Staff::query()
            ->whereIn('id', $staffIds)
            ->get([
                'id',
                'handle',
                'profile_picture_path',
            ]);

        $yearsOfService = $this->yearsOfService($context, $staffIds);

        return $people
            ->sortBy(fn (Staff $staff): array => [
                Str::lower((string) $staff->handle),
                (string) $staff->getKey(),
            ])
            ->map(function (Staff $staff) use ($visibility, $yearsOfService): array {
                $staffId = (string) $staff->getKey();

                return [
                    'id' => $staffId,
                    'handle' => $staff->handle,
                    'profile_picture_url' => $staff->profilePictureUrl(),
                    'years_of_service' => $yearsOfService[$staffId] ?? 0,
                    'locations' => array_map(
                        fn (DirectoryPlacement $placement): array => [
                            'department_id' => $placement->departmentId,
                            'team_id' => $placement->teamId,
                            'kind' => $placement->kind,
                        ],
                        $visibility->placementsFor($staffId),
                    ),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Open question 37, as settled: distinct calendar years in which the
     * person has recorded hours within this organization's events. Counted
     * against the organization whichever context is resolved, because service
     * history belongs to the organization rather than to the event being
     * looked at.
     *
     * @param  list<string>  $staffIds
     * @return array<string, int>
     */
    private function yearsOfService(DirectoryContext $context, array $staffIds): array
    {
        $worked = HoursWorked::query()
            ->whereIn('staff_id', $staffIds)
            ->whereHas('event', fn ($event) => $event
                ->where('organization_id', $context->organization->getKey()))
            ->get(['staff_id', 'actual_started_at']);

        $years = [];

        foreach ($worked as $row) {
            if ($row->actual_started_at === null) {
                continue;
            }

            $years[(string) $row->staff_id][$row->actual_started_at->format('Y')] = true;
        }

        return array_map('count', $years);
    }
}
