<?php

namespace Tests\Feature;

use App\Domain\EventHorizon\EventHorizonCatalog;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventHorizonDismissal;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Event Horizon readiness contract and its presentation window (M18.38,
 * M18.38A; HORIZON-001 through HORIZON-008, HORIZON-010, HORIZON-011;
 * technical spec 21D.1 through 21D.5; data/API 5.8A).
 *
 * The two properties that matter most here are the ones the milestone names:
 * the catalogue is fixed in code with no configuration path that could add,
 * remove, or reorder a kind, and the readiness query refuses no operation and
 * writes no record.
 */
class EventHorizonContractTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | The catalogue (HORIZON-003)
    |--------------------------------------------------------------------------
    */

    /**
     * Exactly the five kinds HORIZON-003 fixes, in the order it fixes them.
     * There is no registration API, no table, and no configuration surface —
     * the catalogue is this code, so this assertion *is* the rule that an
     * organization cannot add, remove, or reorder a kind.
     */
    public function test_the_catalogue_is_exactly_the_five_fixed_kinds_in_order(): void
    {
        $this->assertSame([
            'document_acknowledgment',
            'waiver',
            'training',
            'shift_signup',
            'coverage_gap',
        ], array_column(EventHorizonCatalog::describe(), 'id'));

        // No kind's registration is organization data: nothing in the schema
        // stores an item kind, a threshold, or an ordering.
        $this->assertFalse(Schema::hasTable('event_horizon_item_kinds'));
    }

    /*
    |--------------------------------------------------------------------------
    | The readiness query (HORIZON-002, HORIZON-008; 21D.3)
    |--------------------------------------------------------------------------
    */

    public function test_the_query_answers_refuses_no_operation_and_writes_no_record(): void
    {
        $scenario = $this->scenario();

        $auditBefore = AuditEvent::query()->count();

        $response = $this->readFor($scenario['member'], $scenario)->assertOk();

        $response->assertJsonStructure([
            'context' => ['event_id', 'event_label', 'organization_id', 'time_zone', 'as_of'],
            'window' => ['applies', 'reason', 'lead_days', 'opens_at', 'closes_at'],
            'presentable',
            'hidden',
            'can_hide',
            'outstanding_count',
            'kinds',
            'items',
        ]);

        // Compiled on read and persisted nowhere (21D.3): the only table the
        // feature owns holds preferences, and the read wrote none — and no
        // audit event either, because reading your own readiness is not an
        // operation (21D.10).
        $this->assertSame(0, EventHorizonDismissal::query()->count());
        $this->assertSame($auditBefore, AuditEvent::query()->count());
    }

    public function test_the_read_requires_a_credential_and_event_standing(): void
    {
        $scenario = $this->scenario();

        $this->getJson("/api/events/{$scenario['event']->id}/event-horizon")
            ->assertUnauthorized();

        // A login with no staff standing in the event's organization has no
        // readiness list, rather than an empty one (HORIZON-002).
        $this->actingAsClient(User::factory()->create())
            ->getJson("/api/events/{$scenario['event']->id}/event-horizon")
            ->assertForbidden();
    }

    /**
     * A kind whose records the viewer cannot read is absent from the response
     * rather than empty (HORIZON-002; 21D.5): a plain member reads no coverage
     * gap kind, and a lead of a team reads it.
     */
    public function test_an_unreadable_kind_is_absent_rather_than_empty(): void
    {
        $scenario = $this->scenario();

        $memberKinds = array_column(
            $this->readFor($scenario['member'], $scenario)->json('kinds'),
            'id',
        );
        $this->assertNotContains('coverage_gap', $memberKinds);
        $this->assertContains('shift_signup', $memberKinds);

        $leadKinds = array_column(
            $this->readFor($scenario['teamLead'], $scenario)->json('kinds'),
            'id',
        );
        $this->assertContains('coverage_gap', $leadKinds);
    }

    /*
    |--------------------------------------------------------------------------
    | The presentation window (M18.38A; HORIZON-011; 21D.4)
    |--------------------------------------------------------------------------
    */

    public function test_the_window_opens_at_the_lead_up_boundary_and_closes_with_the_operations_window(): void
    {
        $scenario = $this->scenario();

        // The scenario's active window is July 1 through July 10 with the
        // 30-day default lead, so the surface opens June 1.
        foreach ([
            ['2027-05-31 23:59:59', false, 'before_lead_up'],
            ['2027-06-01 00:00:00', true, 'open'],
            ['2027-07-05 12:00:00', true, 'open'],
            ['2027-07-10 00:00:00', true, 'open'],
            ['2027-07-10 00:00:01', false, 'closed'],
        ] as [$moment, $applies, $reason]) {
            Carbon::setTestNow(Carbon::parse($moment));

            $window = $this->readFor($scenario['member'], $scenario)->json('window');

            $this->assertSame($applies, $window['applies'], "at {$moment}");
            $this->assertSame($reason, $window['reason'], "at {$moment}");
        }
    }

    /**
     * The window is held in days, not dates: moving the event moves the
     * lead-up with it (21D.4, the SHIFT-017 reasoning).
     */
    public function test_a_moved_event_window_moves_the_lead_up_window_with_it(): void
    {
        $scenario = $this->scenario();

        Carbon::setTestNow(Carbon::parse('2027-06-15 12:00:00'));
        $this->assertTrue($this->readFor($scenario['member'], $scenario)->json('window.applies'));

        $scenario['event']->update([
            'active_event_window_starts_at' => Carbon::parse('2027-09-01 00:00:00'),
            'active_event_window_ends_at' => Carbon::parse('2027-09-10 00:00:00'),
        ]);

        $window = $this->readFor($scenario['member'], $scenario)->json('window');
        $this->assertFalse($window['applies']);
        $this->assertSame('before_lead_up', $window['reason']);
        $this->assertSame(
            Carbon::parse('2027-08-02 00:00:00')->toIso8601String(),
            $window['opens_at'],
        );
    }

    public function test_an_event_with_no_active_window_presents_no_surface(): void
    {
        $scenario = $this->scenario();

        $scenario['event']->update([
            'active_event_window_starts_at' => null,
            'active_event_window_ends_at' => null,
        ]);

        $window = $this->readFor($scenario['member'], $scenario)->json('window');
        $this->assertFalse($window['applies']);
        $this->assertSame('no_active_window', $window['reason']);
    }

    /*
    |--------------------------------------------------------------------------
    | The lead-up window as organization configuration (M18.38A; ORG-018)
    |--------------------------------------------------------------------------
    */

    public function test_the_lead_up_window_is_organization_configuration_and_the_change_is_audited(): void
    {
        $scenario = $this->scenario();

        // Outside the active window, because governance edits freeze inside it.
        Carbon::setTestNow(Carbon::parse('2027-03-01 12:00:00'));

        $this->actingAsClient($scenario['organizer'])
            ->postJson('/api/commands/update-organization-configuration', [
                'organization_id' => (string) $scenario['organization']->id,
                'event_horizon_lead_days' => 10,
            ])
            ->assertOk()
            ->assertJsonPath('configuration.event_horizon_lead_days', 10);

        $audit = AuditEvent::query()
            ->where('action', 'organization.configuration_updated')
            ->where('entity_id', $scenario['organization']->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame(30, $audit->before_json['event_horizon_lead_days'] ?? null);
        $this->assertSame(10, $audit->after_json['event_horizon_lead_days'] ?? null);

        // With a 10-day lead the surface no longer opens on June 1.
        Carbon::setTestNow(Carbon::parse('2027-06-15 12:00:00'));
        $window = $this->readFor($scenario['member'], $scenario)->json('window');
        $this->assertFalse($window['applies']);
        $this->assertSame(10, $window['lead_days']);

        Carbon::setTestNow(Carbon::parse('2027-06-21 00:00:00'));
        $this->assertTrue($this->readFor($scenario['member'], $scenario)->json('window.applies'));
    }

    public function test_the_lead_up_window_cannot_be_cleared_and_rejects_out_of_range_values(): void
    {
        $scenario = $this->scenario();
        Carbon::setTestNow(Carbon::parse('2027-03-01 12:00:00'));

        $this->actingAsClient($scenario['organizer'])
            ->postJson('/api/commands/update-organization-configuration', [
                'organization_id' => (string) $scenario['organization']->id,
                'event_horizon_lead_days' => null,
            ])
            ->assertStatus(422);

        $this->actingAsClient($scenario['organizer'])
            ->postJson('/api/commands/update-organization-configuration', [
                'organization_id' => (string) $scenario['organization']->id,
                'event_horizon_lead_days' => 366,
            ])
            ->assertStatus(422);

        // Zero is legitimate: the Event Horizon then opens with the active
        // window itself.
        $this->actingAsClient($scenario['organizer'])
            ->postJson('/api/commands/update-organization-configuration', [
                'organization_id' => (string) $scenario['organization']->id,
                'event_horizon_lead_days' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('configuration.event_horizon_lead_days', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Scenario
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function readFor(User $user, array $scenario): TestResponse
    {
        return $this->actingAsClient($user)
            ->getJson("/api/events/{$scenario['event']->id}/event-horizon")
            ->assertOk();
    }

    /**
     * One event a month out from its active window, a member on a team, a
     * team lead, and an organizer.
     *
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        Carbon::setTestNow(Carbon::parse('2027-06-15 12:00:00'));

        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $organizers = Department::factory()->for($organization)->create(['name' => 'Organizers']);

        $event = Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2027',
            'timezone' => 'America/Los_Angeles',
            'active_event_window_starts_at' => Carbon::parse('2027-07-01 00:00:00'),
            'active_event_window_ends_at' => Carbon::parse('2027-07-10 00:00:00'),
        ]);

        $memberStaff = Staff::factory()->create(['handle' => 'mira']);
        $leadStaff = Staff::factory()->create(['handle' => 'lee']);

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($memberStaff, $department);
        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($leadStaff, $department);

        $department->load('defaultTeam');

        TeamMembership::query()
            ->where('team_id', $department->defaultTeam->id)
            ->where('staff_id', $leadStaff->id)
            ->update(['membership_role' => 'lead']);

        $member = User::factory()->create();
        $member->staffProfiles()->attach($memberStaff->id);

        $teamLead = User::factory()->create();
        $teamLead->staffProfiles()->attach($leadStaff->id);

        $organizerTeam = Team::factory()->for($organizers)->create(['name' => 'Event Organizers']);
        $organizer = $this->userWithRole($organizerTeam, 'organizer');

        return [
            'organization' => $organization,
            'department' => $department,
            'event' => $event,
            'member' => $member,
            'teamLead' => $teamLead,
            'organizer' => $organizer,
        ];
    }

    private function userWithRole(Team $team, string $roleCode): User
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create(['status' => DepartmentMembership::STATUS_ACTIVE]);

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        return $user;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
