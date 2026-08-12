
<?php

namespace Tests\Feature;

use App\Domain\Commands\ShiftAdditionRefusalReason;
use App\Domain\Permissions\PermissionCatalog;
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
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Shift\ShiftRequirementService;
use App\Services\Shift\UnscheduledShiftAdditionException;
use App\Services\Shift\UnscheduledShiftAdditionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Resolving a refused Logistics addition on an authority's own decision
 * (M18.55; CLIENT-017, CLIENT-017A; technical spec 11A.5; data/API 5.6;
 * requirements 2.4).
 *
 * CLIENT-017's floor is that a refusal is surfaced and survives until somebody
 * acts on it, and until M18.55 the only act available was dismissal. This is the
 * second one, and everything below is about the ways it is *not* a way around
 * the eligibility rules: it waives exactly one named reason, only reasons the
 * specification lists, only for a caller holding a capability the addition
 * itself does not need, and it records both facts rather than one.
 */
class ShiftAdditionOverrideTest extends TestCase
{
    use RefreshDatabase;

    private const MOMENT = '2026-07-01 10:00:00';

    public function test_an_authority_overrides_a_refusal_and_the_record_holds_both_facts(): void
    {
        [$shift, $staff] = $this->scenarioNotOnSite();
        $lead = $this->departmentLeadUserFor($shift->department);
        $refusedOperationUuid = (string) Str::uuid();
        $overrideOperationUuid = (string) Str::uuid();

        $outcome = app(UnscheduledShiftAdditionService::class)->overrideRefusedAddition(
            shift: $shift,
            staff: $staff,
            actor: $lead,
            overriddenReason: ShiftAdditionRefusalReason::StaffNotOnSite,
            overriddenOperationUuid: $refusedOperationUuid,
            moment: Carbon::parse(self::MOMENT),
            operationUuid: $overrideOperationUuid,
        );

        $this->assertSame(ShiftAssignment::STATUS_ASSIGNED, $outcome->assignment->assignment_status);
        $this->assertSame($lead->id, $outcome->assignment->assigned_by_user_id);
        // Both facts on the record: the node refused this, and a named person
        // then chose to proceed.
        $this->assertSame('staff_not_on_site', $outcome->assignment->overridden_reason_code);
        $this->assertSame($refusedOperationUuid, $outcome->assignment->override_of_operation_uuid);
        // And the override is its own command, not the refused one wearing a flag.
        $this->assertSame($overrideOperationUuid, $outcome->assignment->unscheduled_operation_uuid);
        $this->assertNotSame($refusedOperationUuid, $outcome->assignment->unscheduled_operation_uuid);
    }

    public function test_the_audit_entry_names_the_actor_the_reason_and_the_refused_command(): void
    {
        [$shift, $staff] = $this->scenarioNotOnSite();
        $lead = $this->departmentLeadUserFor($shift->department);
        $refusedOperationUuid = (string) Str::uuid();

        $outcome = app(UnscheduledShiftAdditionService::class)->overrideRefusedAddition(
            shift: $shift,
            staff: $staff,
            actor: $lead,
            overriddenReason: ShiftAdditionRefusalReason::StaffNotOnSite,
            overriddenOperationUuid: $refusedOperationUuid,
            moment: Carbon::parse(self::MOMENT),
        );

        // Its own action, so "show me the additions somebody overrode a refusal
        // to make" is a question the trail can answer by filtering rather than
        // by reading every payload.
        $audit = AuditEvent::query()
            ->where('action', 'shift_assignment.unscheduled_added_by_override')
            ->where('entity_id', $outcome->assignment->id)
            ->firstOrFail();

        $this->assertSame($lead->id, $audit->actor_user_id);
        $this->assertSame('staff_not_on_site', $audit->after_json['overridden_reason_code']);
        $this->assertSame($refusedOperationUuid, $audit->after_json['override_of_operation_uuid']);
        $this->assertSame($shift->event_id, $audit->event_id);
        $this->assertSame($shift->department_id, $audit->department_id);

        // And an ordinary addition is not filed under it.
        $this->assertSame(
            0,
            AuditEvent::query()->where('action', 'shift_assignment.unscheduled_added')->count(),
        );
    }

