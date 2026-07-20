<?php

namespace App\Models;

use Database\Factories\PermissionRoleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PermissionRole extends Model
{
    public const SCOPE_NODE = 'node';

    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_EVENT = 'event';

    public const SCOPE_DEPARTMENT = 'department';

    public const SCOPE_TEAM = 'team';

    /** @use HasFactory<PermissionRoleFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'scope_type',
    ];

    /**
     * @return list<string>
     */
    public static function scopeTypes(): array
    {
        return [
            self::SCOPE_NODE,
            self::SCOPE_ORGANIZATION,
            self::SCOPE_EVENT,
            self::SCOPE_DEPARTMENT,
            self::SCOPE_TEAM,
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->using(RolePermission::class);
    }

    public function teamGrants(): HasMany
    {
        return $this->hasMany(TeamGrant::class);
    }
}
