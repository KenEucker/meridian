<?php

namespace App\Services\Application;

use App\Models\Department;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventApplicationDepartmentInterest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Domain path for event-specific application intake (requirements APP-001 through
 * APP-004 and APP-011, section 3.10; data/API specification section 10.5).
 *
 * This service owns creation of the {@see EventApplication} in its Submitted
 * state. Applicants apply to an event, not to a department (APP-001, APP-002).
 * Review decisions (approve/reject/defer), DNS auto-rejection, applicant-only
 * withdrawal, and department/team assignment are delivered by their owning
 * tasks in Milestone 5.
 */
class EventApplicationService
{
    /**
     * Create a Submitted application for the given event.
     *
     * @throws EventNotOpenForApplicationsException when the event cannot accept applications
     * @throws DuplicateApplicationException when a Submitted application already exists for this event/email
     */
    public function submit(
        Event $event,
        string $applicantLegalName,
        string $applicantEmail,
        ?User $applicant = null,
        iterable $departmentInterestIds = [],
    ): EventApplication {
        if ($event->isArchived()) {
            throw new EventNotOpenForApplicationsException('This event is not accepting applications.');
        }

        $legalName = trim($applicantLegalName);
        $email = $this->normalizeEmail($applicantEmail);
        $departmentInterestIds = $this->validateDepartmentInterestIds($event, $departmentInterestIds);

        if ($this->hasOpenApplication($event, $email)) {
            throw new DuplicateApplicationException('An application for this event has already been submitted with this email address.');
        }

        return DB::transaction(function () use ($event, $legalName, $email, $departmentInterestIds): EventApplication {
            $application = EventApplication::query()->create([
                'event_id' => $event->id,
                'organization_id' => $event->organization_id,
                'staff_id' => null,
                'applicant_email' => $email,
                'applicant_legal_name' => $legalName,
                'status' => EventApplication::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ]);

            foreach ($departmentInterestIds as $departmentId) {
                EventApplicationDepartmentInterest::query()->create([
                    'event_application_id' => $application->id,
                    'department_id' => $departmentId,
                ]);
            }

            return $application->load('departmentInterests');
        });
    }

    /**
     * Eligible department interests are active departments in the event's
     * organization with an active event-department participation row (APP-011).
     *
     * @return Collection<int, Department>
     */
    public function eligibleDepartmentInterests(Event $event): Collection
    {
        return Department::query()
            ->active()
            ->where('organization_id', $event->organization_id)
            ->whereHas('eventAssignments', fn ($query) => $query
                ->active()
                ->where('event_id', $event->id))
            ->orderBy('name')
            ->get();
    }

    public function hasOpenApplication(Event $event, string $applicantEmail): bool
    {
        return EventApplication::query()
            ->where('event_id', $event->id)
            ->where('applicant_email', $this->normalizeEmail($applicantEmail))
            ->submitted()
            ->exists();
    }

    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function validateDepartmentInterestIds(Event $event, iterable $departmentInterestIds): array
    {
        $ids = collect($departmentInterestIds)
            ->filter(fn ($id): bool => $id !== null && $id !== '')
            ->map(fn ($id): string => (string) $id)
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        if ($ids->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'department_interest_ids' => __('Select each department interest only once.'),
            ]);
        }

        $eligibleIds = $this->eligibleDepartmentInterests($event)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id);

        if ($ids->diff($eligibleIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'department_interest_ids' => __('Selected department interests must be active departments participating in this event.'),
            ]);
        }

        return $ids->all();
    }
}
