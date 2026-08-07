<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Domain\Modules\ModuleKey;
use App\Domain\Permissions\PermissionCatalog;
use App\Models\Shift;
use App\Models\Team;
use App\Services\DepartmentOps\PlanVersusActual;
use App\Services\Offline\Concerns\ShapesOfflineRows;
use Illuminate\Support\Carbon;

/**
 * The Department Planning cache list of technical spec 9.3, composed for one
 * caller (SLB-019, SLB-020).
 *
 * "Identity-free plan-versus-actual aggregate rows by shift/team window.
 * Aggregate fields only: capacity target, signed-up/assigned count, checked-in
 * count, no-show count, unscheduled additions, planned hours, actual hours, and
 * variance/status. Explicit data-freshness metadata."
 *
 * **Identity-free is a property of the payload, not of the screen.** The Planning
 * Table renders no names, and the rows behind it carry none either: no staff id,
 * no assignment id, no signup list. A device that held the identities and
 * declined to draw them would be one inspector away from disclosing exactly what
 * SLB-019 exists to withhold.
 *
 * **The aggregates are computed rather than stored.** This is the one
 * role-additive list that is not rows-as-stored: a device cannot compute
 * plan-versus-actual from an identity-free set, because the counts are counts
 * *of* the identities it does not hold. So the node computes them, through
 * {@see PlanVersusActual} — the same arithmetic the Planning Table reads online,
 * so a planner comparing a cached table against a live one is comparing one
 * implementation of "planned hours" rather than two.
 *
 * Because they are computed, they have a freshness the stored sections do not,
 * and {@see OfflineReadSetSection::aggregate()} is what marks them so the set can
 * report it. It is reported in `readiness` rather than on the rows: a timestamp
 * on a row would enter the set's version, and a version that moved every time
 * the clock did would make every refresh a full transfer on the weakest
 * connection Meridian has.
 */
final class DepartmentPlanningSections implements OfflineReadSetContributor
{
    use ShapesOfflineRows;

    public const AGGREGATE_SECTION = 'planning_aggregates';

    public function __construct(private readonly PlanVersusActual $aggregates) {}

    /**
     * @return list<OfflineReadSetSection>
     */
    public function sectionsFor(OfflineReadSetScope $scope): array
    {
        $scopes = $scope->departmentScopesFor(PermissionCatalog::ROLE_DEPARTMENT_PLANNING);

        if ($scopes === []) {
            return [];
        }

        $now = Carbon::now();

        $rows = [];
        $teams = [];

        foreach ($scopes as $table) {
            $rows = [...$rows, ...$this->aggregateRows($table['event_id'], $table['department_id'], $now)];
            $teams = [...$teams, ...$this->teamOptions($table['department_id'])];
        }

        return [
            OfflineReadSetSection::aggregate(self::AGGREGATE_SECTION, ModuleKey::Scheduling, $this->distinct($rows)),
            /*
             * The team filter SLB-020 narrows the table by. Teams, not people:
             * naming a crew is not naming who is on it, and a filter is useless
             * without the list it filters over.
             */
            OfflineReadSetSection::owned('planning_teams', ModuleKey::Scheduling, $this->distinct($teams)),
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
     * The department's whole schedule for the event, not a desk horizon.
     *
     * Planning is the one surface that is about the shape of the event rather
     * than about the shift in front of somebody, and a table that stopped at
     * tomorrow could not show a coverage gap the week after.
     *
     * @return list<array<string, mixed>>
     */
    private function aggregateRows(string $eventId, string $departmentId, Carbon $now): array
    {
        $shifts = Shift::query()
            ->with('eligibleTeam')
            ->where('event_id', $eventId)
            ->where('department_id', $departmentId)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($this->aggregates->rows($shifts, $now) as $row) {
            $rows[] = [
                'id' => $row['shift_id'],
                'event_id' => $eventId,
                'department_id' => $departmentId,
                ...$row,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function teamOptions(string $departmentId): array
    {
        return $this->rows(
            Team::query()
                ->where('department_id', $departmentId)
                ->active()
                ->orderBy('name')
                ->orderBy('id'),
            fn (Team $team): array => [
                'id' => (string) $team->getKey(),
                'department_id' => (string) $team->department_id,
                'name' => $team->name,
            ],
        );
    }
}
