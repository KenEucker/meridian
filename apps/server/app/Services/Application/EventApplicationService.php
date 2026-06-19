<?php

namespace App\Services\Application;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventApplicationDepartmentInterest;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Status\StaffStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Domain path for event-specific application intake (requirements APP-001 through
 * APP-004 and APP-011, section 3.10; data/API specification section 10.5).
 *
 * This service owns creation of the {@see EventApplication} in its Submitted
 * state, or its DNS auto-rejected state when the applicant email matches an
 * organization Do Not Staff record (STAT-006). Applicants apply to an event,
 * not to a department (APP-001, APP-002). Approval happens at the organization
 * level and creates or ensures Prospective staff status (APP-005, APP-006).
 * Reject/defer, applicant-only withdrawal, and department/team assignment are
 * delivered by their owning tasks in Milestone 5.
 */
class EventApplicationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly StaffStatusService $staffStatuses,
    ) {}

    /**
     * Create an application for the given event.
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

        $matchesDns = $this->matchesDnsEmail($event, $email);

        return DB::transaction(function () use ($event, $legalName, $email, $departmentInterestIds, $matchesDns): EventApplication {
            $submittedAt = now();

            $application = EventApplication::query()->create([
                'event_id' => $event->id,
                'organization_id' => $event->organization_id,
                'staff_id' => null,
                'applicant_email' => $email,
                'applicant_legal_name' => $legalName,
                'status' => $matchesDns
                    ? EventApplication::STATUS_AUTO_REJECTED_DNS
                    : EventApplication::STATUS_SUBMITTED,
                'submitted_at' => $submittedAt,
                'reviewed_at' => $matchesDns ? $submittedAt : null,
                'reviewed_by_user_id' => null,
                'decision_reason' => $matchesDns
                    ? 'Applicant email matched an organization Do Not Staff status.'
                    : null,
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
     * Approve a submitted application at the organization level.
     *
     * @throws ApplicationApprovalException when the application is not approvable
     */
    public function approve(
        EventApplication $application,
        User $reviewer,
        ?string $decisionReason = null,
    ): EventApplication {
        return DB::transaction(function () use ($application, $reviewer, $decisionReason): EventApplication {
            /** @var EventApplication $application */
            $application = EventApplication::query()
                ->with(['event', 'organization'])
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $application->isSubmitted()) {
                throw new ApplicationApprovalException('Only submitted applications can be approved.');
            }

            $reviewedAt = now();
            $reason = $decisionReason ?: 'Approved at the organization level.';
            $staff = $this->findOrCreateStaffForApplication($application);
            $this->ensureProspectiveOrganizationStatus($application, $staff, $reviewer, $reviewedAt);

            $applicationBefore = $this->applicationAuditSnapshot($application);

            $application->forceFill([
                'staff_id' => $staff->id,
                'status' => EventApplication::STATUS_APPROVED,
                'reviewed_at' => $reviewedAt,
                'reviewed_by_user_id' => $reviewer->id,
                'decision_reason' => $reason,
            ])->save();

            $applicationAfter = $this->applicationAuditSnapshot($application->refresh());

            $this->audit->recordForEntity(
                entity: $application,
                action: 'event_application.approved',
                actorUser: $reviewer,
                organizationId: $application->organization_id,
                eventId: $application->event_id,
                before: $applicationBefore,
                after: $applicationAfter,
                reason: $reason,
                sourceContext: AuditEvent::SOURCE_ORCHID,
            );

            return $application
                ->load(['event', 'organization', 'reviewedBy', 'staff', 'departmentInterests'])
                ->setRelation('staff', $staff);
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

    public function matchesDnsEmail(Event $event, string $applicantEmail): bool
    {
        return StaffOrganizationStatus::query()
            ->where('organization_id', $event->organization_id)
            ->where('status', StaffOrganizationStatus::STATUS_DO_NOT_STAFF)
            ->whereHas('staff', fn ($query) => $query
                ->whereRaw('LOWER(email) = ?', [$this->normalizeEmail($applicantEmail)]))
            ->exists();
    }

    private function findOrCreateStaffForApplication(EventApplication $application): Staff
    {
        $email = $this->normalizeEmail($application->applicant_email);

        $staff = Staff::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($staff !== null) {
            return $staff;
        }

        return Staff::query()->create([
            'legal_name' => trim($application->applicant_legal_name),
            'email' => $email,
        ]);
    }

    private function ensureProspectiveOrganizationStatus(
        EventApplication $application,
        Staff $staff,
        User $reviewer,
        \DateTimeInterface $changedAt,
    ): StaffOrganizationStatus {
        $statusRecord = StaffOrganizationStatus::query()
            ->where('organization_id', $application->organization_id)
            ->where('staff_id', $staff->id)
            ->lockForUpdate()
            ->first();

        if ($statusRecord?->status === StaffOrganizationStatus::STATUS_DO_NOT_STAFF) {
            throw new ApplicationApprovalException('Applications for Do Not Staff records cannot be approved.');
        }

        $reason = 'Approved application for '.$application->event?->name.'.';

        if ($statusRecord === null) {
            $statusRecord = StaffOrganizationStatus::query()->create([
                'organization_id' => $application->organization_id,
                'staff_id' => $staff->id,
                'status' => StaffOrganizationStatus::STATUS_PROSPECTIVE,
                'status_reason' => $reason,
                'status_changed_at' => $changedAt,
                'status_changed_by_user_id' => $reviewer->id,
            ]);

            $this->audit->recordForEntity(
                entity: $statusRecord,
                action: 'staff_organization_status.created',
                actorUser: $reviewer,
                organizationId: $application->organization_id,
                eventId: $application->event_id,
                before: null,
                after: $this->statusAuditSnapshot($statusRecord),
                reason: $reason,
                sourceContext: AuditEvent::SOURCE_ORCHID,
            );

            return $statusRecord;
        }

        $before = $this->statusAuditSnapshot($statusRecord);

        $statusRecord = $this->staffStatuses->transitionOrganizationStatus(
            $statusRecord,
            StaffOrganizationStatus::STATUS_PROSPECTIVE,
            $reason,
            $reviewer,
            Carbon::instance($changedAt),
        );

        $after = $this->statusAuditSnapshot($statusRecord);

        if ($before !== $after) {
            $this->audit->recordForEntity(
                entity: $statusRecord,
                action: 'staff_organization_status.changed',
                actorUser: $reviewer,
                organizationId: $application->organization_id,
                eventId: $application->event_id,
                before: $before,
                after: $after,
                reason: $reason,
                sourceContext: AuditEvent::SOURCE_ORCHID,
            );
        }

        return $statusRecord;
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationAuditSnapshot(EventApplication $application): array
    {
        return [
            'status' => $application->status,
            'staff_id' => $application->staff_id,
            'reviewed_at' => $application->reviewed_at?->toISOString(),
            'reviewed_by_user_id' => $application->reviewed_by_user_id,
            'decision_reason' => $application->decision_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statusAuditSnapshot(StaffOrganizationStatus $statusRecord): array
    {
        return [
            'status' => $statusRecord->status,
            'status_reason' => $statusRecord->status_reason,
            'status_changed_at' => $statusRecord->status_changed_at?->toISOString(),
            'status_changed_by_user_id' => $statusRecord->status_changed_by_user_id,
        ];
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
