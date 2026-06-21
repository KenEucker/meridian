<?php

namespace App\Models;

use Database\Factories\TrainingCompletionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class TrainingCompletion extends Model
{
    /** @use HasFactory<TrainingCompletionFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'training_id',
        'staff_id',
        'completed_at',
        'expires_at',
        'recorded_by_user_id',
        'origin_node_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function originNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'origin_node_id');
    }

    /**
     * Whether the completion has lapsed relative to the given moment.
     */
    public function isExpiredAt(?Carbon $moment = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lessThanOrEqualTo($moment ?? Carbon::now());
    }

    /**
     * @param  Builder<TrainingCompletion>  $query
     * @return Builder<TrainingCompletion>
     */
    public function scopeCurrent(Builder $query, ?Carbon $moment = null): Builder
    {
        $moment ??= Carbon::now();

        return $query->where(function (Builder $inner) use ($moment): void {
            $inner->whereNull('expires_at')
                ->orWhere('expires_at', '>', $moment);
        });
    }
}
