<?php

namespace App\Models;

use Database\Factories\SystemConfigOverrideFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A node-local database override for one catalogued environment variable
 * (technical spec 22A.3, 22A.5; SYS-005 through SYS-007, SYS-013).
 *
 * Non-secret values live JSON-encoded in `value_json` so `""`, `null`,
 * `false`, `0`, and `"0"` stay distinct through storage. Secret values live
 * only in `secret_value`, encrypted at rest by the `encrypted` cast, and are
 * never readable back through any Meridian surface — a secret override can be
 * replaced or removed, not revealed.
 */
class SystemConfigOverride extends Model
{
    /** @use HasFactory<SystemConfigOverrideFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'node_id',
        'name',
        'type',
        'value_json',
        'secret_value',
        'is_secret',
        'is_active',
        'change_reason',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret_value' => 'encrypted',
            'is_secret' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * @param  Builder<SystemConfigOverride>  $query
     * @return Builder<SystemConfigOverride>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
