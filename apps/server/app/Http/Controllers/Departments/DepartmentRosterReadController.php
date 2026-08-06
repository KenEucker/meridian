<?php

declare(strict_types=1);

namespace App\Http\Controllers\Departments;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Services\Departments\DepartmentRosterAccess;
use App\Services\Departments\DepartmentRosterScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * `department.roster` — the department staff list (M18.30; UI contract 12.4;
 * VOL-011, VOL-012).
 *
 * The department has had a staff list on the Admin page since M11.13, but that
 * one exists to assign people to teams: it carries a display name and a handle
 * and nothing anybody could call somebody with. This is the list a lead reads
 * to reach their department — names, teams, contact details, and, for the leads
 * VOL-012 names, emergency contacts.
 *
 * Everything the caller may not see is absent from the payload rather than
 * blanked in it. A blank emergency contact has to keep meaning "none recorded"
 * to the lead reading it, which is the same reason M13.3 adds the two columns
 * to the export file rather than writing them empty, and the two surfaces
 * resolve that question through the same population.
 *
 * The whole department comes back in one read and is filtered on the client.
 * A department roster is hundreds of rows at most, the surface is one a lead
 * opens standing in a field, and a search that needs the node is a search that
 * stops working exactly when it is most wanted — the same reasoning SLB-021
 * applies to the Logistics Desk.
 */
final class DepartmentRosterReadController extends Controller
{
    public function __invoke(
        Request $request,
        Event $event,
        Department $department,
        DepartmentRosterAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if ((string) $department->organization_id !== (string) $event->organization_id) {
            return response()->json(['message' => 'Department not found for this event.'], 404);
        }

        $scope = $access->resolve($user, $event, $department);

        if ($scope === null) {
            return response()->json([
                'message' => 'You do not have permission to view the staff list for this department.',
            ], 403);
        }

        $memberships = $this->membershipsInScope($department, $scope);
        $organizationStatuses = $this->organizationStatuses($event, $memberships);

        return response()->json([
            'context' => [
                'event_id' => (string) $event->getKey(),
                'event_label' => $event->name,
                'department_id' => (string) $department->getKey(),
                'department_label' => $department->name,
                /*
                 * Whether the department is actually working this event
                 * (ORG-006). Reported rather than enforced: department
                 * membership is an organization-level record, so the roster is
                 * the same list either way, and a lead who opened the page from
                 * the wrong event should be told that rather than shown an
                 * empty one.
                 */
                'participates_in_event' => EventDepartmentAssignment::query()
                    ->active()
                    ->where('event_id', $event->getKey())
                    ->where('department_id', $department->getKey())
                    ->exists(),
            ],
            'access' => $scope->toArray(),
            'teams' => $this->teamsPayload($department, $scope),
            'members' => $memberships
                ->map(fn (DepartmentMembership $membership): array => $this->member(
                    $membership,
                    $organizationStatuses[(string) $membership->staff_id] ?? null,
                    $scope->emergencyContacts,
                ))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Active department memberships in scope, ordered the way a printed call
     * list reads.
     *
     * Archived memberships are past association and are left out. Non-active
     * statuses are not: a lead has to be able to see that somebody on their
     * department's list is Inactive or Ineligible rather than wonder why they
     * are missing from it, which is the same call M13.3 made for the export.
     *
     * @return Collection<int, DepartmentMembership>
     */
    private function membershipsInScope(
        Department $department,
        DepartmentRosterScope $scope,
    ): Collection {
        $query = DepartmentMembership::query()
            ->active()
            ->where('department_id', $department->getKey())
            ->with(['staff', 'teamMemberships.team']);

        if (! $scope->wholeDepartment) {
            $query->whereHas(
                'teamMemberships',
                fn ($teamQuery) => $teamQuery->active()->whereIn('team_id', $scope->teamIds),
            );
        }

        return $query
            ->get()
            ->sortBy(fn (DepartmentMembership $membership): string => implode('|', [
                Str::lower((string) $this->displayName($membership->staff)),
                (string) $membership->getKey(),
            ]))
            ->values();
    }

    /**
     * Organization-level status per staff member, so a Do Not Staff or Inactive
     * person reads as such on their department's list.
     *
     * @param  Collection<int, DepartmentMembership>  $memberships
     * @return array<string, string>
     */
    private function organizationStatuses(Event $event, Collection $memberships): array
    {
        $staffIds = $memberships->pluck('staff_id')->unique()->all();

        if ($staffIds === []) {
            return [];
        }

        return StaffOrganizationStatus::query()
            ->where('organization_id', $event->organization_id)
            ->whereIn('staff_id', $staffIds)
            ->get(['staff_id', 'status'])
            ->mapWithKeys(static fn (StaffOrganizationStatus $status): array => [
                (string) $status->staff_id => (string) $status->status,
            ])
            ->all();
    }

    /**
     * The teams the roster may be filtered by — every active team in the
     * department for a whole-department reader, and only the led ones for a
     * team lead, so the filter offers nothing the list behind it would refuse.
     *
     * @return list<array{id: string, name: string, code: string, is_default: bool}>
     */
    private function teamsPayload(Department $department, DepartmentRosterScope $scope): array
    {
        $query = Team::query()
            ->active()
            ->where('department_id', $department->getKey())
            ->orderByDesc('is_default')
            ->orderBy('name');

        if (! $scope->wholeDepartment) {
            $query->whereIn('id', $scope->teamIds);
        }

        return $query
            ->get()
            ->map(static fn (Team $team): array => [
                'id' => (string) $team->getKey(),
                'name' => (string) $team->name,
                'code' => (string) $team->code,
                'is_default' => (bool) $team->is_default,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function member(
        DepartmentMembership $membership,
        ?string $organizationStatus,
        bool $withEmergencyContacts,
    ): array {
        $staff = $membership->staff;

        $row = [
            'membership_id' => (string) $membership->getKey(),
            'staff_id' => (string) $membership->staff_id,
            'display_name' => $this->displayName($staff),
            'legal_name' => (string) ($staff?->legal_name ?? ''),
            'preferred_name' => $staff?->preferred_name,
            'handle' => $staff?->handle,
            'email' => $staff?->email,
            'phone' => $staff?->phone,
            'city' => $staff?->city,
            'state' => $staff?->state,
            'membership_status' => (string) $membership->status,
            'organization_status' => $organizationStatus,
            'teams' => $this->teams($membership),
        ];

        if (! $withEmergencyContacts) {
            return $row;
        }

        return [
            ...$row,
            'emergency_contact_name' => $staff?->emergency_contact_name,
            'emergency_contact_phone' => $staff?->emergency_contact_phone,
        ];
    }

    /**
     * The staff member's current teams within this department, with the lead
     * designation, so a reader knows who to call first about a team.
     *
     * @return list<array{id: string, name: string, membership_role: string|null}>
     */
    private function teams(DepartmentMembership $membership): array
    {
        return $membership->teamMemberships
            ->reject(static fn (TeamMembership $teamMembership): bool => $teamMembership->isArchived())
            ->filter(static fn (TeamMembership $teamMembership): bool => $teamMembership->team !== null)
            ->map(static fn (TeamMembership $teamMembership): array => [
                'id' => (string) $teamMembership->team_id,
                'name' => (string) $teamMembership->team?->name,
                'membership_role' => $teamMembership->membership_role,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function displayName(?Staff $staff): string
    {
        if ($staff === null) {
            return '';
        }

        return (string) ($staff->preferred_name ?: $staff->legal_name);
    }
}
