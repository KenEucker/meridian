<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Equipment\EquipmentCheckoutException;
use App\Services\Equipment\EquipmentCheckoutService;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class EquipmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_equipment_tables_have_documented_mvp_fields_and_states(): void
    {
        $this->assertTrue(Schema::hasTable('equipment_items'));
        $this->assertTrue(Schema::hasTable('equipment_checkouts'));

        foreach ([
            'id',
            'organization_id',
            'event_id',
            'department_id',
            'name',
            'asset_tag',
            'serial_number',
            'status',
            'created_at',
            'updated_at',
            'archived_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('equipment_items', $column),
                "equipment_items.{$column} missing",
            );
        }

        foreach ([
            'id',
            'equipment_item_id',
            'event_id',
            'staff_id',
            'shift_id',
            'checked_out_at',
            'checked_out_by_user_id',
            'returned_at',
            'returned_by_user_id',
            'return_condition',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('equipment_checkouts', $column),
                "equipment_checkouts.{$column} missing",
            );
        }

        $this->assertSame([
            'available',
            'checked_out',
            'returned',
            'missing',
            'damaged',
        ], EquipmentItem::statuses());
    }

    public function test_shift_lead_can_checkout_equipment_to_rostered_staff(): void
    {
        [$shift, $staff, , $shiftLead, $equipment] = $this->scheduledScenario();
        $checkedOutAt = Carbon::parse('2026-07-01 09:15:00');

        $result = app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $equipment,
            staff: $staff,
            actor: $shiftLead,
            shift: $shift,
            checkedOutAt: $checkedOutAt,
        );

        $this->assertTrue($result->createdStateChange);
        $this->assertSame(EquipmentItem::STATUS_CHECKED_OUT, $result->equipmentItem->status);
        $this->assertTrue($result->checkout->checked_out_at->equalTo($checkedOutAt));
        $this->assertSame((string) $staff->id, (string) $result->checkout->staff_id);
        $this->assertSame((string) $shift->id, (string) $result->checkout->shift_id);
        $this->assertSame((string) $shiftLead->id, (string) $result->checkout->checked_out_by_user_id);

        $this->assertDatabaseHas('equipment_items', [
            'id' => $equipment->id,
            'status' => EquipmentItem::STATUS_CHECKED_OUT,
        ]);

        $this->assertDatabaseHas('equipment_checkouts', [
            'id' => $result->checkout->id,
            'equipment_item_id' => $equipment->id,
            'event_id' => $shift->event_id,
            'staff_id' => $staff->id,
            'shift_id' => $shift->id,
            'checked_out_by_user_id' => $shiftLead->id,
            'returned_at' => null,
        ]);

        $audit = AuditEvent::query()
            ->where('action', 'equipment.checked_out')
            ->where('entity_id', $result->checkout->id)
            ->firstOrFail();

        $this->assertSame($shiftLead->id, $audit->actor_user_id);
        $this->assertSame($shift->event->organization_id, $audit->organization_id);
        $this->assertSame($shift->event_id, $audit->event_id);
        $this->assertSame($shift->department_id, $audit->department_id);
        $this->assertSame(EquipmentItem::STATUS_AVAILABLE, $audit->before_json['status']);
        $this->assertSame(EquipmentItem::STATUS_CHECKED_OUT, $audit->after_json['status']);
        $this->assertSame($staff->id, $audit->after_json['staff_id']);
        $this->assertSame(AuditEvent::SOURCE_API, $audit->source_context);

        $repeat = app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $equipment->refresh(),
            staff: $staff,
            actor: $shiftLead,
            shift: $shift,
            checkedOutAt: Carbon::parse('2026-07-01 09:16:00'),
        );

        $this->assertFalse($repeat->createdStateChange);
        $this->assertSame($result->checkout->id, $repeat->checkout->id);
        $this->assertDatabaseCount('equipment_checkouts', 1);
        $this->assertSame(1, AuditEvent::query()->where('action', 'equipment.checked_out')->count());
    }

    public function test_department_lead_can_checkout_department_equipment_without_shift_context(): void
    {
        [$shift, $staff, , , $equipment] = $this->scheduledScenario();
        $departmentLead = $this->departmentLeadUserFor($shift->department);

        $result = app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $equipment,
            staff: $staff,
            actor: $departmentLead,
            checkedOutAt: Carbon::parse('2026-07-01 09:20:00'),
        );

        $this->assertTrue($result->createdStateChange);
        $this->assertNull($result->checkout->shift_id);
        $this->assertSame($shift->event_id, $result->checkout->event_id);
        $this->assertSame($departmentLead->id, $result->checkout->checked_out_by_user_id);
    }

    public function test_returning_equipment_records_condition_and_updates_item_state(): void
    {
        [$shift, $staff, , $shiftLead, $equipment] = $this->scheduledScenario();

        $checkout = app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $equipment,
            staff: $staff,
            actor: $shiftLead,
            shift: $shift,
            checkedOutAt: Carbon::parse('2026-07-01 09:15:00'),
        )->checkout;

        $returnedAt = Carbon::parse('2026-07-01 15:45:00');

        $result = app(EquipmentCheckoutService::class)->returnEquipment(
            checkout: $checkout,
            actor: $shiftLead,
            returnCondition: EquipmentItem::STATUS_DAMAGED,
            returnedAt: $returnedAt,
        );

        $this->assertTrue($result->createdStateChange);
        $this->assertSame(EquipmentItem::STATUS_DAMAGED, $result->equipmentItem->status);
        $this->assertTrue($result->checkout->returned_at->equalTo($returnedAt));
        $this->assertSame((string) $shiftLead->id, (string) $result->checkout->returned_by_user_id);
        $this->assertSame(EquipmentItem::STATUS_DAMAGED, $result->checkout->return_condition);

        $this->assertDatabaseHas('equipment_items', [
            'id' => $equipment->id,
            'status' => EquipmentItem::STATUS_DAMAGED,
        ]);

        $audit = AuditEvent::query()
            ->where('action', 'equipment.returned')
            ->where('entity_id', $checkout->id)
            ->firstOrFail();

        $this->assertSame(EquipmentItem::STATUS_CHECKED_OUT, $audit->before_json['status']);
        $this->assertNull($audit->before_json['returned_at']);
        $this->assertSame(EquipmentItem::STATUS_DAMAGED, $audit->after_json['status']);
        $this->assertSame(EquipmentItem::STATUS_DAMAGED, $audit->after_json['return_condition']);
        $this->assertSame($shiftLead->id, $audit->after_json['returned_by_user_id']);

        $repeat = app(EquipmentCheckoutService::class)->returnEquipment(
            checkout: $checkout->refresh(),
            actor: $shiftLead,
            returnCondition: EquipmentItem::STATUS_DAMAGED,
            returnedAt: Carbon::parse('2026-07-01 15:46:00'),
        );

        $this->assertFalse($repeat->createdStateChange);
        $this->assertSame(1, AuditEvent::query()->where('action', 'equipment.returned')->count());
    }

    public function test_equipment_can_be_returned_as_returned_missing_or_damaged(): void
    {
        foreach ([EquipmentItem::STATUS_RETURNED, EquipmentItem::STATUS_MISSING, EquipmentItem::STATUS_DAMAGED] as $condition) {
            [$shift, $staff, , $shiftLead, $equipment] = $this->scheduledScenario();

            $checkout = app(EquipmentCheckoutService::class)->checkoutEquipment(
                equipmentItem: $equipment,
                staff: $staff,
                actor: $shiftLead,
                shift: $shift,
                checkedOutAt: Carbon::parse('2026-07-01 09:15:00'),
            )->checkout;

            $result = app(EquipmentCheckoutService::class)->returnEquipment(
                checkout: $checkout,
                actor: $shiftLead,
                returnCondition: $condition,
                returnedAt: Carbon::parse('2026-07-01 15:45:00'),
            );

            $this->assertSame($condition, $result->equipmentItem->status);
            $this->assertSame($condition, $result->checkout->return_condition);
        }
    }

    public function test_unauthorized_user_cannot_checkout_or_return_equipment(): void
    {
        [$shift, $staff, , $shiftLead, $equipment] = $this->scheduledScenario();

        try {
            app(EquipmentCheckoutService::class)->checkoutEquipment(
                equipmentItem: $equipment,
                staff: $staff,
                actor: User::factory()->create(),
                shift: $shift,
            );

            $this->fail('Unauthorized equipment checkout should have failed.');
        } catch (EquipmentCheckoutException $exception) {
            $this->assertSame('You are not authorized to manage equipment for this scope.', $exception->getMessage());
        }

        $this->assertDatabaseCount('equipment_checkouts', 0);
        $this->assertSame(EquipmentItem::STATUS_AVAILABLE, $equipment->refresh()->status);

        $checkout = app(EquipmentCheckoutService::class)->checkoutEquipment(
            equipmentItem: $equipment,
            staff: $staff,
            actor: $shiftLead,
            shift: $shift,
        )->checkout;

        try {
            app(EquipmentCheckoutService::class)->returnEquipment(
                checkout: $checkout,
                actor: User::factory()->create(),
                returnCondition: EquipmentItem::STATUS_RETURNED,
            );

            $this->fail('Unauthorized equipment return should have failed.');
        } catch (EquipmentCheckoutException $exception) {
            $this->assertSame('You are not authorized to manage equipment for this scope.', $exception->getMessage());
        }

        $this->assertNull($checkout->refresh()->returned_at);
        $this->assertSame(1, AuditEvent::query()->where('action', 'equipment.checked_out')->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'equipment.returned')->count());
    }

    public function test_checkout_rejects_archived_unavailable_cross_scope_and_unrostered_equipment(): void
    {
        [$shift, $staff, , $shiftLead, $equipment] = $this->scheduledScenario();
        $equipment->forceFill(['archived_at' => Carbon::parse('2026-07-01 08:30:00')])->save();

        try {
            app(EquipmentCheckoutService::class)->checkoutEquipment(
                equipmentItem: $equipment->refresh(),
                staff: $staff,
                actor: $shiftLead,
                shift: $shift,
            );

            $this->fail('Archived equipment checkout should have failed.');
        } catch (EquipmentCheckoutException $exception) {
            $this->assertSame('Archived equipment cannot be checked out.', $exception->getMessage());
        }

        $missingEquipment = EquipmentItem::factory()->create([
            'organization_id' => $shift->event->organization_id,
            'event_id' => $shift->event_id,
            'department_id' => $shift->department_id,
            'status' => EquipmentItem::STATUS_MISSING,
        ]);

        try {
            app(EquipmentCheckoutService::class)->checkoutEquipment(
                equipmentItem: $missingEquipment,
                staff: $staff,
                actor: $shiftLead,
                shift: $shift,
            );

            $this->fail('Missing equipment checkout should have failed.');
        } catch (EquipmentCheckoutException $exception) {
            $this->assertSame('Equipment with status missing cannot be checked out.', $exception->getMessage());
        }

        $otherDepartment = Department::factory()
            ->for($shift->event->organization)
            ->create();
        $crossScopeEquipment = EquipmentItem::factory()->create([
            'organization_id' => $shift->event->organization_id,
            'event_id' => $shift->event_id,
            'department_id' => $otherDepartment->id,
        ]);

        try {
            app(EquipmentCheckoutService::class)->checkoutEquipment(
                equipmentItem: $crossScopeEquipment,
                staff: $staff,
                actor: $shiftLead,
                shift: $shift,
            );

            $this->fail('Cross-scope equipment checkout should have failed.');
        } catch (EquipmentCheckoutException $exception) {
            $this->assertSame(
                'Equipment must belong to the same event or department as the shift when those equipment scopes are set.',
                $exception->getMessage(),
            );
        }

        $unrosteredStaff = Staff::factory()->create();

        try {
            app(EquipmentCheckoutService::class)->checkoutEquipment(
                equipmentItem: EquipmentItem::factory()->create([
                    'organization_id' => $shift->event->organization_id,
                    'event_id' => $shift->event_id,
                    'department_id' => $shift->department_id,
                ]),
                staff: $unrosteredStaff,
                actor: $shiftLead,
                shift: $shift,
            );

            $this->fail('Unrostered staff equipment checkout should have failed.');
        } catch (EquipmentCheckoutException $exception) {
            $this->assertSame(
                'Equipment checkout from the Shift Lead Board requires an active shift assignment for the staff member.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('equipment_checkouts', 0);
        $this->assertSame(0, AuditEvent::query()->where('action', 'equipment.checked_out')->count());
    }

    public function test_http_commands_checkout_and_return_equipment(): void
    {
        [$shift, $staff, , $shiftLead, $equipment] = $this->scheduledScenario();

        $checkoutResponse = $this->actingAsClient($shiftLead)
            ->postJson('/api/commands/checkout-equipment', [
                'equipment_item_id' => $equipment->id,
                'staff_id' => $staff->id,
                'shift_id' => $shift->id,
                'checked_out_at' => '2026-07-01T09:15:00Z',
            ])
            ->assertCreated()
            ->assertJsonPath('equipment_item_id', $equipment->id)
            ->assertJsonPath('staff_id', $staff->id)
            ->assertJsonPath('shift_id', $shift->id)
            ->assertJsonPath('equipment_status', EquipmentItem::STATUS_CHECKED_OUT)
            ->assertJsonPath('created_state_change', true);

        $checkoutId = (string) $checkoutResponse->json('equipment_checkout_id');

        $this->actingAsClient($shiftLead)
            ->postJson('/api/commands/checkout-equipment', [
                'equipment_item_id' => $equipment->id,
                'staff_id' => $staff->id,
                'shift_id' => $shift->id,
                'checked_out_at' => '2026-07-01T09:15:00Z',
            ])
            ->assertCreated()
            ->assertJsonPath('equipment_checkout_id', $checkoutId)
            ->assertJsonPath('created_state_change', false);

        $this->actingAsClient($shiftLead)
            ->postJson('/api/commands/return-equipment', [
                'equipment_checkout_id' => $checkoutId,
                'return_condition' => EquipmentItem::STATUS_RETURNED,
                'returned_at' => '2026-07-01T15:45:00Z',
            ])
            ->assertCreated()
            ->assertJsonPath('equipment_checkout_id', $checkoutId)
            ->assertJsonPath('equipment_status', EquipmentItem::STATUS_RETURNED)
            ->assertJsonPath('return_condition', EquipmentItem::STATUS_RETURNED)
            ->assertJsonPath('created_state_change', true);

        $this->assertDatabaseCount('equipment_checkouts', 1);
        $this->assertSame(1, AuditEvent::query()->where('action', 'equipment.checked_out')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'equipment.returned')->count());
    }

    public function test_equipment_commands_require_authentication(): void
    {
        $this->postJson('/api/commands/checkout-equipment', [
            'equipment_item_id' => (string) Str::uuid(),
            'staff_id' => (string) Str::uuid(),
        ])->assertUnauthorized();

        $this->postJson('/api/commands/return-equipment', [
            'equipment_checkout_id' => (string) Str::uuid(),
            'return_condition' => EquipmentItem::STATUS_RETURNED,
        ])->assertUnauthorized();
    }

    /**
     * @return array{0: Shift, 1: Staff, 2: ShiftAssignment, 3: User, 4: EquipmentItem}
     */
    private function scheduledScenario(): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $staff = Staff::factory()->create();

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);
        $department->load('defaultTeam');

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'title' => 'Ranger Dirt Day Shift',
            'starts_at' => Carbon::parse('2026-07-01 08:00:00'),
            'ends_at' => Carbon::parse('2026-07-01 16:00:00'),
        ]);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        $equipment = EquipmentItem::factory()->create([
            'organization_id' => $organization->id,
            'event_id' => $event->id,
            'department_id' => $department->id,
            'name' => 'Radio 12',
            'asset_tag' => 'RDO-12',
            'status' => EquipmentItem::STATUS_AVAILABLE,
        ]);

        return [
            $shift,
            $staff,
            $assignment,
            $this->shiftLeadUserFor($department->defaultTeam),
            $equipment,
        ];
    }

    private function shiftLeadUserFor(Team $team): User
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);
        $departmentMembership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
        ]);
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => $this->role('department_logistics')->id,
        ]);

        return $user;
    }

    private function departmentLeadUserFor(Department $department): User
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);
        $team = Team::factory()->for($department)->create(['name' => $department->name.' Leads']);
        $departmentMembership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
        ]);
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => $this->role('department_logistics')->id,
        ]);

        return $user;
    }

    private function role(string $code): PermissionRole
    {
        return PermissionRole::query()->where('code', $code)->firstOrFail();
    }
}