    public function test_an_override_is_refused_without_the_capability(): void
    {
        // The Logistics role issues the addition and does not hold the override.
        // A refusal the same desk that hit it may wave away is a confirmation
        // dialog rather than a rule.
        [$shift, $staff, $logisticsOperator] = $this->scenarioNotOnSite();

        $this->assertFalse(
            PermissionCatalog::roleHasPermission(
                PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS,
                PermissionCatalog::PERMISSION_DEPARTMENT_SHIFT_ADDITIONS_OVERRIDE,
            ),
        );

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('You are not authorized to override a refused shift addition for this department.');

        app(UnscheduledShiftAdditionService::class)->overrideRefusedAddition(
            shift: $shift,
            staff: $staff,
            actor: $logisticsOperator,
            overriddenReason: ShiftAdditionRefusalReason::StaffNotOnSite,
            overriddenOperationUuid: (string) Str::uuid(),
            moment: Carbon::parse(self::MOMENT),
        );
    }

    public function test_a_lead_of_another_department_cannot_override(): void
    {
        // The capability is department-scoped and resolves through a grant on a
        // team in the shift's own department. A lead elsewhere holds the same
        // code and no authority here.
        //
        // They are refused by the attendance check that guards every addition,
        // before the override question is reached — which is the right order:
        // the override is authority *on top of* the authority to make the
        // addition, never instead of it, so somebody who could not add this
        // person at all is not told they merely lack an override.
        [$shift, $staff] = $this->scenarioNotOnSite();
        $otherDepartment = Department::factory()
            ->for($shift->event->organization)
            ->create(['name' => 'Gate']);
        $foreignLead = $this->departmentLeadUserFor($otherDepartment);

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('You are not authorized to add unscheduled staff to this shift.');

        app(UnscheduledShiftAdditionService::class)->overrideRefusedAddition(
            shift: $shift,
            staff: $staff,
            actor: $foreignLead,
            overriddenReason: ShiftAdditionRefusalReason::StaffNotOnSite,
            overriddenOperationUuid: (string) Str::uuid(),
            moment: Carbon::parse(self::MOMENT),
        );
    }

    public function test_do_not_staff_cannot_be_overridden_at_any_authority(): void
    {
        // An organization's exclusion decision about a person, made deliberately
        // and recorded at organization scope. Not a decision a desk reverses at
        // two in the morning, and not one the authority above the desk reverses
        // either: the allowlist is a property of the reason, not of the caller.
        [$shift, $staff] = $this->scenarioNotOnSite();
        $lead = $this->departmentLeadUserFor($shift->department);
        StaffOrganizationStatus::factory()->create([
            'organization_id' => $shift->event->organization_id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF,
        ]);

        $this->assertFalse(ShiftAdditionRefusalReason::DoNotStaff->isOverridable());

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('A refusal for `do_not_staff` cannot be overridden.');

        app(UnscheduledShiftAdditionService::class)->overrideRefusedAddition(
            shift: $shift,
            staff: $staff,
            actor: $lead,
            overriddenReason: ShiftAdditionRefusalReason::DoNotStaff,
            overriddenOperationUuid: (string) Str::uuid(),
            moment: Carbon::parse(self::MOMENT),
        );

        $this->assertDatabaseCount('shift_assignments', 0);
    }

    /**
     * The allowlist as a whole, asserted rather than assumed.
     *
     * The product decision M18.55 was required to record: three reasons an
     * authority may set aside and three it may not. A reason moving between the
     * lists is a product change and should read as one here.
     */
    public function test_the_overridable_reasons_are_the_three_the_task_recorded(): void
    {
        $this->assertSame(
            [
                'staff_not_on_site',
                'missing_required_training',
                'not_eligible_team_member',
            ],
            ShiftAdditionRefusalReason::overridableCodes(),
        );

        foreach ([
            ShiftAdditionRefusalReason::DoNotStaff,
            ShiftAdditionRefusalReason::MissingRequiredWaiver,
            ShiftAdditionRefusalReason::NoDepartmentMembership,
        ] as $reason) {
            $this->assertFalse($reason->isOverridable(), $reason->value.' must not be overridable.');
        }
    }

