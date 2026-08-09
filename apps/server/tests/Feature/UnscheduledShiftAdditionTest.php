<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
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
use App\Models\Training;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Credential\CredentialEligibilityService;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Shift\ShiftRequirementService;
use App\Services\Shift\UnscheduledShiftAdditionException;
use App\Services\Shift\UnscheduledShiftAdditionService;
use App\Services\Status\StaffStatusService;
use App\Services\Teams\TeamAdminService;
use App\Services\Training\TrainingService;
use App\Services\Waiver\WaiverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnscheduledShiftAdditionTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_lead_can_add_eligible_unscheduled_staff_after_shift_start(): void
    {
        [$shift, $staff, $shiftLead] = $this->unscheduledScenario();
        $moment = Carbon::parse('2026-07-01 10:00:00');

        $outcome = app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: $moment,
        );

        $this->assertSame(ShiftAssignment::STATUS_ASSIGNED, $outcome->assignment->assignment_status);
        $this->assertSame($shiftLead->id, $outcome->assignment->assigned_by_user_id);
        $this->assertTrue($outcome->assignment->created_at->equalTo($moment));
        $this->assertFalse(
            app(CredentialEligibilityService::class)
                ->countsTowardCredentialEligibility($outcome->assignment),
        );

        $audit = AuditEvent::query()
            ->where('action', 'shift_assignment.unscheduled_added')
            ->where('entity_id', $outcome->assignment->id)
            ->firstOrFail();

        $this->assertSame($shiftLead->id, $audit->actor_user_id);
        $this->assertSame($shift->event->organization_id, $audit->organization_id);
        $this->assertSame($shift->event_id, $audit->event_id);
        $this->assertSame($shift->department_id, $audit->department_id);
        $this->assertTrue($audit->after_json['unscheduled']);
    }

    public function test_department_lead_can_add_unscheduled_staff_in_their_department(): void
    {
        [$shift, $staff] = $this->unscheduledScenario();
        $departmentLead = $this->departmentLeadUserFor($shift->department);

        $outcome = app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $departmentLead,
            moment: Carbon::parse('2026-07-01 11:00:00'),
        );

        $this->assertSame(ShiftAssignment::STATUS_ASSIGNED, $outcome->assignment->assignment_status);
        $this->assertSame($departmentLead->id, $outcome->assignment->assigned_by_user_id);
    }

    public function test_unscheduled_addition_reuses_training_and_waiver_requirements(): void
    {
        [$shift, $staff, $shiftLead] = $this->unscheduledScenario();
        $organization = $shift->event->organization;
        $training = Training::factory()->for($organization)->create();
        $waiver = Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
        app(ShiftRequirementService::class)->addTrainingRequirement($shift, $training);
        app(ShiftRequirementService::class)->addWaiverRequirement($shift, $waiver);

        try {
            app(UnscheduledShiftAdditionService::class)->addStaffToShift(
                shift: $shift->refresh(),
                staff: $staff,
                actor: $shiftLead,
                moment: Carbon::parse('2026-07-01 10:00:00'),
            );

            $this->fail('Missing requirements should block unscheduled addition.');
        } catch (UnscheduledShiftAdditionException $exception) {
            $this->assertSame(
                'Required training must be complete before unscheduled shift addition.',
                $exception->getMessage(),
            );
        }

        app(TrainingService::class)->recordCompletion($training, $staff);

        try {
            app(UnscheduledShiftAdditionService::class)->addStaffToShift(
                shift: $shift->refresh(),
                staff: $staff,
                actor: $shiftLead,
                moment: Carbon::parse('2026-07-01 10:00:00'),
            );

            $this->fail('Missing waiver should block unscheduled addition.');
        } catch (UnscheduledShiftAdditionException $exception) {
            $this->assertSame(
                'Required waiver must be complete before unscheduled shift addition.',
                $exception->getMessage(),
            );
        }

        app(WaiverService::class)->recordCompletion($waiver, $staff);

        $outcome = app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift->refresh(),
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 10:00:00'),
        );

        $this->assertSame(ShiftAssignment::STATUS_ASSIGNED, $outcome->assignment->assignment_status);
        $this->assertDatabaseCount('shift_assignments', 1);
    }

    public function test_unscheduled_addition_blocks_ineligible_department_status(): void
    {
        [$shift, $staff, $shiftLead, $departmentMembership] = $this->unscheduledScenario();

        app(StaffStatusService::class)->transitionDepartmentStatus(
            $departmentMembership,
            DepartmentMembership::STATUS_INELIGIBLE,
            'Not eligible for this department.',
        );

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('Ineligible department status prevents unscheduled shift addition.');

        app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 10:00:00'),
        );
    }

    public function test_unscheduled_addition_blocks_staff_outside_eligible_team(): void
    {
        [$shift, $staff, $shiftLead, $departmentMembership] = $this->unscheduledScenario();

        TeamMembership::query()
            ->where('department_membership_id', $departmentMembership->id)
            ->update(['archived_at' => now()]);

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('Staff must belong to the shift eligible team before unscheduled shift addition.');

        app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 10:00:00'),
        );
    }

    /**
     * On-site presence comes first (SLB-008, requirements 5.8).
     *
     * The desk's own order of work: somebody walks up, Logistics marks them
     * on-site, and only then are they somebody who can be put on a shift. A
     * staff member who is off-site — or who this department has never marked
     * either way — is refused here rather than assigned to a shift they are not
     * at.
     */
    public function test_unscheduled_addition_refuses_staff_who_are_not_on_site(): void
    {
        [$shift, $staff, $shiftLead] = $this->unscheduledScenario();

        EventDepartmentPresence::query()
            ->where('event_id', $shift->event_id)
            ->where('department_id', $shift->department_id)
            ->where('staff_id', $staff->id)
            ->update([
                'current_state' => EventDepartmentPresence::STATE_OFF_SITE,
                'marked_on_site_at' => null,
                'marked_off_site_at' => now(),
            ]);

        try {
            app(UnscheduledShiftAdditionService::class)->addStaffToShift(
                shift: $shift,
                staff: $staff,
                actor: $shiftLead,
                moment: Carbon::parse('2026-07-01 10:00:00'),
            );

            $this->fail('An off-site staff member should not be added to a shift.');
        } catch (UnscheduledShiftAdditionException $exception) {
            $this->assertSame(
                'Staff must be marked on-site with this department before unscheduled shift addition.',
                $exception->getMessage(),
            );
        }

        // No presence record at all is the same answer, and is the ordinary case
        // on the first day of an event.
        EventDepartmentPresence::query()
            ->where('event_id', $shift->event_id)
            ->where('staff_id', $staff->id)
            ->delete();

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('Staff must be marked on-site with this department before unscheduled shift addition.');

        app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 10:00:00'),
        );
    }

    /**
     * Somebody from another department is refused before presence is even asked
     * about, because a presence record for a department they do not belong to
     * says nothing about whether this shift is theirs to work.
     */
    public function test_unscheduled_addition_refuses_staff_outside_the_shift_department(): void
    {
        [$shift, , $shiftLead] = $this->unscheduledScenario();
        $outsider = Staff::factory()->create();
        $otherDepartment = Department::factory()
            ->for($shift->event->organization)
            ->create(['name' => 'Gate']);

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($outsider, $otherDepartment);
        EventDepartmentPresence::factory()->onSite()->create([
            'event_id' => $shift->event_id,
            'department_id' => $shift->department_id,
            'staff_id' => $outsider->id,
        ]);

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('Staff must belong to the shift department before unscheduled shift addition.');

        app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $outsider,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 10:00:00'),
        );
    }

    /**
     * Archiving a team does not archive its memberships, so the membership row
     * outlives the team it names. The addition asks about the team as well
     * (`TeamMembership::onEligibleShiftTeam`), which is what keeps the Logistics
     * Desk from offering an addition this refuses.
     */
    public function test_unscheduled_addition_refuses_a_membership_on_an_archived_team(): void
    {
        [$shift, $staff, $shiftLead, $departmentMembership] = $this->unscheduledScenario();
        $department = $shift->department;

        // A named operational team beside the structural default, which is what
        // a department that has been set up for an event actually looks like.
        $dirt = Team::factory()->for($department)->create([
            'name' => 'Dirt',
            'is_default' => false,
        ]);
        TeamMembership::factory()->create([
            'team_id' => $dirt->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
        ]);
        $dirtShift = Shift::factory()->create([
            'event_id' => $shift->event_id,
            'department_id' => $department->id,
            'eligible_team_id' => $dirt->id,
            'title' => 'Ranger Dirt Sweep',
            'starts_at' => Carbon::parse('2026-07-01 08:00:00'),
            'ends_at' => Carbon::parse('2026-07-01 16:00:00'),
        ]);

        app(TeamAdminService::class)->archive($dirt, $shiftLead);

        $this->assertDatabaseHas('team_memberships', [
            'team_id' => $dirt->id,
            'staff_id' => $staff->id,
            'archived_at' => null,
        ]);

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('Staff must belong to the shift eligible team before unscheduled shift addition.');

        app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $dirtShift,
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 10:00:00'),
        );
    }

    public function test_unscheduled_addition_blocks_do_not_staff_organization_status(): void
    {
        [$shift, $staff, $shiftLead] = $this->unscheduledScenario();
        StaffOrganizationStatus::factory()->create([
            'organization_id' => $shift->event->organization_id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF,
        ]);

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('Do Not Staff records cannot be added to shifts.');

        app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 10:00:00'),
        );
    }

    public function test_unscheduled_addition_rejects_before_shift_start(): void
    {
        [$shift, $staff, $shiftLead] = $this->unscheduledScenario();

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('Unscheduled staff can only be added after shift operations have started.');

        app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 07:59:00'),
        );
    }

    public function test_unauthorized_user_cannot_add_unscheduled_staff(): void
    {
        [$shift, $staff] = $this->unscheduledScenario();

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('You are not authorized to add unscheduled staff to this shift.');

        app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: User::factory()->create(),
            moment: Carbon::parse('2026-07-01 10:00:00'),
        );
    }

    public function test_unscheduled_addition_warns_on_overlapping_assignment(): void
    {
        [$shift, $staff, $shiftLead] = $this->unscheduledScenario();
        $overlappingShift = Shift::factory()->create([
            'event_id' => $shift->event_id,
            'department_id' => $shift->department_id,
            'eligible_team_id' => $shift->eligible_team_id,
            'title' => 'Overlapping Dirt Shift',
            'starts_at' => Carbon::parse('2026-07-01 09:00:00'),
            'ends_at' => Carbon::parse('2026-07-01 13:00:00'),
        ]);
        ShiftAssignment::factory()->create([
            'shift_id' => $overlappingShift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        $outcome = app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 10:00:00'),
        );

        $this->assertTrue($outcome->hasOverlapWarnings());
        $this->assertSame((string) $overlappingShift->id, (string) $outcome->warnings[0]->overlappingShift->id);
    }

    /**
     * The capacity question M18.54 had to settle before queueing this command
     * (SLB-008; SHIFT-007).
     *
     * `ShiftEligibilityService::assertCapacityForSelfSignup` is the domain's
     * only capacity guard and it is self-signup's alone, so a Logistics addition
     * over capacity is already permitted at a connected desk. That is what makes
     * queueing it safe without an override: the offline answer and the online one
     * are the same answer, and neither of them is "the shift is full".
     */
    public function test_an_addition_beyond_capacity_is_accepted_rather_than_refused(): void
    {
        [$shift, $staff, $shiftLead] = $this->unscheduledScenario();

        $shift->forceFill(['capacity' => 1])->save();
        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => Staff::factory()->create()->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        $outcome = app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 10:00:00'),
        );

        $this->assertSame(ShiftAssignment::STATUS_ASSIGNED, $outcome->assignment->assignment_status);
        $this->assertSame(
            2,
            ShiftAssignment::query()->where('shift_id', $shift->id)->whereNull('removed_at')->count(),
        );
    }

    /**
     * A queued addition carries the device's key and the operator's moment
     * (M18.54; data/API 5.3), and the audit entry names both — which is what
     * tells a reviewer months later that the timestamp on the assignment is when
     * somebody recorded it rather than when the queue drained.
     */
    public function test_a_queued_addition_records_its_operation_uuid_and_the_moment_it_was_recorded(): void
    {
        [$shift, $staff, $shiftLead] = $this->unscheduledScenario();
        $operationUuid = (string) Str::uuid();
        $recordedAt = Carbon::parse('2026-07-01 10:00:00');

        $outcome = app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 14:00:00'),
            operationUuid: $operationUuid,
            recordedAt: $recordedAt,
        );

        $this->assertFalse($outcome->replayed);
        $this->assertSame($operationUuid, $outcome->assignment->unscheduled_operation_uuid);
        $this->assertTrue($outcome->assignment->created_at->equalTo($recordedAt));

        $audit = AuditEvent::query()
            ->where('action', 'shift_assignment.unscheduled_added')
            ->where('entity_id', $outcome->assignment->id)
            ->firstOrFail();

        $this->assertSame($operationUuid, $audit->after_json['operation_uuid']);
        $this->assertSame($recordedAt->toIso8601String(), $audit->after_json['added_at']);
    }

    /**
     * The same command arriving twice is one assignment and one audit entry, and
     * the second delivery is reported as the replay it is rather than refused as
     * a duplicate (M18.54; CLIENT-016).
     */
    public function test_a_replayed_addition_returns_the_assignment_it_already_made(): void
    {
        [$shift, $staff, $shiftLead] = $this->unscheduledScenario();
        $operationUuid = (string) Str::uuid();

        $first = app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 10:00:00'),
            operationUuid: $operationUuid,
        );

        $replay = app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 10:05:00'),
            operationUuid: $operationUuid,
        );

        $this->assertTrue($replay->replayed);
        $this->assertSame((string) $first->assignment->id, (string) $replay->assignment->id);
        $this->assertSame(1, ShiftAssignment::query()->count());
        $this->assertSame(
            1,
            AuditEvent::query()->where('action', 'shift_assignment.unscheduled_added')->count(),
        );
    }

    /**
     * @return array{0: Shift, 1: Staff, 2: User, 3: DepartmentMembership}
     */
    private function unscheduledScenario(): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $staff = Staff::factory()->create();

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);
        $department->load('defaultTeam');

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'title' => 'Ranger Dirt Day Shift',
            'starts_at' => Carbon::parse('2026-07-01 08:00:00'),
            'ends_at' => Carbon::parse('2026-07-01 16:00:00'),
        ]);

        $departmentMembership = DepartmentMembership::query()
            ->active()
            ->where('department_id', $department->id)
            ->where('staff_id', $staff->id)
            ->firstOrFail();

        $actor = $this->shiftLeadUserFor($department->defaultTeam);
        EventDepartmentPresence::factory()->onSite()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'staff_id' => $staff->id,
            'last_marked_by_user_id' => $actor->id,
        ]);

        return [$shift, $staff, $actor, $departmentMembership];
    }

    private function shiftLeadUserFor(Team $team): User
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
            'permission_role_id' => $this->role('department_logistics')->id,
        ]);

        return $user;
    }

    private function departmentLeadUserFor(Department $department): User
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);
        $team = Team::factory()->for($department)->create(['name' => $department->name.' Leads']);
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
            'permission_role_id' => $this->role('department_logistics')->id,
        ]);

        return $user;
    }

    private function role(string $code): PermissionRole
    {
        return PermissionRole::query()->where('code', $code)->firstOrFail();
    }
}
