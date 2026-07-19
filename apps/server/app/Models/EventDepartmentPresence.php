<?php

namespace App\Models;

use Database\Factories\EventDepartmentPresenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventDepartmentPresence extends Model
{
    public const STATE_ON_SITE = 'on_site';

    public const STATE_OFF_SITE = 'off_site';

    /** @use HasFactory<EventDepartmentPresenceFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'department_id',
        'staff_id',
        'current_state',
        'marked_on_site_at',
        'marked_off_site_at',
        'last_marked_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'marked_on_site_at' => 'datetime',
            'marked_off_site_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return [
            self::STATE_ON_SITE,
            self::STATE_OFF_SITE,
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function lastMarkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_marked_by_user_id');
    }

    /**
     * @param  Builder<EventDepartmentPresence>  $query
     * @return Builder<EventDepartmentPresence>
     */
    public function scopeOnSite(Builder $query): Builder
    {
        return $query->where('current_state', self::STATE_ON_SITE);
    }

    public function isOnSite(): bool
    {
        return $this->current_state === self::STATE_ON_SITE;
    }
}
