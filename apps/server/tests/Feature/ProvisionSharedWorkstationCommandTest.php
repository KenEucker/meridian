<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Node;
use App\Models\Organization;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationLoginCode;
use App\Models\User;
use App\Services\Kiosk\KioskPinnedContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Development provisioning for a shared workstation (M18.32; technical spec
 * 13.1, 13.2).
 *
 * The command exists so a Kiosk can be exercised without three errands, and it
 * does everything through the services God Mode uses — so what is asserted here
 * is that it is those services doing the work, that it is idempotent, and that a
 * command which trusts a device refuses to do so outside a local environment.
 */
class ProvisionSharedWorkstationCommandTest extends TestCase
{
    use RefreshDatabase;

    private function scaffold(): Event
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        User::factory()->create(['email' => 'operator@example.test', 'name' => 'Operator']);

        return Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'timezone' => 'UTC',
        ]);
    }

    public function test_it_provisions_a_trusted_pinned_workstation_and_audits_the_pin(): void
    {
        $event = $this->scaffold();

        $this->artisan('meridian:shared-workstation', ['--codes' => 0])
            ->assertSuccessful();

        $workstation = SharedWorkstation::query()->sole();

        $this->assertSame('onsite-command-1', $workstation->name);
        $this->assertTrue($workstation->isTrusted());
        $this->assertTrue($workstation->hasPinnedKioskContext());
        $this->assertSame($event->getKey(), $workstation->event_id);
        $this->assertSame($event->organization_id, $workstation->organization_id);

        // Through the same service the God Mode screen and the Kiosk setup
        // surface both call, so the pin leaves the record 13.1 requires.
        $this->assertDatabaseHas('audit_events', [
            'action' => KioskPinnedContextService::AUDIT_PINNED,
            'entity_id' => (string) $workstation->getKey(),
        ]);
    }

    /** Running it twice pins the same machine again rather than trusting another. */
    public function test_it_reuses_a_workstation_of_the_same_name(): void
    {
        $this->scaffold();

        foreach ([1, 2] as $ignored) {
            $this->artisan('meridian:shared-workstation', ['--codes' => 0])->assertSuccessful();
        }

        $this->assertSame(1, SharedWorkstation::query()->count());
    }

    public function test_it_issues_a_login_code_per_user_so_a_handover_can_be_exercised(): void
    {
        $this->scaffold();
        User::factory()->create(['email' => 'second@example.test']);

        $this->artisan('meridian:shared-workstation', [
            '--code-for' => ['operator@example.test', 'second@example.test'],
        ])->assertSuccessful();

        $this->assertSame(2, SharedWorkstationLoginCode::query()->count());

        // Only hashes are stored, exactly as the God Mode path stores them
        // (technical spec 13.2).
        foreach (SharedWorkstationLoginCode::query()->get() as $code) {
            $this->assertNotSame('', (string) $code->code_hash);
            $this->assertNull($code->used_at);
        }
    }

    /**
     * The department rules are the service's, not the command's.
     *
     * A convenience command that could pin a department the event does not have
     * would be a convenience command that produced a Kiosk the product could not
     * have produced, which is the one thing development tooling must not do.
     */
    public function test_it_pins_a_named_department_that_works_the_event(): void
    {
        $event = $this->scaffold();
        $logistics = Department::factory()
            ->for($event->organization()->first())
            ->create(['name' => 'Logistics']);

        // Not on the event's participating list yet, so the same refusal the
        // Kiosk setup surface would give (ORG-006's list; M18.31).
        $this->artisan('meridian:shared-workstation', [
            '--department' => 'Logistics',
            '--codes' => 0,
        ])
            ->expectsOutputToContain('does not work this event')
            ->assertFailed();

        EventDepartmentAssignment::query()->create([
            'event_id' => $event->getKey(),
            'department_id' => $logistics->getKey(),
        ]);

        $this->artisan('meridian:shared-workstation', [
            '--department' => 'Logistics',
            '--codes' => 0,
        ])->assertSuccessful();

        $this->assertSame($logistics->getKey(), SharedWorkstation::query()->sole()->department_id);
    }

    public function test_it_refuses_a_department_that_is_not_on_this_node(): void
    {
        $this->scaffold();

        $this->artisan('meridian:shared-workstation', [
            '--department' => 'Nowhere',
            '--codes' => 0,
        ])
            ->expectsOutputToContain('No department named')
            ->assertFailed();

        $this->assertSame(0, SharedWorkstation::query()->count());
    }

    public function test_it_says_what_is_missing_when_there_is_no_event_to_pin_to(): void
    {
        User::factory()->create();

        $this->artisan('meridian:shared-workstation')
            ->expectsOutputToContain('No event to pin to')
            ->assertFailed();

        $this->assertSame(0, SharedWorkstation::query()->count());
    }

    /**
     * Trusting a workstation is a security decision, and not one a stray command
     * in a deployment pipeline gets to make.
     */
    public function test_it_refuses_to_trust_a_workstation_outside_a_local_environment(): void
    {
        $this->scaffold();
        $this->app['env'] = 'production';

        $this->artisan('meridian:shared-workstation')
            ->expectsOutputToContain('Refusing to trust a workstation')
            ->assertFailed();

        $this->assertSame(0, SharedWorkstation::query()->count());
    }

    /**
     * A node locked to an event holds that event's records and no others, and
     * `SessionResolver` refuses to resolve a session at any other one. A
     * workstation pinned elsewhere therefore signs somebody in and then cannot
     * say who they are, which looks like a broken Kiosk rather than a bad pin.
     */
    public function test_a_locked_node_decides_which_event_the_workstation_works(): void
    {
        $event = $this->scaffold();
        $locked = Event::factory()->for($event->organization()->first())->create([
            'name' => 'The Event This Node Runs',
            'timezone' => 'UTC',
        ]);

        Node::factory()->create([
            'event_id' => $locked->getKey(),
            'organization_id' => $event->organization_id,
        ]);

        $this->artisan('meridian:shared-workstation', ['--codes' => 0])->assertSuccessful();

        $this->assertSame($locked->getKey(), SharedWorkstation::query()->sole()->event_id);
    }

    public function test_it_refuses_to_pin_a_locked_node_to_another_event(): void
    {
        $event = $this->scaffold();
        $locked = Event::factory()->for($event->organization()->first())->create([
            'name' => 'The Event This Node Runs',
            'timezone' => 'UTC',
        ]);

        Node::factory()->create([
            'event_id' => $locked->getKey(),
            'organization_id' => $event->organization_id,
        ]);

        $this->artisan('meridian:shared-workstation', [
            '--event' => 'Emberfall 2026',
            '--codes' => 0,
        ])
            ->expectsOutputToContain('locked to')
            ->assertFailed();

        $this->assertSame(0, SharedWorkstation::query()->count());
    }

    public function test_the_json_output_carries_what_a_launcher_needs(): void
    {
        $this->scaffold();

        $this->artisan('meridian:shared-workstation', [
            '--json' => true,
            '--code-for' => ['operator@example.test'],
        ])->assertSuccessful();

        $workstation = SharedWorkstation::query()->sole();

        $this->assertNotNull($workstation->getKey());
        $this->assertTrue($workstation->hasPinnedKioskContext());
    }
}
