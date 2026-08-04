<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Jobs\SendNotificationEmail;
use App\Models\Department;
use App\Models\Event;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Services\Node\NodeOperationRecorder;
use App\Services\Node\NodeSetupService;
use App\Services\Node\NodeSigningException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one way a notification is sent (M18.21; NOTIFY-001 through NOTIFY-009).
 *
 * Domain services call {@see dispatch()} after the operation that caused the
 * notification has been decided, and nothing they do afterwards depends on the
 * result. That is NOTIFY-006 taken literally: a dispatch writes one row and
 * queues one job, so the operation is never waiting on a mail server, and a
 * delivery that later fails leaves the operation exactly where it was. This
 * class therefore swallows its own failures — a notification that cannot even
 * be recorded is logged and stepped over rather than thrown into the middle of
 * a shift cancellation.
 *
 * Ordering matters and is deliberate:
 *
 *   1. **Recipient.** No verified address means nothing to send to
 *      (NOTIFY-005), and it is recorded rather than dropped, because "was this
 *      person told?" has an answer here and it is no.
 *   2. **Node role.** On-site does not send (NOTIFY-008). The delivery is held
 *      and a command-style node operation hands it to central through the
 *      existing sync path.
 *   3. **Suppression.** Global or per organization (NOTIFY-009).
 *   4. **Queue.** Everything left is queued for the mailer.
 *
 * Callers decide *whether* an event is notifiable; this decides whether it can
 * be delivered. NOTIFY-002's silence for a Do Not Staff auto-rejection is a
 * caller's decision for that reason — the application service never dispatches
 * for an auto-rejected application, so no record exists to disclose that one
 * was considered.
 */
