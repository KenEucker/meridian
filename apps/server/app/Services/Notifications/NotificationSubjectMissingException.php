<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use RuntimeException;

/**
 * The record a queued notification is about is no longer there.
 *
 * Not an error in the operation that queued it — that operation succeeded, and
 * NOTIFY-006 is explicit that a delivery failure does not roll it back. It is
 * the ordinary end of a notification whose subject was withdrawn, archived, or
 * deleted between the queue and the send, and it resolves the delivery record
 * as failed with a reason an operator can read rather than retrying against a
 * row that will not come back.
 */
class NotificationSubjectMissingException extends RuntimeException
{
    public static function forDelivery(NotificationDelivery $delivery): self
    {
        return new self(sprintf(
            'The %s record this notification is about no longer exists.',
            class_basename((string) $delivery->subject_entity_type),
        ));
    }

    public static function unknownType(string $type): self
    {
        return new self(sprintf('"%s" is not a notification type this build knows.', $type));
    }
}
