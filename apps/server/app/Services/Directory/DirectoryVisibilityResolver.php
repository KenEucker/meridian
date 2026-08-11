<?php

declare(strict_types=1);

namespace App\Services\Directory;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\DepartmentMembership;
use App\Models\EventDepartmentAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The one Directory visibility rule (M18.71; DIR-017 through DIR-026,
 * DIR-030; technical spec 21E.4, 21E.5).
 *
 * Given a viewer and a resolved context, it answers which staff members may be
 * returned and which of each person's chart locations may be returned. It is
 * resolved server-side and consumed by every path that returns Directory data
 * — the online chart read, the search, and the offline read set — because
 * three implementations of "who may see whom" is three chances to be wrong
 * about it (DIR-017).
 *
 * The rule is additive over the positions the viewer holds, each within its
 * own scope (DIR-022):
 *
 * - holding nothing: every organizer, department lead, and team lead, and no
 *   ordinary member (DIR-018);
 * - department lead of D: additionally, everything inside D (DIR-019);
 * - team lead of T: additionally, the members of T and nothing else in T's
 *   department (DIR-020);
 * - organizer: the whole population (DIR-021).
 *
 * Positions come from the records that already exist rather than from a named
 * permission (DIR-024): organizer and department-lead standing resolve through
 * {@see EffectiveRoleResolver}-equivalent grant semantics, and team-lead
 * standing is the `membership_role = 'lead'` designation (M11.17) — the same
 * reading the Event Horizon coverage-gap items use for "a team the viewer
 * leads" (technical spec 21D.5). No other role widens anything (DIR-023):
 * Staff Coordinator, the IC roles, department Operators, organization owners,
 * and God Mode hold exactly ordinary-staff visibility here unless they
 * independently occupy one of the positions above.
 *
 * The status filter is applied here rather than by consumers, so exclusion
 * cannot be forgotten by a later one (DIR-025, DIR-026): an excluded status
 * removes the person even where a position would otherwise present them.
 *
 * The population comes from the context (technical spec 21E.2). Resolved to an
 * event, it is the active membership of the departments actively assigned to
 * that event — the same intersection the staff contact export draws, because
 * department membership is an organization-level record and an event chart
 * that ignored the assignment would list staff of departments not working the
 * event at all (DIR-006). Resolved to the organization, it is the active
 * membership of the organization's departments (DIR-007). Visibility and
 * status run after the population is chosen, never before it (DIR-008).
 */