class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationRecipientResolver $recipients,
        private readonly NotificationSendingPolicy $policy,
        private readonly NodeSetupService $nodes,
        private readonly NodeOperationRecorder $operations,
        private readonly NotificationAuditRecorder $audit,
    ) {}

    /**
     * Record and, where this node sends, queue one notification.
     *
     * @param  Model  $subject  the record the notification is about
     * @param  User|null  $actor  who took the action, for the node operation
     *                            an on-site node records; the recipient is
     *                            never the actor
     */
    public function dispatch(
        NotificationType $type,
        Model $subject,
        ?NotificationRecipient $recipient,
        ?Organization $organization = null,
        ?Event $event = null,
        ?Department $department = null,
        ?User $actor = null,
        ?string $originOperationUuid = null,
    ): ?NotificationDelivery {
        try {
            return $this->record(
                type: $type,
                subject: $subject,
                recipient: $recipient,
                organization: $organization,
                event: $event,
                department: $department,
                actor: $actor,
                originOperationUuid: $originOperationUuid,
            );
        } catch (Throwable $exception) {
            // The operation that caused this notification has already been
            // decided. Failing here would undo it, which is the one outcome
            // NOTIFY-006 names as unacceptable.
            Log::error('Notification dispatch failed.', [
                'notification_type' => $type->value,
                'subject_type' => $subject::class,
                'subject_id' => (string) $subject->getKey(),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function record(
        NotificationType $type,
        Model $subject,
        ?NotificationRecipient $recipient,
        ?Organization $organization,
        ?Event $event,
        ?Department $department,
        ?User $actor,
        ?string $originOperationUuid,
    ): NotificationDelivery {
        $node = $this->nodes->activeNode();

        $delivery = new NotificationDelivery([
            'notification_type' => $type->value,
            'organization_id' => $organization?->getKey(),
            'event_id' => $event?->getKey(),
            'department_id' => $department?->getKey(),
            'subject_entity_type' => $subject::class,
            'subject_entity_id' => (string) $subject->getKey(),
            'recipient_user_id' => $recipient?->user?->getKey(),
            'recipient_staff_id' => $recipient?->staff?->getKey(),
            // A delivery with no recipient still records the address it would
            // have used, and there is none — the empty string is the honest
            // value and the status beside it says why.
            'recipient_email' => $recipient?->email ?? '',
            'context_json' => $this->context($type, $subject, $recipient, $organization, $event, $department),
            'origin_node_id' => $node?->getKey(),
            'origin_operation_uuid' => $originOperationUuid,
        ]);

        if (! $recipient instanceof NotificationRecipient) {
            return $this->resolve(
                $delivery,
                NotificationDelivery::STATUS_NO_VERIFIED_ADDRESS,
                'The recipient has no verified email address to notify.',
            );
        }

        $refusal = $this->policy->refusalFor($organization);

        if ($refusal !== null) {
            [$status, $reason] = $refusal;

            $delivery = $this->resolve($delivery, $status, $reason);

            if ($status === NotificationDelivery::STATUS_HELD_FOR_CENTRAL) {
                $this->handToCentral($delivery, $type, $subject, $event, $actor);
            }

            return $delivery;
        }

        $delivery->forceFill([
            'status' => NotificationDelivery::STATUS_QUEUED,
            'queued_at' => now(),
        ])->save();

        $this->audit->record($delivery);

        // After commit, so a job that runs before the operation's transaction
        // lands cannot compose a message from a subject record nobody can see
        // yet.
        SendNotificationEmail::dispatch($delivery->getKey())
            ->afterCommit()
            ->onQueue((string) config('meridian.notifications.queue', 'notifications'));

        return $delivery;
    }

    private function resolve(NotificationDelivery $delivery, string $status, string $reason): NotificationDelivery
    {
        $delivery->forceFill([
            'status' => $status,
            'outcome_reason' => $reason,
            'resolved_at' => now(),
        ])->save();

        $this->audit->record($delivery);

        return $delivery;
    }

    /**
     * Queue the notification for central through the node sync path
     * (NOTIFY-008).
     *
     * The operation's meaning is entirely in its type and the entity it names,
     * because `payload_json` is unauthenticated and is never applied (data/API
     * 13.3). Central re-derives the recipient and the message from its own copy
     * of the subject record, which is also what keeps a notification honest
     * across the sync: it describes the record as central holds it, not as
     * on-site held it some hours earlier.
     */
    private function handToCentral(
        NotificationDelivery $delivery,
        NotificationType $type,
        Model $subject,
        ?Event $event,
        ?User $actor,
    ): void {
        if (! $actor instanceof User) {
            // Every node operation is signed on behalf of an actor. A
            // notification with no actor — a scheduled sweep, say — has nobody
            // to sign as, and a sweep is central's job anyway.
            return;
        }

        // One operation per subject record, not per recipient. A cancelled
        // shift notifies everybody who was signed up for it, and handing
        // central forty operations that all say "this shift was cancelled"
        // would have central re-derive the same roster forty times. Central
        // fans out from the one operation instead.
        $existing = NotificationDelivery::query()
            ->ofType($type)
            ->where('subject_entity_type', $subject::class)
            ->where('subject_entity_id', (string) $subject->getKey())
            ->where('status', NotificationDelivery::STATUS_HELD_FOR_CENTRAL)
            ->whereNotNull('origin_operation_uuid')
            ->value('origin_operation_uuid');

        if (is_string($existing)) {
            $delivery->forceFill(['origin_operation_uuid' => $existing])->save();

            return;
        }

        try {
            $operation = $this->operations->record(
                operationType: $type->operationType(),
                entityType: $subject::class,
                entityId: (string) $subject->getKey(),
                actorUser: $actor,
                event: $event,
                targetNode: null,
                payload: null,
            );

            $delivery->forceFill(['origin_operation_uuid' => $operation->uuid])->save();
        } catch (NodeSigningException) {
            // An unsigned node cannot hand anything to central. The delivery
            // record stays as it is and says so, which is more useful than a
            // half-queued operation nobody will ever push.
            $delivery->forceFill([
                'outcome_reason' => 'This node cannot sign operations, so the notification was not handed to central.',
            ])->save();
        }
    }

    /**
     * Identifiers and scope for reading the trail back, never a rendered
     * message (NOTIFY-007).
     *
     * @return array<string, mixed>
     */
    private function context(
        NotificationType $type,
        Model $subject,
        ?NotificationRecipient $recipient,
        ?Organization $organization,
        ?Event $event,
        ?Department $department,
    ): array {
        return array_filter([
            'notification_type' => $type->value,
            'recipient_name' => $recipient?->name,
            'organization_name' => $organization?->name,
            'event_name' => $event?->name,
            'department_name' => $department?->name,
            'subject_id' => (string) $subject->getKey(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
