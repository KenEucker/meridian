<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use App\Models\SharedWorkstation;
use App\Models\User;
use App\Orchid\Screens\SharedWorkstation\SharedWorkstationListScreen;
use App\Services\Kiosk\KioskPinnedContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

/**
 * God Mode Kiosk pinned context (M18.32; UI-019, UI-021; technical spec 13.1).
 *
 * "Shared workstations are managed in God mode", and the first pin has to be
 * God Mode's because a workstation with no pinned organization has none for an
 * organizer's authority to be held in.
 */
class SharedWorkstationOrchidTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    private function godModeUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                SharedWorkstationListScreen::PERMISSION => true,
            ],
        ]);
    }

    public function test_god_mode_pins_a_workstation_that_had_no_context_and_the_change_is_audited(): void
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $event = Event::factory()->for($organization)->create(['name' => 'Emberfall 2026']);
        $workstation = SharedWorkstation::factory()->create([
            'name' => 'onsite-command-1',
            'organization_id' => null,
            'event_id' => null,
            'context_pinned_at' => null,
        ]);

        $response = $this->screen('platform.shared-workstations')
            ->actingAs($this->godModeUser(), 'web')
            ->method('pin', [
                'pin' => [
                    'shared_workstation_id' => $workstation->getKey(),
                    'organization_id' => $organization->getKey(),
                    'event_id' => $event->getKey(),
                    'department_id' => null,
                ],
            ]);

        $response->assertOk();

        $workstation->refresh();

        $this->assertTrue($workstation->hasPinnedKioskContext());
        $this->assertSame($event->getKey(), $workstation->event_id);

        $this->assertDatabaseHas('audit_events', [
            'action' => KioskPinnedContextService::AUDIT_PINNED,
            'entity_id' => (string) $workstation->getKey(),
            'source_context' => AuditEvent::SOURCE_ORCHID,
        ]);
    }

    /**
     * The department pin is admissible only for a department that works the
     * event, and the refusal says which rule it broke rather than quietly
     * dropping the field.
     */
    public function test_a_department_that_does_not_work_the_event_is_refused_with_its_reason(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $kitchen = Department::factory()->for($organization)->create(['name' => 'Kitchen']);
        $workstation = SharedWorkstation::factory()->create([
            'organization_id' => $organization->getKey(),
            'event_id' => $event->getKey(),
        ]);

        $this->screen('platform.shared-workstations')
            ->actingAs($this->godModeUser(), 'web')
            ->method('pin', [
                'pin' => [
                    'shared_workstation_id' => $workstation->getKey(),
                    'organization_id' => null,
                    'event_id' => $event->getKey(),
                    'department_id' => $kitchen->getKey(),
                ],
            ])
            ->assertSee('does not work this event');

        $this->assertNull($workstation->refresh()->department_id);

        // The same department, now on the event's participating list, is taken.
        EventDepartmentAssignment::query()->create([
            'event_id' => $event->getKey(),
            'department_id' => $kitchen->getKey(),
        ]);

        $this->screen('platform.shared-workstations')
            ->actingAs($this->godModeUser(), 'web')
            ->method('pin', [
                'pin' => [
                    'shared_workstation_id' => $workstation->getKey(),
                    'organization_id' => null,
                    'event_id' => $event->getKey(),
                    'department_id' => $kitchen->getKey(),
                ],
            ])
            ->assertOk();

        $this->assertSame($kitchen->getKey(), $workstation->refresh()->department_id);
    }

    /**
     * The identifier a machine in setup asks for has to be readable somewhere a
     * technician can reach (M18.64). Until this column existed the only ways to
     * obtain one were an artisan command and a database query, and the Kiosk
     * setup screen asked for a value the console did not show.
     */
    public function test_the_list_shows_the_identifier_and_workstation_code_a_technician_types(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();

        $pinned = SharedWorkstation::factory()->create([
            'name' => 'onsite-command-1',
            'organization_id' => $organization->getKey(),
            'event_id' => $event->getKey(),
            'short_code' => 'BCS2TK4A',
        ]);

        // An unpinned workstation has no event for a code to be unique in, and
        // the column says so rather than rendering an empty cell.
        $unpinned = SharedWorkstation::factory()->create([
            'name' => 'gate-desk-2',
            'organization_id' => null,
            'event_id' => null,
            'short_code' => null,
        ]);

        $this->screen('platform.shared-workstations')
            ->actingAs($this->godModeUser(), 'web')
            ->display()
            ->assertOk()
            ->assertSee((string) $pinned->getKey(), false)
            ->assertSee('BCS2TK4A', false)
            ->assertSee((string) $unpinned->getKey(), false)
            ->assertSee('Assigned when pinned', false);
    }

    public function test_the_screen_is_refused_without_the_permission(): void
    {
        $this->screen('platform.shared-workstations')
            ->actingAs(User::factory()->create(['permissions' => ['platform.index' => true]]), 'web')
            ->display()
            ->assertForbidden();
    }
}
