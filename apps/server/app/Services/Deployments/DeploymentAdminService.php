<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Models\AuditEvent;
use App\Models\CurrentDeploymentAssignment;
use App\Models\Department;
use App\Models\Deployment;
use App\Models\Event;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Node\EventAuthorityException;
use App\Services\Node\EventScopedWriteGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Product-path administration of a department's deployment options (M18.30;
 * SLB-009, SLB-010; data/API section 10.14).
 *
 * `deployments` has existed since M10.8 and the Operations Center has read it
 * since M16.21, but nothing outside a factory ever wrote a row. An organization
 * running its first event had a deployment module with an empty list and no way
 * to fill it, which made SLB-009's "shall always include a deployment/location
 * module" true and useless at the same time. This is the way.
 *
 * Three rules shape what is allowed, and all three come from what a deployment
 * is used for rather than from what the table looks like.
 *
 *  1. **Archive, never delete.** `current_deployment_assignments` points at a
 *     deployment with a restricting foreign key, and a location a department
 *     stopped using is still where somebody was standing. Archiving takes it
 *     off the assignable list and leaves the history intact.
 *  2. **A deployment holding people cannot be archived.** Those staff are
 *     standing somewhere right now, and taking the option away would leave the
 *     Operations Center naming a place it can no longer offer. The refusal says
 *     how many people are there, because the next thing the operator has to do
 *     is move them.
 *  3. **Names are unique per event and department, whatever their casing.**
 *     Two spellings of one gate are two rows an operator has to choose between
 *     over the radio, and the wrong one is not a visible mistake.
 *
 * Deployments are event-scoped records, so {@see EventScopedWriteGuard}
 * already refuses these writes on a node that does not hold event authority.
 * That is not restated here: the guard exists precisely so each write path does
 * not have to remember it, and a second check would be a second place to get it
 * wrong.
 */
