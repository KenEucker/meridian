<?php

namespace App\Models;

use Database\Factories\OrganizationInquiryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Screen\AsSource;

/**
 * An organization that asked about Meridian from the public marketing surface
 * (M18.23; PUBLIC-002, PUBLIC-003, PUBLIC-004).
 *
 * This is a message, not an account. It names no organization record, no user,
 * and no staff member, and it never will: PUBLIC-003 puts all of those out of
 * reach of a public form, and PUBLIC-004 keeps organization creation a God Mode
 * action taken deliberately by a person. What the row carries is what somebody
 * typed and what somebody in the console has done about it since.
 */
class OrganizationInquiry extends Model
{
    use AsSource;
    use Filterable;

    /** @use HasFactory<OrganizationInquiryFactory> */
    use HasFactory, HasUuids;

    /** Nobody in the console has read it yet. */
    public const STATUS_NEW = 'new';

    /** Somebody has read it and the conversation is live. */
    public const STATUS_REVIEWED = 'reviewed';

    /** There is nothing further to do with it. */
    public const STATUS_CLOSED = 'closed';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_name',
        'contact_name',
        'contact_email',
        'description',
        'status',
        'review_notes',
        'reviewed_by_user_id',
        'reviewed_at',
        'submitted_at',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'id' => Where::class,
        'organization_name' => Like::class,
        'contact_name' => Like::class,
        'contact_email' => Like::class,
        'status' => Like::class,
        'submitted_at' => WhereDateStartEnd::class,
        'created_at' => WhereDateStartEnd::class,
    ];

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'organization_name',
        'contact_name',
        'contact_email',
        'status',
        'submitted_at',
        'reviewed_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_NEW,
            self::STATUS_REVIEWED,
            self::STATUS_CLOSED,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_NEW => 'New',
            self::STATUS_REVIEWED => 'Reviewed',
            self::STATUS_CLOSED => 'Closed',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    /**
     * The God Mode user who last recorded a decision about this inquiry. Null
     * while it is still new, which is the only relationship this record has to
     * anything operational.
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function isNew(): bool
    {
        return $this->status === self::STATUS_NEW;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