    public function test_a_missing_waiver_is_refused_even_where_the_training_was_overridden(): void
    {
        // An override waives one reason, and one only. A staff member overridden
        // past a missing training who also has no waiver is still refused for the
        // waiver — which is the property that keeps this a recorded exception to
        // one rule rather than a way past all of them.
        [$shift, $staff] = $this->scenarioOnSite();
        $lead = $this->departmentLeadUserFor($shift->department);
        $organization = $shift->event->organization;
        $training = Training::factory()->for($organization)->create();
        $waiver = Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
        app(ShiftRequirementService::class)->addTrainingRequirement($shift, $training);
        app(ShiftRequirementService::class)->addWaiverRequirement($shift, $waiver);

        try {
            app(UnscheduledShiftAdditionService::class)->overrideRefusedAddition(
                shift: $shift->refresh(),
                staff: $staff,
                actor: $lead,
                overriddenReason: ShiftAdditionRefusalReason::MissingRequiredTraining,
                overriddenOperationUuid: (string) Str::uuid(),
                moment: Carbon::parse(self::MOMENT),
            );

            $this->fail('A second refusal should survive an override of the first.');
        } catch (UnscheduledShiftAdditionException $exception) {
            $this->assertSame(
                'Required waiver must be complete before unscheduled shift addition.',
                $exception->getMessage(),
            );
            $this->assertSame('missing_required_waiver', $exception->reasonCode());
        }

        $this->assertDatabaseCount('shift_assignments', 0);
    }

    public function test_an_override_of_a_reason_that_does_not_apply_is_refused(): void
    {
        // Overriding nothing. Refused rather than quietly applied as an ordinary
        // addition, so an override entry in the audit trail always means one
        // actually happened.
        [$shift, $staff] = $this->scenarioOnSite();
        $lead = $this->departmentLeadUserFor($shift->department);

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('This addition is not refused for `staff_not_on_site`, so there is nothing to override.');

        app(UnscheduledShiftAdditionService::class)->overrideRefusedAddition(
            shift: $shift,
            staff: $staff,
            actor: $lead,
            overriddenReason: ShiftAdditionRefusalReason::StaffNotOnSite,
            overriddenOperationUuid: (string) Str::uuid(),
            moment: Carbon::parse(self::MOMENT),
        );
    }

    public function test_the_shifts_own_conditions_still_refuse_an_override(): void
    {
        // Cancelled and not-yet-started are facts about the shift rather than
        // about the person, and neither is on the allowlist. An authority
        // overriding a presence check does not thereby start a shift early.
        [$shift, $staff] = $this->scenarioNotOnSite();
        $lead = $this->departmentLeadUserFor($shift->department);

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('Unscheduled staff can only be added after shift operations have started.');

        app(UnscheduledShiftAdditionService::class)->overrideRefusedAddition(
            shift: $shift,
            staff: $staff,
            actor: $lead,
            overriddenReason: ShiftAdditionRefusalReason::StaffNotOnSite,
            overriddenOperationUuid: (string) Str::uuid(),
            moment: Carbon::parse('2026-07-01 06:00:00'),
        );
    }

