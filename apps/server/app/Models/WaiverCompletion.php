<?php

namespace App\Models;

use Database\Factories\WaiverCompletionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class WaiverCompletion extends Model
{
    /** @use HasFactory<WaiverCompletionFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'waiver_id',
        'staff_id',
        'completed_at',
        'expires_at',
        'recorded_by_user_id',
        'document_type',
        'document_id',
        'document_revision',
        'fragment_revision',
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
            'document_revision' => 'integer',
            'fragment_revision' => 'integer',
        ];
    }

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(Waiver::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * Whether the completion has lapsed relative to the given moment (WAIVER-002).
     */
    public function isExpiredAt(?Carbon $moment = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lessThanOrEqualTo($moment ?? Carbon::now());
    }

    /**
     * @param  Builder<WaiverCompletion>  $query
     * @return Builder<WaiverCompletion>
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
