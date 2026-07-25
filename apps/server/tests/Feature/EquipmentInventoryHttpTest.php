<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product-path equipment inventory setup and CSV import (M11.18; EQUIP-001
 * through EQUIP-005, EQUIP-007; UI contract 12.4 `department.equipment`).
 */
class EquipmentInventoryHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_logistics_creates_inventory_that_starts_available(): void
    {
        [$department, $logistics] = $this->departmentWithRole('department_logistics');
        $event = Event::factory()->for($department->organization)->create();

        $item = $this->actingAs($logistics)
            ->postJson('/api/commands/create-equipment-item', [
                'department_id' => $department->id,
                'name' => 'Radio 12',
                'asset_tag' => 'RAD-012',
                'serial_number' => 'SN-0012',
                'event_id' => $event->id,
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Radio 12')
            ->assertJsonPath('asset_tag', 'RAD-012')
            ->assertJsonPath('status', EquipmentItem::STATUS_AVAILABLE)
            ->assertJsonPath('status_label', 'Available')
            ->assertJsonPath('department_id', (string) $department->id)
            ->assertJsonPath('event_id', (string) $event->id)
            ->json();

        $this->assertDatabaseHas('equipment_items', [
            'id' => $item['id'],
            'organization_id' => $department->organization_id,
            'department_id' => $department->id,
            'status' => EquipmentItem::STATUS_AVAILABLE,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'equipment_item.created',
            'entity_id' => $item['id'],
        ]);

        $this->actingAs($logistics)
            ->getJson("/api/departments/{$department->id}/equipment")
            ->assertOk()
            ->assertJsonCount(1, 'equipment')
            ->assertJsonPath('equipment.0.name', 'Radio 12')
            ->assertJsonPath('equipment.0.has_open_checkout', false)
            ->assertJsonPath('access.can_manage', true);
    }

    public function test_department_lead_maintains_inventory_and_archive_preserves_the_record(): void
    {
        [$department, $lead] = $this->departmentWithRole('department_lead');

        $item = $this->actingAs($lead)
            ->postJson('/api/commands/create-equipment-item', [
                'department_id' => $department->id,
                'name' => 'Vest 3',
            ])
            ->assertCreated()
            ->json();

        $this->actingAs($lead)
            ->postJson('/api/commands/update-equipment-item', [
                'equipment_item_id' => $item['id'],
                'name' => 'Vest 3 (Large)',
                'asset_tag' => 'VEST-003',
                'status' => EquipmentItem::STATUS_DAMAGED,
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Vest 3 (Large)')
            ->assertJsonPath('status', EquipmentItem::STATUS_DAMAGED);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'equipment_item.updated',
            'entity_id' => $item['id'],
        ]);

        $this->actingAs($lead)
            ->postJson('/api/commands/archive-equipment-item', ['equipment_item_id' => $item['id']])
            ->assertOk()
            ->assertJsonPath('archived_at', fn ($value) => $value !== null);

        // Archived equipment is preserved, not deleted, and drops out of the
        // active inventory a Logistics maintainer picks from.
        $this->assertDatabaseHas('equipment_items', ['id' => $item['id']]);

        $this->actingAs($lead)
            ->getJson("/api/departments/{$department->id}/equipment?status=active")
            ->assertOk()
            ->assertJsonCount(0, 'equipment');

        $this->actingAs($lead)
            ->postJson('/api/commands/update-equipment-item', [
                'equipment_item_id' => $item['id'],
                'name' => 'Edited While Archived',
            ])
            ->assertStatus(422);

        $this->actingAs($lead)
            ->postJson('/api/commands/restore-equipment-item', ['equipment_item_id' => $item['id']])
            ->assertOk()
            ->assertJsonPath('archived_at', null);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'equipment_item.archived',
            'entity_id' => $item['id'],
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'equipment_item.restored',
            'entity_id' => $item['id'],
        ]);
    }

    public function test_inventory_setup_cannot_take_over_the_logistics_checkout_lifecycle(): void
    {
        [$department, $logistics] = $this->departmentWithRole('department_logistics');
        $event = Event::factory()->for($department->organization)->create();

        $item = EquipmentItem::factory()->create([
            'organization_id' => $department->organization_id,
            'department_id' => $department->id,
            'event_id' => $event->id,
            'name' => 'Radio 20',
            'status' => EquipmentItem::STATUS_CHECKED_OUT,
        ]);

        EquipmentCheckout::factory()->create([
            'equipment_item_id' => $item->id,
            'event_id' => $event->id,
            'staff_id' => Staff::factory()->create()->id,
            'checked_out_by_user_id' => $logistics->id,
            'returned_at' => null,
        ]);

        // Checked out and Returned are produced by check-in/check-out only.
        $this->actingAs($logistics)
            ->postJson('/api/commands/update-equipment-item', [
                'equipment_item_id' => $item->id,
                'name' => 'Radio 20',
                'status' => EquipmentItem::STATUS_CHECKED_OUT,
            ])
            ->assertStatus(422);

        // A state correction cannot bypass an open checkout either.
        $this->actingAs($logistics)
            ->postJson('/api/commands/update-equipment-item', [
                'equipment_item_id' => $item->id,
                'name' => 'Radio 20',
                'status' => EquipmentItem::STATUS_AVAILABLE,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($value) => str_contains((string) $value, 'Logistics Window'));

        $this->actingAs($logistics)
            ->postJson('/api/commands/archive-equipment-item', ['equipment_item_id' => $item->id])
            ->assertStatus(422);

        // Non-state edits still work while the item is out.
        $this->actingAs($logistics)
            ->postJson('/api/commands/update-equipment-item', [
                'equipment_item_id' => $item->id,
                'name' => 'Radio 20 (Handheld)',
            ])
            ->assertOk()
            ->assertJsonPath('status', EquipmentItem::STATUS_CHECKED_OUT);

        $this->actingAs($logistics)
            ->getJson("/api/departments/{$department->id}/equipment")
            ->assertOk()
            ->assertJsonPath('equipment.0.has_open_checkout', true);
    }

    public function test_asset_tags_stay_unique_within_the_department(): void
    {
        [$department, $logistics] = $this->departmentWithRole('department_logistics');

        $this->actingAs($logistics)
            ->postJson('/api/commands/create-equipment-item', [
                'department_id' => $department->id,
                'name' => 'Radio 12',
                'asset_tag' => 'RAD-012',
            ])
            ->assertCreated();

        $this->actingAs($logistics)
            ->postJson('/api/commands/create-equipment-item', [
                'department_id' => $department->id,
                'name' => 'Radio 12 Duplicate',
                'asset_tag' => 'RAD-012',
            ])
            ->assertStatus(422);

        // Another department may reuse the same physical labelling scheme.
        [$otherDepartment, $otherLogistics] = $this->departmentWithRole('department_logistics');

        $this->actingAs($otherLogistics)
            ->postJson('/api/commands/create-equipment-item', [
                'department_id' => $otherDepartment->id,
                'name' => 'Radio 12',
                'asset_tag' => 'RAD-012',
            ])
            ->assertCreated();
    }

    public function test_event_must_belong_to_the_department_organization(): void
    {
        [$department, $logistics] = $this->departmentWithRole('department_logistics');
        $foreignEvent = Event::factory()->for(Organization::factory()->create())->create();

        $this->actingAs($logistics)
            ->postJson('/api/commands/create-equipment-item', [
                'department_id' => $department->id,
                'name' => 'Borrowed Radio',
                'event_id' => $foreignEvent->id,
            ])
            ->assertStatus(422);
    }

    public function test_csv_import_creates_inventory_and_reports_skipped_rows(): void
    {
        [$department, $logistics] = $this->departmentWithRole('department_logistics');
        $event = Event::factory()->for($department->organization)->create();

        $csv = implode("\n", [
            'name,asset_tag,serial_number,notes',
            'Radio 12,RAD-012,SN-0012,ignored column',
            'Radio 13,RAD-013,SN-0013,',
            ',RAD-014,SN-0014,',
            'Radio 15,RAD-012,SN-0015,',
            '',
            'Vest 1,,,',
        ]);

        $result = $this->actingAs($logistics)
            ->postJson('/api/commands/import-equipment-inventory', [
                'department_id' => $department->id,
                'event_id' => $event->id,
                'csv' => $csv,
            ])
            ->assertCreated()
            ->assertJsonPath('imported', 3)
            ->assertJsonPath('skipped', 2)
            ->json();

        $this->assertSame('Missing name.', $this->reasonForLine($result['rows'], 4));
        $this->assertStringContainsString('already exists', (string) $this->reasonForLine($result['rows'], 5));

        $this->assertDatabaseHas('equipment_items', [
            'department_id' => $department->id,
            'asset_tag' => 'RAD-013',
            'event_id' => $event->id,
            'status' => EquipmentItem::STATUS_AVAILABLE,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'equipment_inventory.imported',
            'entity_id' => (string) $department->id,
        ]);

        // Re-running the same file skips everything with an asset tag rather
        // than duplicating equipment; only the untagged vest is created again.
        $this->actingAs($logistics)
            ->postJson('/api/commands/import-equipment-inventory', [
                'department_id' => $department->id,
                'event_id' => $event->id,
                'csv' => $csv,
            ])
            ->assertCreated()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('skipped', 4);

        $this->assertSame(
            1,
            EquipmentItem::query()
                ->where('department_id', $department->id)
                ->where('asset_tag', 'RAD-012')
                ->count(),
        );
    }

    public function test_csv_import_rejects_a_file_without_a_name_column(): void
    {
        [$department, $logistics] = $this->departmentWithRole('department_logistics');

        $this->actingAs($logistics)
            ->postJson('/api/commands/import-equipment-inventory', [
                'department_id' => $department->id,
                'csv' => "asset_tag,serial_number\nRAD-012,SN-0012",
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The CSV must include a "name" header column.');

        $this->actingAs($logistics)
            ->postJson('/api/commands/import-equipment-inventory', [
                'department_id' => $department->id,
                'csv' => '   ',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('csv');
    }

    public function test_staff_and_cross_department_actors_fail_closed(): void
    {
        [$department, $logistics] = $this->departmentWithRole('department_logistics');
        [, $staffUser] = $this->departmentWithRole('staff');
        [, $otherLogistics] = $this->departmentWithRole('department_logistics');

        $item = $this->actingAs($logistics)
            ->postJson('/api/commands/create-equipment-item', [
                'department_id' => $department->id,
                'name' => 'Radio 12',
            ])
            ->assertCreated()
            ->json();

        foreach ([$staffUser, $otherLogistics] as $denied) {
            $this->actingAs($denied)
                ->getJson("/api/departments/{$department->id}/equipment")
                ->assertForbidden();

            $this->actingAs($denied)
                ->postJson('/api/commands/create-equipment-item', [
                    'department_id' => $department->id,
                    'name' => 'Unauthorized Radio',
                ])
                ->assertForbidden();

            $this->actingAs($denied)
                ->postJson('/api/commands/update-equipment-item', [
                    'equipment_item_id' => $item['id'],
                    'name' => 'Hijacked Radio',
                ])
                ->assertForbidden();

            $this->actingAs($denied)
                ->postJson('/api/commands/archive-equipment-item', ['equipment_item_id' => $item['id']])
                ->assertForbidden();

            $this->actingAs($denied)
                ->postJson('/api/commands/import-equipment-inventory', [
                    'department_id' => $department->id,
                    'csv' => "name\nUnauthorized Radio",
                ])
                ->assertForbidden();
        }

        // Department-to-department allotments are out of MVP scope (EQUIP-006),
        // so equipment without a department stays God Mode repair tooling.
        $organizationItem = EquipmentItem::factory()->create([
            'organization_id' => $department->organization_id,
            'department_id' => null,
            'event_id' => null,
            'name' => 'Organization Spare',
        ]);

        $this->actingAs($logistics)
            ->postJson('/api/commands/update-equipment-item', [
                'equipment_item_id' => $organizationItem->id,
                'name' => 'Claimed Spare',
            ])
            ->assertForbidden();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function reasonForLine(array $rows, int $line): ?string
    {
        foreach ($rows as $row) {
            if ((int) $row['line'] === $line) {
                return $row['reason'] === null ? null : (string) $row['reason'];
            }
        }

        return null;
    }

    /**
     * @return array{Department, User}
     */
    private function departmentWithRole(string $roleCode): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers', 'code' => 'RANGERS']);
        $team = Team::factory()->for($department)->create(['is_default' => true]);

        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        StaffOrganizationStatus::query()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
            'status_reason' => 'Test setup.',
            'status_changed_at' => now(),
        ]);

        $membership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        if ($roleCode !== 'staff') {
            TeamGrant::factory()->create([
                'team_id' => $team->id,
                'event_id' => null,
                'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
            ]);
        }

        return [$department, $user];
    }
}
