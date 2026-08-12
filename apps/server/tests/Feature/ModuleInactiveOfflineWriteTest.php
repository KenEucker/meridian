<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Modules\ModuleKey;
use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\FieldReport;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\SyncConflict;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Node\SyncConflictResolutionException;
use App\Services\Node\SyncConflictResolver;
use App\Services\Offline\OfflineWriteCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A queued offline write that landed on an inactive module (MOD-017; technical
 * spec 15A.8; data/API 7.2, 7.5, 7.6; M19.17).
 *
 * The requirement forbids two outcomes and this is where both are pinned. It is
 * not **silently applied** — the module gate refuses it exactly as it refuses
 * everything else, and no record is written. It is not **silently dropped** —
 * an open conflict lands in the God Mode queue naming the module, the device's
 * own key, and what was sent, so an organizer who switched a module off does not
 * take somebody's night's work with it without anybody finding out.
 *
 * The event-window rule (MOD-010) makes this rare by construction: module state
 * cannot change during an active event, which is when offline queues are
 * deepest. Rare is not never, and a queue drained the morning after an
 * organizer narrowed the product is exactly the case.
 */
class ModuleInactiveOfflineWriteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Event $event;

    private Department $department;

    private Team $team;

    private Shift $shift;

    private Staff $staff;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $this->event = Event::factory()->for($this->organization)->create();
        $this->department = Department::factory()->for($this->organization)->create();
        $this->team = Team::factory()->for($this->department)->create();

        $this->shift = Shift::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->department->id,
            'eligible_team_id' => $this->team->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(4),
        ]);

        [$this->staff, $this->operator] = $this->member(PermissionCatalog::ROLE_LEAD_ORGANIZER);
    }

    /*
    |--------------------------------------------------------------------------
    | MOD-017: recorded, not dropped
    |--------------------------------------------------------------------------
    */

    public function test_a_queued_write_against_a_now_inactive_module_becomes_a_sync_conflict(): void
    {
        $this->deactivate(ModuleKey::Scheduling);

        $key = (string) Str::uuid();

        $this->actingAsClient($this->operator)
            ->postJson(route('api.commands.add-staff-to-shift'), [
                'shift_id' => $this->shift->id,
                'staff_id' => $this->staff->id,
                'operation_uuid' => $key,
                'device_created_at' => now()->subHours(6)->toIso8601String(),
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'module_inactive')
            ->assertJsonPath('error.module', ModuleKey::Scheduling->value);

        // Not silently applied.
        $this->assertDatabaseCount('shift_assignments', 0);

        // Not silently dropped.
        $conflict = SyncConflict::query()->sole();

        $this->assertSame(SyncConflict::TYPE_MODULE_INACTIVE, $conflict->conflict_type);
        $this->assertSame(SyncConflict::STATUS_OPEN, $conflict->status);
        $this->assertSame($key, $conflict->origin_operation_uuid);
        $this->assertNull($conflict->operation_id);
        $this->assertSame('shift_assignment', $conflict->entity_type);
        $this->assertSame((string) $this->shift->id, $conflict->entity_id);
        $this->assertStringContainsString('Scheduling', (string) $conflict->reason);
    }

    /**
     * The row has to be readable as a piece of work somebody did, or an operator
     * looking at the queue can only see that something was refused.
     */
    public function test_the_conflict_carries_the_write_the_device_sent(): void
    {
        $this->deactivate(ModuleKey::Scheduling);

        $key = (string) Str::uuid();
        $recordedAt = now()->subHours(6)->toIso8601String();

        $this->queueShiftAddition($key, $recordedAt);

        $local = SyncConflict::query()->sole()->local_value_json;

        $this->assertSame('add-staff-to-shift', $local['command']);
        $this->assertSame($key, $local['operation_uuid']);
        $this->assertSame((string) $this->operator->id, $local['submitted_by_user_id']);
        $this->assertSame((string) $this->shift->id, $local['fields']['shift_id']);
        $this->assertSame((string) $this->staff->id, $local['fields']['staff_id']);
        $this->assertSame($recordedAt, $local['fields']['device_created_at']);
    }

    /**
     * Entitled-and-not-enabled and not-entitled produce the same absence for the
     * device and different work for whoever resolves the conflict (MOD-005), so
     * the row reports both halves rather than the one word "inactive".
     */
    public function test_the_conflict_records_both_halves_of_the_module_state(): void
    {
        $this->stateFor(ModuleKey::Scheduling, entitled: false, enabled: true);

        $this->queueShiftAddition((string) Str::uuid());

        $remote = SyncConflict::query()->sole()->remote_value_json;

        $this->assertSame((string) $this->organization->id, $remote['organization_id']);
        $this->assertSame(ModuleKey::Scheduling->value, $remote['module']);
        $this->assertFalse($remote['entitled']);
        $this->assertTrue($remote['enabled']);
        $this->assertFalse($remote['active']);
    }

    /**
     * The key is the command (data/API 5.3). A device that sends its queued
     * write again after a lost reply is sending the same command, so it meets
     * the conflict it already has rather than filing a second one — otherwise
     * a device retrying on every drain would bury the queue it is reporting to.
     */
    public function test_a_repeated_delivery_of_one_queued_write_is_one_conflict(): void
    {
        $this->deactivate(ModuleKey::Scheduling);

        $key = (string) Str::uuid();

        $this->queueShiftAddition($key);
        $this->queueShiftAddition($key);
        $this->queueShiftAddition($key);

        $this->assertSame(1, SyncConflict::query()->count());
    }

    /**
     * Two queued writes are two refusals, however alike they look: an operator
     * needs to know that two people's work was turned away, not one.
     */
    public function test_two_queued_writes_are_two_conflicts(): void
    {
        $this->deactivate(ModuleKey::Scheduling);

        $this->queueShiftAddition((string) Str::uuid());
        $this->queueShiftAddition((string) Str::uuid());

        $this->assertSame(2, SyncConflict::query()->count());
    }

    /**
     * A request carrying no device key was composed with the node in front of
     * it, which makes it a client offering a surface it should not have
     * (MOD-015, M19.16) rather than work somebody is owed an answer about. It is
     * refused and forgotten, the way every other refusal is.
     */
    public function test_a_live_request_is_refused_without_filing_a_conflict(): void
    {
        $this->deactivate(ModuleKey::Scheduling);

        $this->actingAsClient($this->operator)
            ->postJson(route('api.commands.add-staff-to-shift'), [
                'shift_id' => $this->shift->id,
                'staff_id' => $this->staff->id,
            ])
            ->assertNotFound();

        $this->assertSame(0, SyncConflict::query()->count());
    }

    /**
     * MOD-012's refusal does not vary by who is asking, and M19.17 must not make
     * it vary by how the request arrived either. The queue row is a side effect
     * of the refusal and is invisible in it.
     */
    public function test_the_refusal_is_identical_whether_or_not_a_conflict_was_filed(): void
    {
        $this->deactivate(ModuleKey::Scheduling);

        $live = $this->actingAsClient($this->operator)
            ->postJson(route('api.commands.add-staff-to-shift'), [
                'shift_id' => $this->shift->id,
                'staff_id' => $this->staff->id,
            ]);

        $queued = $this->queueShiftAddition((string) Str::uuid());

        $this->assertSame($live->getStatusCode(), $queued->getStatusCode());
        $this->assertSame($live->json(), $queued->json());
    }

    /**
     * The Field Report path, which is the other module-owned offline write and
     * the one with the most work behind it: a report composed at a dead camp is
     * the case data/API 7.2 exists for.
     */
    public function test_a_queued_field_report_against_an_inactive_module_becomes_a_sync_conflict(): void
    {
        $this->deactivate(ModuleKey::IncidentManagement);

        $reportId = (string) Str::uuid();

        $this->actingAsClient($this->operator)
            ->postJson(route('api.commands.submit-field-report'), [
                'id' => $reportId,
                'event_id' => $this->event->id,
                'staff_id' => $this->staff->id,
                'department_id' => $this->department->id,
                'title' => 'Lost radio at Gate 3',
                'body' => 'Handed a radio back at the gate with no tag on it.',
                'device_submitted_at' => now()->subHours(3)->toIso8601String(),
                'origin_device_id' => (string) Str::uuid(),
            ])
            ->assertNotFound()
            ->assertJsonPath('error.module', ModuleKey::IncidentManagement->value);

        $this->assertSame(0, FieldReport::query()->count());

        $conflict = SyncConflict::query()->sole();

        $this->assertSame($reportId, $conflict->origin_operation_uuid);
        $this->assertSame('field_report', $conflict->entity_type);
        $this->assertSame($reportId, $conflict->entity_id);
        $this->assertSame(
            'Lost radio at Gate 3',
            $conflict->local_value_json['fields']['title'],
        );
    }

    /**
     * A photo is megabytes and the queue is not a blob store. Everything that
     * identifies the refused upload survives; the bytes stay on the device that
     * is still holding the queue entry.
     */
    public function test_a_refused_photo_upload_keeps_its_identity_and_not_its_bytes(): void
    {
        $this->deactivate(ModuleKey::IncidentManagement);

        $report = FieldReport::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->department->id,
            'staff_id' => $this->staff->id,
            'submitted_by_user_id' => $this->operator->id,
        ]);

        $attachmentId = (string) Str::uuid();

        $this->actingAsClient($this->operator)
            ->postJson(route('api.commands.upload-field-report-photo'), [
                'id' => $attachmentId,
                'field_report_id' => $report->id,
                'origin_device_id' => (string) Str::uuid(),
                'checksum_sha256' => str_repeat('a', 64),
                'bytes_base64' => base64_encode(str_repeat('x', 4096)),
            ])
            ->assertNotFound();

        $local = SyncConflict::query()->sole()->local_value_json;

        $this->assertSame($attachmentId, $local['operation_uuid']);
        $this->assertSame((string) $report->id, $local['fields']['field_report_id']);
        $this->assertArrayNotHasKey('bytes_base64', $local['fields']);
    }

    /**
     * Core capability never reaches this path, whatever a device queued and
     * however long it held it: check-in does not require a shift (requirements
     * 5.8), so an organization with Scheduling switched off still takes it.
     */
    public function test_a_queued_core_write_is_unaffected_by_an_inactive_module(): void
    {
        EventDepartmentAssignment::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->department->id,
        ]);
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);

        $this->deactivate(ModuleKey::Scheduling);

        $this->actingAsClient($this->operator)
            ->postJson(route('api.commands.mark-staff-on-site'), [
                'event_id' => $this->event->id,
                'department_id' => $this->department->id,
                'staff_id' => $this->staff->id,
            ])
            ->assertSuccessful();

        $this->assertSame(0, SyncConflict::query()->count());
        $this->assertDatabaseCount('event_department_presences', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution (data/API 7.5)
    |--------------------------------------------------------------------------
    */

    public function test_a_refused_device_write_is_closed_by_keeping_the_central_version(): void
    {
        $this->deactivate(ModuleKey::Scheduling);
        $this->queueShiftAddition((string) Str::uuid());

        $reviewer = User::factory()->create();
        $conflict = SyncConflict::query()->sole();

        $resolved = app(SyncConflictResolver::class)->acceptCentral($conflict, $reviewer);

        $this->assertTrue($resolved->isResolved());
        $this->assertSame(SyncConflict::RESOLUTION_ACCEPT_CENTRAL, $resolved->resolution);
        $this->assertSame($reviewer->id, $resolved->reviewed_by_user_id);

        // Still not applied: keeping the central version keeps the
        // organization's configuration, and the write was never work the node
        // may do.
        $this->assertDatabaseCount('shift_assignments', 0);

        $this->assertDatabaseHas('audit_events', [
            'action' => SyncConflictResolver::AUDIT_RESOLVED,
            'entity_id' => $conflict->id,
            'actor_user_id' => $reviewer->id,
            'organization_id' => $this->organization->id,
        ]);
    }

    /**
     * There is nothing to accept from the device. Applying the write would mean
     * writing a record for capability the organization has switched off, which
     * is the thing MOD-012 refuses everywhere else, so the conflict stays open
     * for the choice that can actually be made.
     */
    public function test_a_refused_device_write_cannot_be_accepted_from_the_device(): void
    {
        $this->deactivate(ModuleKey::Scheduling);
        $this->queueShiftAddition((string) Str::uuid());

        $conflict = SyncConflict::query()->sole();

        try {
            app(SyncConflictResolver::class)->acceptOnsite($conflict, User::factory()->create());
            $this->fail('Accepting a refused device write from the device should be refused.');
        } catch (SyncConflictResolutionException $exception) {
            $this->assertSame(
                SyncConflictResolutionException::REASON_MODULE_INACTIVE,
                $exception->reason,
            );
        }

        $this->assertTrue($conflict->refresh()->isOpen());
        $this->assertDatabaseCount('shift_assignments', 0);
        $this->assertDatabaseMissing('audit_events', [
            'action' => SyncConflictResolver::AUDIT_RESOLVED,
            'entity_id' => $conflict->id,
        ]);
    }

    public function test_the_recommended_resolution_is_the_one_that_can_be_carried_out(): void
    {
        $this->deactivate(ModuleKey::Scheduling);
        $this->queueShiftAddition((string) Str::uuid());

        $this->assertSame(
            SyncConflict::RESOLUTION_ACCEPT_CENTRAL,
            app(SyncConflictResolver::class)->defaultResolutionFor(SyncConflict::query()->sole()),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Data/API 7.2: the declaration behind all of it
    |--------------------------------------------------------------------------
    */

    /**
     * Every offline write declares a route that exists. A command renamed on one
     * side and not the other would leave the node unable to recognize a queued
     * write, and the failure would be a conflict that quietly stopped being
     * recorded rather than anything a caller could see.
     */
    public function test_every_declared_offline_write_names_a_route_that_exists(): void
    {
        foreach (OfflineWriteCommand::cases() as $command) {
            $this->assertTrue(
                app('router')->has($command->routeName()),
                "`{$command->value}` is declared as an offline write and `{$command->routeName()}` does not exist.",
            );
        }
    }

    /**
     * A module-owned offline write must be describable, or MOD-017 has no path
     * for it: without a key the node cannot tell it from a live request, and
     * without a subject the conflict row would name nothing.
     */
    public function test_every_module_owned_offline_write_can_be_recorded(): void
    {
        foreach (OfflineWriteCommand::cases() as $command) {
            if ($command->module() === null) {
                continue;
            }

            $this->assertNotNull(
                $command->operationKey(),
                "`{$command->value}` is module-owned and declares no device key.",
            );
            $this->assertNotNull(
                $command->subjectKey(),
                "`{$command->value}` is module-owned and declares no subject.",
            );
            $this->assertNotNull($command->entityType());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    private function queueShiftAddition(string $key, ?string $recordedAt = null): TestResponse
    {
        return $this->actingAsClient($this->operator)
            ->postJson(route('api.commands.add-staff-to-shift'), [
                'shift_id' => $this->shift->id,
                'staff_id' => $this->staff->id,
                'operation_uuid' => $key,
                'device_created_at' => $recordedAt ?? now()->subHours(6)->toIso8601String(),
            ]);
    }

    private function deactivate(ModuleKey $module): void
    {
        $this->stateFor($module, entitled: true, enabled: false);
    }

    private function stateFor(ModuleKey $module, bool $entitled, bool $enabled): void
    {
        OrganizationModule::query()->updateOrCreate(
            [
                'organization_id' => $this->organization->id,
                'module_key' => $module->value,
            ],
            [
                'entitled' => $entitled,
                'enabled' => $enabled,
            ],
        );
    }

    /**
     * @return array{0: Staff, 1: User}
     */
    private function member(?string $roleCode = null): array
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        StaffOrganizationStatus::factory()->create([
            'staff_id' => $staff->id,
            'organization_id' => $this->organization->id,
        ]);

        $membership = DepartmentMembership::factory()
            ->for($this->department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $this->team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
            'membership_role' => 'lead',
        ]);

        if ($roleCode !== null) {
            $this->grant($roleCode);
        }

        return [$staff, $user];
    }

    private function grant(string $roleCode): void
    {
        TeamGrant::factory()->create([
            'team_id' => $this->team->id,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);
    }
}
