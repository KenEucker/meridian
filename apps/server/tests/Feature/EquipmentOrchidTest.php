<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

class EquipmentOrchidTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    public function test_orchid_equipment_list_displays_inventory(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Idaho Burners',
        ]);
        $event = Event::factory()->for($organization)->create([
            'name' => 'Idaho Decompression 2026',
        ]);
        $department = Department::factory()->for($organization)->create([
            'name' => 'Rangers',
        ]);

        EquipmentItem::factory()->create([
            'organization_id' => $organization->id,
            'event_id' => $event->id,
            'department_id' => $department->id,
            'name' => 'Radio 12',
            'asset_tag' => 'RDO-12',
            'status' => EquipmentItem::STATUS_DAMAGED,
        ]);

        $response = $this->actingAs($this->equipmentAdmin())->get(route('platform.equipment'));

        $response->assertOk();
        $response->assertSee('Equipment');
        $response->assertSee('Radio 12');
        $response->assertSee('RDO-12');
        $response->assertSee('Damaged');
        $response->assertSee('Idaho Burners');
        $response->assertSee('Idaho Decompression 2026');
        $response->assertSee('Rangers');
    }

    public function test_orchid_equipment_edit_displays_scaffold(): void
    {
        $equipmentItem = EquipmentItem::factory()->create([
            'name' => 'Safety Vest',
            'asset_tag' => 'VEST-04',
            'serial_number' => 'SN-1234',
        ]);

        $response = $this->actingAs($this->equipmentAdmin())
            ->get(route('platform.equipment.edit', $equipmentItem));

        $response->assertOk();
        $response->assertSee('Edit Equipment');
        $response->assertSee('Safety Vest');
        $response->assertSee('VEST-04');
        $response->assertSee('SN-1234');
        $response->assertSee('Save');
        $response->assertSee('Cancel');
        $response->assertSee('Archive');
    }

    public function test_orchid_equipment_screen_requires_permission(): void
    {
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('platform.equipment'));

        $response->assertForbidden();
    }

    public function test_orchid_equipment_save_creates_inventory_item(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();

        $response = $this->screen('platform.equipment.create')
            ->actingAs($this->equipmentAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'equipmentItem' => [
                    'organization_id' => $organization->id,
                    'event_id' => $event->id,
                    'department_id' => $department->id,
                    'name' => 'Radio 14',
                    'asset_tag' => 'RDO-14',
                    'serial_number' => 'SN-RDO-14',
                    'status' => EquipmentItem::STATUS_AVAILABLE,
                ],
            ]);

        $response->assertRedirect(route('platform.equipment'));

        $this->assertDatabaseHas('equipment_items', [
            'organization_id' => $organization->id,
            'event_id' => $event->id,
            'department_id' => $department->id,
            'name' => 'Radio 14',
            'asset_tag' => 'RDO-14',
            'serial_number' => 'SN-RDO-14',
            'status' => EquipmentItem::STATUS_AVAILABLE,
        ]);
    }

    public function test_orchid_equipment_save_rejects_mismatched_scope(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $event = Event::factory()->for($otherOrganization)->create();

        $response = $this->screen('platform.equipment.create')
            ->actingAs($this->equipmentAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'equipmentItem' => [
                    'organization_id' => $organization->id,
                    'event_id' => $event->id,
                    'name' => 'Radio 15',
                    'status' => EquipmentItem::STATUS_AVAILABLE,
                ],
            ]);

        $response->assertSessionHasErrors('equipmentItem.event_id');
        $this->assertDatabaseMissing('equipment_items', [
            'name' => 'Radio 15',
        ]);
    }

    private function equipmentAdmin(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.equipment' => true,
            ],
        ]);
    }
}
