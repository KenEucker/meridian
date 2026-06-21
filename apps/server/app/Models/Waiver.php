<?php

namespace App\Models;

use Database\Factories\WaiverFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Waiver extends Model
{
    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_DEPARTMENT = 'department';

    public const SCOPE_TEAM = 'team';

    /** @use HasFactory<WaiverFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'scope_type',
        'scope_id',
        'name',
        'description',
        'expires_after_days',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_after_days' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function scopeTypes(): array
    {
        return [
            self::SCOPE_ORGANIZATION,
            self::SCOPE_DEPARTMENT,
            self::SCOPE_TEAM,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function completions(): HasMany
    {
        return $this->hasMany(WaiverCompletion::class);
    }

    /**
     * Whether the waiver expires after a configured number of days (WAIVER-002).
     */
    public function expires(): bool
    {
        return $this->expires_after_days !== null;
    }

    /**
     * Whether the waiver is assigned at organization scope (WAIVER-001).
     */
    public function isOrganizationScoped(): bool
    {
        return $this->scope_type === self::SCOPE_ORGANIZATION;
    }

    /**
     * Whether the staff member has a current completion for this waiver (WAIVER-003).
     */
    public function isCompleteFor(Staff $staff, ?Carbon $moment = null): bool
    {
        return $this->completions()
            ->where('staff_id', $staff->id)
            ->current($moment)
            ->exists();
    }

    /**
     * @param  Builder<Waiver>  $query
     * @return Builder<Waiver>
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
