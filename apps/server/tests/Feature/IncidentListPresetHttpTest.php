<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Incident;
use App\Models\IncidentListPreset;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Saved IMS incident list filter presets (M11.19).
 *
 * Presets are personal view state for one user on one event. They store no
 * incident content and are never an authorization source.
 */
class IncidentListPresetHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_ic_user_saves_and_reapplies_a_named_filter_selection(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);

        $critical = Incident::factory()->forEvent($event)->create([
            'title' => 'Critical record',
            'priority_label' => Incident::PRIORITY_CRITICAL,
        ]);
        Incident::factory()->forEvent($event)->create([
            'title' => 'Routine record',
            'priority_label' => Incident::PRIORITY_ROUTINE,
        ]);

        $saved = $this->actingAs($viewer)
            ->postJson('/api/commands/save-incident-list-preset', [
                'event_id' => $event->id,
                'name' => 'Critical only',
                'filters' => [
                    'priority' => Incident::PRIORITY_CRITICAL,
                    'state' => 'all',
                    'sort' => 'priority',
                    'direction' => 'asc',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('preset.name', 'Critical only')
            ->assertJsonPath('preset.filters.priority', Incident::PRIORITY_CRITICAL)
            ->assertJsonPath('preset.filters.state', 'all')
            ->assertJsonPath('preset.query.priority', Incident::PRIORITY_CRITICAL)
            ->assertJsonCount(1, 'presets');

        $presetId = $saved->json('preset.id');
        $query = http_build_query($saved->json('preset.query'));

        $this->actingAs($viewer)
            ->getJson("/api/events/{$event->id}/incidents?{$query}")
            ->assertOk()
            ->assertJsonPath('incidents.0.id', $critical->id)
            ->assertJsonCount(1, 'incidents');

        $this->actingAs($viewer)
            ->getJson("/api/events/{$event->id}/incidents")
            ->assertOk()
            ->assertJsonPath('presets.0.id', $presetId)
            ->assertJsonPath('presets.0.name', 'Critical only')
            ->assertJsonCount(1, 'presets');
    }

    public function test_presets_do_not_store_paging_position(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);

        $this->actingAs($viewer)
            ->postJson('/api/commands/save-incident-list-preset', [
                'event_id' => $event->id,
                'name' => 'Second page',
                'filters' => ['state' => 'all', 'page' => 3, 'per_page' => 5],
            ])
            ->assertCreated()
            ->assertJsonMissingPath('preset.filters.page')
            ->assertJsonMissingPath('preset.filters.per_page')
            ->assertJsonMissingPath('preset.query.page')
            ->assertJsonMissingPath('preset.query.per_page');
    }

    public function test_saving_an_existing_name_overwrites_that_preset(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);

        $first = $this->actingAs($viewer)
            ->postJson('/api/commands/save-incident-list-preset', [
                'event_id' => $event->id,
                'name' => 'My view',
                'filters' => ['priority' => Incident::PRIORITY_CRITICAL],
            ])
            ->assertCreated();

        $second = $this->actingAs($viewer)
            ->postJson('/api/commands/save-incident-list-preset', [
                'event_id' => $event->id,
                'name' => 'my view',
                'filters' => ['priority' => Incident::PRIORITY_SERIOUS],
            ])
            ->assertCreated()
            ->assertJsonPath('preset.filters.priority', Incident::PRIORITY_SERIOUS)
            ->assertJsonCount(1, 'presets');

        $this->assertSame($first->json('preset.id'), $second->json('preset.id'));
        $this->assertSame(1, IncidentListPreset::query()->count());
    }

    public function test_preset_names_are_required_bounded_and_capped_per_event(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);

        // Laravel trims before `required`, so a whitespace-only name is refused
        // at the request layer; the service keeps the same rule for direct use.
        $this->actingAs($viewer)
            ->postJson('/api/commands/save-incident-list-preset', [
                'event_id' => $event->id,
                'name' => '   ',
            ])
            ->assertStatus(422);

        $this->assertSame(0, IncidentListPreset::query()->count());

        $this->actingAs($viewer)
            ->postJson('/api/commands/save-incident-list-preset', [
                'event_id' => $event->id,
                'name' => str_repeat('a', IncidentListPreset::MAX_NAME_LENGTH + 1),
            ])
            ->assertStatus(422);

        $this->actingAs($viewer)
            ->postJson('/api/commands/save-incident-list-preset', [
                'event_id' => $event->id,
                'name' => 'Bad filters',
                'filters' => ['state' => 'archived'],
            ])
            ->assertStatus(422);

        IncidentListPreset::factory()
            ->forEvent($event)
            ->forUser($viewer)
            ->count(IncidentListPreset::MAX_PER_USER_PER_EVENT)
            ->create();

        $this->actingAs($viewer)
            ->postJson('/api/commands/save-incident-list-preset', [
                'event_id' => $event->id,
                'name' => 'One too many',
            ])
            ->assertStatus(422);

        $this->assertSame(
            IncidentListPreset::MAX_PER_USER_PER_EVENT,
            IncidentListPreset::query()->count(),
        );
    }

    public function test_presets_are_private_to_their_owner_and_event(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $owner = $this->userWithEventRole('ic_operator', $event);
        $otherIcUser = $this->userWithEventRole('ic_lead', $event);

        $ownPreset = IncidentListPreset::factory()
            ->forEvent($event)
            ->forUser($owner)
            ->create(['name' => 'Owner view']);
        IncidentListPreset::factory()
            ->forEvent($otherEvent)
            ->forUser($owner)
            ->create(['name' => 'Other event view']);

        $this->actingAs($owner)
            ->getJson("/api/events/{$event->id}/incidents")
            ->assertOk()
            ->assertJsonPath('presets.0.name', 'Owner view')
            ->assertJsonCount(1, 'presets');

        $this->actingAs($otherIcUser)
            ->getJson("/api/events/{$event->id}/incidents")
            ->assertOk()
            ->assertJsonCount(0, 'presets');

        $this->actingAs($otherIcUser)
            ->postJson('/api/commands/delete-incident-list-preset', [
                'event_id' => $event->id,
                'preset_id' => $ownPreset->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Saved incident list preset not found.');

        $this->assertModelExists($ownPreset);
    }

    public function test_owner_deletes_their_own_preset(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $owner = $this->userWithEventRole('ic_viewer', $event);
        $preset = IncidentListPreset::factory()
            ->forEvent($event)
            ->forUser($owner)
            ->create(['name' => 'Temporary view']);

        $this->actingAs($owner)
            ->postJson('/api/commands/delete-incident-list-preset', [
                'event_id' => $event->id,
                'preset_id' => $preset->id,
            ])
            ->assertOk()
            ->assertJsonCount(0, 'presets');

        $this->assertModelMissing($preset);
    }

    public function test_presets_require_incident_command_access(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $organizer = $this->userWithRole('organizer', $event, eventScoped: false);
        $revoked = $this->userWithRole('ic_operator', $event, eventScoped: true, revoked: true);
        $wrongEventViewer = $this->userWithEventRole('ic_viewer', $this->eventWithIncidentCommandDepartment());

        $this->postJson('/api/commands/save-incident-list-preset', [
            'event_id' => $event->id,
            'name' => 'Unauthenticated view',
        ])->assertUnauthorized();

        foreach ([$organizer, $revoked, $wrongEventViewer] as $actor) {
            $this->actingAs($actor)
                ->postJson('/api/commands/save-incident-list-preset', [
                    'event_id' => $event->id,
                    'name' => 'Denied view',
                ])
                ->assertForbidden()
                ->assertJsonPath(
                    'message',
                    'This page requires Incident Command access for the event configured IC department.',
                );

            $this->actingAs($actor)
                ->postJson('/api/commands/delete-incident-list-preset', [
                    'event_id' => $event->id,
                    'preset_id' => (string) Str::uuid(),
                ])
                ->assertForbidden();
        }

        $this->assertSame(0, IncidentListPreset::query()->count());
    }

    private function eventWithIncidentCommandDepartment(): Event
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();

        return Event::factory()->for($organization)->create([
            'ic_department_id' => $department->id,
            'starts_at' => Carbon::parse('2027-07-04 09:00:00', 'America/Los_Angeles')->utc(),
            'timezone' => 'America/Los_Angeles',
        ]);
    }

    private function userWithEventRole(string $roleCode, Event $event, bool $revoked = false): User
    {
        return $this->userWithRole($roleCode, $event, eventScoped: true, revoked: $revoked);
    }

    private function userWithRole(
        string $roleCode,
        Event $event,
        bool $eventScoped,
        bool $revoked = false,
    ): User {
        $department = $eventScoped && in_array($roleCode, ['ic_lead', 'ic_operator', 'ic_viewer'], true)
            ? Department::query()->findOrFail($event->ic_department_id)
            : Department::factory()->for($event->organization)->create();

        if (in_array($roleCode, ['organizer', 'lead_organizer'], true)) {
            $event->organization->forceFill(['organizers_department_id' => $department->id])->save();
        }

        $team = Team::factory()->for($department)->create();
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        $grantAttributes = [
            'team_id' => $team->id,
            'event_id' => $eventScoped ? $event->id : null,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ];

        if ($revoked) {
            TeamGrant::factory()->revoked()->create($grantAttributes);
        } else {
            TeamGrant::factory()->create($grantAttributes);
        }

        return $user;
    }
}
