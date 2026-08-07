<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Domain\Modules\ModuleKey;
use App\Domain\Permissions\PermissionCatalog;
use App\Models\CurrentDeploymentAssignment;
use App\Models\Deployment;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Services\DepartmentOps\DeskHorizon;
use App\Services\Offline\Concerns\ShapesOfflineRows;
use Illuminate\Support\Carbon;

/**
 * The Department Operations cache list of technical spec 9.3, composed for one
 * caller (SLB-009, SLB-010, SLB-022).
 *
 * "Current and upcoming shift assignments for their department. Current
 * deployment/location assignment for those shifts. Active deployment/location
 * options. Capability-authorized overview module payloads only; the Operations
 * Center shell does not expand cache authority by itself."
 *
 * That last line is the one this class is shaped by. The Operations Center is a
 * shell that composes modules — Field Reports, incidents, equipment — from
 * capabilities the actor already holds elsewhere, so nothing here carries any of
 * them. A `department_operations` grant caches deployments and the assignments
 * they attach to, and reaches no further; the incident an operator can open on
 * that screen is one their own IC standing reaches, through its own list, and
 * incidents are not greedily cached in any case (technical spec 9.3).
 */
final class DepartmentOperationsSections implements OfflineReadSetContributor
{
    use ShapesOfflineRows;

    /**
     * @return list<OfflineReadSetSection>
     */
    public function sectionsFor(OfflineReadSetScope $scope): array
    {
        $scopes = $scope->departmentScopesFor(PermissionCatalog::ROLE_DEPARTMENT_OPERATIONS);

        if ($scopes === []) {
            return [];
        }

        $now = Carbon::now();

        $assignments = [];
        $deployments = [];
        $options = [];

        foreach ($scopes as $center) {
            $eventId = $center['event_id'];
            $departmentId = $center['department_id'];

            $shiftIds = $this->currentAndUpcomingShiftIds($eventId, $departmentId, $now);

            $assignments = [...$assignments, ...$this->assignments($shiftIds)];
            $deployments = [...$deployments, ...$this->currentDeployments($eventId, $departmentId, $shiftIds)];
            $options = [...$options, ...$this->deploymentOptions($eventId, $departmentId)];
        }

        return [
            OfflineReadSetSection::owned('operations_shift_assignments', ModuleKey::Scheduling, $this->distinct($assignments)),
            /*
             * Deployments belong to Event Geography: a deployment names a place
             * on the event's map, and data/API 5.9 gates `*-deployment*` commands
             * on that module. An organization not running it has no locations to
             * assign anybody to, so the Operations Center's one module has
             * nothing to show and the sections are absent rather than empty.
             */
            OfflineReadSetSection::owned('operations_deployment_options', ModuleKey::EventGeography, $this->distinct($options)),
            OfflineReadSetSection::owned('operations_current_deployments', ModuleKey::EventGeography, $this->distinct($deployments)),
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
     * The shifts an Operations Center may move people between: the ones running
     * now and the ones still to start inside the desk horizon.
     *
     * Not the ones already over. A deployment is where somebody is standing
     * right now (SLB-010), and reassigning a completed shift is not an operation
     * the screen offers.
     *
     * @return list<string>
     */
    private function currentAndUpcomingShiftIds(string $eventId, string $departmentId, Carbon $now): array
    {
        /** @var list<string> $ids */
        $ids = Shift::query()
            ->where('event_id', $eventId)
            ->where('department_id', $departmentId)
            ->active()
            ->where('ends_at', '>=', $now)
            ->where('starts_at', '<=', DeskHorizon::startsBefore($now))
            ->orderBy('starts_at')
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return $ids;
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
     * Where each assigned staff member is currently deployed.
     *
     * One row per shift and staff member: `DeploymentAssignmentService` keeps
     * exactly one current assignment for that pair (SLB-010; technical spec
     * 20.5), so the device holds the current answer rather than a history it
     * would have to reduce.
     *
     * @param  list<string>  $shiftIds
     * @return list<array<string, mixed>>
     */
    private function currentDeployments(string $eventId, string $departmentId, array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        return $this->rows(
            CurrentDeploymentAssignment::query()
                ->where('event_id', $eventId)
                ->where('department_id', $departmentId)
                ->whereIn('shift_id', $shiftIds)
                ->orderBy('id'),
            fn (CurrentDeploymentAssignment $assignment): array => [
                'id' => (string) $assignment->getKey(),
                'event_id' => (string) $assignment->event_id,
                'department_id' => (string) $assignment->department_id,
                'shift_id' => (string) $assignment->shift_id,
                'staff_id' => (string) $assignment->staff_id,
                'deployment_id' => (string) $assignment->deployment_id,
                'assigned_at' => $this->moment($assignment->assigned_at),
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deploymentOptions(string $eventId, string $departmentId): array
    {
        return $this->rows(
            Deployment::query()
                ->where('event_id', $eventId)
                ->where('department_id', $departmentId)
                ->active()
                ->orderBy('name')
                ->orderBy('id'),
            fn (Deployment $deployment): array => [
                'id' => (string) $deployment->getKey(),
                'event_id' => (string) $deployment->event_id,
                'department_id' => (string) $deployment->department_id,
                'name' => $deployment->name,
                'description' => $deployment->description,
                'location_details' => $deployment->location_details,
                /*
                 * The map location this deployment names, as an identifier only.
                 * The map package is its own cache list in technical spec 9.3
                 * with its own permission rules, and a sensitive layer must not
                 * reach a device through a deployment row.
                 */
                'map_location_id' => $deployment->map_location_id === null
                    ? null
                    : (string) $deployment->map_location_id,
            ],
        );
    }
}