class DirectoryVisibilityResolver
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    /**
     * Department membership statuses the Directory presents (DIR-025).
     *
     * @var list<string>
     */
    private const VISIBLE_DEPARTMENT_STATUSES = [
        DepartmentMembership::STATUS_ACTIVE,
        DepartmentMembership::STATUS_PROSPECTIVE,
        DepartmentMembership::STATUS_EMERITUS,
        DepartmentMembership::STATUS_RETIRED,
    ];

    /**
     * Organization statuses that exclude a staff member outright (DIR-025),
     * on the requirements 2.7 rule that organization status supersedes
     * department status. A staff member holding no organization status row is
     * carried by their department membership rather than excluded, the same
     * standing question the Event Horizon viewer resolver answers.
     *
     * @var list<string>
     */
    private const EXCLUDED_ORGANIZATION_STATUSES = [
        StaffOrganizationStatus::STATUS_INACTIVE,
        StaffOrganizationStatus::STATUS_DO_NOT_STAFF,
    ];

    public function resolve(User $viewer, DirectoryContext $context): DirectoryVisibility
    {
        $organizationId = (string) $context->organization->getKey();
        $organizersDepartmentId = $context->organization->organizers_department_id !== null
            ? (string) $context->organization->organizers_department_id
            : null;

        $departmentIds = $this->scopeDepartmentIds($context);

        if ($departmentIds === []) {
            return new DirectoryVisibility([]);
        }

        $teams = $this->scopeTeams($departmentIds);
        $memberships = $this->populationMemberships($departmentIds);
        $memberships = $this->withoutExcludedStaff($organizationId, $memberships);
        $teamMemberships = $this->populationTeamMemberships($memberships, $teams);
        $departmentLeadTeamIds = $this->departmentLeadTeamIds($context, $teams);

        $placements = $this->placements(
            $memberships,
            $teamMemberships,
            $teams,
            $departmentLeadTeamIds,
        );

        $authorized = $this->authorize($viewer, $context, $placements, $organizersDepartmentId);

        return new DirectoryVisibility($authorized);
    }

    /**
     * The departments whose membership is the population (technical spec
     * 21E.2): the organization's active departments, intersected in event
     * context with the active event assignment.
     *
     * @return list<string>
     */
    private function scopeDepartmentIds(DirectoryContext $context): array
    {
        $query = $context->organization->departments()->getQuery()->whereNull('archived_at');

        if ($context->event !== null) {
            $query->whereIn('id', EventDepartmentAssignment::query()
                ->active()
                ->where('event_id', $context->event->getKey())
                ->select('department_id'));
        }

        /** @var list<string> $ids */
        $ids = $query->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return $ids;
    }

    /**
     * Active teams of the scope departments, keyed by team id. Everything
     * placement composition needs to know about a team is its department.
     *
     * @param  list<string>  $departmentIds
     * @return Collection<string, Team>
     */
    private function scopeTeams(array $departmentIds): Collection
    {
        return Team::query()
            ->active()
            ->whereIn('department_id', $departmentIds)
            ->get(['id', 'department_id'])
            ->keyBy(fn (Team $team): string => (string) $team->getKey());
    }

    /**
     * The population: active department memberships in scope whose department
     * status the Directory presents (DIR-025), for staff records that still
     * exist.
     *
     * @param  list<string>  $departmentIds
     * @return Collection<int, DepartmentMembership>
     */
    private function populationMemberships(array $departmentIds): Collection
    {
        return DepartmentMembership::query()
            ->active()
            ->whereIn('department_id', $departmentIds)
            ->whereIn('status', self::VISIBLE_DEPARTMENT_STATUSES)
            ->whereHas('staff', fn (Builder $staff) => $staff->whereNull('archived_at'))
            ->get(['id', 'department_id', 'staff_id', 'status']);
    }

    /**
     * Drops every membership of a staff member whose organization status
     * excludes them (DIR-025, DIR-026). Applied to the whole population before
     * positions are considered, which is what makes exclusion win over
     * position: an Inactive department lead is not drawn because they are a
     * lead.
     *
     * @param  Collection<int, DepartmentMembership>  $memberships
     * @return Collection<int, DepartmentMembership>
     */
    private function withoutExcludedStaff(string $organizationId, Collection $memberships): Collection
    {
        $staffIds = $memberships->pluck('staff_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();

        if ($staffIds->isEmpty()) {
            return $memberships;
        }

        $excluded = StaffOrganizationStatus::query()
            ->where('organization_id', $organizationId)
            ->whereIn('staff_id', $staffIds->all())
            ->whereIn('status', self::EXCLUDED_ORGANIZATION_STATUSES)
            ->pluck('staff_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->flip();

        return $memberships
            ->reject(fn (DepartmentMembership $membership): bool => $excluded->has((string) $membership->staff_id))
            ->values();
    }

    /**
     * Active team memberships hanging off the population's department
     * memberships, restricted to the scope's active teams.
     *
     * @param  Collection<int, DepartmentMembership>  $memberships
     * @param  Collection<string, Team>  $teams
     * @return Collection<int, TeamMembership>
     */
    private function populationTeamMemberships(Collection $memberships, Collection $teams): Collection
    {
        if ($memberships->isEmpty() || $teams->isEmpty()) {
            return collect();
        }

        return TeamMembership::query()
            ->active()
            ->whereIn('department_membership_id', $memberships->map(
                fn (DepartmentMembership $membership): string => (string) $membership->getKey(),
            )->all())
            ->whereIn('team_id', $teams->keys()->all())
            ->get(['id', 'team_id', 'staff_id', 'department_membership_id', 'membership_role']);
    }

    /**
     * The teams whose active `department_lead` grant makes their members
     * department leads, in this context: an unscoped grant, or one scoped to
     * the context event (the same admission {@see EffectiveRoleResolver}
     * applies to a grant it resolves).
     *
     * @param  Collection<string, Team>  $teams
     * @return array<string, true> keyed by team id
     */
    private function departmentLeadTeamIds(DirectoryContext $context, Collection $teams): array
    {
        if ($teams->isEmpty()) {
            return [];
        }

        $grants = TeamGrant::query()
            ->active()
            ->whereIn('team_id', $teams->keys()->all())
            ->whereHas('permissionRole', fn (Builder $role) => $role
                ->where('code', PermissionCatalog::ROLE_DEPARTMENT_LEAD))
            ->where(function (Builder $query) use ($context): void {
                $query->whereNull('event_id');

                if ($context->event !== null) {
                    $query->orWhere('event_id', $context->event->getKey());
                }
            })
            ->pluck('team_id');

        $ids = [];

        foreach ($grants as $teamId) {
            $ids[(string) $teamId] = true;
        }

        return $ids;
    }

    /**
     * Every chart placement the population holds (DIR-011 through DIR-014),
     * before the viewer's positions decide which of them may be returned.
     *
     * Per department membership: a placement per active team membership — a
     * designated lead is a team lead and deliberately not also a member of the
     * same team (DIR-012) — a department-lead placement where any of those
     * teams carries the department-lead grant, and a Prospectives placement
     * where no team holds the person at all (DIR-013).
     *
     * @param  Collection<int, DepartmentMembership>  $memberships
     * @param  Collection<int, TeamMembership>  $teamMemberships
     * @param  Collection<string, Team>  $teams
     * @param  array<string, true>  $departmentLeadTeamIds
     * @return list<DirectoryPlacement>
     */
    private function placements(
        Collection $memberships,
        Collection $teamMemberships,
        Collection $teams,
        array $departmentLeadTeamIds,
    ): array {
        $teamMembershipsByDepartmentMembership = $teamMemberships->groupBy(
            fn (TeamMembership $teamMembership): string => (string) $teamMembership->department_membership_id,
        );

        $placements = [];

        foreach ($memberships as $membership) {
            $staffId = (string) $membership->staff_id;
            $departmentId = (string) $membership->department_id;
            $held = $teamMembershipsByDepartmentMembership->get((string) $membership->getKey(), collect());

            if ($held->isEmpty()) {
                $placements[] = new DirectoryPlacement(
                    staffId: $staffId,
                    departmentId: $departmentId,
                    teamId: null,
                    kind: DirectoryPlacement::KIND_PROSPECTIVE,
                    status: (string) $membership->status,
                );

                continue;
            }

            $isDepartmentLead = false;

            foreach ($held as $teamMembership) {
                $teamId = (string) $teamMembership->team_id;

                if (! $teams->has($teamId)) {
                    continue;
                }

                if (isset($departmentLeadTeamIds[$teamId])) {
                    $isDepartmentLead = true;
                }

                $placements[] = new DirectoryPlacement(
                    staffId: $staffId,
                    departmentId: $departmentId,
                    teamId: $teamId,
                    kind: $teamMembership->membership_role === 'lead'
                        ? DirectoryPlacement::KIND_TEAM_LEAD
                        : DirectoryPlacement::KIND_TEAM_MEMBER,
                    status: (string) $membership->status,
                );
            }

            if ($isDepartmentLead) {
                $placements[] = new DirectoryPlacement(
                    staffId: $staffId,
                    departmentId: $departmentId,
                    teamId: null,
                    kind: DirectoryPlacement::KIND_DEPARTMENT_LEAD,
                    status: (string) $membership->status,
                );
            }
        }

        return $this->sorted($placements);
    }

    /**
     * The additive union of what each of the viewer's positions grants, each
     * within its own scope (DIR-018 through DIR-023, DIR-030).
     *
     * @param  list<DirectoryPlacement>  $placements
     * @return list<DirectoryPlacement>
     */
    private function authorize(
        User $viewer,
        DirectoryContext $context,
        array $placements,
        ?string $organizersDepartmentId,
    ): array {
        $positions = $this->viewerPositions($viewer, $context);

        if ($positions['organizer']) {
            return $placements;
        }

        $authorized = [];

        foreach ($placements as $index => $placement) {
            if ($this->baselineAuthorizes($placement, $organizersDepartmentId)) {
                $authorized[$index] = $placement;

                continue;
            }

            if (isset($positions['ledDepartmentIds'][$placement->departmentId])) {
                $authorized[$index] = $placement;

                continue;
            }

            if ($placement->teamId !== null && isset($positions['ledTeamIds'][$placement->teamId])) {
                $authorized[$index] = $placement;
            }
        }

        return array_values($authorized);
    }

    /**
     * What every staff member sees regardless of position (DIR-018): the
     * organization's leadership. A department lead is visible as the
     * department lead they are, a team lead as the team lead they are, and an
     * organizer — whose standing is membership of the Organizers Department
     * (requirements 4.2) — in every location they hold within that department.
     *
     * What the baseline deliberately does not authorize is a leader's ordinary
     * locations elsewhere (DIR-030): Directory-visible as a team lead is not
     * Directory-visible as a member of the surrounding department's other
     * teams.
     */
    private function baselineAuthorizes(DirectoryPlacement $placement, ?string $organizersDepartmentId): bool
    {
        if ($placement->kind === DirectoryPlacement::KIND_DEPARTMENT_LEAD
            || $placement->kind === DirectoryPlacement::KIND_TEAM_LEAD) {
            return true;
        }

        return $organizersDepartmentId !== null
            && $placement->departmentId === $organizersDepartmentId;
    }

    /**
     * The positions this viewer holds (DIR-024): organizer standing and
     * department leadership resolved through {@see EffectiveRoleResolver},
     * the resolver every other API read already answers from — restricted to
     * the context organization, so standing somewhere else grants nothing
     * here — and team leadership through the `membership_role = 'lead'`
     * designation (M11.17), the same reading the Event Horizon uses for "a
     * team the viewer leads".
     *
     * @return array{
     *     organizer: bool,
     *     ledDepartmentIds: array<string, true>,
     *     ledTeamIds: array<string, true>,
     * }
     */
    private function viewerPositions(User $viewer, DirectoryContext $context): array
    {
        $organizationId = (string) $context->organization->getKey();

        $staff = $viewer->staffProfiles()->get();
        $staffIds = $staff->map(fn (Staff $profile): string => (string) $profile->getKey())->all();

        if ($staffIds === []) {
            return ['organizer' => false, 'ledDepartmentIds' => [], 'ledTeamIds' => []];
        }

        $roles = $staff->flatMap(
            fn (Staff $profile): Collection => $this->roles->resolveForStaff($profile, $context->event),
        );

        /*
         * A role is held over a team, and a team's department belongs to one
         * organization. The resolver answers about the viewer's teams wherever
         * they are, so without this check a login with standing in two
         * organizations would carry one organization's leadership into the
         * other's Directory — the same narrowing the offline scope resolver
         * applies to its role grants.
         */
        $roleTeams = Team::query()
            ->whereIn('id', $roles->map(fn ($role): string => $role->teamId)->unique()->values()->all())
            ->with('department')
            ->get()
            ->keyBy(fn (Team $team): string => (string) $team->getKey());

        $organizer = false;
        $ledDepartmentIds = [];

        foreach ($roles as $role) {
            $team = $roleTeams->get($role->teamId);

            if (! $team instanceof Team
                || $team->department === null
                || (string) $team->department->organization_id !== $organizationId) {
                continue;
            }

            if ($role->roleCode === PermissionCatalog::ROLE_ORGANIZER
                || $role->roleCode === PermissionCatalog::ROLE_LEAD_ORGANIZER) {
                $organizer = true;

                continue;
            }

            if ($role->roleCode === PermissionCatalog::ROLE_DEPARTMENT_LEAD) {
                $ledDepartmentIds[(string) $team->department_id] = true;
            }
        }

        $ledTeamIds = [];

        $designatedLeadTeamIds = TeamMembership::query()
            ->active()
            ->whereIn('staff_id', $staffIds)
            ->where('membership_role', 'lead')
            ->whereHas('team', fn (Builder $team) => $team
                ->active()
                ->whereHas('department', fn (Builder $department) => $department
                    ->whereNull('archived_at')
                    ->where('organization_id', $organizationId)))
            ->pluck('team_id');

        foreach ($designatedLeadTeamIds as $teamId) {
            $ledTeamIds[(string) $teamId] = true;
        }

        return [
            'organizer' => $organizer,
            'ledDepartmentIds' => $ledDepartmentIds,
            'ledTeamIds' => $ledTeamIds,
        ];
    }

    /**
     * Deterministic order, so two resolutions of unchanged data are the same
     * list: by department, department leads first, then teams, then the
     * Prospectives section, and by staff within each (DIR-011).
     *
     * @param  list<DirectoryPlacement>  $placements
     * @return list<DirectoryPlacement>
     */
    private function sorted(array $placements): array
    {
        $kindOrder = [
            DirectoryPlacement::KIND_DEPARTMENT_LEAD => 0,
            DirectoryPlacement::KIND_TEAM_LEAD => 1,
            DirectoryPlacement::KIND_TEAM_MEMBER => 2,
            DirectoryPlacement::KIND_PROSPECTIVE => 3,
        ];

        usort($placements, fn (DirectoryPlacement $a, DirectoryPlacement $b): int => [
            $a->departmentId,
            $a->teamId ?? '',
            $kindOrder[$a->kind],
            $a->staffId,
        ] <=> [
            $b->departmentId,
            $b->teamId ?? '',
            $kindOrder[$b->kind],
            $b->staffId,
        ]);

        return $placements;
    }
}