final class DeploymentAdminService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @throws DeploymentAdminException
     * @throws EventAuthorityException
     */
    public function create(
        Event $event,
        Department $department,
        string $name,
        ?string $description,
        ?string $locationDetails,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Deployment {
        $name = $this->validName($name);
        $description = $this->validText($description, 'description');
        $locationDetails = $this->validText($locationDetails, 'location details');

        $this->assertNameAvailable($event, $department, $name);

        return DB::transaction(function () use (
            $event,
            $department,
            $name,
            $description,
            $locationDetails,
            $actor,
            $sourceContext,
        ): Deployment {
            $deployment = Deployment::query()->create([
                'event_id' => (string) $event->getKey(),
                'department_id' => (string) $department->getKey(),
                'name' => $name,
                'description' => $description,
                'location_details' => $locationDetails,
            ]);

            $this->audit->recordForEntity(
                entity: $deployment,
                action: 'deployment.created',
                actorUser: $actor,
                organizationId: (string) $event->organization_id,
                eventId: (string) $event->getKey(),
                departmentId: (string) $department->getKey(),
                after: $this->snapshot($deployment),
                sourceContext: $sourceContext,
            );

            return $deployment->refresh();
        });
    }

    /**
     * Rename and re-describe in place.
     *
     * Nothing carries a copy of the name — a current assignment names the row
     * rather than the label — so renaming the gate an operator has been calling
     * "North" moves every reference to it at once, which is the point.
     *
     * @throws DeploymentAdminException
     * @throws EventAuthorityException
     */
    public function update(
        Deployment $deployment,
        string $name,
        ?string $description,
        ?string $locationDetails,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Deployment {
        $event = $this->eventFor($deployment);
        $department = $this->departmentFor($deployment);

        $name = $this->validName($name);
        $description = $this->validText($description, 'description');
        $locationDetails = $this->validText($locationDetails, 'location details');

        $this->assertNameAvailable($event, $department, $name, $deployment);

        return DB::transaction(function () use (
            $deployment,
            $event,
            $department,
            $name,
            $description,
            $locationDetails,
            $actor,
            $sourceContext,
        ): Deployment {
            $before = $this->snapshot($deployment);

            $deployment->forceFill([
                'name' => $name,
                'description' => $description,
                'location_details' => $locationDetails,
            ])->save();

            $after = $this->snapshot($deployment->refresh());

            if ($before !== $after) {
                $this->audit->recordForEntity(
                    entity: $deployment,
                    action: 'deployment.updated',
                    actorUser: $actor,
                    organizationId: (string) $event->organization_id,
                    eventId: (string) $event->getKey(),
                    departmentId: (string) $department->getKey(),
                    before: $before,
                    after: $after,
                    sourceContext: $sourceContext,
                );
            }

            return $deployment;
        });
    }

    /**
     * @throws DeploymentAdminException
     * @throws EventAuthorityException
     */
    public function archive(
        Deployment $deployment,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Deployment {
        if ($deployment->isArchived()) {
            throw DeploymentAdminException::invalid('This deployment is already archived.');
        }

        $assigned = $this->currentAssignmentCount($deployment);

        if ($assigned > 0) {
            throw DeploymentAdminException::invalid(sprintf(
                $assigned === 1
                    ? 'One staff member is currently deployed here. Move them to another deployment before archiving this one.'
                    : '%d staff members are currently deployed here. Move them to another deployment before archiving this one.',
                $assigned,
            ));
        }

        return $this->setArchivedAt($deployment, Carbon::now(), 'deployment.archived', $actor, $sourceContext);
    }

    /**
     * @throws DeploymentAdminException
     * @throws EventAuthorityException
     */
    public function restore(
        Deployment $deployment,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Deployment {
        if (! $deployment->isArchived()) {
            throw DeploymentAdminException::invalid('This deployment is not archived.');
        }

        // A name freed up while this one was archived may have been taken since.
        $this->assertNameAvailable(
            $this->eventFor($deployment),
            $this->departmentFor($deployment),
            (string) $deployment->name,
            $deployment,
        );

        return $this->setArchivedAt($deployment, null, 'deployment.restored', $actor, $sourceContext);
    }

    /**
     * How many staff are standing at a deployment right now, for the archive
     * refusal.
     */
    public function currentAssignmentCount(Deployment $deployment): int
    {
        $id = (string) $deployment->getKey();

        return $this->currentAssignmentCounts([$id])[$id] ?? 0;
    }

    /**
     * The same count for a page of deployments, in one query.
     *
     * The surface's usage note and the archive refusal read it from here so the
     * two cannot disagree about whether archiving an option would strand
     * somebody standing at it.
     *
     * @param  list<mixed>  $deploymentIds
     * @return array<string, int>
     */
    public function currentAssignmentCounts(array $deploymentIds): array
    {
        if ($deploymentIds === []) {
            return [];
        }

        return CurrentDeploymentAssignment::query()
            ->whereIn('deployment_id', $deploymentIds)
            ->selectRaw('deployment_id, count(*) as assigned')
            ->groupBy('deployment_id')
            ->get()
            ->mapWithKeys(static fn ($row): array => [
                (string) $row->deployment_id => (int) $row->assigned,
            ])
            ->all();
    }

    /**
     * @throws EventAuthorityException
     */
    private function setArchivedAt(
        Deployment $deployment,
        ?Carbon $archivedAt,
        string $action,
        User $actor,
        string $sourceContext,
    ): Deployment {
        $event = $this->eventFor($deployment);
        $department = $this->departmentFor($deployment);

        return DB::transaction(function () use (
            $deployment,
            $archivedAt,
            $action,
            $actor,
            $event,
            $department,
            $sourceContext,
        ): Deployment {
            $before = $this->snapshot($deployment);

            $deployment->forceFill(['archived_at' => $archivedAt])->save();

            $this->audit->recordForEntity(
                entity: $deployment,
                action: $action,
                actorUser: $actor,
                organizationId: (string) $event->organization_id,
                eventId: (string) $event->getKey(),
                departmentId: (string) $department->getKey(),
                before: $before,
                after: $this->snapshot($deployment->refresh()),
                sourceContext: $sourceContext,
            );

            return $deployment;
        });
    }

    private function validName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw DeploymentAdminException::invalid('Deployment name is required.');
        }

        if (mb_strlen($name) > 100) {
            throw DeploymentAdminException::invalid(
                'Deployment name may not be greater than 100 characters.',
            );
        }

        return $name;
    }

    /**
     * Free text, trimmed, with an empty string stored as absent.
     *
     * A description somebody cleared should read as "none written" rather than
     * as a blank one, since the surface renders the two differently.
     */
    private function validText(?string $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 2000) {
            throw DeploymentAdminException::invalid(sprintf(
                'Deployment %s may not be greater than 2000 characters.',
                $label,
            ));
        }

        return $value;
    }

    /**
     * Refuse a name this department already uses at this event, whatever its
     * casing. Archived rows are included: the row still holds the name, and a
     * restore later has to land somewhere.
     */
    private function assertNameAvailable(
        Event $event,
        Department $department,
        string $name,
        ?Deployment $ignore = null,
    ): void {
        $query = Deployment::query()
            ->where('event_id', (string) $event->getKey())
            ->where('department_id', (string) $department->getKey())
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)]);

        if ($ignore !== null) {
            $query->whereKeyNot($ignore->getKey());
        }

        if ($query->exists()) {
            throw DeploymentAdminException::invalid(sprintf(
                'This department already has a deployment named %s at this event.',
                $name,
            ));
        }
    }

    private function eventFor(Deployment $deployment): Event
    {
        $deployment->loadMissing('event');
        $event = $deployment->event;

        if (! $event instanceof Event) {
            throw DeploymentAdminException::invalid('This deployment has no event.');
        }

        return $event;
    }

    private function departmentFor(Deployment $deployment): Department
    {
        $deployment->loadMissing('department');
        $department = $deployment->department;

        if (! $department instanceof Department) {
            throw DeploymentAdminException::invalid('This deployment has no department.');
        }

        return $department;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Deployment $deployment): array
    {
        return [
            'id' => (string) $deployment->getKey(),
            'event_id' => (string) $deployment->event_id,
            'department_id' => (string) $deployment->department_id,
            'name' => $deployment->name,
            'description' => $deployment->description,
            'location_details' => $deployment->location_details,
            'archived_at' => $deployment->archived_at?->toIso8601String(),
        ];
    }
}
