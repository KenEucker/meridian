<?php

namespace App\Models;

use App\Services\Notifications\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One record of a person told, or deliberately not told (NOTIFY-007).
 *
 * The row is written before the decision to send is taken, so every outcome is
 * on it: `sent` and `failed` are the two a mail log would also know about, and
 * `suppressed`, `no_verified_address`, and `held_for_central` are the three it
 * would not, because in those cases nothing was ever handed to a transport.
 * "Was this person told?" has to be answerable for all five, and the last three
 * are the ones an operator is actually confused by.
 *
 * No message body is stored (NOTIFY-007). `context_json` carries identifiers
 * and names for reading the trail back; the message itself is composed from
 * the subject record when the send runs.
 */
class NotificationDelivery extends Model
{
    use HasUuids;

    /** Written and awaiting the queued send. */
    public const STATUS_QUEUED = 'queued';

    /** Handed to the mail transport without error. */
    public const STATUS_SENT = 'sent';

    /** Attempted and refused by the transport, up to the configured attempts. */
    public const STATUS_FAILED = 'failed';

    /** Not sent because the organization or the node has sending switched off (NOTIFY-009). */
    public const STATUS_SUPPRESSED = 'suppressed';

    /** Not sent because the recipient has no verified address to send to (NOTIFY-005). */
    public const STATUS_NO_VERIFIED_ADDRESS = 'no_verified_address';

    /** Generated on an on-site node, which does not send; central owns it now (NOTIFY-008). */
    public const STATUS_HELD_FOR_CENTRAL = 'held_for_central';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'notification_type',
        'organization_id',
        'event_id',
        'department_id',
        'subject_entity_type',
        'subject_entity_id',
        'recipient_user_id',
        'recipient_staff_id',
        'recipient_email',
        'status',
        'outcome_reason',
        'context_json',
        'attempts',
        'queued_at',
        'sent_at',
        'resolved_at',
        'origin_node_id',
        'origin_operation_uuid',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context_json' => 'array',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function recipientStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'recipient_staff_id');
    }

    public function type(): ?NotificationType
    {
        return NotificationType::tryFrom((string) $this->notification_type);
    }

    /**
     * The record this notification is about, or null once it no longer exists.
     *
     * Resolved by class name rather than through a morph relation because the
     * subject classes are the closed set {@see NotificationType::subjectClass()}
     * names, and a morph map would be a second place for that set to be
     * declared and to disagree.
     */
    public function subject(): ?Model
    {
        $class = $this->subject_entity_type;

        if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->find($this->subject_entity_id);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    /**
     * @param  Builder<NotificationDelivery>  $query
     * @return Builder<NotificationDelivery>
     */
    public function scopeOfType(Builder $query, NotificationType $type): Builder
    {
        return $query->where('notification_type', $type->value);
    }

    /**
     * Deliveries already written for a subject record, whatever became of them.
     *
     * The sweep that finds outstanding acknowledgments and waivers asks this
     * before writing a new row, so a requirement that stays outstanding for a
     * fortnight produces one email rather than fourteen. A record of a
     * suppressed or unaddressable delivery counts as having been considered:
     * turning suppression off should not release a backlog of every day it was
     * on.
     *
     * @param  Builder<NotificationDelivery>  $query
     * @return Builder<NotificationDelivery>
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('subject_entity_type', $subject::class)
            ->where('subject_entity_id', (string) $subject->getKey());
    }
}
