<?php

namespace App\Services\Training;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\Training;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Product-path training management authorization (M11.16).
 *
 * Trainings managed through the normal product surface are department
 * trainings (UI contract 12.4 `department.trainings`): department leads and
 * department administration manage their own department's trainings, and
 * organizers manage trainings for every department in the organization
 * (TRAIN-001 through TRAIN-006). Team leads of a training's team may record
 * completions as authorized trainers (TRAIN-005) without gaining training
 * creation authority.
 */
final class TrainingProductAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    /**
     * Create, edit, archive/restore trainings and maintain prerequisites for
     * the department.
     */
    public function canManageTrainings(User $user, Department $department): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_DEPARTMENT_TRAININGS_MANAGE,
                )) {
                    continue;
                }

                $team = Team::query()->with('department')->find($role->teamId);
                if ($team?->department === null) {
                    continue;
                }

                $scopeType = PermissionCatalog::roles()[$role->roleCode]['scope_type'] ?? null;

                if ($scopeType === PermissionRole::SCOPE_ORGANIZATION) {
                    if ((string) $team->department->organization_id === (string) $department->organization_id) {
                        return true;
                    }

                    continue;
                }

                if ((string) $team->department_id === (string) $department->id) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Record completions and view the roster for a training (TRAIN-005).
     *
     * Managers always qualify; team leads of the training's team qualify as
     * authorized trainers.
     */
    public function canRecordCompletions(User $user, Training $training): bool
    {
        $training->loadMissing('department');

        if ($training->department !== null && $this->canManageTrainings($user, $training->department)) {
            return true;
        }

        if ($training->team_id === null) {
            return false;
        }

        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if ($role->roleCode === PermissionCatalog::ROLE_SHIFT_LEAD
                    && (string) $role->teamId === (string) $training->team_id) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * View the department training list. Managers, trainers, and staff with
     * active department membership can see trainings so they can sign up.
     */
    public function canViewTrainings(User $user, Department $department): bool
    {
        if ($this->canManageTrainings($user, $department)) {
            return true;
        }

        return $this->signupProfile($user, $department) !== null;
    }

    /**
     * The staff profile a user signs up with: an active membership in the
     * training's department. Team-scoped trainings additionally require
     * active membership in the training's team.
     */
    public function signupProfile(User $user, Department $department, ?Training $training = null): ?Staff
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            $inDepartment = $staff->departmentMemberships()
                ->active()
                ->where('department_id', $department->id)
                ->exists();

            if (! $inDepartment) {
                continue;
            }

            if ($training?->team_id !== null) {
                $inTeam = $staff->teamMemberships()
                    ->active()
                    ->where('team_id', $training->team_id)
                    ->exists();

                if (! $inTeam) {
                    continue;
                }
            }

            return $staff;
        }

        return null;
    }
}
