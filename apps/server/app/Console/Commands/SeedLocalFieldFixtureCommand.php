<?php

namespace App\Console\Commands;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Node;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\User;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Permissions\TeamGrantService;
use App\Support\LocalFieldFixture;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the well-known local Field Report QA fixture (M9.8 local upload path).
 */
class SeedLocalFieldFixtureCommand extends Command
{
    protected $signature = 'meridian:seed-local-field-fixture';

    protected $description = 'Seed well-known local Field Report author/device/event rows for mobile photo upload QA';

    public function handle(): int
    {
        DB::transaction(function (): void {
            app(PermissionCatalogSeeder::class)->run();

            $organization = $this->upsert(
                Organization::class,
                LocalFieldFixture::ORGANIZATION_ID,
                [
                    'name' => 'Local Field Organization',
                    'slug' => 'local-field-org',
                    'default_ic_department_id' => null,
                    'default_credit_policy_id' => null,
                    'active_inactive_threshold_years' => 2,
                    'prospective_inactive_threshold_years' => 1,
                    'calendar_year_start_month' => 1,
                    'calendar_year_start_day' => 1,
                    'archived_at' => null,
                ],
                uniqueBy: ['slug' => 'local-field-org'],
            );

            $department = $this->upsert(
                Department::class,
                LocalFieldFixture::DEPARTMENT_ID,
                [
                    'organization_id' => $organization->id,
                    'name' => 'Rangers',
                    'code' => 'RANGERS',
                    'description' => 'Local fixture Incident Command Department.',
                    'archived_at' => null,
                ],
                uniqueBy: ['organization_id' => $organization->id, 'code' => 'RANGERS'],
            );

            $team = $this->upsert(
                Team::class,
                LocalFieldFixture::TEAM_ID,
                [
                    'department_id' => $department->id,
                    'name' => 'Command',
                    'code' => 'COMMAND',
                    'description' => 'Local fixture IC operator team.',
                    'is_default' => false,
                    'archived_at' => null,
                ],
                uniqueBy: ['department_id' => $department->id, 'code' => 'COMMAND'],
            );

            $organization->forceFill([
                'default_ic_department_id' => $department->id,
            ])->save();

            $event = $this->upsert(
                Event::class,
                LocalFieldFixture::EVENT_ID,
                [
                    'organization_id' => $organization->id,
                    'name' => 'Local Field Event',
                    'slug' => 'local-field-event',
                    'starts_at' => Carbon::parse('2027-07-04 09:00:00', 'America/Los_Angeles')->utc(),
                    'ends_at' => Carbon::parse('2027-07-08 18:00:00', 'America/Los_Angeles')->utc(),
                    'timezone' => 'America/Los_Angeles',
                    'minimum_staff_age' => null,
                    'status' => null,
                    'ic_department_id' => $department->id,
                    'active_event_window_starts_at' => Carbon::parse('2027-07-03 09:00:00', 'America/Los_Angeles')->utc(),
                    'active_event_window_ends_at' => Carbon::parse('2027-07-09 18:00:00', 'America/Los_Angeles')->utc(),
                    'archived_at' => null,
                ],
                uniqueBy: ['slug' => 'local-field-event', 'organization_id' => $organization->id],
            );

            EventDepartmentAssignment::query()->updateOrCreate(
                [
                    'event_id' => $event->id,
                    'department_id' => $department->id,
                ],
                [
                    'archived_at' => null,
                ],
            );

            $user = $this->upsert(
                User::class,
                LocalFieldFixture::USER_ID,
                [
                    'name' => LocalFieldFixture::USER_NAME,
                    'email' => LocalFieldFixture::USER_EMAIL,
                    'password' => Hash::make('password'),
                ],
                uniqueBy: ['email' => LocalFieldFixture::USER_EMAIL],
            );

            $staff = $this->upsert(
                Staff::class,
                LocalFieldFixture::STAFF_ID,
                [
                    'legal_name' => LocalFieldFixture::USER_NAME,
                    'preferred_name' => 'Local Field',
                    'handle' => 'local-field-author',
                    'formerly_known_as' => null,
                    'email' => LocalFieldFixture::USER_EMAIL,
                    'phone' => null,
                    'city' => null,
                    'state' => null,
                    'date_of_birth' => '1990-01-01',
                    'emergency_contact_name' => null,
                    'emergency_contact_phone' => null,
                    'archived_at' => null,
                ],
                uniqueBy: ['handle' => 'local-field-author'],
            );
            if (! $staff->users()->whereKey($user->id)->exists()) {
                $staff->users()->attach($user->id);
            }

            StaffOrganizationStatus::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'staff_id' => $staff->id,
                ],
                [
                    'status' => StaffOrganizationStatus::STATUS_ACTIVE,
                    'status_reason' => 'Local fixture IC-capable test account.',
                    'status_changed_at' => now(),
                    'status_changed_by_user_id' => $user->id,
                ],
            );

            $this->ensureDepartmentTeamMembership($staff, $department, $team, $user);
            $this->ensureIncidentCommandGrant($team, $event);

            $device = $this->upsert(Device::class, LocalFieldFixture::DEVICE_ID, [
                'device_label' => 'Local Field Device',
                'platform' => 'browser',
                'device_public_key' => base64_encode(str_repeat('D', 32)),
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'revoked_at' => null,
            ]);

            DeviceTrust::query()->updateOrCreate(
                [
                    'user_id' => LocalFieldFixture::USER_ID,
                    'device_id' => LocalFieldFixture::DEVICE_ID,
                ],
                [
                    'trusted_node_fingerprint' => hash('sha256', 'local-field-fixture'),
                    'first_trusted_at' => now(),
                    'last_seen_at' => now(),
                    'expires_at' => DeviceTrust::expiresAtFrom(now()),
                    'revoked_at' => null,
                ],
            );

            $this->upsert(
                Node::class,
                LocalFieldFixture::NODE_ID,
                [
                    'node_name' => 'local-field-node',
                    'node_role' => Node::ROLE_DEVELOPMENT,
                    'public_key' => base64_encode(str_repeat('L', 32)),
                    'organization_id' => LocalFieldFixture::ORGANIZATION_ID,
                    'event_id' => LocalFieldFixture::EVENT_ID,
                    'central_node_url' => null,
                    'revoked_at' => null,
                ],
                uniqueBy: ['node_name' => 'local-field-node'],
            );
        });

        $this->info('Local Field fixture seeded.');
        $this->line('User: '.LocalFieldFixture::USER_EMAIL.' / password');
        $this->line('Event ID: '.LocalFieldFixture::EVENT_ID);
        $this->line('Run `corepack pnpm run env:local` from the repository root to configure matching local API/client env.');

        return self::SUCCESS;
    }

    private function ensureDepartmentTeamMembership(
        Staff $staff,
        Department $department,
        Team $team,
        User $changedBy,
    ): void {
        $membership = $staff->departmentMemberships()
            ->where('department_id', $department->id)
            ->whereNull('archived_at')
            ->first();

        if ($membership === null) {
            app(DepartmentMembershipService::class)->createWithTeams(
                $staff,
                $department,
                [$team],
                DepartmentMembership::STATUS_ACTIVE,
                'Local fixture IC-capable test account.',
                $changedBy,
            );

            return;
        }

        $membership->forceFill([
            'status' => DepartmentMembership::STATUS_ACTIVE,
            'status_reason' => 'Local fixture IC-capable test account.',
        ])->save();

        $membership->teamMemberships()->updateOrCreate(
            [
                'team_id' => $team->id,
                'staff_id' => $staff->id,
            ],
            [
                'membership_role' => 'member',
                'archived_at' => null,
            ],
        );
    }

    private function ensureIncidentCommandGrant(Team $team, Event $event): void
    {
        $role = PermissionRole::query()
            ->where('code', PermissionCatalog::ROLE_IC_OPERATOR)
            ->firstOrFail();

        $existingGrant = TeamGrant::query()
            ->active()
            ->where('team_id', $team->id)
            ->where('event_id', $event->id)
            ->where('permission_role_id', $role->id)
            ->exists();

        if (! $existingGrant) {
            app(TeamGrantService::class)->grant($team, $role, $event);
        }
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $uniqueBy
     */
    private function upsert(
        string $model,
        string $id,
        array $attributes,
        array $uniqueBy = [],
    ): Model {
        $record = $model::query()->find($id);

        if ($record === null && $uniqueBy !== []) {
            $conflict = $model::query()->where($uniqueBy)->first();
            if ($conflict !== null && (string) $conflict->getKey() !== $id) {
                $this->deleteConflictingFixtureRow($model, $conflict);
            } elseif ($conflict !== null) {
                $record = $conflict;
            }
        }

        $record ??= new $model;
        $record->forceFill(['id' => $id, ...$attributes])->save();

        return $record->fresh() ?? $record;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function deleteConflictingFixtureRow(string $model, Model $conflict): void
    {
        if ($model === Organization::class) {
            Event::query()
                ->where('organization_id', $conflict->getKey())
                ->where('slug', 'local-field-event')
                ->each(function (Event $event): void {
                    $event->delete();
                });
        }

        if ($model === Event::class) {
            Node::query()
                ->where('event_id', $conflict->getKey())
                ->where('node_name', 'local-field-node')
                ->each(function (Node $node): void {
                    $node->delete();
                });
        }

        if ($model === User::class) {
            DeviceTrust::query()->where('user_id', $conflict->getKey())->delete();
        }

        $conflict->delete();
    }
}
