<?php

namespace App\Models;

use Database\Factories\TeamMembershipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class TeamMembership extends Model
{
    /** @use HasFactory<TeamMembershipFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'staff_id',
        'department_membership_id',
        'membership_role',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TeamMembership $teamMembership): void {
            $teamMembership->assertTeamBelongsToDepartmentMembership();

            if ($teamMembership->exists && $teamMembership->isDirty('archived_at') && $teamMembership->archived_at !== null) {
                $teamMembership->assertAnotherActiveTeamMembershipExists();
            }
        });

        static::deleting(function (TeamMembership $teamMembership): void {
            if (! $teamMembership->isArchived()) {
                $teamMembership->assertAnotherActiveTeamMembershipExists();
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function departmentMembership(): BelongsTo
    {
        return $this->belongsTo(DepartmentMembership::class);
    }

    /**
     * @param  Builder<TeamMembership>  $query
     * @return Builder<TeamMembership>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    private function assertTeamBelongsToDepartmentMembership(): void
    {
        $teamDepartmentId = Team::query()
            ->whereKey($this->team_id)
            ->value('department_id');

        $membershipDepartmentId = DepartmentMembership::query()
            ->whereKey($this->department_membership_id)
            ->value('department_id');

        if ($teamDepartmentId === null || $membershipDepartmentId === null || (string) $teamDepartmentId !== (string) $membershipDepartmentId) {
            throw new RuntimeException('Team membership must use a team from the same department as the department membership.');
        }
    }

    private function assertAnotherActiveTeamMembershipExists(): void
    {
        $hasAnotherActiveTeamMembership = self::query()
            ->where('department_membership_id', $this->department_membership_id)
            ->whereKeyNot($this->getKey())
            ->whereNull('archived_at')
            ->exists();

        if (! $hasAnotherActiveTeamMembership) {
            throw new RuntimeException('Department membership requires at least one active team membership.');
        }
    }
}
