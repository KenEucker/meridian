<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Modules\ModuleKey;
use Database\Factories\OrganizationModuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One organization's state for one module in the fixed catalogue (MOD-005;
 * data/API 10.1A).
 *
 * `entitled` is the platform's decision (MOD-006); `enabled` is the
 * organization's (MOD-008). They are never collapsed into one column, so
 * revoking entitlement leaves the organization's own choice standing and
 * restoring it honors that choice again rather than defaulting (MOD-007).
 * Active is `entitled && enabled`, computed here and nowhere stored.
 *
 * The absence of a row is not "inactive". It is "never written", which
 * {@see \App\Services\Modules\ActiveModuleResolver} reads as entitled and
 * enabled — the MOD-009 default every organization created before this table
 * existed is in.
 *
 * `module_key` is a plain string validated against the code catalogue on save
 * rather than a foreign key, because the catalogue is Meridian's own build-time
 * constant and a database row must never be able to invent a module the code
 * does not implement (MOD-003). It is read back with
 * {@see ModuleKey::tryFrom()} rather than cast, so a key left behind by a build
 * whose catalogue no longer lists it reads as nothing at all instead of
 * throwing on every query that touches the row.
 */
class OrganizationModule extends Model
{
    /** @use HasFactory<OrganizationModuleFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'module_key',
        'entitled',
        'enabled',
        'entitlement_changed_at',
        'entitlement_changed_by_user_id',
        'enablement_changed_at',
        'enablement_changed_by_user_id',
    ];

    /**
     * Reject a module key the catalogue does not know, on the way in
     * (data/API 10.1A).
     *
     * The guard sits on the model rather than on a screen because there is more
     * than one writer coming — God Mode entitlement (M19.13), organizer
     * enablement (M19.15), and organization creation (M19.19) — and a check
     * that each of them has to remember is a check one of them will not.
     */
    protected static function booted(): void
    {
        static::saving(function (self $organizationModule): void {
            $key = $organizationModule->getAttributeValue('module_key');

            if (! is_string($key) || ModuleKey::tryFrom($key) === null) {
                throw new InvalidArgumentException(
                    'Unknown module key ['.(is_string($key) ? $key : gettype($key)).']. '
                    .'The module catalogue is fixed in code (MOD-003) and is exactly: '
                    .implode(', ', ModuleKey::keys()).'.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entitled' => 'boolean',
            'enabled' => 'boolean',
            'entitlement_changed_at' => 'datetime',
            'enablement_changed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function entitlementChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entitlement_changed_by_user_id');
    }

    public function enablementChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enablement_changed_by_user_id');
    }

    /**
     * The catalogue entry this row is about, or null when the row names a
     * module this build does not have.
     */
    public function module(): ?ModuleKey
    {
        return ModuleKey::tryFrom((string) $this->module_key);
    }

    /**
     * MOD-005: active is both halves, and nothing else.
     */
    public function isActive(): bool
    {
        return $this->entitled && $this->enabled;
    }

    /**
     * @param  Builder<OrganizationModule>  $query
     * @return Builder<OrganizationModule>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('entitled', true)->where('enabled', true);
    }
}
