<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Domain\Modules\ModuleKey;
use App\Domain\Permissions\PermissionCatalog;
use App\Models\DocumentFragment;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\TeamMembership;
use App\Services\Offline\Concerns\ComposesDocumentSections;
use App\Services\Offline\Concerns\ShapesOfflineRows;

/**
 * The designated shift lead's cache list (TEAM-009; technical spec 9.3, 11A.7).
 *
 * The roster of the teams they lead, the shifts those teams are eligible for,
 * and who is assigned to them — what `shift_lead_cache` replicated, composed
 * through the resolver instead.
 *
 * **The designation is the whole of the scope.** A `shift_lead` grant is held by
 * the team and exercised by the members designated `membership_role = 'lead'`,
 * and {@see \App\Services\Permissions\EffectiveRoleResolver} is where that rule
 * lives. This class asks for the caller's `shift_lead` grants and composes from
 * whatever comes back, so an undesignated member of a granted team receives
 * nothing here by never producing a grant — not by a condition this class
 * remembers to apply. That is the difference M16.13 found the hard way: the sync
 * rules had scoped by bare team membership, which would have replicated a lead's
 * roster to every member of a granted team.
 *
 * Staff rows carry name and handle. A lead needs to call people by the name they
 * answer to on the radio; they do not need anybody's date of birth, and the M8.2
 * exclusions hold for a roster exactly as they hold for the caller's own record.
 */
final class ShiftLeadSections implements OfflineReadSetContributor
{
    use ComposesDocumentSections;
    use ShapesOfflineRows;

    /**
     * @return list<OfflineReadSetSection>
     */
    public function sectionsFor(OfflineReadSetScope $scope): array
    {
        $scopes = $scope->teamScopesFor(PermissionCatalog::ROLE_SHIFT_LEAD);

        if ($scopes === []) {
            return [];
        }

        $teamIds = array_values(array_unique(array_column($scopes, 'team_id')));

        $shifts = [];
        $assignments = [];

        foreach ($scopes as $led) {
            $eventShifts = $this->shifts($led['event_id'], $led['team_id']);

            $shifts = [...$shifts, ...$eventShifts];
            $assignments = [...$assignments, ...$this->assignments(array_column($eventShifts, 'id'))];
        }

        $memberships = $this->memberships($teamIds);

        return [
            OfflineReadSetSection::core('shift_lead_team_memberships', $memberships),
            OfflineReadSetSection::core('shift_lead_staff', $this->roster(
                array_column($memberships, 'staff_id'),
                array_column($assignments, 'staff_id'),
            )),

            OfflineReadSetSection::owned('shift_lead_shifts', ModuleKey::Scheduling, $this->distinct($shifts)),
            OfflineReadSetSection::owned('shift_lead_shift_assignments', ModuleKey::Scheduling, $this->distinct($assignments)),

            /*
             * A designated team lead is the maintainer of their own team's
             * documents (`DocumentProductAccess::canMaintainScope`), so they hold
             * drafts and archived revisions of them as well as the published ones
             * every member already holds. This is the team half of technical spec
             * 9.3's "documents and fragments they are allowed to maintain"; the
             * department half is a department lead's.
             */
            ...$this->maintainableDocumentSections('shift_lead', DocumentFragment::SCOPE_TEAM, $teamIds),
        ];
    }

    /**
     * @return list<DeferredOfflineReadSetSection>
     */
    public function deferredFor(OfflineReadSetScope $scope): array
    {
        return [];
    }

    /**
     * The shifts a led team is eligible for, at one event.
     *
     * The event is the grant's, resolved before this class is reached: an
     * event-scoped grant produced one scope and reaches only that event's
     * shifts, which is the narrowing the retired sync-rule tests asserted and
     * `OfflineReadSetRoleScopesTest` now asserts against the endpoint.
     *
     * @return list<array<string, mixed>>
     */
    private function shifts(string $eventId, string $teamId): array
    {
        return $this->rows(
            Shift::query()
                ->where('event_id', $eventId)
                ->where('eligible_team_id', $teamId)
                ->orderBy('starts_at')
                ->orderBy('id'),
            fn (Shift $shift): array => [
                'id' => (string) $shift->getKey(),
                'event_id' => (string) $shift->event_id,
                'department_id' => (string) $shift->department_id,
                'eligible_team_id' => $shift->eligible_team_id === null
                    ? null
                    : (string) $shift->eligible_team_id,
                'title' => $shift->title,
                'department_name_snapshot' => $shift->department_name_snapshot,
                'team_name_snapshot' => $shift->team_name_snapshot,
                'starts_at' => $this->moment($shift->starts_at),
                'ends_at' => $this->moment($shift->ends_at),
                'capacity' => $shift->capacity,
                'signup_opens_at' => $this->moment($shift->signup_opens_at),
                'signup_closes_at' => $this->moment($shift->signup_closes_at),
                'schedule_lock_at' => $this->moment($shift->schedule_lock_at),
                'cancelled_at' => $this->moment($shift->cancelled_at),
            ],
        );
    }

    /**
     * @param  list<string>  $shiftIds
     * @return list<array<string, mixed>>
     */
    private function assignments(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        return $this->rows(
            ShiftAssignment::query()
                ->whereIn('shift_id', $shiftIds)
                ->whereNull('removed_at')
                ->orderBy('id'),
            fn (ShiftAssignment $assignment): array => [
                'id' => (string) $assignment->getKey(),
                'shift_id' => (string) $assignment->shift_id,
                'staff_id' => (string) $assignment->staff_id,
                'assignment_status' => $assignment->assignment_status,
            ],
        );
    }

    /**
     * @param  list<string>  $teamIds
     * @return list<array<string, mixed>>
     */
    private function memberships(array $teamIds): array
    {
        return $this->rows(
            TeamMembership::query()
                ->active()
                ->whereIn('team_id', $teamIds)
                ->orderBy('id'),
            fn (TeamMembership $membership): array => [
                'id' => (string) $membership->getKey(),
                'team_id' => (string) $membership->team_id,
                'staff_id' => (string) $membership->staff_id,
                'membership_role' => $membership->membership_role,
            ],
        );
    }

    /**
     * The people on the led teams, plus anybody assigned to their shifts.
     *
     * The second list is not the first: somebody added to a shift from the
     * Logistics desk (SLB-008) is on the roster the lead is running without
     * being on the team, and a lead who could not name them would be reading a
     * shift with an unlabelled row on it.
     *
     * @param  list<string>  $memberStaffIds
     * @param  list<string>  $assignedStaffIds
     * @return list<array<string, mixed>>
     */
    private function roster(array $memberStaffIds, array $assignedStaffIds): array
    {
        $staffIds = array_values(array_unique([...$memberStaffIds, ...$assignedStaffIds]));

        if ($staffIds === []) {
            return [];
        }

        return $this->rows(
            Staff::query()->whereKey($staffIds)->orderBy('id'),
            fn (Staff $member): array => [
                'id' => (string) $member->getKey(),
                'legal_name' => $member->legal_name,
                'preferred_name' => $member->preferred_name,
                'handle' => $member->handle,
                'archived_at' => $this->moment($member->archived_at),
            ],
        );
    }
}
