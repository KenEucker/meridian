<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Modules\ModuleKey;
use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\PermissionRole;
use App\Models\PolicyDocument;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\Training;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The module gate (MOD-012, MOD-013; technical spec 15A.4, 15A.5; data/API 5.9,
 * 6.8; M19.12).
 *
 * An inactive module is absent from the product rather than hidden in it: its
 * endpoints refuse, the refusal names the module, and it is the same refusal
 * whoever asks.
 */
class ModuleGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One route per module with endpoints, and the request that reaches it.
     *
     * The Briefing and Insights have no endpoints in the build yet — their
     * domains are the ones technical spec 15A.2 names and Milestone 18 has not
     * finished — so there is nothing here to refuse for them. They carry
     * catalogue entries and no routes, and
     * {@see ModuleRouteCoverageTest} is what will notice the day one arrives
     * ungated.
     *
     * @return array<string, array{0: ModuleKey, 1: string}>
     */
    public static function gatedEndpoints(): array
    {
        return [
            'scheduling' => [ModuleKey::Scheduling, 'shifts'],
            'ims' => [ModuleKey::IncidentManagement, 'incidents'],
            'documents' => [ModuleKey::Documents, 'documents'],
            'qualifications' => [ModuleKey::Qualifications, 'trainings'],
            'equipment' => [ModuleKey::Equipment, 'equipment'],
            'geography' => [ModuleKey::EventGeography, 'deployments'],
        ];
    }

    /**
     * MOD-012: an inactive module's API endpoints refuse. One per module, so a
     * module whose gate is wired to the wrong key or to nothing at all is a
     * failure naming that module rather than a general one.
     */
    #[DataProvider('gatedEndpoints')]
    public function test_an_inactive_module_refuses_its_endpoints(ModuleKey $module, string $surface): void
    {
        $world = $this->world();

        $this->deactivate($world['organization'], $module);

        $this->actingAsClient($world['organizer'])
            ->getJson($this->url($world, $surface))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'module_inactive')
            ->assertJsonPath('error.module', $module->value);
    }

    /**
     * The same endpoints do not refuse on module state when the module is
     * active, so the test above is measuring the gate rather than a route that
     * never answered.
     *
     * Not-a-module-refusal rather than 200: each of these surfaces authorizes
     * differently — the incident list wants Incident Command standing, the
     * deployment index wants department operations — and a caller assembled to
     * satisfy all six would be testing this file's fixture rather than the
     * gate. What matters here is that the gate let the request through to
     * whatever decision the surface makes for itself.
     */
    #[DataProvider('gatedEndpoints')]
    public function test_an_active_module_does_not_refuse_its_endpoints(ModuleKey $module, string $surface): void
    {
        $world = $this->world();

        $response = $this->actingAsClient($world['organizer'])
            ->getJson($this->url($world, $surface));

        $this->assertNull(
            $response->json('error.code'),
            "[{$surface}] refused with the module active.",
        );
    }

    /**
     * MOD-005, MOD-007: active is entitled *and* enabled, and the gate reads
     * the pair rather than either half.
     */
    public function test_either_half_of_module_state_being_off_refuses(): void
    {
        $world = $this->world();

        foreach ([[true, false], [false, true], [false, false]] as [$entitled, $enabled]) {
            OrganizationModule::query()
                ->where('organization_id', $world['organization']->id)
                ->delete();

            OrganizationModule::factory()
                ->forModule(ModuleKey::Scheduling)
                ->create([
                    'organization_id' => $world['organization']->id,
                    'entitled' => $entitled,
                    'enabled' => $enabled,
                ]);

            $this->actingAsClient($world['organizer'])
                ->getJson($this->url($world, 'shifts'))
                ->assertNotFound()
                ->assertJsonPath('error.module', 'scheduling');
        }
    }

    /**
     * MOD-013, data/API 6.8: the refusal does not depend on the caller.
     *
     * A department lead who may manage these shifts, a staff member who may
     * not, and a God Mode operator all receive byte-identical answers. God Mode
     * is in here because 6.8 is explicit that it does not bypass the gate: it
     * changes module state from the console rather than reaching past it
     * through the product API.
     */
    public function test_the_refusal_is_identical_for_permitted_unpermitted_and_god_mode(): void
    {
        $world = $this->world();

        $this->deactivate($world['organization'], ModuleKey::Scheduling);

        $godMode = $this->memberOf($world);
        $godMode->forceFill(['permissions' => ['platform.index' => true, 'platform.systems' => true]])->save();

        $answers = [];

        foreach (['organizer' => $world['organizer'], 'member' => $world['member'], 'god_mode' => $godMode] as $label => $user) {
            $answers[$label] = $this->actingAsClient($user)
                ->getJson($this->url($world, 'shifts'))
                ->assertNotFound()
                ->getContent();
        }

        $this->assertSame($answers['organizer'], $answers['member']);
        $this->assertSame($answers['organizer'], $answers['god_mode']);
    }

    /**
     * Technical spec 15A.4: the gate runs before the permission check.
     *
     * The proof is the difference between the two answers. With the module
     * active, a caller holding nothing is refused for holding nothing — a
     * permission decision was made about them. With it inactive, the same
     * caller and the same request get not-found instead, because there was no
     * capability present for a permission decision to be about.
     */
    public function test_the_gate_precedes_the_permission_check(): void
    {
        $world = $this->world();

        $withModule = $this->actingAsClient($world['member'])
            ->getJson($this->url($world, 'incidents'));

        $this->assertSame(403, $withModule->getStatusCode());

        $this->deactivate($world['organization'], ModuleKey::IncidentManagement);

        $this->actingAsClient($world['member'])
            ->getJson($this->url($world, 'incidents'))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'module_inactive')
            ->assertJsonPath('error.module', 'ims');
    }

    /**
     * MOD-012: a command against an inactive module never reaches its handler.
     *
     * Commands name their subject in the body rather than the path (data/API
     * 5.9), so this is the other half of the resolution the reads exercise: the
     * gate has to find the organization behind `shift_id` before anything
     * validates or authorizes the request.
     */
    public function test_a_command_owned_by_an_inactive_module_is_refused_before_its_handler(): void
    {
        $world = $this->world();

        $this->deactivate($world['organization'], ModuleKey::Scheduling);

        $this->actingAsClient($world['organizer'])
            ->postJson(route('api.commands.cancel-shift'), [
                'shift_id' => (string) $world['shift']->id,
                'reason' => 'Weather.',
            ])
            ->assertNotFound()
            ->assertJsonPath('error.module', 'scheduling');

        $this->assertNull($world['shift']->fresh()->canceled_at);
    }

    /**
     * MOD-004: core survives every module being off.
     *
     * An organization running nothing is still a working organization — it
     * holds departments and staff, and the endpoints that say so are ungated.
     */
    public function test_core_endpoints_answer_with_every_module_inactive(): void
    {
        $world = $this->world();

        foreach (ModuleKey::cases() as $module) {
            $this->deactivate($world['organization'], $module);
        }

        $this->actingAsClient($world['organizer'])
            ->getJson(route('api.organizations.departments.index', $world['organization']))
            ->assertOk();

        $this->actingAsClient($world['organizer'])
            ->getJson(route('api.me'))
            ->assertOk();
    }

    /**
     * A module-owned read that names no organization is absent only when none
     * of the caller's organizations runs the module.
     *
     * `/api/document-acknowledgments/me` is the case: it answers about the
     * caller across every organization they hold a status in (data/API 5.9,
     * `/api/document-acknowledgments*`). A member of one organization that runs
     * Documents still gets the read; a member of none does not.
     */
    public function test_a_caller_scoped_module_read_follows_the_callers_organizations(): void
    {
        $world = $this->world();

        $this->deactivate($world['organization'], ModuleKey::Documents);

        $this->actingAsClient($world['member'])
            ->getJson(route('api.document-acknowledgments.me'))
            ->assertNotFound()
            ->assertJsonPath('error.module', 'documents');

        // A second organization that does run Documents, and the same person in
        // it. The read comes back: the gate stops deciding once one of the
        // caller's organizations runs the module, because refusing the whole
        // read would take the surface away from the organization that does.
        // Narrowing what the read then carries is MOD-019's aggregator rule and
        // is M19.18's, not the gate's.
        $elsewhere = Organization::factory()->create();
        StaffOrganizationStatus::factory()->create([
            'staff_id' => $world['member']->staffProfiles->first()->id,
            'organization_id' => $elsewhere->id,
        ]);

        $this->actingAsClient($world['member'])
            ->getJson(route('api.document-acknowledgments.me'))
            ->assertOk();
    }

    /**
     * The gate reads the organization the *host* names as readily as the one a
     * path names (M19.8; technical spec 8.7). An organization subdomain is the
     * ordinary way an organization is addressed, so a gate that only understood
     * path form would leave every subdomain request ungated.
     */
    public function test_the_gate_reads_the_organization_from_the_request_host(): void
    {
        $world = $this->world();

        config(['meridian.platform_domain' => 'meridian.test']);

        $this->deactivate($world['organization'], ModuleKey::Documents);

        $this->actingAsClient($world['organizer'])
            ->getJson(
                route('api.document-acknowledgments.me'),
                ['Host' => $world['organization']->slug.'.meridian.test'],
            )
            ->assertNotFound()
            ->assertJsonPath('error.module', 'documents');
    }

    /**
     * The refusal is the shape data/API 5.9 specifies, and it names a
     * catalogue key rather than a label.
     */
    public function test_the_refusal_carries_the_specified_machine_readable_reason(): void
    {
        $world = $this->world();

        $this->deactivate($world['organization'], ModuleKey::IncidentManagement);

        $response = $this->actingAsClient($world['organizer'])
            ->getJson($this->url($world, 'incidents'))
            ->assertNotFound();

        $this->assertSame(
            ['code' => 'module_inactive', 'module' => 'ims'],
            $response->json('error'),
        );

        $this->assertContains(
            $response->json('error.module'),
            ModuleKey::keys(),
        );
    }

    /**
     * The web download routes are gated on the same rule as the API ones. A
     * short-lived signed URL issued while a module was active must not outlive
     * the module: 5.9 keeps the URL from being issued, and this keeps one that
     * already was from serving the file anyway.
     */
    public function test_a_signed_download_for_an_inactive_module_is_refused(): void
    {
        $world = $this->world();

        $url = URL::signedRoute(
            'downloads.exports.shift-roster',
            ['event' => $world['event']->id],
            now()->addMinutes(5),
            absolute: false,
        );

        $this->deactivate($world['organization'], ModuleKey::Scheduling);

        $this->actingAs($world['organizer'])
            ->get($url)
            ->assertNotFound();
    }

    /**
     * MOD-014: disabling a module does not touch permission grants, so what an
     * organization already decided about who may do what survives being turned
     * off and comes back with the module.
     */
    public function test_role_grants_survive_a_module_being_inactive(): void
    {
        $world = $this->world();

        $grantsBefore = TeamGrant::query()->count();

        $this->deactivate($world['organization'], ModuleKey::Scheduling);

        $this->actingAsClient($world['organizer'])
            ->getJson($this->url($world, 'shifts'))
            ->assertNotFound();

        $this->assertSame($grantsBefore, TeamGrant::query()->count());

        OrganizationModule::query()
            ->where('organization_id', $world['organization']->id)
            ->update(['entitled' => true, 'enabled' => true]);

        $this->actingAsClient($world['organizer'])
            ->getJson($this->url($world, 'shifts'))
            ->assertOk();
    }

    /**
     * One organization's module state decides nothing for another's.
     */
    public function test_another_organizations_module_state_does_not_reach_this_one(): void
    {
        $world = $this->world();
        $elsewhere = Organization::factory()->create();

        foreach (ModuleKey::cases() as $module) {
            $this->deactivate($elsewhere, $module);
        }

        $this->actingAsClient($world['organizer'])
            ->getJson($this->url($world, 'shifts'))
            ->assertOk();
    }

    private function deactivate(Organization $organization, ModuleKey $module): void
    {
        OrganizationModule::query()
            ->where('organization_id', $organization->id)
            ->where('module_key', $module->value)
            ->delete();

        OrganizationModule::factory()
            ->forModule($module)
            ->create([
                'organization_id' => $organization->id,
                'entitled' => true,
                'enabled' => false,
            ]);
    }

    /**
     * @param  array<string, mixed>  $world
     */
    private function url(array $world, string $surface): string
    {
        return match ($surface) {
            'shifts' => route('api.departments.shifts.index', $world['department']),
            'incidents' => route('api.events.incidents.index', $world['event']),
            'documents' => route('api.organizations.documents.index', $world['organization']),
            'waivers' => route('api.organizations.waivers.index', $world['organization']),
            'trainings' => route('api.departments.trainings.index', $world['department']),
            'equipment' => route('api.departments.equipment.index', $world['department']),
            'deployments' => route('api.events.departments.deployments.index', [
                'event' => $world['event'],
                'department' => $world['department'],
            ]),
        };
    }

    /**
     * One organization with one of everything a gated route needs to resolve.
     *
     * @return array<string, mixed>
     */
    private function world(): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();

        $world = [
            'organization' => $organization,
            'event' => $event,
            'department' => $department,
            'team' => $team,
        ];

        $world['organizer'] = $this->memberOf($world, PermissionCatalog::ROLE_LEAD_ORGANIZER);
        $world['member'] = $this->memberOf($world);

        $world['shift'] = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
        ]);

        Training::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
        ]);

        PolicyDocument::factory()->create(['organization_id' => $organization->id]);

        return $world;
    }

    /**
     * A user with a staff profile in the organization, optionally carrying a
     * role through the team.
     *
     * @param  array<string, mixed>  $world
     */
    private function memberOf(array $world, ?string $roleCode = null): User
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        StaffOrganizationStatus::factory()->create([
            'staff_id' => $staff->id,
            'organization_id' => $world['organization']->id,
        ]);

        $membership = DepartmentMembership::factory()
            ->for($world['department'])
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $world['team']->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
            'membership_role' => 'lead',
        ]);

        if ($roleCode !== null) {
            TeamGrant::factory()->create([
                'team_id' => $world['team']->id,
                'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
            ]);
        }

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Every assertion here is about a route that exists, so a renamed or
        // removed one should fail as a missing route rather than as a 404 that
        // reads like a module refusal.
        $this->assertNotNull(Route::getRoutes()->getByName('api.departments.shifts.index'));
    }
}
