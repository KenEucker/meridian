<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Which departments participate in an event (M18.31; ORG-006; PLACE-003;
 * data/API 10.2, 10.6).
 *
 * `event_department_assignments` has existed since M4 and decides a great deal:
 * which departments an applicant may express interest in, which departments a
 * session carries, who the Incident Command designation may name, and which
 * staff a contact export reaches. Nothing outside a seeder and a factory ever
 * wrote a row. An organization adding a department mid-season had no way to put
 * it in the event it was created for.
 *
 * Two rules govern removal, and both are the same rule read from opposite ends.
 * ORG-006 admits only a department assigned to the event as its Incident
 * Command, and PLACE-003 says the same of the Placement designation. Enforcing
 * them only when a designation is *made* would leave the other door open: the
 * department could be removed from the event afterwards and the designation
 * would survive, pointing at a department that no longer participates. So a
 * designated department is refused removal while it holds the designation, and
 * the refusal names which designation to clear first.
 *
 * Removal archives rather than deletes. A department that worked an event
 * worked it, and the shifts, hours, and incidents that name it are read through
 * rows that are still there; `archived_at` is what every other participation
 * reader already filters on.
 *
 * Re-adding a department restores its archived row rather than writing a second
 * one, because the table is unique on event and department and a "removed by
 * mistake" is the ordinary reason a department comes back.
 */
final class EventDepartmentParticipationService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Add a department to an event, or restore one that was removed.
     */
    public function assign(
        Event $event,
        Department $department,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): EventDepartmentAssignment {
        $this->assertSameOrganization($event, $department);

        if ($department->isArchived()) {
            throw EventAdministrationException::invalid(sprintf(
                '%s is archived, so it cannot be added to an event.',
                (string) $department->name,
            ));
        }

        return DB::transaction(function () use ($event, $department, $actor, $sourceContext): EventDepartmentAssignment {
            $assignment = EventDepartmentAssignment::query()
                ->where('event_id', $event->getKey())
                ->where('department_id', $department->getKey())
                ->first();

            if ($assignment !== null && ! $assignment->isArchived()) {
                return $assignment;
            }

            if ($assignment === null) {
                $assignment = EventDepartmentAssignment::query()->create([
                    'event_id' => $event->getKey(),
                    'department_id' => $department->getKey(),
                ]);
            } else {
                $assignment->forceFill(['archived_at' => null])->save();
            }

            $this->audit->recordForEntity(
                entity: $event,
                action: 'event.department_assigned',
                actorUser: $actor,
                organizationId: (string) $event->organization_id,
                eventId: (string) $event->getKey(),
                departmentId: (string) $department->getKey(),
                after: [
                    'department_id' => (string) $department->getKey(),
                    'department_name' => (string) $department->name,
                ],
                sourceContext: $sourceContext,
            );

            return $assignment->refresh();
        });
    }

    /**
     * Remove a department from an event, unless it holds a designation the
     * event still depends on (ORG-006, PLACE-003).
     */
    public function remove(
        Event $event,
        Department $department,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): void {
        $assignment = EventDepartmentAssignment::query()
            ->where('event_id', $event->getKey())
            ->where('department_id', $department->getKey())
            ->whereNull('archived_at')
            ->first();

        if ($assignment === null) {
            throw EventAdministrationException::invalid(sprintf(
                '%s does not participate in this event.',
                (string) $department->name,
            ));
        }

        $this->assertNotDesignated($event, $department);

        DB::transaction(function () use ($event, $department, $assignment, $actor, $sourceContext): void {
            $assignment->forceFill(['archived_at' => now()])->save();

            $this->audit->recordForEntity(
                entity: $event,
                action: 'event.department_removed',
                actorUser: $actor,
                organizationId: (string) $event->organization_id,
                eventId: (string) $event->getKey(),
                departmentId: (string) $department->getKey(),
                before: [
                    'department_id' => (string) $department->getKey(),
                    'department_name' => (string) $department->name,
                ],
                sourceContext: $sourceContext,
            );
        });
    }

    /**
     * Why this department cannot be removed from this event, or null when it
     * can be.
     *
     * One sentence for both the refusal and the read, so what the surface says
     * before the attempt and what the node says after it cannot come apart. A
     * department may hold both designations at once (PLACE-008); naming the
     * first is enough, because clearing it brings the reader back here to be
     * told about the second.
     */
    public function removalRefusal(Event $event, Department $department): ?string
    {
        $departmentId = (string) $department->getKey();

        $designation = match (true) {
            (string) $event->ic_department_id === $departmentId => 'Incident Command',
            (string) $event->placement_department_id === $departmentId => 'Placement',
            default => null,
        };

        if ($designation === null) {
            return null;
        }

        return sprintf(
            '%s runs %s for this event. Designate another department, or clear the designation, and then remove it.',
            (string) $department->name,
            $designation,
        );
    }

    private function assertNotDesignated(Event $event, Department $department): void
    {
        $refusal = $this->removalRefusal($event, $department);

        if ($refusal !== null) {
            throw EventAdministrationException::invalid($refusal);
        }
    }

    private function assertSameOrganization(Event $event, Department $department): void
    {
        if ((string) $department->organization_id !== (string) $event->organization_id) {
            throw EventAdministrationException::invalid(
                'A department may only participate in an event its own organization produces.',
            );
        }
    }
}
