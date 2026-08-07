<?php

namespace App\Models;

use Database\Factories\EventHorizonDismissalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One staff member having hidden the Event Horizon for one event (M18.44;
 * HORIZON-012 through HORIZON-015; data/API 10.21).
 *
 * Personal view state, and nothing more: invisible to every other user, not
 * audited (technical spec 21D.10), and read by no other feature. One row per
 * staff member per event; restoring the surface deletes the row rather than
 * adding a second state, because "not hidden" is the absence of a decision and
 * needs no record. The row does not survive a new outstanding item
 * (HORIZON-015): it is discarded when one is found, rather than suppressing a
 * list that now has work on it.
 *
 * This is the only row the Event Horizon owns. No table stores a compiled
 * item, an outstanding count, or a readiness state — the view compiles on read
 * (technical spec 21D.3).
 */
class EventHorizonDismissal extends Model
{
    /** @use HasFactory<EventHorizonDismissalFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'staff_id',
        'event_id',
        'dismissed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
