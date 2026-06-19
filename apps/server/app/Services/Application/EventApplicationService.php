<?php

namespace App\Services\Application;

use App\Models\Event;
use App\Models\EventApplication;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Domain path for event-specific application intake (requirements APP-001 through
 * APP-004, section 3.10; data/API specification section 10.5).
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
    ): EventApplication {
        if ($event->isArchived()) {
            throw new EventNotOpenForApplicationsException('This event is not accepting applications.');
        }

        $legalName = trim($applicantLegalName);
        $email = $this->normalizeEmail($applicantEmail);

        if ($this->hasOpenApplication($event, $email)) {
            throw new DuplicateApplicationException('An application for this event has already been submitted with this email address.');
        }

        return EventApplication::query()->create([
            'event_id' => $event->id,
            'organization_id' => $event->organization_id,
            'staff_id' => null,
            'applicant_email' => $email,
            'applicant_legal_name' => $legalName,
            'status' => EventApplication::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
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
}
