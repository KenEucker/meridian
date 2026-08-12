<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Modules\ModuleKey;
use App\Models\Department;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\Event;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\PolicyDocument;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Training;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Credential\CredentialEligibilityService;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Notifications\OutstandingRequirementSweep;
use App\Services\Shift\ShiftRequirementService;
use App\Services\Shift\ShiftSignupException;
use App\Services\Shift\ShiftSignupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Requirements an inactive module owns evaluate as satisfied and are not
 * presented (MOD-018; technical spec 15A.7; requirements 5.6, 5.8; M19.18).
 *
 * The rule this file pins is one sentence: **a module's absence is never an
 * error condition in another module.** An organization running Scheduling
 * without Qualifications has shifts with training requirements attached to them
 * and nobody who can complete a training, and the honest reading of that is not
 * "everybody is refused" — it is that the requirement is not asked. The same
 * goes for a shift's waiver requirements without Documents, and for requirements
 * 5.6's signed-up-shift condition without Scheduling.
 *
 * Two properties matter as much as the refusal going away.
 *
 * **The records survive.** Every test below switches a module off and asserts
 * the requirement rows are still on the shift, because MOD-018 asks for the gate
 * to be restored *exactly as it stood* when the module comes back — which is a
 * claim about the data, not about the check. Deleting a requirement when a
 * module went off would make reactivation a different organization's schedule.
 *
 * **Nothing else moves.** A module going off lifts its own condition and no
 * other. The department-status and capacity refusals still refuse with
 * Qualifications and Documents both off, because they were never Qualifications'
 * or Documents' to lift.
 */
class VacuousRequirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_training_stops_blocking_signup_when_qualifications_is_inactive(): void
    {
        [$shift, $staff, $user] = $this->shiftRequiring(training: true, waiver: false);

        // The baseline, so the test below is a change in behavior rather than a
        // scenario that never refused.
        $this->assertRefuses($shift, $staff, $user, 'Required training must be complete before shift signup.');

        $this->deactivate($shift, ModuleKey::Qualifications);

        $assignment = app(ShiftSignupService::class)->signUp($shift->refresh(), $staff, $user)->assignment;

        $this->assertSame(ShiftAssignment::STATUS_SIGNED_UP, $assignment->assignment_status);
        $this->assertRequirementsSurvive($shift, trainings: 1, waivers: 0);
    }

    public function test_a_missing_waiver_stops_blocking_signup_when_documents_is_inactive(): void
    {
        [$shift, $staff, $user] = $this->shiftRequiring(training: false, waiver: true);

        $this->assertRefuses($shift, $staff, $user, 'Required waiver must be complete before shift signup.');

        $this->deactivate($shift, ModuleKey::Documents);

        $assignment = app(ShiftSignupService::class)->signUp($shift->refresh(), $staff, $user)->assignment;

        $this->assertSame(ShiftAssignment::STATUS_SIGNED_UP, $assignment->assignment_status);
        $this->assertRequirementsSurvive($shift, trainings: 0, waivers: 1);
    }

    /**
     * One module off lifts one condition, and the other still refuses.
     *
     * The case that would go unnoticed if the two checks had been written as one
     * block: a shift requiring both, with only Qualifications switched off, must
     * still refuse for the waiver rather than sailing through.
     */
    public function test_switching_one_module_off_leaves_the_other_modules_requirement_standing(): void
    {
        [$shift, $staff, $user] = $this->shiftRequiring(training: true, waiver: true);

        $this->deactivate($shift, ModuleKey::Qualifications);

        $this->assertRefuses($shift, $staff, $user, 'Required waiver must be complete before shift signup.');
    }

    /**
     * Activation restores the gate as it stood (MOD-018, MOD-020).
     *
     * Asserted by the refusal coming back with its own words for the same
     * requirement row, which is the strongest available statement that the
     * requirement was read past rather than removed.
     */
    public function test_activating_the_module_restores_the_gate_it_owned(): void
    {
        [$shift, $staff, $user] = $this->shiftRequiring(training: true, waiver: false);

        $this->deactivate($shift, ModuleKey::Qualifications);
        app(ShiftSignupService::class)->signUp($shift->refresh(), $staff, $user);

        // A second staff member, because the first now holds an assignment and
        // would be refused as a duplicate rather than for the training.
        [$secondStaff, $secondUser] = $this->staffIn($shift->department);
        $this->activate($shift, ModuleKey::Qualifications);

        $this->assertRefuses(
            $shift,
            $secondStaff,
            $secondUser,
            'Required training must be complete before shift signup.',
        );
    }

    /**
     * A refusal that is not the module's stays a refusal (requirements 5.8).
     */
    public function test_a_refusal_no_module_owns_survives_both_modules_being_off(): void
    {
        [$shift, $staff, $user] = $this->shiftRequiring(training: true, waiver: true);

        $this->deactivate($shift, ModuleKey::Qualifications);
        $this->deactivate($shift, ModuleKey::Documents);
        $shift->forceFill(['capacity' => 1])->save();
        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => Staff::factory()->create()->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        $this->assertRefuses(
            $shift,
            $staff,
            $user,
            'This shift is full and cannot accept additional signup.',
        );
    }

    /**
     * The shift board stops naming a requirement nobody is held to (MOD-018:
     * "shall not be presented").
     */
    public function test_the_shift_board_does_not_name_requirements_an_inactive_module_owns(): void
    {
        [$shift, $staff, $user] = $this->shiftRequiring(training: true, waiver: true);
        $event = $shift->event;

        $live = $this->actingAsClient($user)
            ->getJson("/api/events/{$event->id}/shift-board")
            ->assertOk();

        $this->assertCount(1, $live->json('shifts.0.required_training_names'));
        $this->assertCount(1, $live->json('shifts.0.required_waiver_names'));

        $this->deactivate($shift, ModuleKey::Qualifications);
        $this->deactivate($shift, ModuleKey::Documents);

        $degraded = $this->actingAsClient($user)
            ->getJson("/api/events/{$event->id}/shift-board")
            ->assertOk();

        $this->assertSame([], $degraded->json('shifts.0.required_training_names'));
        $this->assertSame([], $degraded->json('shifts.0.required_waiver_names'));
        // The board itself is Scheduling's and is still there: only the
        // requirement names went.
        $this->assertSame((string) $shift->id, $degraded->json('shifts.0.id'));
        $this->assertRequirementsSurvive($shift, trainings: 1, waivers: 1);
    }

    /**
     * Credential eligibility works with Scheduling inactive (requirements 5.6).
     *
     * The condition requirements 5.6 lists first — "at least one signed-up
     * shift" — is Scheduling's, and an organization running Qualifications alone
     * has no shift for anybody to hold. Without this the whole feature would be
     * unreachable rather than unrequired: every credential would read blocked
     * for a reason nobody in the organization could act on.
     */
    public function test_credential_eligibility_does_not_require_a_shift_when_scheduling_is_inactive(): void
    {
        [$shift, $staff] = $this->shiftRequiring(training: false, waiver: false);
        $event = $shift->event;

        $blocked = app(CredentialEligibilityService::class)->evaluate($event, $staff);
        $this->assertFalse($blocked->eligible);
        $this->assertSame(
            CredentialEligibilityService::REASON_NO_SIGNED_UP_SHIFTS,
            $blocked->blockReason,
        );

        $this->deactivate($shift, ModuleKey::Scheduling);

        $evaluation = app(CredentialEligibilityService::class)->evaluate($event, $staff);
        $this->assertTrue($evaluation->eligible);
        $this->assertNull($evaluation->blockReason);
    }

    /**
     * The other four conditions still decide, so the vacuous one does not become
     * a way past the rest.
     */
    public function test_credential_eligibility_still_blocks_on_a_core_condition_without_scheduling(): void
    {
        [$shift, $staff] = $this->shiftRequiring(training: false, waiver: false);
        $event = $shift->event;
        $this->deactivate($shift, ModuleKey::Scheduling);

        StaffOrganizationStatus::query()->updateOrCreate(
            ['organization_id' => $event->organization_id, 'staff_id' => $staff->id],
            ['status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF],
        );

        $evaluation = app(CredentialEligibilityService::class)->evaluate($event, $staff);

        $this->assertFalse($evaluation->eligible);
        $this->assertSame(
            CredentialEligibilityService::REASON_ORGANIZATION_BLOCKING_STATUS,
            $evaluation->blockReason,
        );
    }

    /**
     * A shift's waiver requirements do not gate credential eligibility either
     * (MOD-018's first bullet names both places).
     */
    public function test_credential_eligibility_ignores_shift_waivers_when_documents_is_inactive(): void
    {
        [$shift, $staff, $user] = $this->shiftRequiring(training: false, waiver: true);
        $event = $shift->event;

        $this->deactivate($shift, ModuleKey::Documents);
        app(ShiftSignupService::class)->signUp($shift->refresh(), $staff, $user);

        $evaluation = app(CredentialEligibilityService::class)->evaluate($event, $staff->refresh());

        $this->assertTrue($evaluation->eligible);
        $this->assertRequirementsSurvive($shift, trainings: 0, waivers: 1);
    }

    /**
     * The caller's own acknowledgment list spans organizations, so it narrows
     * rather than refusing (data/API 5.9; MOD-019).
     *
     * The route gate refuses this read only when *no* organization the caller
     * belongs to runs Documents. One that does not run it contributes no rows,
     * and one that does keeps its own — which is the difference between omitting
     * a contribution and taking the surface away.
     */
    public function test_the_personal_acknowledgment_list_omits_an_organization_that_does_not_run_documents(): void
    {
        $user = User::factory()->create();
        $running = $this->requirementFor($user, 'Running Documents');
        $notRunning = $this->requirementFor($user, 'Not Running Documents');

        OrganizationModule::query()->create([
            'organization_id' => $notRunning->organization_id,
            'module_key' => ModuleKey::Documents->value,
            'entitled' => true,
            'enabled' => false,
        ]);

        $response = $this->actingAsClient($user)
            ->getJson('/api/document-acknowledgments/me')
            ->assertOk();

        $ids = collect($response->json('requirements'))->pluck('requirement_id');

        $this->assertTrue($ids->contains((string) $running->id));
        $this->assertFalse($ids->contains((string) $notRunning->id));
        $this->assertSame(1, $response->json('outstanding_count'));

        // Nothing was deleted to achieve that.
        $this->assertDatabaseHas('document_acknowledgment_requirements', [
            'id' => $notRunning->id,
        ]);
    }

    /**
     * The outstanding-requirement sweep is a presentation too (MOD-018).
     *
     * Both notifications it sends are about Documents' own requirements, so an
     * organization that does not run it is skipped — and skipped without writing
     * a delivery record, so a person who was never told is still owed the
     * message when the module comes back rather than counted as told.
     */
    public function test_the_outstanding_requirement_sweep_skips_an_organization_without_documents(): void
    {
        $user = User::factory()->create();
        $requirement = $this->requirementFor($user, 'Quiet Organization');

        OrganizationModule::query()->create([
            'organization_id' => $requirement->organization_id,
            'module_key' => ModuleKey::Documents->value,
            'entitled' => true,
            'enabled' => false,
        ]);

        $this->assertSame(
            ['acknowledgments' => 0, 'waivers' => 0],
            app(OutstandingRequirementSweep::class)->run(),
        );
        $this->assertSame(0, NotificationDelivery::query()->count());

        OrganizationModule::query()
            ->where('organization_id', $requirement->organization_id)
            ->update(['enabled' => true]);

        $this->assertSame(1, app(OutstandingRequirementSweep::class)->run()['acknowledgments']);
    }

    /**
     * A shift in a department of one organization, optionally requiring a
     * training, a waiver, or both, with one staff member eligible for it.
     *
     * @return array{0: Shift, 1: Staff, 2: User}
     */
    private function shiftRequiring(bool $training, bool $waiver): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);

        [$staff, $user] = $this->staffIn($department);

        $department->load('defaultTeam');

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'title' => 'Gate Lead',
            'signup_opens_at' => null,
            'signup_closes_at' => null,
        ]);

        $requirements = app(ShiftRequirementService::class);

        if ($training) {
            $requirements->addTrainingRequirement(
                $shift,
                Training::factory()->for($organization)->create(),
            );
        }

        if ($waiver) {
            $requirements->addWaiverRequirement($shift, Waiver::factory()->for($organization)->create([
                'scope_type' => Waiver::SCOPE_ORGANIZATION,
                'scope_id' => $organization->id,
            ]));
        }

        return [$shift->refresh(), $staff, $user];
    }

    /**
     * @return array{0: Staff, 1: User}
     */
    private function staffIn(Department $department): array
    {
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);

        return [$staff, $user];
    }

    /**
     * An organization-scoped acknowledgment requirement this user is in scope
     * for, in an organization of its own.
     */
    private function requirementFor(User $user, string $organizationName): DocumentAcknowledgmentRequirement
    {
        $organization = Organization::factory()->create(['name' => $organizationName]);
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        StaffOrganizationStatus::query()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);

        $document = PolicyDocument::factory()->for($organization)->create([
            'title' => "{$organizationName} policy",
            'state' => PolicyDocument::STATE_PUBLISHED,
            'published_at' => now(),
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);

        return DocumentAcknowledgmentRequirement::query()->create([
            'organization_id' => $organization->id,
            'document_type' => DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY,
            'document_id' => $document->id,
            'scope_type' => DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'requirement_context' => DocumentAcknowledgmentRequirement::CONTEXT_SIGNUP,
        ]);
    }

    private function assertRefuses(Shift $shift, Staff $staff, User $user, string $message): void
    {
        try {
            app(ShiftSignupService::class)->signUp($shift->refresh(), $staff, $user);
            $this->fail("Expected signup to be refused with: {$message}");
        } catch (ShiftSignupException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    /**
     * The requirement rows are still attached to the shift (MOD-018, MOD-020).
     */
    private function assertRequirementsSurvive(Shift $shift, int $trainings, int $waivers): void
    {
        $shift->refresh()->load(['requiredTrainings', 'requiredWaivers']);

        $this->assertCount($trainings, $shift->requiredTrainings);
        $this->assertCount($waivers, $shift->requiredWaivers);
    }

    private function deactivate(Shift $shift, ModuleKey $module): void
    {
        $this->stateFor($shift, $module, enabled: false);
    }

    private function activate(Shift $shift, ModuleKey $module): void
    {
        $this->stateFor($shift, $module, enabled: true);
    }

    /**
     * Switch a module the way the organizer surface does: entitled, and enabled
     * or not (MOD-008).
     */
    private function stateFor(Shift $shift, ModuleKey $module, bool $enabled): void
    {
        OrganizationModule::query()->updateOrCreate(
            [
                'organization_id' => $shift->event->organization_id,
                'module_key' => $module->value,
            ],
            [
                'entitled' => true,
                'enabled' => $enabled,
            ],
        );
    }
}
