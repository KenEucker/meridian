<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\NotificationMail;
use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationAuditRecorder;
use App\Services\Notifications\NotificationComposer;
use App\Services\Notifications\NotificationSendingPolicy;
use App\Services\Notifications\NotificationSubjectMissingException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Hands one recorded notification to the mail transport (NOTIFY-006,
 * NOTIFY-007).
 *
 * Queued, because NOTIFY-006 requires that delivery not block the operation
 * that caused it, and a transactional email that waits on an SMTP handshake
 * would make a shift cancellation as slow as the slowest mail server the
 * organization uses.
 *
 * The job carries a delivery id rather than a serialized model, and composes
 * the message itself when it runs. Both follow from NOTIFY-007 keeping message
 * bodies out of the retained trail: there is nothing stored to serialize, and
 * what the recipient reads is derived from the subject record as it stands at
 * send time.
 *
 * Failure is a terminal state on the delivery record, never an exception that
 * reaches the operation. The operation committed before this job existed, and
 * NOTIFY-006 is explicit that a delivery failure does not roll it back.
 */
class SendNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Resolved when the job runs rather than injected into the constructor,
     * because a queued job is serialized and a service is not.
     */
    private ?NotificationAuditRecorder $audit = null;

    public function __construct(public readonly string $deliveryId) {}

    public function tries(): int
    {
        return max(1, (int) config('meridian.notifications.max_attempts', 3));
    }

    public function handle(
        NotificationComposer $composer,
        NotificationSendingPolicy $policy,
        NotificationAuditRecorder $audit,
    ): void {
        $this->audit = $audit;

        $delivery = NotificationDelivery::query()->find($this->deliveryId);

        if (! $delivery instanceof NotificationDelivery || ! $delivery->isPending()) {
            // Already resolved — a retry after a send that did land, or a
            // delivery central resolved while this node's copy was queued.
            return;
        }

        $delivery->loadMissing(['organization', 'recipientStaff']);

        // Re-asked here rather than trusted from dispatch time, because a
        // notification queued minutes ago may have been queued before an
        // operator switched suppression on, and NOTIFY-009's switch is
        // worthless if it only governs notifications nobody had queued yet.
        $refusal = $policy->refusalFor($delivery->organization);

        if ($refusal !== null) {
            [$status, $reason] = $refusal;

            $this->resolve($delivery, $status, $reason);

            return;
        }

        try {
            $message = $composer->compose($delivery);
        } catch (NotificationSubjectMissingException $exception) {
            // Nothing to describe and nothing a retry would recover.
            $this->resolve($delivery, NotificationDelivery::STATUS_FAILED, $exception->getMessage());

            return;
        }

        $delivery->forceFill(['attempts' => (int) $delivery->attempts + 1])->save();

        $recipientName = is_array($delivery->context_json)
            ? ($delivery->context_json['recipient_name'] ?? null)
            : null;

        try {
            Mail::to($delivery->recipient_email, is_string($recipientName) ? $recipientName : null)
                ->send(new NotificationMail($message));
        } catch (Throwable $exception) {
            // NOTIFY-006, and the whole reason this class exists: the operation
            // that caused the notification is long committed, and a refused
            // send must not become an exception the caller sees.
            //
            // Rethrowing is how a queue worker learns to retry, so it is right
            // wherever a worker is the thing that catches it. On the sync
            // connection the job runs inside the caller's own stack, so an
            // exception thrown here surfaces from whatever cancelled the shift
            // — and there is no worker to retry it either. There, the failure
            // is recorded and stops.
            if ($this->retryable() && (int) $delivery->attempts < $this->tries()) {
                throw $exception;
            }

            $this->resolve($delivery, NotificationDelivery::STATUS_FAILED, $exception->getMessage());

            return;
        }

        $this->resolve($delivery, NotificationDelivery::STATUS_SENT, null, sent: true);
    }

    /**
     * The last attempt failed. Record the outcome so NOTIFY-007's question has
     * an answer, and stop.
     */
    public function failed(?Throwable $exception): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);

        if (! $delivery instanceof NotificationDelivery || ! $delivery->isPending()) {
            return;
        }

        $this->resolve(
            $delivery,
            NotificationDelivery::STATUS_FAILED,
            $exception?->getMessage() ?? 'The mail transport refused the message.',
        );
    }

    /**
     * Whether something other than the caller's own stack will catch a
     * rethrown exception and try again.
     */
    private function retryable(): bool
    {
        return $this->job !== null && $this->job->getConnectionName() !== 'sync';
    }

    private function resolve(
        NotificationDelivery $delivery,
        string $status,
        ?string $reason,
        bool $sent = false,
    ): void {
        $delivery->forceFill(array_filter([
            'status' => $status,
            'outcome_reason' => $reason,
            'resolved_at' => now(),
            'sent_at' => $sent ? now() : null,
        ], static fn (mixed $value): bool => $value !== null))->save();

        // NOTIFY-007: the outcome, not just the intention. A trail that
        // recorded "queued" and never what became of it would answer the
        // question an operator is not asking.
        ($this->audit ?? app(NotificationAuditRecorder::class))->record($delivery);
    }
}
