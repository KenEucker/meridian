<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\CurrentDeploymentAssignment;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Deployment;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\EventDepartmentPresence;
use App\Models\HoursWorked;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Teams\TeamAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The four department operations reads (M16.21; SLB-001 through SLB-022).
 */
class DepartmentOperationsReadHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_answers_with_the_running_shift_and_its_exceptions(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['logistics'])
            ->getJson($this->path($scenario, 'overview'))
            ->assertOk();

        $response->assertJsonPath('context.event_id', (string) $scenario['event']->id);
        $response->assertJsonPath('context.department_label', 'Rangers');
        $response->assertJsonPath('access.can_manage_attendance', true);
        $response->assertJsonPath('selected_shift_id', (string) $scenario['shift']->id);
        $response->assertJsonPath('on_site_count', 3);

        $assignments = collect($response->json('assignments'));
        $this->assertCount(2, $assignments);
        $this->assertSame(
            AttendanceRecord::STATE_CHECKED_IN,
            $assignments->firstWhere('staff_id', (string) $scenario['checkedIn']->id)['attendance_state'],
        );
        $this->assertSame(
            AttendanceRecord::STATE_SCHEDULED,
            $assignments->firstWhere('staff_id', (string) $scenario['scheduled']->id)['attendance_state'],
        );
        $this->assertSame(
            (string) $scenario['deployment']->id,
            $assignments->firstWhere('staff_id', (string) $scenario['checkedIn']->id)['current_deployment_id'],
        );

        // Capacity 3 against 2 assigned, one of them still not checked in after
        // the shift started, and one radio still out (SLB-002).
        $exceptions = collect($response->json('exceptions'))->pluck('id');
        $this->assertTrue($exceptions->contains('coverage'));
        $this->assertTrue($exceptions->contains('awaiting-check-in'));
        $this->assertTrue($exceptions->contains('equipment-out'));

        $this->assertSame('Radio 12', $response->json('equipment_out.0.item_name'));
        $this->assertSame('Gate 1', $response->json('deployments.0.name'));
    }

    public function test_logistics_indexes_the_department_and_states_why_someone_cannot_leave(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['logistics'])
            ->getJson($this->path($scenario, 'logistics'))
            ->assertOk();

        $staffIds = collect($response->json('searchable_staff'))->pluck('staff_id');
        $this->assertTrue($staffIds->contains((string) $scenario['checkedIn']->id));
        $this->assertTrue($staffIds->contains((string) $scenario['scheduled']->id));

        $equipment = collect($response->json('searchable_equipment'));
        $this->assertSame(
            'Vera Checked-In',
            $equipment->firstWhere('name', 'Radio 12')['holder_name'],
        );
        $this->assertNull($equipment->firstWhere('name', 'Radio 13')['holder_name']);

        $checkedIn = $response->json('staff_workspaces.'.(string) $scenario['checkedIn']->id);
        $this->assertFalse($checkedIn['can_go_off_site']);
        $this->assertSame(
            'Staff must be checked out from department shifts before being marked off-site.',
            $checkedIn['off_site_blocked_reason'],
        );

        $card = collect($checkedIn['shift_cards'])->firstWhere('shift_id', (string) $scenario['shift']->id);
        $this->assertTrue($card['can_check_out']);
        $this->assertFalse($card['can_check_in']);
        $this->assertFalse($card['can_mark_no_show']);

        $scheduled = $response->json('staff_workspaces.'.(string) $scenario['scheduled']->id);
        $scheduledCard = collect($scheduled['shift_cards'])->firstWhere('shift_id', (string) $scenario['shift']->id);
        $this->assertTrue($scheduledCard['can_check_in']);
        $this->assertTrue($scheduledCard['can_mark_no_show']);
        $this->assertFalse($scheduledCard['can_check_out']);

        // On-site, no assignment, shift started, eligible team member: the one
        // case SLB-008 exists for.
        $unscheduled = $response->json('staff_workspaces.'.(string) $scenario['unassigned']->id);
        $this->assertTrue(
            collect($unscheduled['shift_cards'])
                ->firstWhere('shift_id', (string) $scenario['shift']->id)['can_add_to_shift'],
        );

        // Off-site, so nothing is offered and the card is not there to offer it.
        $offSite = $response->json('staff_workspaces.'.(string) $scenario['offSite']->id);
        $this->assertSame([], $offSite['shift_cards']);
        $this->assertTrue($offSite['can_go_off_site']);
    }

    /**
     * The equipment half of the off-site block (SLB-018), and the exception the
     * requirement writes into it.
     *
     * The desk's answer and the command's have to be the same answer. An item
     * written off as Missing while its checkout is still open is outstanding —
     * it stays in the workspace's equipment list, because somebody still has to
     * account for it — and it is no longer a reason to refuse an off-site mark,
     * which is what `mark-staff-off-site` would do with it.
     */
    public function test_held_equipment_blocks_leaving_until_it_is_returned_or_written_off(): void
    {
        $scenario = $this->scenario();

        $vest = EquipmentItem::factory()->forDepartment($scenario['department'])->checkedOut()->create([
            'name' => 'Vest 4',
            'asset_tag' => 'VST-04',
        ]);
        EquipmentCheckout::factory()->create([
            'equipment_item_id' => $vest->id,
            'event_id' => $scenario['event']->id,
            'staff_id' => $scenario['unassigned']->id,
            'shift_id' => null,
        ]);

        $holding = $this->actingAsClient($scenario['logistics'])
            ->getJson($this->path($scenario, 'logistics'))
            ->assertOk()
            ->json('staff_workspaces.'.(string) $scenario['unassigned']->id);

        $this->assertFalse($holding['can_go_off_site']);
        $this->assertSame(
            'Staff must return or resolve checked-out department equipment before being marked off-site.',
            $holding['off_site_blocked_reason'],
        );
        $this->assertSame('Vest 4', $holding['open_equipment'][0]['name']);

        $vest->forceFill(['status' => EquipmentItem::STATUS_MISSING])->save();

        $writtenOff = $this->actingAsClient($scenario['logistics'])
            ->getJson($this->path($scenario, 'logistics'))
            ->assertOk()
            ->json('staff_workspaces.'.(string) $scenario['unassigned']->id);

        $this->assertTrue($writtenOff['can_go_off_site']);
        $this->assertNull($writtenOff['off_site_blocked_reason']);
        $this->assertSame('Vest 4', $writtenOff['open_equipment'][0]['name']);
    }

    /**
     * The desk says why it is not offering an addition instead of showing
     * nothing at all.
     *
     * This is the bug an operator hit: create a shift, mark somebody on-site, go
     * to add them, and find no shift card, no button, and no sentence anywhere on
     * the screen. The card was dropped whenever the addition could not be
     * offered, which meant the two most common reasons — the shift is for another
     * team, the shift has not started — were invisible.
     */
    public function test_an_on_site_member_sees_why_a_running_shift_will_not_take_them(): void
    {
        $scenario = $this->scenario();

        // A shift running now, for a team the on-site unassigned member is not on.
        $otherTeamShift = Shift::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'eligible_team_id' => $scenario['otherTeam']->id,
            'title' => 'Ranger Command Day Shift',
            'starts_at' => Carbon::parse('2027-07-04 17:00:00'),
            'ends_at' => Carbon::parse('2027-07-04 23:00:00'),
            'capacity' => null,
        ]);

        // And one that has not started yet, on the team they are on.
        $notStarted = Shift::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'eligible_team_id' => $scenario['team']->id,
            'title' => 'Ranger Dirt Night Shift',
            'starts_at' => Carbon::parse('2027-07-04 22:00:00'),
            'ends_at' => Carbon::parse('2027-07-05 04:00:00'),
            'capacity' => null,
        ]);

        $response = $this->actingAsClient($scenario['logistics'])
            ->getJson($this->path($scenario, 'logistics'))
            ->assertOk();

        $cards = collect(
            $response->json('staff_workspaces.'.(string) $scenario['unassigned']->id.'.shift_cards'),
        );

        $wrongTeam = $cards->firstWhere('shift_id', (string) $otherTeamShift->id);
        $this->assertNotNull($wrongTeam, 'The card is on screen so it can explain itself.');
        $this->assertFalse($wrongTeam['can_add_to_shift']);
        $this->assertSame(
            'This shift is for the Command team, and they are not a member of it.',
            $wrongTeam['add_to_shift_blocked_reason'],
        );

        $upcoming = $cards->firstWhere('shift_id', (string) $notStarted->id);
        $this->assertNotNull($upcoming);
        $this->assertFalse($upcoming['can_add_to_shift']);
        $this->assertSame(
            'This shift has not started yet. Staff can be added once it is running.',
            $upcoming['add_to_shift_blocked_reason'],
        );

        // The one they can be added to says nothing, because there is nothing to
        // explain.
        $addable = $cards->firstWhere('shift_id', (string) $scenario['shift']->id);
        $this->assertTrue($addable['can_add_to_shift']);
        $this->assertNull($addable['add_to_shift_blocked_reason']);

        // Off-site is still nothing at all: no presence, no cards, no additions.
        $this->assertSame(
            [],
            $response->json('staff_workspaces.'.(string) $scenario['offSite']->id.'.shift_cards'),
        );
    }

    /**
     * The desk offers an addition on exactly the terms the command takes one
     * (M18.3; SLB-008).
     *
     * Archiving a team leaves its memberships alone and leaves its shifts on the
     * desk, so the read used to go on offering **Add to shift** for a shift
     * `UnscheduledShiftAdditionService` would refuse — a button whose answer the
     * desk had got wrong, which is the one thing a node-decided control must not
     * be. Both sides now ask `TeamMembership::onEligibleShiftTeam`.
     */
    public function test_an_archived_eligible_team_withdraws_the_addition_the_command_would_refuse(): void
    {
        $scenario = $this->scenario();

        $sweeps = Team::factory()->for($scenario['department'])->create(['name' => 'Sweeps']);
        TeamMembership::factory()->create([
            'team_id' => $sweeps->id,
            'staff_id' => $scenario['unassigned']->id,
            'department_membership_id' => DepartmentMembership::query()
                ->active()
                ->where('department_id', $scenario['department']->id)
                ->where('staff_id', $scenario['unassigned']->id)
                ->value('id'),
        ]);

        $sweepShift = Shift::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'eligible_team_id' => $sweeps->id,
            'title' => 'Ranger Sweeps Afternoon',
            'starts_at' => Carbon::parse('2027-07-04 17:00:00'),
            'ends_at' => Carbon::parse('2027-07-04 23:00:00'),
            'capacity' => null,
        ]);

        $offered = $this->addToShiftCard($scenario, $sweepShift);
        $this->assertTrue($offered['can_add_to_shift']);
        $this->assertNull($offered['add_to_shift_blocked_reason']);

        app(TeamAdminService::class)->archive($sweeps, $scenario['logistics']);

        $withdrawn = $this->addToShiftCard($scenario, $sweepShift);
        $this->assertFalse($withdrawn['can_add_to_shift']);
        // Not "they are not a member of it": they still are, and sending an
        // operator to put them on a team that takes nobody is a wasted trip.
        $this->assertSame(
            'The Sweeps team has been archived, so this shift takes no additions.',
            $withdrawn['add_to_shift_blocked_reason'],
        );
    }

    /**
     * The hours a correction would edit, and the refusal once they freeze
     * (M18.4; SLB-031, HOURS-008).
     *
     * SLB-031 puts correction in the staff workspace, so the record has to
     * reach the card that names the shift it belongs to — with the recorded
     * times on it, because a correction is an edit to two numbers already on
     * file and a dialog that opened empty would make the operator go and find
     * them somewhere else first.
     *
     * Once the record freezes the desk offers nothing and prints
     * `HoursCorrectionService`'s own sentence. The date is the one an operator
     * can act on, and it is read in the event's zone: a period that closed at
     * five in the afternoon locally would otherwise be reported as midnight the
     * next day, which is a different day to somebody deciding whether they are
     * too late.
     */
    public function test_the_desk_carries_correctable_hours_and_names_a_closed_grace_period(): void
    {
        $scenario = $this->scenario();
        $hours = HoursWorked::query()
            ->where('shift_id', $scenario['shift']->id)
            ->where('staff_id', $scenario['checkedIn']->id)
            ->firstOrFail();

        $open = $this->shiftCardFor($scenario, $scenario['checkedIn'], $scenario['shift']);

        $this->assertSame((string) $hours->id, $open['hours_worked_id']);
        $this->assertSame($hours->actual_started_at->toIso8601String(), $open['actual_started_at']);
        $this->assertSame($hours->actual_ended_at->toIso8601String(), $open['actual_ended_at']);
        $this->assertSame(120, $open['minutes_worked']);
        $this->assertTrue($open['can_correct_hours']);
        $this->assertNull($open['correct_hours_blocked_reason']);

        $hours->forceFill(['frozen_at' => Carbon::parse('2027-07-19 00:00:00')])->save();

        $frozen = $this->shiftCardFor($scenario, $scenario['checkedIn'], $scenario['shift']);

        $this->assertFalse($frozen['can_correct_hours']);
        $this->assertSame(
            'The correction grace period closed on 18 Jul 2027 17:00 PDT, so these hours are frozen and can no longer be corrected.',
            $frozen['correct_hours_blocked_reason'],
        );
    }

    /**
     * A finished shift nobody was checked out of has nothing to correct, and
     * says what is actually missing.
     *
     * The operator came to the workspace looking for hours and the answer is
     * that the check-out never happened. Naming the check-out is the difference
     * between a card an operator can act on and one with no controls and no
     * explanation.
     */
    public function test_a_finished_shift_with_no_hours_names_the_missing_check_out(): void
    {
        $scenario = $this->scenario();

        ShiftAssignment::factory()->create([
            'shift_id' => $scenario['overnight']->id,
            'staff_id' => $scenario['scheduled']->id,
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        $card = $this->shiftCardFor($scenario, $scenario['scheduled'], $scenario['overnight']);

        $this->assertNull($card['hours_worked_id']);
        $this->assertFalse($card['can_correct_hours']);
        $this->assertSame(
            'This shift has no recorded hours yet. Check this staff member out to create them.',
            $card['correct_hours_blocked_reason'],
        );

        // A running shift is not the same case: hours arrive at check-out, and a
        // card saying so mid-shift would be on every workspace on the desk.
        $running = $this->shiftCardFor($scenario, $scenario['scheduled'], $scenario['shift']);
        $this->assertNull($running['correct_hours_blocked_reason']);
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    private function shiftCardFor(array $scenario, Staff $staff, Shift $shift): array
    {
        $card = collect(
            $this->actingAsClient($scenario['logistics'])
                ->getJson($this->path($scenario, 'logistics'))
                ->assertOk()
                ->json('staff_workspaces.'.(string) $staff->id.'.shift_cards'),
        )->firstWhere('shift_id', (string) $shift->id);

        $this->assertNotNull($card, 'The card is on screen so it can explain itself.');

        return $card;
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    private function addToShiftCard(array $scenario, Shift $shift): array
    {
        $cards = collect(
            $this->actingAsClient($scenario['logistics'])
                ->getJson($this->path($scenario, 'logistics'))
                ->assertOk()
                ->json('staff_workspaces.'.(string) $scenario['unassigned']->id.'.shift_cards'),
        );

        $card = $cards->firstWhere('shift_id', (string) $shift->id);
        $this->assertNotNull($card, 'The card is on screen so it can explain itself.');

        return $card;
    }

    public function test_operations_lists_deployment_options_and_staff_on_shift(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['operations'])
            ->getJson($this->path($scenario, 'operations'))
            ->assertOk();

        $response->assertJsonPath('access.can_assign_deployments', true);
        $response->assertJsonPath('deployments.0.name', 'Gate 1');

        $rows = collect($response->json('rows'));
        $this->assertCount(2, $rows);
        $this->assertSame(
            'Gate 1',
            $rows->firstWhere('staff_id', (string) $scenario['checkedIn']->id)['current_deployment_name'],
        );
        $this->assertNull(
            $rows->firstWhere('staff_id', (string) $scenario['scheduled']->id)['current_deployment_id'],
        );
    }

    public function test_planning_answers_with_identity_free_aggregates_and_narrows_by_team(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['planning'])
            ->getJson($this->path($scenario, 'planning'))
            ->assertOk();

        $rows = collect($response->json('rows'));
        $this->assertCount(2, $rows);

        $row = $rows->firstWhere('shift_id', (string) $scenario['shift']->id);
        $this->assertSame(2, $row['signed_up_or_assigned_count']);
        $this->assertSame(1, $row['checked_in_count']);
        $this->assertSame(0, $row['no_show_count']);
        // Capacity 3 across a six-hour window.
        $this->assertEqualsWithDelta(18, $row['planned_hours'], 0.01);
        $this->assertEqualsWithDelta(2, $row['actual_hours'], 0.01);
        $this->assertEqualsWithDelta(-16, $row['variance_hours'], 0.01);
        $this->assertSame('Under target', $row['status_label']);

        // SLB-019: no identity reaches an aggregate row.
        foreach (['staff_id', 'display_name', 'handle', 'assignment_id', 'signup_id'] as $field) {
            $this->assertStringNotContainsString(
                '"'.$field.'"',
                json_encode($response->json('rows'), JSON_THROW_ON_ERROR),
            );
        }

        $this->actingAsClient($scenario['planning'])
            ->getJson($this->path($scenario, 'planning').'?team_id='.$scenario['otherTeam']->id)
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.title', 'Ranger Command Overnight');
    }

    public function test_a_caller_with_no_department_standing_is_refused(): void
    {
        $scenario = $this->scenario();
        $outsider = User::factory()->create();

        foreach (['overview', 'logistics', 'operations', 'planning'] as $surface) {
            $this->actingAsClient($outsider)
                ->getJson($this->path($scenario, $surface))
                ->assertForbidden();
        }
    }

    public function test_a_department_outside_the_events_organization_is_not_found(): void
    {
        $scenario = $this->scenario();
        $elsewhere = Department::factory()->create();

        $this->actingAsClient($scenario['logistics'])
            ->getJson("/api/events/{$scenario['event']->id}/departments/{$elsewhere->id}/logistics")
            ->assertNotFound();
    }

    public function test_the_reads_require_a_credential(): void
    {
        $scenario = $this->scenario();

        $this->getJson($this->path($scenario, 'overview'))->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function path(array $scenario, string $surface): string
    {
        return "/api/events/{$scenario['event']->id}/departments/{$scenario['department']->id}/{$surface}";
    }

    /**
     * A department mid-shift.
     *
     * One six-hour shift running now with capacity for three and two people on
     * it — one checked in holding a radio and deployed to Gate 1, one still
     * scheduled — plus an on-site department member who is not assigned to it,
     * an off-site member who is, and a completed shift on another team for the
     * Planning Table to aggregate separately.
     *
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        Carbon::setTestNow(Carbon::parse('2027-07-04 18:00:00'));

        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2027',
            'timezone' => 'America/Los_Angeles',
        ]);
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);

        $checkedIn = $this->staffNamed('Vera Checked-In', 'vera');
        $scheduled = $this->staffNamed('Sam Scheduled', 'sam');
        $unassigned = $this->staffNamed('Ari Unassigned', 'ari');
        $offSite = $this->staffNamed('Uma Off-Site', 'uma');

        foreach ([$checkedIn, $scheduled, $unassigned, $offSite] as $member) {
            app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($member, $department);
        }

        $department->load('defaultTeam');
        $team = $department->defaultTeam;
        $otherTeam = Team::factory()->for($department)->create(['name' => 'Command']);

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => 'Ranger Dirt Day Shift',
            'starts_at' => Carbon::parse('2027-07-04 16:00:00'),
            'ends_at' => Carbon::parse('2027-07-04 22:00:00'),
            'capacity' => 3,
        ]);

        $overnight = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $otherTeam->id,
            'title' => 'Ranger Command Overnight',
            'starts_at' => Carbon::parse('2027-07-04 04:00:00'),
            'ends_at' => Carbon::parse('2027-07-04 10:00:00'),
            'capacity' => null,
        ]);

        foreach ([$checkedIn, $scheduled, $offSite] as $member) {
            ShiftAssignment::factory()->create([
                'shift_id' => $shift->id,
                'staff_id' => $member->id,
                'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
                'assigned_by_user_id' => null,
                'removed_at' => null,
            ]);
        }

        // Off-site, so the desk offers this one nothing; removing the assignment
        // would remove the reason the card is empty rather than test it.
        ShiftAssignment::query()
            ->where('shift_id', $shift->id)
            ->where('staff_id', $offSite->id)
            ->update(['removed_at' => Carbon::now()]);

        $record = AttendanceRecord::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'shift_id' => $shift->id,
            'staff_id' => $checkedIn->id,
            'current_state' => AttendanceRecord::STATE_CHECKED_IN,
            'checked_in_at' => Carbon::parse('2027-07-04 16:02:00'),
        ]);

        HoursWorked::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'shift_id' => $shift->id,
            'staff_id' => $checkedIn->id,
            'attendance_record_id' => $record->id,
            'minutes_worked' => 120,
        ]);

        foreach ([$checkedIn, $scheduled, $unassigned] as $member) {
            EventDepartmentPresence::factory()->onSite()->create([
                'event_id' => $event->id,
                'department_id' => $department->id,
                'staff_id' => $member->id,
            ]);
        }

        EventDepartmentPresence::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'staff_id' => $offSite->id,
            'current_state' => EventDepartmentPresence::STATE_OFF_SITE,
        ]);

        $radio = EquipmentItem::factory()->forDepartment($department)->checkedOut()->create([
            'name' => 'Radio 12',
            'asset_tag' => 'RDO-12',
        ]);
        EquipmentItem::factory()->forDepartment($department)->create([
            'name' => 'Radio 13',
            'asset_tag' => 'RDO-13',
        ]);

        EquipmentCheckout::factory()->create([
            'equipment_item_id' => $radio->id,
            'event_id' => $event->id,
            'staff_id' => $checkedIn->id,
            'shift_id' => $shift->id,
        ]);

        $deployment = Deployment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'name' => 'Gate 1',
        ]);

        CurrentDeploymentAssignment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'shift_id' => $shift->id,
            'staff_id' => $checkedIn->id,
            'deployment_id' => $deployment->id,
        ]);

        return [
            'event' => $event,
            'department' => $department,
            'team' => $team,
            'otherTeam' => $otherTeam,
            'shift' => $shift,
            'overnight' => $overnight,
            'deployment' => $deployment,
            'checkedIn' => $checkedIn,
            'scheduled' => $scheduled,
            'unassigned' => $unassigned,
            'offSite' => $offSite,
            'logistics' => $this->userWithRole($team, 'department_logistics'),
            'operations' => $this->userWithRole($team, 'department_operations'),
            'planning' => $this->userWithRole($team, 'department_planning'),
        ];
    }

    /**
     * A staff member who goes by their legal name, so an assertion about a
     * display name is an assertion about the name it was given.
     */
    private function staffNamed(string $legalName, string $handle): Staff
    {
        return Staff::factory()->create([
            'legal_name' => $legalName,
            'preferred_name' => null,
            'handle' => $handle,
        ]);
    }

    private function userWithRole(Team $team, string $roleCode): User
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
