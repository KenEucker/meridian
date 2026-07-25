<?php

namespace App\Models;

use Database\Factories\IncidentListPresetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's saved IMS incident list filter selection (M11.19).
 *
 * Personal view state for one user on one event. Presets store only the
 * search/filter/sort selection — never incident content — and never act as an
 * authorization source: incident reads always run `IncidentReadAccess` first.
 */
class IncidentListPreset extends Model
{
    /** @use HasFactory<IncidentListPresetFactory> */
    use HasFactory, HasUuids;

    public const MAX_NAME_LENGTH = 60;

    /**
     * Presets are a convenience, not storage. The cap keeps one user from
     * turning their own filter bar into an unbounded list.
     */
    public const MAX_PER_USER_PER_EVENT = 20;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'event_id',
        'user_id',
        'name',
        'filters',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope presets to one user on one event; there is no shared scope.
     *
     * @param  Builder<IncidentListPreset>  $query
     * @return Builder<IncidentListPreset>
     */
    public function scopeOwnedBy(Builder $query, User|string $user, Event|string $event): Builder
    {
        return $query
            ->where('user_id', (string) ($user instanceof User ? $user->getKey() : $user))
            ->where('event_id', (string) ($event instanceof Event ? $event->getKey() : $event));
    }
}
