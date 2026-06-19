<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\User;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Permissions\TeamGrantService;
use Database\Seeders\Support\DevelopmentScenarioCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DevelopmentScenarioSeeder extends Seeder
{
    /**
     * Idempotently seed the canonical development organization, event,
     * departments, teams, personas, memberships, statuses, and permission grants.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $organization = $this->seedOrganization();
            [$teamsByCode, $departmentsByCode] = $this->seedDepartmentsAndTeams($organization);
            $event = $this->seedEvent($organization, $departmentsByCode['ORGANIZERS']);
            $this->seedPersonas($organization, $teamsByCode, $departmentsByCode);
            $this->seedTeamGrants($event, $teamsByCode, $departmentsByCode);
        });
    }

    private function seedOrganization(): Organization
    {
        return Organization::query()->updateOrCreate(
            ['slug' => DevelopmentScenarioCatalog::ORGANIZATION_SLUG],
            ['name' => DevelopmentScenarioCatalog::ORGANIZATION_NAME],
        );
    }

    /**
     * @return array{0: array<string, Team>, 1: array<string, Department>}
     */
    private function seedDepartmentsAndTeams(Organization $organization): array
    {
        $teamsByCode = [];
        $departmentsByCode = [];
        $organizersDepartmentId = null;
        $icDepartmentId = null;

        foreach (DevelopmentScenarioCatalog::departments() as $departmentDefinition) {
            $department = Department::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'code' => $departmentDefinition['code'],
                ],
                [
                    'name' => $departmentDefinition['name'],
                    'description' => null,
                ],
            );

            $departmentsByCode[$departmentDefinition['code']] = $department;
            $this->ensureDefaultTeam($department);

            if ($departmentDefinition['code'] === 'ORGANIZERS') {
                $organizersDepartmentId = $department->id;
                $icDepartmentId = $department->id;
            }

            foreach ($departmentDefinition['teams'] as $teamDefinition) {
                $teamsByCode[$teamDefinition['code']] = Team::query()->updateOrCreate(
                    [
                        'department_id' => $department->id,
                        'code' => $teamDefinition['code'],
                    ],
                    [
                        'name' => $teamDefinition['name'],
                        'description' => null,
                        'is_default' => false,
                    ],
                );
            }
        }

        $organization->forceFill([
            'organizers_department_id' => $organizersDepartmentId,
            'default_ic_department_id' => $icDepartmentId,
        ])->save();

        return [$teamsByCode, $departmentsByCode];
    }

    private function seedEvent(Organization $organization, Department $icDepartment): Event
    {
        $startsAt = now()->addMonths(4)->setTime(9, 0);
        $endsAt = $startsAt->copy()->addDays(4)->setTime(18, 0);

        return Event::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'slug' => DevelopmentScenarioCatalog::EVENT_SLUG,
            ],
            [
                'name' => DevelopmentScenarioCatalog::EVENT_NAME,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'timezone' => DevelopmentScenarioCatalog::EVENT_TIMEZONE,
                'status' => null,
                'ic_department_id' => $icDepartment->id,
                'active_event_window_starts_at' => $startsAt->copy()->subDay(),
                'active_event_window_ends_at' => $endsAt->copy()->addDay(),
            ],
        );
    }

    /**
     * @param  array<string, Team>  $teamsByCode
     * @param  array<string, Department>  $departmentsByCode
     */
    private function seedPersonas(Organization $organization, array $teamsByCode, array $departmentsByCode): void
    {
        $membershipService = app(DepartmentMembershipService::class);

        foreach (DevelopmentScenarioCatalog::personas() as $persona) {
            $user = User::query()->updateOrCreate(
                ['email' => $persona['email']],
                [
                    'name' => $persona['user_name'],
                    'password' => Hash::make(DevelopmentScenarioCatalog::DEFAULT_PASSWORD),
                    'email_verified_at' => now(),
                ],
            );

            $staff = Staff::query()->updateOrCreate(
                ['email' => $persona['email']],
                [
                    'legal_name' => $persona['user_name'],
                    'preferred_name' => explode(' ', $persona['user_name'])[0],
                ],
            );

            $user->staffProfiles()->syncWithoutDetaching([$staff->id]);

            StaffOrganizationStatus::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'staff_id' => $staff->id,
                ],
                [
                    'status' => $persona['org_status'],
                    'status_reason' => $this->organizationStatusReason($persona['org_status']),
                    'status_changed_at' => now(),
                    'status_changed_by_user_id' => $user->id,
                ],
            );

            if ($persona['department_code'] === null || $persona['team_code'] === null) {
                continue;
            }

            $team = $this->resolveTeam($persona, $teamsByCode, $departmentsByCode);
            $department = $team->department()->firstOrFail();
            $departmentStatus = $persona['department_status'] ?? 'active';

            $existingMembership = $staff->departmentMemberships()
                ->where('department_id', $department->id)
                ->whereNull('archived_at')
                ->first();

            if ($existingMembership === null) {
                $membershipService->createWithTeams(
                    $staff,
                    $department,
                    [$team],
                    $departmentStatus,
                    $departmentStatus === 'ineligible'
                        ? 'Department eligibility review pending.'
                        : null,
                    $user,
                );

                continue;
            }

            $existingMembership->forceFill([
                'status' => $departmentStatus,
                'status_reason' => $departmentStatus === 'ineligible'
                    ? 'Department eligibility review pending.'
                    : null,
            ])->save();

            $existingMembership->teamMemberships()
                ->where('team_id', $team->id)
                ->whereNull('archived_at')
                ->first()
                ?? $existingMembership->teamMemberships()->create([
                    'team_id' => $team->id,
                    'staff_id' => $staff->id,
                    'membership_role' => 'member',
                ]);
        }
    }

    /**
     * @param  array<string, Team>  $teamsByCode
     * @param  array<string, Department>  $departmentsByCode
     */
    private function seedTeamGrants(Event $event, array $teamsByCode, array $departmentsByCode): void
    {
        $grantService = new TeamGrantService;

        foreach (DevelopmentScenarioCatalog::personas() as $persona) {
            if ($persona['grants'] === []) {
                continue;
            }

            $team = $this->resolveTeam($persona, $teamsByCode, $departmentsByCode);

            foreach ($persona['grants'] as $grantDefinition) {
                $role = PermissionRole::query()->where('code', $grantDefinition['role'])->firstOrFail();
                $scopedEvent = $grantDefinition['event_scoped'] ? $event : null;

                $existingGrant = TeamGrant::query()
                    ->where('team_id', $team->id)
                    ->where('permission_role_id', $role->id)
                    ->when(
                        $scopedEvent === null,
                        fn ($query) => $query->whereNull('event_id'),
                        fn ($query) => $query->where('event_id', $scopedEvent->id),
                    )
                    ->whereNull('revoked_at')
                    ->exists();

                if ($existingGrant) {
                    continue;
                }

                $grantService->grant($team, $role, $scopedEvent);
            }
        }
    }

    private function organizationStatusReason(string $status): ?string
    {
        return match ($status) {
            StaffOrganizationStatus::STATUS_DO_NOT_STAFF => 'Development seed Do Not Staff persona.',
            StaffOrganizationStatus::STATUS_PROSPECTIVE => 'Development seed prospective applicant.',
            default => null,
        };
    }

    /**
     * @param  array<string, Team>  $teamsByCode
     * @param  array<string, Department>  $departmentsByCode
     */
    private function resolveTeam(array $persona, array $teamsByCode, array $departmentsByCode): Team
    {
        if ($persona['team_code'] === 'DEFAULT') {
            $department = $departmentsByCode[$persona['department_code']];

            return $this->ensureDefaultTeam($department);
        }

        return $teamsByCode[$persona['team_code']];
    }

    private function ensureDefaultTeam(Department $department): Team
    {
        $department->refresh();

        if ($department->default_team_id !== null) {
            return $department->defaultTeam()->firstOrFail();
        }

        $defaultTeam = $department->teams()->create([
            'name' => 'Default',
            'code' => 'DEFAULT',
            'description' => null,
            'is_default' => true,
        ]);

        $department->forceFill([
            'default_team_id' => $defaultTeam->id,
        ])->saveQuietly();

        return $defaultTeam;
    }
}
