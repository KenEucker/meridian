<?php

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationSession;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Kiosk\KioskPinnedContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Kiosk pinned context (M18.32; UI-019 through UI-021; technical spec 13.1).
 *
 * A Kiosk must know before anybody signs in whether the machine is pinned to an
 * organization and an event, and it must not work that out from anything it
 * holds locally (UI-020). So the read answers a caller with no credential at
 * all, and the write — which UI-021 gives to organizers, lead organizers, and
 * God Mode — answers to `organization.events.manage`.
 */
class KioskPinnedContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unpinned_workstation_reports_itself_unpinned_to_a_caller_holding_nothing(): void
    {
        // The whole point of the read. A Kiosk in this state has no session, no
        // token, and no way to be given one — a login code is scoped to a pinned
        // event — so an answer it cannot obtain would leave it inferring, which
        // is exactly what UI-020 forbids.
        $workstation = SharedWorkstation::factory()->create([
            'organization_id' => null,
            'event_id' => null,
            'department_id' => null,
            'context_pinned_at' => null,
            'name' => 'Gate A Workstation',
        ]);

        $this->getJson(route('api.kiosk.workstations.show', $workstation))
            ->assertOk()
            ->assertJsonPath('pinned', false)
            ->assertJsonPath('organization', null)
            ->assertJsonPath('event', null)
            ->assertJsonPath('shared_workstation.name', 'Gate A Workstation');
    }

    public function test_a_pinned_workstation_reports_its_organization_event_and_department(): void
    {
        [$organization, , $event] = $this->scaffold();
        $logistics = Department::factory()->for($organization)->create(['name' => 'Logistics']);
        $this->participate($event, $logistics);

        $workstation = $this->workstation($organization, $event, $logistics);

        $this->getJson(route('api.kiosk.workstations.show', $workstation))
            ->assertOk()
            ->assertJsonPath('pinned', true)
            ->assertJsonPath('organization.name', 'Northwood Collective')
            ->assertJsonPath('event.name', 'Emberfall 2026')
            ->assertJsonPath('department.name', 'Logistics');
    }

    /**
     * The read discloses a machine's context and nothing about the people or the
     * work behind it, so an untrusted or revoked machine is simply not a
     * workstation this node answers for.
     */
    public function test_an_untrusted_or_revoked_workstation_is_not_answered_for(): void
    {
        [$organization, , $event] = $this->scaffold();

        $untrusted = SharedWorkstation::factory()->untrusted()->create([
            'organization_id' => $organization->id,
            'event_id' => $event->id,
        ]);
        $revoked = SharedWorkstation::factory()->revoked()->create([
            'organization_id' => $organization->id,
            'event_id' => $event->id,
        ]);

        $this->getJson(route('api.kiosk.workstations.show', $untrusted))->assertNotFound();
        $this->getJson(route('api.kiosk.workstations.show', $revoked))->assertNotFound();
    }

    public function test_an_organizer_moves_a_workstation_to_another_event_and_the_change_is_audited(): void
    {
        [$organization, $organizer, $event] = $this->scaffold();
        $workstation = $this->workstation($organization, $event);

        $nextEvent = Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2027',
            'slug' => 'emberfall-2027',
            'timezone' => 'UTC',
        ]);

        $this->actingAsClient($organizer)
            ->putJson(route('api.kiosk.workstations.pinned-context.update', $workstation), [
                'event_id' => (string) $nextEvent->id,
            ])
            ->assertOk()
            ->assertJsonPath('pinned', true)
            ->assertJsonPath('event.name', 'Emberfall 2027')
            ->assertJsonPath('department', null);

        $this->assertDatabaseHas('shared_workstations', [
            'id' => (string) $workstation->id,
            'event_id' => (string) $nextEvent->id,
            'context_pinned_by_user_id' => $organizer->id,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'shared_workstation.context_pinned',
            'entity_id' => (string) $workstation->id,
            'actor_user_id' => $organizer->id,
            'event_id' => (string) $nextEvent->id,
        ]);
    }

    public function test_a_department_that_does_not_work_the_event_is_refused(): void
    {
        [$organization, $organizer, $event] = $this->scaffold();
        $workstation = $this->workstation($organization, $event);

        // The organization's own department, and not on this event's
        // participating list — the same list ORG-006 admits the Incident Command
        // designation from (M18.31).
        $absent = Department::factory()->for($organization)->create(['name' => 'Kitchen']);

        $this->actingAsClient($organizer)
            ->putJson(route('api.kiosk.workstations.pinned-context.update', $workstation), [
                'event_id' => (string) $event->id,
                'department_id' => (string) $absent->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'department_not_participating');

        $this->assertDatabaseHas('shared_workstations', [
            'id' => (string) $workstation->id,
            'department_id' => null,
        ]);
    }

    public function test_an_event_of_another_organization_is_refused(): void
    {
        [$organization, $organizer, $event] = $this->scaffold();
        $workstation = $this->workstation($organization, $event);

        $elsewhere = Event::factory()->for(Organization::factory()->create())->create();

        $this->actingAsClient($organizer)
            ->putJson(route('api.kiosk.workstations.pinned-context.update', $workstation), [
                'event_id' => (string) $elsewhere->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'event_out_of_scope');

        $this->assertDatabaseHas('shared_workstations', [
            'id' => (string) $workstation->id,
            'event_id' => (string) $event->id,
        ]);
    }

    /**
     * TEAM-014: a Staff Coordinator holds application review and "no other
     * organizer governance capability", and deciding which event a machine on
     * site is working is governance.
     */
    public function test_a_staff_coordinator_cannot_change_a_pinned_context(): void
    {
        [$organization, , $event] = $this->scaffold();
        $workstation = $this->workstation($organization, $event);
        $coordinator = $this->userHoldingRoleIn($organization, PermissionCatalog::ROLE_STAFF_COORDINATOR);

        $this->actingAsClient($coordinator)
            ->putJson(route('api.kiosk.workstations.pinned-context.update', $workstation), [
                'event_id' => (string) $event->id,
            ])
            ->assertStatus(403);
    }

    public function test_an_organizer_of_another_organization_cannot_change_a_pinned_context(): void
    {
        [$organization, , $event] = $this->scaffold();
        $workstation = $this->workstation($organization, $event);

        $stranger = $this->userHoldingRoleIn(
            Organization::factory()->create(['name' => 'Somebody Else']),
            PermissionCatalog::ROLE_ORGANIZER,
        );

        $this->actingAsClient($stranger)
            ->putJson(route('api.kiosk.workstations.pinned-context.update', $workstation), [
                'event_id' => (string) $event->id,
            ])
            ->assertStatus(403);
    }

    /**
     * Technical spec 13.1 gives the first pin to God Mode, because a workstation
     * with no pinned organization has no organization for an organizer's
     * authority to be held in.
     */
    public function test_a_workstation_with_no_pinned_organization_is_god_modes_to_pin(): void
    {
        [$organization, $organizer, $event] = $this->scaffold();
        $workstation = SharedWorkstation::factory()->create([
            'organization_id' => null,
            'event_id' => null,
            'context_pinned_at' => null,
        ]);

        $this->actingAsClient($organizer)
            ->putJson(route('api.kiosk.workstations.pinned-context.update', $workstation), [
                'event_id' => (string) $event->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'workstation_organization_unpinned');

        // The God Mode path is the service, which the Orchid screen calls with
        // the organization the API refuses to take.
        app(KioskPinnedContextService::class)->pin(
            workstation: $workstation,
            event: $event,
            department: null,
            actor: $organizer,
            organization: $organization,
        );

        $this->assertTrue($workstation->refresh()->hasPinnedKioskContext());
    }

    /**
     * The pinned context frames the shell for whoever is signed in, so a session
     * established under the old one does not survive the change.
     */
    public function test_repinning_ends_the_live_session_at_the_workstation(): void
    {
        [$organization, $organizer, $event] = $this->scaffold();
        $workstation = $this->workstation($organization, $event);

        $session = SharedWorkstationSession::factory()->create([
            'shared_workstation_id' => $workstation->id,
            'user_id' => User::factory()->create()->id,
            'event_id' => $event->id,
            'started_at' => now(),
            'last_activity_at' => now(),
            'ended_at' => null,
            'ended_reason' => null,
        ]);

        $nextEvent = Event::factory()->for($organization)->create(['timezone' => 'UTC']);

        $this->actingAsClient($organizer)
            ->putJson(route('api.kiosk.workstations.pinned-context.update', $workstation), [
                'event_id' => (string) $nextEvent->id,
            ])
            ->assertOk();

        $session->refresh();

        $this->assertNotNull($session->ended_at);
        $this->assertSame(SharedWorkstationSession::ENDED_SUPERSEDED, $session->ended_reason);
    }

    public function test_the_options_read_offers_the_organizations_events_with_who_works_each(): void
    {
        [$organization, $organizer, $event] = $this->scaffold();
        $logistics = Department::factory()->for($organization)->create(['name' => 'Logistics']);
        $this->participate($event, $logistics);

        $workstation = $this->workstation($organization, $event);

        $this->actingAsClient($organizer)
            ->getJson(route('api.kiosk.workstations.pinned-context.options', $workstation))
            ->assertOk()
            ->assertJsonPath('options.0.name', 'Emberfall 2026')
            ->assertJsonPath('options.0.departments.0.name', 'Logistics');
    }

    /**
     * @return array{0: Organization, 1: User, 2: Event}
     */
    private function scaffold(): array
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $organizersDepartment = Department::factory()->for($organization)->create([
            'name' => 'Organizers',
        ]);
        $organization->forceFill(['organizers_department_id' => $organizersDepartment->id])->save();

        $event = Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall-2026',
            'timezone' => 'UTC',
        ]);

        return [
            $organization->refresh(),
            $this->userHoldingRoleIn($organization, PermissionCatalog::ROLE_ORGANIZER),
            $event,
        ];
    }

    private function workstation(
        Organization $organization,
        Event $event,
        ?Department $department = null,
    ): SharedWorkstation {
        return SharedWorkstation::factory()->create([
            'organization_id' => $organization->id,
            'event_id' => $event->id,
            'department_id' => $department?->id,
        ]);
    }

    private function participate(Event $event, Department $department): void
    {
        EventDepartmentAssignment::query()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);
    }

    private function userHoldingRoleIn(Organization $organization, string $roleCode): User
    {
        $department = Department::query()
            ->where('organization_id', $organization->id)
            ->firstOr(fn (): Department => Department::factory()->for($organization)->create());

        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $team = Team::factory()->for($department)->create(['name' => $roleCode.' team']);
        $membership = DepartmentMembership::factory()->for($department)->for($staff)->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()
                ->where('code', $roleCode)
                ->firstOrFail()->id,
        ]);

        return $user;
    }
}
