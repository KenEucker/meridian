<?php

namespace App\Services\Application;

use App\Models\Department;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\User;

/**
 * Authorization for assigning approved applicants to departments (APP-007;
 * requirements sections 4.4, 4.5, and 5.3).
 *
 * Organizers and Staff Coordinators with application review permission may
 * assign approved staff to any eligible event-participating department in the
 * application organization. Department leads may assign approved staff only to
 * departments they lead.
 */
class DepartmentAssignmentAccess
{
    public function canAssignToDepartment(
        User $user,
        EventApplication $application,
        Department $department,
    ): bool {
        if (! $application->isApproved() || $application->staff_id === null) {
            return false;
        }

        if ((string) $application->organization_id !== (string) $department->organization_id) {
            return false;
        }

        $event = $application->relationLoaded('event')
            ? $application->event
            : $application->event()->first();

        if ($event === null) {
            return false;
        }

        if (app(ApplicationReviewAccess::class)->canReviewApplications($user)) {
            return (string) $application->organization_id === (string) $department->organization_id;
        }

        if (! $this->departmentParticipatesInEvent($event, $department)) {
            return false;
        }

        return app(ApplicationReviewAccess::class)
            ->departmentLeadDepartmentIds($user)
            ->contains((string) $department->id)
            && $this->departmentParticipatesInEvent($event, $department);
    }

    private function departmentParticipatesInEvent(Event $event, Department $department): bool
    {
        if ($department->isArchived()) {
            return false;
        }

        return $department->eventAssignments()
            ->active()
            ->where('event_id', $event->id)
            ->exists();
    }
}
