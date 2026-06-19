<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Platform\Models\User as Authenticatable;

class User extends Authenticatable
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'permissions',
        'current_volunteer_id',
        'disabled_at',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
        'permissions',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'permissions' => 'array',
        'email_verified_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    /**
     * The attributes for which you can use filters in url.
     *
     * @var array
     */
    protected $allowedFilters = [
        'id' => Where::class,
        'name' => Like::class,
        'email' => Like::class,
        'updated_at' => WhereDateStartEnd::class,
        'created_at' => WhereDateStartEnd::class,
    ];

    /**
     * The attributes for which can use sort in url.
     *
     * @var array
     */
    protected $allowedSorts = [
        'id',
        'name',
        'email',
        'updated_at',
        'created_at',
    ];

    public function authIdentities(): HasMany
    {
        return $this->hasMany(AuthIdentity::class);
    }

    public function deviceTrusts(): HasMany
    {
        return $this->hasMany(DeviceTrust::class);
    }

    public function sharedWorkstationLoginCodes(): HasMany
    {
        return $this->hasMany(SharedWorkstationLoginCode::class);
    }

    public function staffProfiles(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'staff_user')->using(StaffUser::class)->withTimestamps();
    }

    public function staffOrganizationStatusChanges(): HasMany
    {
        return $this->hasMany(StaffOrganizationStatus::class, 'status_changed_by_user_id');
    }

    public function generatedSharedWorkstationLoginCodes(): HasMany
    {
        return $this->hasMany(SharedWorkstationLoginCode::class, 'generated_by_user_id');
    }

    public function trustedDevices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'device_trusts')
            ->withPivot([
                'trusted_node_fingerprint',
                'first_trusted_at',
                'last_seen_at',
                'expires_at',
                'revoked_at',
            ])
            ->withTimestamps();
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }
}
