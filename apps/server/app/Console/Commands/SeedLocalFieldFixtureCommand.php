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
 *
 * Development seed data and nothing more (M16.11). The rows it writes — an
 * organization, an event, a staff member, a device, a node — are what a
 * developer signs in against; the seeded user authenticates the same way every
 * other user does, with a login code and a device-bound token.
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

            /*
             * The team the client's shift fixtures have the fixture staff member
             * working. A Field Report filed on shift carries that team, so the
             * node has to know it exists before it can accept one.
             */
            $this->upsert(
                Team::class,
                LocalFieldFixture::RANGERS_DIRT_TEAM_ID,
                [
                    'department_id' => $department->id,
                    'name' => 'Dirt',
                    'code' => 'DIRT',
                    'description' => 'Local fixture Ranger field team.',
                    'is_default' => false,
                    'archived_at' => null,
                ],
                uniqueBy: ['department_id' => $department->id, 'code' => 'DIRT'],
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
            $this->seedSwitchableDepartments($organization, $department, $staff, $user);

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
                    /*
                     * This row is the install's *own* node, not a peer learned
                     * through pairing, and `is_local` is what says so.
                     *
                     * The column defaults to false, so omitting it here left
                     * the fixture with no resolvable local node at all —
                     * `NodeSetupService::activeNode()` filters on it. Nothing
                     * failed loudly: `GET /api/health` simply reported a null
                     * node role, organization, and event; the desktop wrapper
                     * never branded its window; and an install that names an
                     * event in this very row behaved as though it were locked
                     * to none.
                     */
                    'is_local' => true,
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
        // The fixture is seed data, not a credential (M16.11). A client reaches
        // it by signing in as this user: ask for a login code from the client's
        // sign-in screen and read the code out of the mail log.
        $this->line('Sign in from a client with this email; the login code is mailed (storage/logs in local development).');
        $this->line('Run `corepack pnpm run env:local` from the repository root to configure matching local client env.');

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

    /**
     * The other three departments the client's switcher offers, with the role
     * grants that make each switch mean the same thing on both sides.
     *
     * The grants are deliberately uneven, because the useful fixture is one
     * that exercises both outcomes:
     *
     *   - **Organizer** holds `organizer` and `lead_organizer`, the two roles
     *     BRAND-019 allows to edit an organization branding profile.
     *   - **Rangers** holds `department_lead`, so department branding is
     *     editable there.
     *   - **Gate** is plain staff and **DPW** is a team lead. Neither may edit
     *     its own department's branding, which gives two distinct denial paths
     *     to check rather than an install where everything is permitted and
     *     nothing is proved.
     *
     * Grants are organization-scoped (`event_id` null) rather than tied to the
     * fixture event, because branding is organization governance data and an
     * organizer keeps that authority between events.
     *
     * Each grant hangs off the department's own default team. TEAM-002 creates
     * that team with the department, so it is the one team every department is
     * guaranteed to have.
     */
    private function seedSwitchableDepartments(
        Organization $organization,
        Department $rangers,
        Staff $staff,
        User $user,
    ): void {
        $rangersDefault = $this->defaultTeamFor($rangers);
        $this->grantRole($rangersDefault, PermissionCatalog::ROLE_DEPARTMENT_LEAD);

        $organizers = $this->upsertDepartment(
            $organization,
            LocalFieldFixture::ORGANIZER_DEPARTMENT_ID,
            'Organizer',
            'ORG',
            'Organization-level event administration.',
        );
        $organizersDefault = $this->defaultTeamFor($organizers);

        // Recorded before the grants, not after: ORG-005 makes the Organizers
        // Department an organization's organizer home, and TeamGrantService
        // refuses an organizer role until the organization names one.
        $organization->forceFill(['organizers_department_id' => $organizers->id])->save();

        $this->grantRole($organizersDefault, PermissionCatalog::ROLE_ORGANIZER);
        $this->grantRole($organizersDefault, PermissionCatalog::ROLE_LEAD_ORGANIZER);

        $gate = $this->upsertDepartment(
            $organization,
            LocalFieldFixture::GATE_DEPARTMENT_ID,
            'Gate',
            'GATE',
            'Entry and credentialing.',
        );

        $dpw = $this->upsertDepartment(
            $organization,
            LocalFieldFixture::DPW_DEPARTMENT_ID,
            'DPW',
            'DPW',
            'Build, roads, and event infrastructure.',
        );
        $dpwDefault = $this->defaultTeamFor($dpw);

        // A team lead, which is deliberately not enough for branding.
        $this->grantRole($dpwDefault, PermissionCatalog::ROLE_SHIFT_LEAD);

        $this->ensureDepartmentTeamMembership($staff, $rangers, $rangersDefault, $user);
        $this->ensureDepartmentTeamMembership($staff, $organizers, $organizersDefault, $user);
        $this->ensureDepartmentTeamMembership($staff, $gate, $this->defaultTeamFor($gate), $user);
        $this->ensureDepartmentTeamMembership($staff, $dpw, $dpwDefault, $user);
    }

    private function upsertDepartment(
        Organization $organization,
        string $id,
        string $name,
        string $code,
        string $description,
    ): Department {
        /** @var Department $department */
        $department = $this->upsert(
            Department::class,
            $id,
            [
                'organization_id' => $organization->id,
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'archived_at' => null,
            ],
            uniqueBy: ['organization_id' => $organization->id, 'code' => $code],
        );

        return $department;
    }

    /**
     * The department's default team, creating it when an older fixture row
     * predates the boot hook that now adds one.
     */
    private function defaultTeamFor(Department $department): Team
    {
        $department->refresh();

        $existing = $department->default_team_id !== null
            ? Team::query()->find($department->default_team_id)
            : null;

        if ($existing instanceof Team) {
            return $existing;
        }

        $team = $department->teams()->firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default', 'description' => null, 'is_default' => true],
        );

        $department->forceFill(['default_team_id' => $team->getKey()])->saveQuietly();

        return $team;
    }

    private function grantRole(Team $team, string $roleCode): void
    {
        $role = PermissionRole::query()->where('code', $roleCode)->firstOrFail();

        $existing = TeamGrant::query()
            ->active()
            ->where('team_id', $team->getKey())
            ->whereNull('event_id')
            ->where('permission_role_id', $role->id)
            ->exists();

        if (! $existing) {
            app(TeamGrantService::class)->grant($team, $role);
        }
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
