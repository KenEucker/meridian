<?php

namespace App\Services\Events;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IncidentCommandDepartmentSelectionService
{
    public function __construct(private readonly AuditService $audit) {}

    public function configureOrganizationDefault(
        Organization $organization,
        ?Department $department,
        ?User $actor = null,
        string $sourceContext = AuditEvent::SOURCE_SYSTEM,
    ): Organization {
        if ($department !== null) {
            $this->assertDepartmentBelongsToOrganization($department, $organization);
            $this->assertDepartmentIsActive($department);
        }

        return DB::transaction(function () use ($organization, $department, $actor, $sourceContext): Organization {
            $beforeDepartmentId = $organization->default_ic_department_id;
            $afterDepartmentId = $department?->id;

            if ((string) $beforeDepartmentId === (string) $afterDepartmentId) {
                return $organization;
            }

            $organization->forceFill([
                'default_ic_department_id' => $afterDepartmentId,
            ])->save();

            $this->audit->recordForEntity(
                entity: $organization,
                action: 'organization.default_ic_department_changed',
                actorUser: $actor,
                organizationId: $organization->id,
                departmentId: $afterDepartmentId,
                before: ['default_ic_department_id' => $beforeDepartmentId],
                after: ['default_ic_department_id' => $afterDepartmentId],
                sourceContext: $sourceContext,
            );

            return $organization->refresh();
        });
    }

    public function configureEventOverride(
        Event $event,
        ?Department $department,
        ?User $actor = null,
        string $sourceContext = AuditEvent::SOURCE_SYSTEM,
    ): Event {
        $event->loadMissing('organization');

        if ($department !== null) {
            $this->assertDepartmentBelongsToOrganization($department, $event->organization);
            $this->assertDepartmentIsActive($department);
            $this->assertDepartmentIsActivelyAssignedToEvent($department, $event);
        }

        return DB::transaction(function () use ($event, $department, $actor, $sourceContext): Event {
            $beforeDepartmentId = $event->ic_department_id;
            $afterDepartmentId = $department?->id;

            if ((string) $beforeDepartmentId === (string) $afterDepartmentId) {
                return $event;
            }

            $event->forceFill([
                'ic_department_id' => $afterDepartmentId,
            ])->save();

            $this->audit->recordForEntity(
                entity: $event,
                action: 'event.ic_department_changed',
                actorUser: $actor,
                organizationId: $event->organization_id,
                eventId: $event->id,
                departmentId: $afterDepartmentId,
                before: ['ic_department_id' => $beforeDepartmentId],
                after: ['ic_department_id' => $afterDepartmentId],
                sourceContext: $sourceContext,
            );

            return $event->refresh();
        });
    }

    public function effectiveDepartment(Event $event): ?Department
    {
        $event->loadMissing(['icDepartment', 'organization.defaultIcDepartment']);

        return $event->icDepartment ?? $event->organization?->defaultIcDepartment;
    }

    private function assertDepartmentBelongsToOrganization(Department $department, ?Organization $organization): void
    {
        if ($organization === null || (string) $department->organization_id !== (string) $organization->id) {
            throw new InvalidArgumentException('Incident Command department must belong to the selected organization.');
        }
    }

    private function assertDepartmentIsActive(Department $department): void
    {
        if ($department->isArchived()) {
            throw new InvalidArgumentException('Incident Command department must be an active department.');
        }
    }

    private function assertDepartmentIsActivelyAssignedToEvent(Department $department, Event $event): void
    {
        $isAssigned = EventDepartmentAssignment::query()
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->whereNull('archived_at')
            ->exists();

        if (! $isAssigned) {
            throw new InvalidArgumentException('Incident Command department must be actively assigned to the event.');
        }
    }
}
