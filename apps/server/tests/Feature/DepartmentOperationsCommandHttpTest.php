<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\EventDepartmentPresence;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Presence and unscheduled shift addition over HTTP (M16.21; SLB-008, SLB-015
 * through SLB-018; technical spec 20.3, 20.5).
 *
 * The domain services behind these have been enforcing their rules since M10.4
 * and M10.6 with nothing able to reach them. These tests are about the transport
 * and about the refusals arriving in the words the service wrote them in.
 */
class DepartmentOperationsCommandHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_logistics_marks_a_department_member_on_site_and_back_off(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/mark-staff-on-site', [
                'event_id' => $scenario['event']->id,
                'department_id' => $scenario['department']->id,
                'staff_id' => $scenario['staff']->id,
            ])
            ->assertCreated()
            ->assertJsonPath('current_state', EventDepartmentPresence::STATE_ON_SITE)
            ->assertJsonPath('created_state_change', true);

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/mark-staff-off-site', [
                'event_id' => $scenario['event']->id,
                'department_id' => $scenario['department']->id,
                'staff_id' => $scenario['staff']->id,
            ])
            ->assertCreated()
            ->assertJsonPath('current_state', EventDepartmentPresence::STATE_OFF_SITE);

        $this->assertDatabaseCount('event_department_presences', 1);
    }

    public function test_going_off_site_is_refused_in_the_services_own_words_while_checked_in(): void
    {
        $scenario = $this->scenario();

        EventDepartmentPresence::factory()->onSite()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'staff_id' => $scenario['staff']->id,
        ]);

        AttendanceRecord::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'shift_id' => $scenario['shift']->id,
            'staff_id' => $scenario['staff']->id,
            'current_state' => AttendanceRecord::STATE_CHECKED_IN,
            'checked_in_at' => Carbon::now(),
        ]);

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/mark-staff-off-site', [
                'event_id' => $scenario['event']->id,
                'department_id' => $scenario['department']->id,
                'staff_id' => $scenario['staff']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Staff must be checked out from department shifts before being marked off-site.',
            );
    }

    public function test_going_off_site_is_refused_while_equipment_is_held_and_allowed_once_it_is_written_off(): void
    {
        $scenario = $this->scenario();

        EventDepartmentPresence::factory()->onSite()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'staff_id' => $scenario['staff']->id,
        ]);

        $radio = EquipmentItem::factory()->create([
            'organization_id' => $scenario['event']->organization_id,
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'name' => 'Radio 12',
            'status' => EquipmentItem::STATUS_CHECKED_OUT,
        ]);
        EquipmentCheckout::factory()->create([
            'equipment_item_id' => $radio->id,
            'event_id' => $scenario['event']->id,
            'staff_id' => $scenario['staff']->id,
            'shift_id' => null,
            'returned_at' => null,
        ]);

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/mark-staff-off-site', [
                'event_id' => $scenario['event']->id,
                'department_id' => $scenario['department']->id,
                'staff_id' => $scenario['staff']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Staff must return or resolve checked-out department equipment before being marked off-site.',
            );

        // SLB-018's own exception: an item marked Missing is outstanding but no
        // longer a reason to hold somebody here.
        $radio->forceFill(['status' => EquipmentItem::STATUS_MISSING])->save();

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/mark-staff-off-site', [
                'event_id' => $scenario['event']->id,
                'department_id' => $scenario['department']->id,
                'staff_id' => $scenario['staff']->id,
            ])
            ->assertCreated()
            ->assertJsonPath('current_state', EventDepartmentPresence::STATE_OFF_SITE);
    }

    public function test_presence_is_refused_without_the_logistics_role(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient(User::factory()->create())
            ->postJson('/api/commands/mark-staff-on-site', [
                'event_id' => $scenario['event']->id,
                'department_id' => $scenario['department']->id,
                'staff_id' => $scenario['staff']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You are not authorized to manage department presence.');
    }

    public function test_an_on_site_staff_member_is_added_to_a_running_shift(): void
    {
        $scenario = $this->scenario();

        EventDepartmentPresence::factory()->onSite()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'staff_id' => $scenario['staff']->id,
        ]);

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/add-staff-to-shift', [
                'shift_id' => $scenario['shift']->id,
                'staff_id' => $scenario['staff']->id,
            ])
            ->assertCreated()
            ->assertJsonPath('shift_id', (string) $scenario['shift']->id)
            ->assertJsonPath('assignment_status', ShiftAssignment::STATUS_ASSIGNED)
            ->assertJsonPath('warnings', []);

        $this->assertDatabaseHas('shift_assignments', [
            'shift_id' => $scenario['shift']->id,
            'staff_id' => $scenario['staff']->id,
            'removed_at' => null,
        ]);
    }

    public function test_adding_an_off_site_staff_member_to_a_shift_is_refused(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/add-staff-to-shift', [
                'shift_id' => $scenario['shift']->id,
                'staff_id' => $scenario['staff']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Staff must be marked on-site with this department before unscheduled shift addition.',
            )
            // The code beside the sentence (M18.55). The client decides whether
            // to offer an override from this; a rule written about the sentence
            // would break the day somebody rewords the sentence.
            ->assertJsonPath('reason_code', 'staff_not_on_site');

        $this->assertDatabaseCount('shift_assignments', 0);
    }

    /**
     * Overriding a refusal over the wire (M18.55; CLIENT-017A).
     *
     * The desk operator hits the refusal and cannot resolve it — Logistics
     * issues additions and does not override their refusals. The department
     * lead standing beside them can, and the record afterwards says both that
     * the node refused and that a named person chose to proceed.
     */
    public function test_a_department_lead_overrides_a_refused_addition_over_the_wire(): void
    {
        $scenario = $this->scenario();
        $lead = $this->departmentLeadUserFor($scenario['department']);
        $refusedOperationUuid = (string) Str::uuid();
        $overrideOperationUuid = (string) Str::uuid();

        $payload = [
            'shift_id' => $scenario['shift']->id,
            'staff_id' => $scenario['staff']->id,
            'overridden_operation_uuid' => $refusedOperationUuid,
            'overridden_reason_code' => 'staff_not_on_site',
            'operation_uuid' => $overrideOperationUuid,
        ];

        // The desk that hit the refusal cannot wave it away.
        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/override-shift-addition', $payload)
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'You are not authorized to override a refused shift addition for this department.',
            );

        $this->actingAsClient($lead)
            ->postJson('/api/commands/override-shift-addition', $payload)
            ->assertCreated()
            ->assertJsonPath('assignment_status', ShiftAssignment::STATUS_ASSIGNED)
            ->assertJsonPath('overridden_reason_code', 'staff_not_on_site')
            ->assertJsonPath('override_of_operation_uuid', $refusedOperationUuid)
            // A distinct command: the assignment's own key is the override's,
            // not the refused command's.
            ->assertJsonPath('operation_uuid', $overrideOperationUuid);

        $this->assertDatabaseHas('shift_assignments', [
            'staff_id' => $scenario['staff']->id,
            'overridden_reason_code' => 'staff_not_on_site',
            'override_of_operation_uuid' => $refusedOperationUuid,
        ]);
    }

    public function test_the_override_endpoint_refuses_do_not_staff(): void
    {
        $scenario = $this->scenario();
        $lead = $this->departmentLeadUserFor($scenario['department']);
        StaffOrganizationStatus::factory()->create([
            'organization_id' => $scenario['event']->organization_id,
            'staff_id' => $scenario['staff']->id,
            'status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF,
        ]);

        $this->actingAsClient($lead)
            ->postJson('/api/commands/override-shift-addition', [
                'shift_id' => $scenario['shift']->id,
                'staff_id' => $scenario['staff']->id,
                'overridden_operation_uuid' => (string) Str::uuid(),
                'overridden_reason_code' => 'do_not_staff',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'A refusal for `do_not_staff` cannot be overridden. It is not a decision this desk makes.',
            );

        $this->assertDatabaseCount('shift_assignments', 0);
    }

    /**
     * The addition as a queued write (M18.54; technical spec 9.4, data/API 7.2).
     *
     * A device holds it, sends it later, and may send it twice when a reply is
     * lost on a field network. The operation UUID is what makes the second
     * delivery the same command: the same assignment comes back, nothing is
     * written a second time, and the operator is not shown "already assigned"
     * for work they made once.
     */
    public function test_a_replayed_addition_is_the_same_command_rather_than_a_duplicate(): void
    {
        $scenario = $this->scenario();

        EventDepartmentPresence::factory()->onSite()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'staff_id' => $scenario['staff']->id,
        ]);

        $operationUuid = (string) Str::uuid();
        $payload = [
            'shift_id' => $scenario['shift']->id,
            'staff_id' => $scenario['staff']->id,
            'operation_uuid' => $operationUuid,
            // Recorded at the desk half an hour before the queue drained.
            'device_created_at' => '2027-07-04T17:30:00+00:00',
        ];

        $first = $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/add-staff-to-shift', $payload)
            ->assertCreated()
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('operation_uuid', $operationUuid);

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/add-staff-to-shift', $payload)
            ->assertCreated()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath(
                'shift_assignment_id',
                $first->json('shift_assignment_id'),
            );

        $this->assertDatabaseCount('shift_assignments', 1);

        // The moment the operator recorded it, not the moment it arrived
        // (SLB-008 reads this to tell an unscheduled addition from a signup).
        $this->assertSame(
            '2027-07-04T17:30:00+00:00',
            ShiftAssignment::query()->firstOrFail()->created_at?->toIso8601String(),
        );
    }

    /**
     * A second addition of the same person under a *different* key is not a
     * replay: somebody else got there first, and the refusal is real.
     */
    public function test_a_second_addition_under_a_new_key_is_still_refused_as_already_assigned(): void
    {
        $scenario = $this->scenario();

        EventDepartmentPresence::factory()->onSite()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'staff_id' => $scenario['staff']->id,
        ]);

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/add-staff-to-shift', [
                'shift_id' => $scenario['shift']->id,
                'staff_id' => $scenario['staff']->id,
                'operation_uuid' => (string) Str::uuid(),
            ])
            ->assertCreated();

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/add-staff-to-shift', [
                'shift_id' => $scenario['shift']->id,
                'staff_id' => $scenario['staff']->id,
                'operation_uuid' => (string) Str::uuid(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This staff member is already assigned to the shift.');

        $this->assertDatabaseCount('shift_assignments', 1);
    }

    /**
     * The on-site mark as a queued write (M18.54), which is the other half of
     * the pairing: an addition is refused for anybody not already marked
     * on-site, so a device that could queue the addition and not the mark would
     * hold work that rejects every time.
     *
     * Idempotent by construction — a second delivery changes nothing and says
     * so — and stamped with the moment the operator marked it.
     */
    public function test_a_queued_on_site_mark_records_the_operators_moment_and_replays_safely(): void
    {
        $scenario = $this->scenario();

        $payload = [
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'staff_id' => $scenario['staff']->id,
            'marked_at' => '2027-07-04T17:15:00+00:00',
        ];

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/mark-staff-on-site', $payload)
            ->assertCreated()
            ->assertJsonPath('created_state_change', true)
            ->assertJsonPath('marked_on_site_at', '2027-07-04T17:15:00+00:00');

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/mark-staff-on-site', $payload)
            ->assertCreated()
            ->assertJsonPath('created_state_change', false);

        $this->assertDatabaseCount('event_department_presences', 1);
    }

    /**
     * The trade M18.54 accepts, end to end (SLB-008).
     *
     * Eligibility is not a question a device can answer, so a queued addition
     * for somebody the shift may not take is refused when it arrives — with the
     * node's own sentence, which is what the outbox holds in front of the person
     * who issued it (CLIENT-017). Nothing is written.
     */
    public function test_an_ineligible_queued_addition_is_refused_in_the_services_own_words(): void
    {
        $scenario = $this->scenario();

        EventDepartmentPresence::factory()->onSite()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'staff_id' => $scenario['staff']->id,
        ]);

        StaffOrganizationStatus::factory()->create([
            'organization_id' => $scenario['event']->organization_id,
            'staff_id' => $scenario['staff']->id,
            'status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF,
        ]);

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/add-staff-to-shift', [
                'shift_id' => $scenario['shift']->id,
                'staff_id' => $scenario['staff']->id,
                'operation_uuid' => (string) Str::uuid(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Do Not Staff records cannot be added to shifts.');

        $this->assertDatabaseCount('shift_assignments', 0);
    }

    public function test_the_commands_require_a_credential(): void
    {
        $scenario = $this->scenario();

        $this->postJson('/api/commands/mark-staff-on-site', [
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'staff_id' => $scenario['staff']->id,
        ])->assertUnauthorized();

        $this->postJson('/api/commands/add-staff-to-shift', [
            'shift_id' => $scenario['shift']->id,
            'staff_id' => $scenario['staff']->id,
        ])->assertUnauthorized();
    }

    /**
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        Carbon::setTestNow(Carbon::parse('2027-07-04 18:00:00'));

        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $staff = Staff::factory()->create(['legal_name' => 'Ari Ranger', 'preferred_name' => null]);

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);
        $department->load('defaultTeam');

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'title' => 'Ranger Dirt Day Shift',
            'starts_at' => Carbon::parse('2027-07-04 16:00:00'),
            'ends_at' => Carbon::parse('2027-07-04 22:00:00'),
        ]);

        return [
            'event' => $event,
            'department' => $department,
            'shift' => $shift,
            'staff' => $staff,
            'logistics' => $this->logisticsUserFor($department->defaultTeam),
        ];
    }

    /** The authority the M18.55 override answers to, which Logistics is not. */
    private function departmentLeadUserFor(Department $department): User
    {
        $team = Team::factory()->for($department)->create([
            'name' => $department->name.' Leads',
        ]);
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);
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
            'permission_role_id' => PermissionRole::query()
                ->where('code', 'department_lead')
                ->firstOrFail()
                ->id,
        ]);

        return $user;
    }

    private function logisticsUserFor(Team $team): User
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
            'permission_role_id' => PermissionRole::query()
                ->where('code', 'department_logistics')
                ->firstOrFail()
                ->id,
        ]);

        return $user;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
