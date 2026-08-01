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

    /**
     * Memberships that put somebody on a shift's eligible team (SLB-008,
     * SHIFT-016).
     *
     * Three conditions, and a caller that skips any of them is asking a looser
     * question than the one an unscheduled addition is decided by: the
     * membership is not archived, the team it names is not archived, and it
     * hangs off the department membership that puts the person in that
     * department — a row left behind by a membership that was archived and
     * replaced is not standing on that team any more.
     *
     * It lives on the model because `UnscheduledShiftAdditionService` and the
     * Logistics Desk read both ask it, and they did not ask it the same way.
     * The desk weighed only the membership, so an archived team kept offering
     * **Add to shift** for a shift the node then refused — the desk drawing a
     * button whose answer it had got wrong, which is the failure the read
     * exists to prevent.
     *
     * @param  Builder<TeamMembership>  $query
     * @param  DepartmentMembership|list<string>|string  $departmentMemberships
     * @return Builder<TeamMembership>
     */
    public function scopeOnEligibleShiftTeam(
        Builder $query,
        DepartmentMembership|array|string $departmentMemberships,
    ): Builder {
        $ids = match (true) {
            $departmentMemberships instanceof DepartmentMembership => [(string) $departmentMemberships->getKey()],
            is_array($departmentMemberships) => $departmentMemberships,
            default => [$departmentMemberships],
        };

        return $query
            ->active()
            ->whereIn('department_membership_id', $ids)
            ->whereHas('team', fn (Builder $team) => $team->active());
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