    public function test_a_replayed_override_returns_the_assignment_it_already_made(): void
    {
        // The override is a queued command like any other and reaches the node
        // twice when a reply is lost. Its own key carries the replay guarantee,
        // exactly as the addition's does (data/API 5.3).
        [$shift, $staff] = $this->scenarioNotOnSite();
        $lead = $this->departmentLeadUserFor($shift->department);
        $overrideOperationUuid = (string) Str::uuid();
        $refusedOperationUuid = (string) Str::uuid();

        $first = app(UnscheduledShiftAdditionService::class)->overrideRefusedAddition(
            shift: $shift,
            staff: $staff,
            actor: $lead,
            overriddenReason: ShiftAdditionRefusalReason::StaffNotOnSite,
            overriddenOperationUuid: $refusedOperationUuid,
            moment: Carbon::parse(self::MOMENT),
            operationUuid: $overrideOperationUuid,
        );

        $replay = app(UnscheduledShiftAdditionService::class)->overrideRefusedAddition(
            shift: $shift,
            staff: $staff,
            actor: $lead,
            overriddenReason: ShiftAdditionRefusalReason::StaffNotOnSite,
            overriddenOperationUuid: $refusedOperationUuid,
            moment: Carbon::parse('2026-07-01 10:05:00'),
            operationUuid: $overrideOperationUuid,
        );

        $this->assertTrue($replay->replayed);
        $this->assertSame((string) $first->assignment->id, (string) $replay->assignment->id);
        $this->assertDatabaseCount('shift_assignments', 1);
        $this->assertSame(
            1,
            AuditEvent::query()
                ->where('action', 'shift_assignment.unscheduled_added_by_override')
                ->count(),
        );
    }

    public function test_an_ordinary_addition_records_no_override_provenance(): void
    {
        // The columns mean "there was a refusal and somebody overrode it", which
        // requires them to be empty everywhere else.
        [$shift, $staff, $logisticsOperator] = $this->scenarioOnSite();

        $outcome = app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $logisticsOperator,
            moment: Carbon::parse(self::MOMENT),
        );

        $this->assertNull($outcome->assignment->overridden_reason_code);
        $this->assertNull($outcome->assignment->override_of_operation_uuid);
    }

    /**
     * A refused addition carries a code as well as a sentence (M18.55).
     *
     * The client's override control is decided from the code, so a refusal that
     * arrived without one would be a refusal nothing could ever be done about.
     */
    public function test_every_eligibility_refusal_carries_a_reason_code(): void
    {
        [$shift, $staff, $logisticsOperator] = $this->scenarioNotOnSite();

        try {
            app(UnscheduledShiftAdditionService::class)->addStaffToShift(
                shift: $shift,
                staff: $staff,
                actor: $logisticsOperator,
                moment: Carbon::parse(self::MOMENT),
            );

            $this->fail('A staff member who is not on site should be refused.');
        } catch (UnscheduledShiftAdditionException $exception) {
            $this->assertSame('staff_not_on_site', $exception->reasonCode());
        }
    }

    /**
     * @return array{0: Shift, 1: Staff, 2: User}
     */
    private function scenarioOnSite(): array
    {
        [$shift, $staff, $actor] = $this->scenarioNotOnSite();

        EventDepartmentPresence::factory()->onSite()->create([
            'event_id' => $shift->event_id,
            'department_id' => $shift->department_id,
            'staff_id' => $staff->id,
            'last_marked_by_user_id' => $actor->id,
        ]);

        return [$shift, $staff, $actor];
    }

    /**
     * Everything an addition needs except the on-site mark, which is the
     * overridable refusal most of these tests turn on.
     *
     * @return array{0: Shift, 1: Staff, 2: User}
     */
    private function scenarioNotOnSite(): array
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

        return [$shift, $staff, $this->grantedUserFor($department->defaultTeam, 'department_logistics')];
    }

    /** A department lead: the authority the override answers to. */
    private function departmentLeadUserFor(Department $department): User
    {
        $team = Team::factory()->for($department)->create([
            'name' => $department->name.' Leads',
        ]);

        return $this->grantedUserFor($team, PermissionCatalog::ROLE_DEPARTMENT_LEAD);
    }

    private function grantedUserFor(Team $team, string $roleCode): User
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
                ->where('code', $roleCode)
                ->firstOrFail()
                ->id,
        ]);

        return $user;
    }
}
