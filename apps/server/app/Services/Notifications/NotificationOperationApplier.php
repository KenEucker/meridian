<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventCredential;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffProfileChangeRequest;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Node\NodeOperationApplier;
use App\Services\Node\SignedNodeOperation;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Central's side of NOTIFY-008: send the notifications an on-site node
 * generated during the event window.
 *
 * An on-site node does not send. It records the notification locally as
 * `held_for_central` and records a command-style node operation naming the
 * subject record — `notification.send.<type>` on the entity the notification is
 * about. Nothing travels in the payload, because `payload_json` is
 * unauthenticated and appliers are handed the signed field projection only
 * (data/API 13.3).
 *
 * That constraint turns out to be the right shape rather than a limitation.
 * Central holds its own copy of the subject record, so it re-derives the
 * recipient and the message from state it can verify. A notification that
 * crossed the sync therefore describes the record as central holds it — after
 * whatever else arrived in the same exchange — rather than as on-site saw it
 * hours earlier with no internet.
 *
 * Idempotency is the operation uuid, which is minted at the origin and is the
 * same on every node that sees the operation (technical spec 10.1). A
 * redelivered operation finds its delivery already recorded and does nothing.
 */
class NotificationOperationApplier implements NodeOperationApplier
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
        private readonly NotificationRecipientResolver $recipients,
    ) {}

    public function supports(SignedNodeOperation $operation): bool
    {
        $type = NotificationType::fromOperationType($operation->operationType);

        return $type !== null && $type->subjectClass() === $operation->entityType;
    }

    public function apply(SignedNodeOperation $operation): void
    {
        $type = NotificationType::fromOperationType($operation->operationType);

        if ($type === null) {
            throw new RuntimeException('This build does not know that notification type.');
        }

        $alreadyApplied = NotificationDelivery::query()
            ->where('origin_operation_uuid', $operation->uuid)
            ->where('status', '!=', NotificationDelivery::STATUS_HELD_FOR_CENTRAL)
            ->exists();

        if ($alreadyApplied) {
            return;
        }

        $subjectClass = $type->subjectClass();
        $subject = $subjectClass::query()->find($operation->entityId);

        if (! $subject instanceof Model) {
            // Retryable rather than terminal. Sync applies operations in origin
            // creation order, but the record itself reaches central by its own
            // path, and an operation that arrives first should wait for it
            // rather than be discarded.
            throw new RuntimeException(
                'The record this notification is about has not reached this node yet.',
            );
        }

        $actor = User::query()->find($operation->actorUserId);
        $organization = $this->organizationFor($subject);
        $event = $this->eventFor($subject, $operation);
        $department = $this->departmentFor($subject);

        foreach ($this->recipientsFor($subject) as $recipient) {
            $this->notifications->dispatch(
                type: $type,
                subject: $subject,
                recipient: $recipient,
                organization: $organization,
                event: $event,
                department: $department,
                actor: $actor,
                originOperationUuid: $operation->uuid,
            );
        }
    }

    /**
     * Who this operation's notification goes to, re-derived on central.
     *
     * A list rather than one recipient because a cancelled shift notifies
     * everybody who was signed up for it, and the roster is central's to read.
     * A subject with no addressable recipient yields one null entry rather than
     * an empty list, so the delivery is still recorded as considered and
     * unaddressable (NOTIFY-005, NOTIFY-007) instead of vanishing.
     *
     * @return list<NotificationRecipient|null>
     */
    private function recipientsFor(Model $subject): array
    {
        if ($subject instanceof EventApplication) {
            return [$this->recipients->forApplication($subject)];
        }

        if ($subject instanceof Shift) {
            $recipients = [];

            foreach ($subject->activeAssignments()->with('staff')->get() as $assignment) {
                if ($assignment->staff instanceof Staff) {
                    $recipients[] = $this->recipients->forStaff($assignment->staff);
                }
            }

            return $recipients;
        }

        $staff = $this->staffFor($subject);

        return [$staff instanceof Staff ? $this->recipients->forStaff($staff) : null];
    }

    private function staffFor(Model $subject): ?Staff
    {
        return match (true) {
            $subject instanceof DepartmentMembership,
            $subject instanceof TeamMembership,
            $subject instanceof ShiftAssignment,
            $subject instanceof EventCredential,
            $subject instanceof StaffProfileChangeRequest => $subject->staff,
            default => null,
        };
    }

    private function organizationFor(Model $subject): ?Organization
    {
        return match (true) {
            $subject instanceof EventApplication => $subject->organization,
            $subject instanceof StaffProfileChangeRequest => $subject->organization,
            $subject instanceof DepartmentMembership => $subject->department?->organization,
            $subject instanceof TeamMembership => $subject->team?->department?->organization,
            $subject instanceof EventCredential => $subject->event?->organization,
            $subject instanceof ShiftAssignment => $subject->shift?->event?->organization,
            $subject instanceof Shift => $subject->event?->organization,
            default => null,
        };
    }

    private function eventFor(Model $subject, SignedNodeOperation $operation): ?Event
    {
        $event = match (true) {
            $subject instanceof EventApplication,
            $subject instanceof EventCredential => $subject->event,
            $subject instanceof ShiftAssignment => $subject->shift?->event,
            $subject instanceof Shift => $subject->event,
            default => null,
        };

        return $event ?? ($operation->eventId !== null ? Event::query()->find($operation->eventId) : null);
    }

    private function departmentFor(Model $subject): ?Department
    {
        return match (true) {
            $subject instanceof DepartmentMembership => $subject->department,
            $subject instanceof TeamMembership => $subject->team?->department,
            $subject instanceof ShiftAssignment => $subject->shift?->department,
            $subject instanceof Shift => $subject->department,
            default => null,
        };
    }
}
