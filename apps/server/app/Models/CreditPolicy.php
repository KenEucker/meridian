<?php

namespace App\Models;

use Database\Factories\CreditPolicyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rate at which worked hours become credits (ORG-009, SHIFT-010; data/API
 * section 10.12).
 *
 * An organization names one policy as its default and a shift may name its own
 * (CREDIT-002, CREDIT-003). There is no department default, because ORG-010
 * says Meridian does not support one.
 *
 * Which policy a shift uses is read from `shifts.credit_policy_id`, not from
 * this record's own `event_id`/`shift_id`. Those columns record what a policy
 * was written for so an operator can find it again; the pointer on the shift is
 * the single answer to "which policy applies", and one lookup path means a
 * shift cannot be priced two ways.
 */
class CreditPolicy extends Model
{
    /** @use HasFactory<CreditPolicyFactory> */
    use HasFactory, HasUuids;

    protected $table = 'credit_policies';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'event_id',
        'shift_id',
        'name',
        'credit_multiplier',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credit_multiplier' => 'decimal:3',
            'archived_at' => 'datetime',
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

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @param  Builder<CreditPolicy>  $query
     * @return Builder<CreditPolicy>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
