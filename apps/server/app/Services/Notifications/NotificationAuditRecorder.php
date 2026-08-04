<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\AuditEvent;
use App\Models\NotificationDelivery;
use App\Services\Audit\AuditService;

/**
 * The audit half of NOTIFY-007: recipient, notification type, subject record,
 * and delivery outcome, and never a message body.
 *
 * Separate from the delivery row because the two answer different questions.
 * The row is what an operator queries when somebody says they were not told —
 * it is current, it is indexed by person, and it can be pruned. The audit event
 * is the immutable trail, and it is what survives that pruning.
 *
 * Every state change writes one, including the ones where nothing was sent. A
 * suppressed organization and an unverified address are decisions Meridian took
 * about a person's mail, and a trail that recorded only successful sends would
 * be missing exactly the entries somebody goes looking for.
 */
class NotificationAuditRecorder
{
    public function __construct(private readonly AuditService $audit) {}

    public function record(NotificationDelivery $delivery): void
    {
        $this->audit->recordForEntity(
            entity: $delivery,
            action: 'notification.'.$delivery->status,
            organizationId: $delivery->organization_id,
            eventId: $delivery->event_id,
            departmentId: $delivery->department_id,
            after: [
                'notification_type' => $delivery->notification_type,
                'recipient_email' => $delivery->recipient_email,
                'recipient_user_id' => $delivery->recipient_user_id,
                'subject_entity_type' => $delivery->subject_entity_type,
                'subject_entity_id' => $delivery->subject_entity_id,
                'status' => $delivery->status,
            ],
            reason: $delivery->outcome_reason,
            sourceContext: AuditEvent::SOURCE_SYSTEM,
        );
    }
}
