<?php

namespace App\Models;

use App\Domain\Permissions\PermissionCatalog;
use Database\Factories\TeamDesignationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team designation attaches an existing operational grant to a named team
 * (TEAM-011). Department designations carry the section 4.8A functions;
 * the organization designation carries the Staff Coordinator team within the
 * configured Organizers Department (TEAM-014). Each active designation owns
 * the `team_grants` row it created, which is what makes removal revoke
 * exactly the derived authority and nothing granted directly (TEAM-013).
 */
class TeamDesignation extends Model
{
    /** @use HasFactory<TeamDesignationFactory> */
    use HasFactory, HasUuids;

    public const FUNCTION_LOGISTICS = 'logistics';

    public const FUNCTION_OPERATIONS = 'operations';

    public const FUNCTION_PLANNING = 'planning';

    public const FUNCTION_ADMINISTRATION = 'administration';

    public const FUNCTION_OPERATOR = 'operator';

    public const FUNCTION_STAFF_COORDINATOR = 'staff_coordinator';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'department_id',
        'function_code',
        'team_id',
        'team_grant_id',
        'removed_at',
    ];

    /**
     * The department operational functions a department may designate a team
     * for (TEAM-012).
     *
     * @return list<string>
     */
    public static function departmentFunctions(): array
    {
        return [
            self::FUNCTION_LOGISTICS,
            self::FUNCTION_OPERATIONS,
            self::FUNCTION_PLANNING,
            self::FUNCTION_ADMINISTRATION,
            self::FUNCTION_OPERATOR,
        ];
    }

    /**
     * The human-readable label for a designated function, as audit reasons
     * and permission explanations name it (TEAM-017, TEAM-018).
     */
    public static function functionLabel(string $functionCode): string
    {
        return str_replace('_', ' ', ucwords($functionCode, '_'));
    }

    /**
     * The permission role a designated function attaches to the team
     * (TEAM-012, TEAM-014).
     *
     * @return array<string, string>
     */
    public static function functionRoleCodes(): array
    {
        return [
            self::FUNCTION_LOGISTICS => PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS,
            self::FUNCTION_OPERATIONS => PermissionCatalog::ROLE_DEPARTMENT_OPERATIONS,
            self::FUNCTION_PLANNING => PermissionCatalog::ROLE_DEPARTMENT_PLANNING,
            self::FUNCTION_ADMINISTRATION => PermissionCatalog::ROLE_DEPARTMENT_ADMINISTRATION,
            self::FUNCTION_OPERATOR => PermissionCatalog::ROLE_DEPARTMENT_OPERATOR,
            self::FUNCTION_STAFF_COORDINATOR => PermissionCatalog::ROLE_STAFF_COORDINATOR,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'removed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function teamGrant(): BelongsTo
    {
        return $this->belongsTo(TeamGrant::class);
    }

    /**
     * @param  Builder<TeamDesignation>  $query
     * @return Builder<TeamDesignation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }
}
