<?php

namespace App\Services\Application;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventApplicationDepartmentInterest;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Membership\TeamMembershipService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationRecipientResolver;
use App\Services\Notifications\NotificationType;
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
 * Reject/defer transitions are organizer/Staff Coordinator review actions.
 * Applicant-only withdrawal is enforced through {@see ApplicationApplicantAccess}.
 * Approved applications may be rescinded before operational team assignment
 * (APP-008 through APP-010).
 * Department assignment after approval is delivered by {@see assignToDepartment};
 * operational team assignment is delivered by
 * {@see TeamMembershipService::assignStaffToTeam}.
 */
class EventApplicationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly StaffStatusService $staffStatuses,
        private readonly DepartmentMembershipService $departmentMemberships,
        private readonly DepartmentAssignmentAccess $departmentAssignmentAccess,
        private readonly NotificationDispatcher $notifications,
        private readonly NotificationRecipientResolver $notificationRecipients,
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

            $this->notifyApplicationDecision($application, NotificationType::ApplicationApproved, $reviewer);

            return $application
                ->load(['event', 'organization', 'reviewedBy', 'staff', 'departmentInterests'])
                ->setRelation('staff', $staff);
        });
    }

    /**
     * Reject a submitted application at the organization level.
     *
     * @throws ApplicationReviewException when the application is not rejectable
     */
    public function reject(
        EventApplication $application,
        User $reviewer,
        ?string $decisionReason = null,
    ): EventApplication {
        return $this->transitionReviewDecision(
            application: $application,
            reviewer: $reviewer,
            status: EventApplication::STATUS_REJECTED,
            auditAction: 'event_application.rejected',
            defaultReason: 'Rejected at the organization level.',
            notificationType: NotificationType::ApplicationRejected,
            decisionReason: $decisionReason,
        );
    }

    /**
     * Defer a submitted application at the organization level.
     *
     * @throws ApplicationReviewException when the application is not deferrable
     */
    public function defer(
        EventApplication $application,
        User $reviewer,
        ?string $decisionReason = null,
    ): EventApplication {
        return $this->transitionReviewDecision(
            application: $application,
            reviewer: $reviewer,
            status: EventApplication::STATUS_DEFERRED,
            auditAction: 'event_application.deferred',
            defaultReason: 'Deferred at the organization level.',
            notificationType: NotificationType::ApplicationDeferred,
            decisionReason: $decisionReason,
        );
    }

    /**
     * Withdraw a submitted application on behalf of the applicant.
     *
     * @throws ApplicationWithdrawalException when the application is not withdrawable
     */
    public function withdraw(
        EventApplication $application,
        ?User $applicant = null,
        ?string $decisionReason = null,
    ): EventApplication {
        return DB::transaction(function () use ($application, $applicant, $decisionReason): EventApplication {
            /** @var EventApplication $application */
            $application = EventApplication::query()
                ->with(['event', 'organization'])
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $application->isSubmitted()) {
                throw new ApplicationWithdrawalException('Only submitted applications can be withdrawn.');
            }

            $withdrawnAt = now();
            $reason = $decisionReason ?: 'Withdrawn by the applicant.';
            $applicationBefore = $this->applicationAuditSnapshot($application);

            $application->forceFill([
                'status' => EventApplication::STATUS_WITHDRAWN,
                'withdrawn_at' => $withdrawnAt,
                'decision_reason' => $reason,
            ])->save();

            $applicationAfter = $this->applicationAuditSnapshot($application->refresh());

            $this->audit->recordForEntity(
                entity: $application,
                action: 'event_application.withdrawn',
                actorUser: $applicant,
                organizationId: $application->organization_id,
                eventId: $application->event_id,
                before: $applicationBefore,
                after: $applicationAfter,
                reason: $reason,
                sourceContext: AuditEvent::SOURCE_WEB,
            );

            return $application->load(['event', 'organization', 'departmentInterests']);
        });
    }

    /**
     * Rescind an approved application before operational team assignment.
     *
     * The canonical application status set has no separate rescinded state, so
     * rescission terminates the approval as Withdrawn while retaining reviewer
     * metadata, decision reason, and explicit rescind audit history.
     *
     * @throws ApplicationRescindException when the application is not rescindable
     */
    public function rescind(
        EventApplication $application,
        User $reviewer,
        ?string $decisionReason = null,
    ): EventApplication {
        return DB::transaction(function () use ($application, $reviewer, $decisionReason): EventApplication {
            /** @var EventApplication $application */
            $application = EventApplication::query()
                ->with(['event', 'organization', 'staff'])
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $application->isApproved() || $application->staff_id === null) {
                throw new ApplicationRescindException('Only approved applications with a linked staff profile can be rescinded.');
            }

            $staff = $application->staff;

            if ($staff === null) {
                throw new ApplicationRescindException('Only approved applications with a linked staff profile can be rescinded.');
            }

            if ($this->hasOperationalTeamAssignment($application)) {
                throw new ApplicationRescindException('Applications cannot be rescinded after team assignment.');
            }

            $rescindedAt = now();
            $reason = $decisionReason ?: 'Rescinded before team assignment.';
            $applicationBefore = $this->applicationAuditSnapshot($application);

            $this->inactivateDefaultOnlyDepartmentMemberships($application, $reviewer, $reason);
            $this->ensureInactiveOrganizationStatus($application, $reviewer, $rescindedAt, $reason);

            $application->forceFill([
                'status' => EventApplication::STATUS_WITHDRAWN,
                'reviewed_at' => $rescindedAt,
                'reviewed_by_user_id' => $reviewer->id,
                'decision_reason' => $reason,
                'withdrawn_at' => $rescindedAt,
            ])->save();

            $applicationAfter = $this->applicationAuditSnapshot($application->refresh());

            $this->audit->recordForEntity(
                entity: $application,
                action: 'event_application.rescinded',
                actorUser: $reviewer,
                organizationId: $application->organization_id,
                eventId: $application->event_id,
                before: $applicationBefore,
                after: $applicationAfter,
                reason: $reason,
                sourceContext: AuditEvent::SOURCE_ORCHID,
            );

            return $application->load(['event', 'organization', 'reviewedBy', 'staff', 'departmentInterests']);
        });
    }

    /**
     * Assign an approved applicant to a department after organization approval.
     *
     * @throws DepartmentAssignmentException when assignment is not permitted or valid
     */
    public function assignToDepartment(
        EventApplication $application,
        Department $department,
        User $assigner,
    ): DepartmentMembership {
        if (! $this->departmentAssignmentAccess->canAssignToDepartment($assigner, $application, $department)) {
            throw new DepartmentAssignmentException('You are not authorized to assign this applicant to the selected department.');
        }

        return DB::transaction(function () use ($application, $department, $assigner): DepartmentMembership {
            /** @var EventApplication $application */
            $application = EventApplication::query()
                ->with(['event', 'organization', 'staff'])
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $application->isApproved() || $application->staff_id === null) {
                throw new DepartmentAssignmentException('Only approved applications with a linked staff profile can be assigned to a department.');
            }

            $department = Department::query()
                ->with('defaultTeam')
                ->whereKey($department->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDepartmentEligibleForAssignment($application, $department);

            $staff = $application->staff;
            if ($staff === null) {
                throw new DepartmentAssignmentException('Only approved applications with a linked staff profile can be assigned to a department.');
            }

            $organizationStatus = StaffOrganizationStatus::query()
                ->where('organization_id', $application->organization_id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($organizationStatus?->status === StaffOrganizationStatus::STATUS_DO_NOT_STAFF) {
                throw new DepartmentAssignmentException('Do Not Staff records cannot be assigned to departments.');
            }

            if (DepartmentMembership::query()
                ->active()
                ->where('department_id', $department->id)
                ->where('staff_id', $staff->id)
                ->exists()) {
                throw new DepartmentAssignmentException('This staff member is already assigned to the selected department.');
            }

            $reason = 'Assigned to '.$department->name.' after approval for '.$application->event?->name.'.';
            $departmentMembership = $this->departmentMemberships->assignStaffWithDefaultTeam(
                $staff,
                $department,
                $assigner,
                $reason,
            );

            $this->audit->recordForEntity(
                entity: $departmentMembership,
                action: 'department_membership.assigned_from_application',
                actorUser: $assigner,
                organizationId: $application->organization_id,
                eventId: $application->event_id,
                departmentId: $department->id,
                before: null,
                after: $this->departmentMembershipAuditSnapshot($departmentMembership),
                reason: $reason,
                sourceContext: AuditEvent::SOURCE_ORCHID,
            );

            return $departmentMembership->load('teamMemberships.team');
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
     * @throws ApplicationReviewException
     */
    private function transitionReviewDecision(
        EventApplication $application,
        User $reviewer,
        string $status,
        string $auditAction,
        string $defaultReason,
        NotificationType $notificationType,
        ?string $decisionReason = null,
    ): EventApplication {
        return DB::transaction(function () use (
            $application,
            $reviewer,
            $status,
            $auditAction,
            $defaultReason,
            $notificationType,
            $decisionReason,
        ): EventApplication {
            /** @var EventApplication $application */
            $application = EventApplication::query()
                ->with(['event', 'organization'])
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $application->isSubmitted()) {
                throw new ApplicationReviewException('Only submitted applications can be reviewed.');
            }

            $reviewedAt = now();
            $reason = $decisionReason ?: $defaultReason;
            $applicationBefore = $this->applicationAuditSnapshot($application);

            $application->forceFill([
                'status' => $status,
                'reviewed_at' => $reviewedAt,
                'reviewed_by_user_id' => $reviewer->id,
                'decision_reason' => $reason,
            ])->save();

            $applicationAfter = $this->applicationAuditSnapshot($application->refresh());

            $this->audit->recordForEntity(
                entity: $application,
                action: $auditAction,
                actorUser: $reviewer,
                organizationId: $application->organization_id,
                eventId: $application->event_id,
                before: $applicationBefore,
                after: $applicationAfter,
                reason: $reason,
                sourceContext: AuditEvent::SOURCE_ORCHID,
            );

            $this->notifyApplicationDecision($application, $notificationType, $reviewer);

            return $application->load(['event', 'organization', 'reviewedBy', 'departmentInterests']);
        });
    }

    /**
     * Tell the applicant what was decided (NOTIFY-001).
     *
     * The Do Not Staff silence NOTIFY-002 requires is structural rather than a
     * condition here: an auto-rejected application never becomes Submitted, and
     * every path into this method has already refused anything that is not. The
     * guard below is belt to that braces — the requirement is that nothing is
     * ever sent, and a future reviewer path that forgot the Submitted check
     * would otherwise disclose the record by mailing about it.
     */
    private function notifyApplicationDecision(
        EventApplication $application,
        NotificationType $type,
        User $reviewer,
    ): void {
        if ($application->status === EventApplication::STATUS_AUTO_REJECTED_DNS) {
            return;
        }

        $application->loadMissing(['event', 'organization', 'staff']);

        $this->notifications->dispatch(
            type: $type,
            subject: $application,
            recipient: $this->notificationRecipients->forApplication($application),
            organization: $application->organization,
            event: $application->event,
            actor: $reviewer,
        );
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
            'withdrawn_at' => $application->withdrawn_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function departmentMembershipAuditSnapshot(DepartmentMembership $departmentMembership): array
    {
        return [
            'department_id' => $departmentMembership->department_id,
            'staff_id' => $departmentMembership->staff_id,
            'status' => $departmentMembership->status,
            'status_reason' => $departmentMembership->status_reason,
            'team_ids' => $departmentMembership->relationLoaded('teamMemberships')
                ? $departmentMembership->teamMemberships->pluck('team_id')->all()
                : $departmentMembership->teamMemberships()->pluck('team_id')->all(),
        ];
    }

    private function hasOperationalTeamAssignment(EventApplication $application): bool
    {
        if ($application->staff_id === null) {
            return false;
        }

        return TeamMembership::query()
            ->join('teams', 'teams.id', '=', 'team_memberships.team_id')
            ->join('departments', 'departments.id', '=', 'teams.department_id')
            ->where('team_memberships.staff_id', $application->staff_id)
            ->where('departments.organization_id', $application->organization_id)
            ->whereColumn('teams.id', '!=', 'departments.default_team_id')
            ->exists();
    }

    private function inactivateDefaultOnlyDepartmentMemberships(
        EventApplication $application,
        User $reviewer,
        string $reason,
    ): void {
        if ($application->staff_id === null) {
            return;
        }

        $memberships = DepartmentMembership::query()
            ->with(['department', 'teamMemberships.team'])
            ->where('staff_id', $application->staff_id)
            ->where('status', DepartmentMembership::STATUS_ACTIVE)
            ->whereNull('archived_at')
            ->whereHas('department', fn ($query) => $query
                ->where('organization_id', $application->organization_id))
            ->lockForUpdate()
            ->get();

        foreach ($memberships as $membership) {
            $before = $this->departmentMembershipAuditSnapshot($membership);
            $updated = $this->staffStatuses->transitionDepartmentStatus(
                $membership,
                DepartmentMembership::STATUS_INACTIVE,
                $reason,
            )->load(['department', 'teamMemberships.team']);

            $this->audit->recordForEntity(
                entity: $updated,
                action: 'department_membership.inactivated_from_application_rescind',
                actorUser: $reviewer,
                organizationId: $application->organization_id,
                eventId: $application->event_id,
                departmentId: $updated->department_id,
                before: $before,
                after: $this->departmentMembershipAuditSnapshot($updated),
                reason: $reason,
                sourceContext: AuditEvent::SOURCE_ORCHID,
            );
        }
    }

    private function ensureInactiveOrganizationStatus(
        EventApplication $application,
        User $reviewer,
        \DateTimeInterface $changedAt,
        string $reason,
    ): StaffOrganizationStatus {
        $statusRecord = StaffOrganizationStatus::query()
            ->where('organization_id', $application->organization_id)
            ->where('staff_id', $application->staff_id)
            ->lockForUpdate()
            ->first();

        if ($statusRecord === null) {
            $statusRecord = StaffOrganizationStatus::query()->create([
                'organization_id' => $application->organization_id,
                'staff_id' => $application->staff_id,
                'status' => StaffOrganizationStatus::STATUS_INACTIVE,
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
            StaffOrganizationStatus::STATUS_INACTIVE,
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
     * @throws DepartmentAssignmentException
     */
    private function assertDepartmentEligibleForAssignment(
        EventApplication $application,
        Department $department,
    ): void {
        if ($department->isArchived()) {
            throw new DepartmentAssignmentException('Archived departments cannot receive new assignments.');
        }

        if ((string) $department->organization_id !== (string) $application->organization_id) {
            throw new DepartmentAssignmentException('The selected department must belong to the application organization.');
        }

        $participates = $department->eventAssignments()
            ->active()
            ->where('event_id', $application->event_id)
            ->exists();

        if (! $participates) {
            throw new DepartmentAssignmentException('The selected department must participate in this event.');
        }

        if ($department->defaultTeam === null) {
            throw new DepartmentAssignmentException('The selected department must have a default team before assignment.');
        }
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
