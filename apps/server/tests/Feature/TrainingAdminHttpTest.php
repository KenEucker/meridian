<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\Training;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product-path training management (M11.16, TRAIN-001 through TRAIN-006).
 */
class TrainingAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_lead_creates_training_with_prerequisite_and_expiration_setup(): void
    {
        [$department, $lead] = $this->departmentWithRole('department_lead');

        $orientation = $this->actingAsClient($lead)
            ->postJson('/api/commands/create-training', [
                'department_id' => $department->id,
                'name' => 'Ranger Orientation',
                'description' => 'Required before field work.',
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Ranger Orientation')
            ->assertJsonPath('requires_scheduled_attendance', false)
            ->json();

        $radio = $this->actingAsClient($lead)
            ->postJson('/api/commands/create-training', [
                'department_id' => $department->id,
                'name' => 'Radio Certification',
                'expires_after_days' => 365,
            ])
            ->assertCreated()
            ->assertJsonPath('expires_after_days', 365)
            ->json();

        $this->actingAsClient($lead)
            ->postJson('/api/commands/add-training-prerequisite', [
                'training_id' => $radio['id'],
                'prerequisite_training_id' => $orientation['id'],
            ])
            ->assertOk()
            ->assertJsonPath('prerequisites.0.name', 'Ranger Orientation');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'training.created',
            'entity_id' => $orientation['id'],
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'training.prerequisite_added',
        ]);

        // Prerequisite cycles stay blocked through the product path.
        $this->actingAsClient($lead)
            ->postJson('/api/commands/add-training-prerequisite', [
                'training_id' => $orientation['id'],
                'prerequisite_training_id' => $radio['id'],
            ])
            ->assertStatus(422);

        $this->actingAsClient($lead)
            ->postJson('/api/commands/remove-training-prerequisite', [
                'training_id' => $radio['id'],
                'prerequisite_training_id' => $orientation['id'],
            ])
            ->assertOk()
            ->assertJsonCount(0, 'prerequisites');
    }

    public function test_organizer_manages_department_trainings_but_staff_fail_closed(): void
    {
        [$organization, $organizer] = $this->organizationWithRole('organizer');
        $department = Department::factory()->for($organization)->create(['name' => 'Gate', 'code' => 'GATE']);
        Team::factory()->for($department)->create(['is_default' => true]);

        $training = $this->actingAsClient($organizer)
            ->postJson('/api/commands/create-training', [
                'department_id' => $department->id,
                'name' => 'Gate Basics',
            ])
            ->assertCreated()
            ->json();

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/archive-training', ['training_id' => $training['id']])
            ->assertOk()
            ->assertJsonPath('archived_at', fn ($value) => $value !== null);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/restore-training', ['training_id' => $training['id']])
            ->assertOk()
            ->assertJsonPath('archived_at', null);

        [$otherDepartment, $staffUser] = $this->departmentWithRole('staff');

        $this->actingAsClient($staffUser)
            ->postJson('/api/commands/create-training', [
                'department_id' => $otherDepartment->id,
                'name' => 'Unauthorized Training',
            ])
            ->assertForbidden();

        $this->actingAsClient($staffUser)
            ->postJson('/api/commands/update-training', [
                'training_id' => $training['id'],
                'name' => 'Hijacked',
            ])
            ->assertForbidden();
    }

    public function test_department_lead_of_another_department_cannot_manage_trainings(): void
    {
        [$department] = $this->departmentWithRole('department_lead');
        [, $otherLead] = $this->departmentWithRole('department_lead');

        $this->actingAsClient($otherLead)
            ->postJson('/api/commands/create-training', [
                'department_id' => $department->id,
                'name' => 'Cross-department Training',
            ])
            ->assertForbidden();
    }

    public function test_staff_sign_up_for_scheduled_training_with_capacity_and_roster_restrictions(): void
    {
        [$department, $lead] = $this->departmentWithRole('department_lead');
        $defaultTeam = $department->teams()->where('is_default', true)->firstOrFail();

        $memberUser = $this->staffUserInTeam($defaultTeam);
        $secondUser = $this->staffUserInTeam($defaultTeam);

        $training = $this->actingAsClient($lead)
            ->postJson('/api/commands/create-training', [
                'department_id' => $department->id,
                'name' => 'Scheduled Field Training',
                'scheduled_start_at' => now()->addDays(7)->toIso8601String(),
                'scheduled_end_at' => now()->addDays(7)->addHours(3)->toIso8601String(),
                'location' => 'HQ Tent',
                'capacity' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('requires_scheduled_attendance', true)
            ->json();

        $this->actingAsClient($memberUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $training['id']])
            ->assertCreated()
            ->assertJsonPath('active_signup_count', 1);

        // Duplicate signup is rejected.
        $this->actingAsClient($memberUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $training['id']])
            ->assertStatus(422);

        // Capacity is enforced for the second staff member.
        $this->actingAsClient($secondUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $training['id']])
            ->assertStatus(422);

        // The lead sees the roster; the staff member does not.
        $this->actingAsClient($lead)
            ->getJson("/api/departments/{$department->id}/trainings/{$training['id']}")
            ->assertOk()
            ->assertJsonCount(1, 'roster');

        $memberDetail = $this->actingAsClient($memberUser)
            ->getJson("/api/departments/{$department->id}/trainings/{$training['id']}")
            ->assertOk()
            ->assertJsonPath('viewer.is_signed_up', true)
            ->json();
        $this->assertArrayNotHasKey('roster', $memberDetail);

        // Cancelling frees the seat for the second staff member.
        $this->actingAsClient($memberUser)
            ->postJson('/api/commands/cancel-training-signup', ['training_id' => $training['id']])
            ->assertOk()
            ->assertJsonPath('active_signup_count', 0);

        $this->actingAsClient($secondUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $training['id']])
            ->assertCreated();

        $this->assertDatabaseHas('audit_events', ['action' => 'training.signed_up']);
        $this->assertDatabaseHas('audit_events', ['action' => 'training.signup_cancelled']);
    }

    public function test_signup_requires_scheduled_attendance_membership_and_prerequisites(): void
    {
        [$department, $lead] = $this->departmentWithRole('department_lead');
        $defaultTeam = $department->teams()->where('is_default', true)->firstOrFail();
        $memberUser = $this->staffUserInTeam($defaultTeam);

        $unscheduled = Training::factory()
            ->for($department->organization)
            ->create(['department_id' => $department->id, 'name' => 'One-off Reading']);

        $this->actingAsClient($memberUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $unscheduled->id])
            ->assertStatus(422);

        $prerequisite = Training::factory()
            ->for($department->organization)
            ->create(['department_id' => $department->id, 'name' => 'Orientation']);

        $scheduled = Training::factory()
            ->for($department->organization)
            ->create([
                'department_id' => $department->id,
                'name' => 'Advanced Session',
                'scheduled_start_at' => now()->addDays(3),
            ]);

        $this->actingAsClient($lead)
            ->postJson('/api/commands/add-training-prerequisite', [
                'training_id' => $scheduled->id,
                'prerequisite_training_id' => $prerequisite->id,
            ])
            ->assertOk();

        // Prerequisite incomplete blocks signup (TRAIN-004, TRAIN-008 spirit).
        $this->actingAsClient($memberUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $scheduled->id])
            ->assertStatus(422);

        $memberStaff = $memberUser->staffProfiles()->firstOrFail();

        $this->actingAsClient($lead)
            ->postJson('/api/commands/record-training-completion', [
                'training_id' => $prerequisite->id,
                'staff_id' => $memberStaff->id,
            ])
            ->assertCreated();

        $this->actingAsClient($memberUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $scheduled->id])
            ->assertCreated();

        // Users outside the department cannot sign up.
        [, $outsideUser] = $this->departmentWithRole('staff');
        $this->actingAsClient($outsideUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $scheduled->id])
            ->assertForbidden();
    }

    public function test_authorized_trainers_record_completion_with_derived_expiration(): void
    {
        [$department, $lead] = $this->departmentWithRole('department_lead');
        $defaultTeam = $department->teams()->where('is_default', true)->firstOrFail();
        $memberUser = $this->staffUserInTeam($defaultTeam);
        $memberStaff = $memberUser->staffProfiles()->firstOrFail();

        $training = Training::factory()
            ->for($department->organization)
            ->create([
                'department_id' => $department->id,
                'name' => 'Annual Refresher',
                'expires_after_days' => 365,
            ]);

        // Staff cannot record their own completion.
        $this->actingAsClient($memberUser)
            ->postJson('/api/commands/record-training-completion', [
                'training_id' => $training->id,
                'staff_id' => $memberStaff->id,
            ])
            ->assertForbidden();

        $completion = $this->actingAsClient($lead)
            ->postJson('/api/commands/record-training-completion', [
                'training_id' => $training->id,
                'staff_id' => $memberStaff->id,
                'completed_at' => '2026-07-01T00:00:00Z',
            ])
            ->assertCreated()
            ->json();

        $this->assertSame('2026-07-01T00:00:00+00:00', $completion['completed_at']);
        $this->assertSame('2027-07-01T00:00:00+00:00', $completion['expires_at']);

        $this->assertDatabaseHas('audit_events', ['action' => 'training.completion_recorded']);

        // A team lead (shift_lead) of the training's team is an authorized trainer.
        $teamTraining = Training::factory()
            ->for($department->organization)
            ->create([
                'department_id' => $department->id,
                'team_id' => $defaultTeam->id,
                'name' => 'Team Radio Drill',
            ]);

        $teamLeadUser = $this->staffUserInTeam($defaultTeam, 'shift_lead');

        $this->actingAsClient($teamLeadUser)
            ->postJson('/api/commands/record-training-completion', [
                'training_id' => $teamTraining->id,
                'staff_id' => $memberStaff->id,
            ])
            ->assertCreated();
    }

    public function test_completion_spreadsheet_import_records_and_skips_rows(): void
    {
        [$department, $lead] = $this->departmentWithRole('department_lead');
        $defaultTeam = $department->teams()->where('is_default', true)->firstOrFail();
        $memberUser = $this->staffUserInTeam($defaultTeam);
        $memberStaff = $memberUser->staffProfiles()->firstOrFail();

        $training = Training::factory()
            ->for($department->organization)
            ->create([
                'department_id' => $department->id,
                'name' => 'Imported Training',
                'expires_after_days' => 30,
            ]);

        $csv = implode("\n", [
            'email,completed_at',
            "{$memberStaff->email},2026-07-10",
            'unknown-person@example.org,2026-07-10',
            "{$memberStaff->email},not-a-date",
        ]);

        $result = $this->actingAsClient($lead)
            ->postJson('/api/commands/import-training-completions', [
                'training_id' => $training->id,
                'csv' => $csv,
            ])
            ->assertOk()
            ->json();

        $this->assertSame(1, $result['imported']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame('imported', $result['rows'][0]['status']);
        $this->assertSame('No staff record with this email.', $result['rows'][1]['reason']);
        $this->assertSame('Unreadable completed_at date.', $result['rows'][2]['reason']);

        $this->assertDatabaseHas('training_completions', [
            'training_id' => $training->id,
            'staff_id' => $memberStaff->id,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'training.completions_imported']);

        // Import without a usable header fails closed.
        $this->actingAsClient($lead)
            ->postJson('/api/commands/import-training-completions', [
                'training_id' => $training->id,
                'csv' => "name\nAlex",
            ])
            ->assertStatus(422);

        // Staff cannot import completions.
        $this->actingAsClient($memberUser)
            ->postJson('/api/commands/import-training-completions', [
                'training_id' => $training->id,
                'csv' => $csv,
            ])
            ->assertForbidden();
    }

    public function test_online_training_requires_url_and_does_not_take_signups(): void
    {
        [$department, $lead] = $this->departmentWithRole('department_lead');
        $defaultTeam = $department->teams()->where('is_default', true)->firstOrFail();
        $memberUser = $this->staffUserInTeam($defaultTeam);

        // Online delivery without a URL fails closed.
        $this->actingAsClient($lead)
            ->postJson('/api/commands/create-training', [
                'department_id' => $department->id,
                'name' => 'Radio Theory Online',
                'delivery' => 'online',
            ])
            ->assertStatus(422);

        $training = $this->actingAsClient($lead)
            ->postJson('/api/commands/create-training', [
                'department_id' => $department->id,
                'name' => 'Radio Theory Online',
                'delivery' => 'online',
                'online_url' => 'https://training.example.org/radio-theory',
                'time_commitment' => 'About 45 minutes, self paced.',
                'after_training' => 'A trainer records your completion after the follow-up quiz.',
            ])
            ->assertCreated()
            ->assertJsonPath('delivery', 'online')
            ->assertJsonPath('online_url', 'https://training.example.org/radio-theory')
            ->assertJsonPath('requires_scheduled_attendance', false)
            ->json();

        // Online trainings never take signups; staff visit the URL instead.
        $this->actingAsClient($memberUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $training['id']])
            ->assertStatus(422);

        // In-person trainings reject a training URL.
        $this->actingAsClient($lead)
            ->postJson('/api/commands/create-training', [
                'department_id' => $department->id,
                'name' => 'In Person With URL',
                'online_url' => 'https://training.example.org/nope',
            ])
            ->assertStatus(422);

        // The staff detail page payload carries the webpage fields.
        $this->actingAsClient($memberUser)
            ->getJson("/api/departments/{$department->id}/trainings/{$training['id']}")
            ->assertOk()
            ->assertJsonPath('time_commitment', 'About 45 minutes, self paced.')
            ->assertJsonPath('after_training', 'A trainer records your completion after the follow-up quiz.');
    }

    public function test_event_bound_in_person_training_materializes_linked_shift_signup(): void
    {
        [$department, $lead] = $this->departmentWithRole('department_lead');
        $organization = $department->organization;
        $event = Event::factory()->for($organization)->create();
        $defaultTeam = $department->teams()->where('is_default', true)->firstOrFail();
        $memberUser = $this->staffUserInTeam($defaultTeam);
        $memberStaff = $memberUser->staffProfiles()->firstOrFail();

        // A scheduled event training needs an end time to become a shift.
        $this->actingAsClient($lead)
            ->postJson('/api/commands/create-training', [
                'department_id' => $department->id,
                'name' => 'Field Session',
                'event_id' => $event->id,
                'scheduled_start_at' => now()->addDays(5)->toIso8601String(),
            ])
            ->assertStatus(422);

        $prerequisite = Training::factory()
            ->for($organization)
            ->create(['department_id' => $department->id, 'name' => 'Orientation']);

        $training = $this->actingAsClient($lead)
            ->postJson('/api/commands/create-training', [
                'department_id' => $department->id,
                'name' => 'Field Session',
                'event_id' => $event->id,
                'scheduled_start_at' => now()->addDays(5)->toIso8601String(),
                'scheduled_end_at' => now()->addDays(5)->addHours(2)->toIso8601String(),
                'capacity' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('linked_shift.title', 'Training: Field Session')
            ->json();

        $shiftId = $training['linked_shift']['id'];

        $this->assertDatabaseHas('shifts', [
            'id' => $shiftId,
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $defaultTeam->id,
            'capacity' => 5,
        ]);

        // Prerequisites become shift training requirements (TRAIN-008 machinery).
        $this->actingAsClient($lead)
            ->postJson('/api/commands/add-training-prerequisite', [
                'training_id' => $training['id'],
                'prerequisite_training_id' => $prerequisite->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('shift_training_requirements', [
            'shift_id' => $shiftId,
            'training_id' => $prerequisite->id,
        ]);

        // Signup flows through shift signup and enforces the prerequisite.
        $this->actingAsClient($memberUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $training['id']])
            ->assertStatus(422);

        $this->actingAsClient($lead)
            ->postJson('/api/commands/record-training-completion', [
                'training_id' => $prerequisite->id,
                'staff_id' => $memberStaff->id,
            ])
            ->assertCreated();

        $this->actingAsClient($memberUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $training['id']])
            ->assertCreated()
            ->assertJsonPath('shift_id', $shiftId)
            ->assertJsonPath('active_signup_count', 1);

        $this->assertDatabaseHas('shift_assignments', [
            'shift_id' => $shiftId,
            'staff_id' => $memberStaff->id,
            'removed_at' => null,
        ]);

        // The trainer roster reads from the linked shift assignments, and the
        // page derives shifts unlocked by the prerequisite training.
        $this->actingAsClient($lead)
            ->getJson("/api/departments/{$department->id}/trainings/{$training['id']}")
            ->assertOk()
            ->assertJsonCount(1, 'roster')
            ->assertJsonPath('roster.0.staff_id', (string) $memberStaff->id);

        $this->actingAsClient($lead)
            ->getJson("/api/departments/{$department->id}/trainings/{$prerequisite->id}")
            ->assertOk()
            ->assertJsonPath('unlocked_shifts.0.title', 'Training: Field Session');

        // Self-cancel withdraws the shift assignment.
        $this->actingAsClient($memberUser)
            ->postJson('/api/commands/cancel-training-signup', ['training_id' => $training['id']])
            ->assertOk()
            ->assertJsonPath('active_signup_count', 0);

        // Archiving the training cancels the linked shift.
        $this->actingAsClient($lead)
            ->postJson('/api/commands/archive-training', ['training_id' => $training['id']])
            ->assertOk();

        $this->assertNotNull(Shift::query()->findOrFail($shiftId)->cancelled_at);
    }

    public function test_training_list_is_permission_filtered_and_hides_archived_from_staff(): void
    {
        [$department, $lead] = $this->departmentWithRole('department_lead');
        $defaultTeam = $department->teams()->where('is_default', true)->firstOrFail();
        $memberUser = $this->staffUserInTeam($defaultTeam);

        Training::factory()
            ->for($department->organization)
            ->create(['department_id' => $department->id, 'name' => 'Active Training']);
        Training::factory()
            ->for($department->organization)
            ->archived()
            ->create(['department_id' => $department->id, 'name' => 'Archived Training']);

        $this->actingAsClient($lead)
            ->getJson("/api/departments/{$department->id}/trainings")
            ->assertOk()
            ->assertJsonCount(2, 'trainings');

        $this->actingAsClient($memberUser)
            ->getJson("/api/departments/{$department->id}/trainings")
            ->assertOk()
            ->assertJsonCount(1, 'trainings')
            ->assertJsonPath('trainings.0.name', 'Active Training')
            ->assertJsonPath('trainings.0.viewer.can_manage', false);

        // Users with no relationship to the department fail closed.
        [, $outsideUser] = $this->departmentWithRole('staff');
        $this->actingAsClient($outsideUser)
            ->getJson("/api/departments/{$department->id}/trainings")
            ->assertForbidden();
    }

    /**
     * The trainings read states the caller's authority and carries only the
     * option lists that authority entitles them to (M16.16, CLIENT-006).
     *
     * The surface decides what to offer from this response rather than from a
     * role it interpreted for itself, so the response has to answer for the page
     * as a whole — a department with no trainings yet still has to know whether
     * it may offer to create one.
     */
    public function test_training_list_states_the_readers_authority_and_option_lists(): void
    {
        [$department, $lead] = $this->departmentWithRole('department_lead');
        $defaultTeam = $department->teams()->where('is_default', true)->firstOrFail();
        $archivedTeam = Team::factory()->for($department)->archived()->create(['name' => 'Retired Patrol']);
        $memberUser = $this->staffUserInTeam($defaultTeam);
        $memberStaff = $memberUser->staffProfiles()->firstOrFail();

        Training::factory()
            ->for($department->organization)
            ->create(['department_id' => $department->id, 'name' => 'Active Training']);

        $managerRead = $this->actingAsClient($lead)
            ->getJson("/api/departments/{$department->id}/trainings")
            ->assertOk()
            ->assertJsonPath('access.can_manage', true)
            ->assertJsonPath('access.can_record_completions', true)
            ->json();

        $teamIds = array_column($managerRead['teams'], 'id');
        $this->assertContains((string) $defaultTeam->id, $teamIds);

        // An archived team is offered and marked, because a training already
        // scoped to one has to keep saying so.
        $this->assertContains((string) $archivedTeam->id, $teamIds);
        $archivedOption = collect($managerRead['teams'])->firstWhere('id', (string) $archivedTeam->id);
        $this->assertNotNull($archivedOption['archived_at']);

        $this->assertContains(
            (string) $memberStaff->id,
            array_column($managerRead['department_staff'], 'staff_id'),
        );

        /*
         * An ordinary member's read states no authority over the surface and
         * carries neither the team scope options nor the department roster, so a
         * member who reaches an edit URL has nothing to fill a form with.
         */
        $this->actingAsClient($memberUser)
            ->getJson("/api/departments/{$department->id}/trainings")
            ->assertOk()
            ->assertJsonPath('access.can_manage', false)
            ->assertJsonPath('access.can_record_completions', false)
            ->assertJsonCount(0, 'teams')
            ->assertJsonCount(0, 'department_staff');

        /*
         * A team lead is an authorized trainer for their own team's trainings
         * without managing the department's (TRAIN-005). Their read states the
         * recording authority and carries the roster that needs, but not the team
         * scope options, which are a manager's field.
         */
        $teamLeadUser = $this->staffUserInTeam($defaultTeam, 'shift_lead');
        Training::factory()
            ->for($department->organization)
            ->create([
                'department_id' => $department->id,
                'team_id' => $defaultTeam->id,
                'name' => 'Team Radio Drill',
            ]);

        $trainerRead = $this->actingAsClient($teamLeadUser)
            ->getJson("/api/departments/{$department->id}/trainings")
            ->assertOk()
            ->assertJsonPath('access.can_manage', false)
            ->assertJsonPath('access.can_record_completions', true)
            ->assertJsonCount(0, 'teams')
            ->json();

        $this->assertNotSame([], $trainerRead['department_staff']);
    }

    /**
     * The prerequisite list says which prerequisites this reader has behind them
     * (TRAIN-010).
     *
     * "Before you start" is only useful if it distinguishes what is left to do
     * from what is done, and an incomplete prerequisite is exactly what signup is
     * refused on (TRAIN-004).
     */
    public function test_prerequisite_list_says_which_prerequisites_the_reader_has_completed(): void
    {
        [$department, $lead] = $this->departmentWithRole('department_lead');
        $defaultTeam = $department->teams()->where('is_default', true)->firstOrFail();
        $memberUser = $this->staffUserInTeam($defaultTeam);
        $memberStaff = $memberUser->staffProfiles()->firstOrFail();

        $orientation = Training::factory()
            ->for($department->organization)
            ->create(['department_id' => $department->id, 'name' => 'Orientation']);

        $advanced = Training::factory()
            ->for($department->organization)
            ->create([
                'department_id' => $department->id,
                'name' => 'Advanced Session',
                'scheduled_start_at' => now()->addDays(3),
            ]);

        $this->actingAsClient($lead)
            ->postJson('/api/commands/add-training-prerequisite', [
                'training_id' => $advanced->id,
                'prerequisite_training_id' => $orientation->id,
            ])
            ->assertOk();

        $this->actingAsClient($memberUser)
            ->getJson("/api/departments/{$department->id}/trainings/{$advanced->id}")
            ->assertOk()
            ->assertJsonPath('prerequisites.0.name', 'Orientation')
            ->assertJsonPath('prerequisites.0.viewer_completed', false);

        $this->actingAsClient($lead)
            ->postJson('/api/commands/record-training-completion', [
                'training_id' => $orientation->id,
                'staff_id' => $memberStaff->id,
            ])
            ->assertCreated();

        $this->actingAsClient($memberUser)
            ->getJson("/api/departments/{$department->id}/trainings/{$advanced->id}")
            ->assertOk()
            ->assertJsonPath('prerequisites.0.viewer_completed', true);

        // The list read answers the same way, so a card can say what a detail
        // page would without a second request.
        $this->actingAsClient($memberUser)
            ->getJson("/api/departments/{$department->id}/trainings")
            ->assertOk()
            ->assertJsonPath('trainings.0.name', 'Advanced Session')
            ->assertJsonPath('trainings.0.prerequisites.0.viewer_completed', true);

        // The prerequisite is the reader's own history, not the training's: the
        // lead who recorded it has not completed anything.
        $this->actingAsClient($lead)
            ->getJson("/api/departments/{$department->id}/trainings/{$advanced->id}")
            ->assertOk()
            ->assertJsonPath('prerequisites.0.viewer_completed', false);
    }

    /**
     * A training this caller may not read is refused in the node's own words
     * (M16.16).
     *
     * The surface prints what the endpoint answered rather than inventing a
     * sentence for a bare status code, so the refusal has to carry one.
     */
    public function test_training_read_outside_the_readers_reach_is_a_stated_refusal(): void
    {
        [$department, $lead] = $this->departmentWithRole('department_lead');
        $defaultTeam = $department->teams()->where('is_default', true)->firstOrFail();
        $memberUser = $this->staffUserInTeam($defaultTeam);

        [$otherDepartment, $otherLead] = $this->departmentWithRole('department_lead');
        $foreign = Training::factory()
            ->for($otherDepartment->organization)
            ->create(['department_id' => $otherDepartment->id, 'name' => 'Another Department Training']);

        $this->actingAsClient($lead)
            ->getJson("/api/departments/{$department->id}/trainings/{$foreign->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Training not found for this department.');

        $archived = Training::factory()
            ->for($department->organization)
            ->archived()
            ->create(['department_id' => $department->id, 'name' => 'Retired Training']);

        // An archived training is the manager's to read and nobody else's, and a
        // member is told the same thing as for a training that never existed.
        $this->actingAsClient($lead)
            ->getJson("/api/departments/{$department->id}/trainings/{$archived->id}")
            ->assertOk()
            ->assertJsonPath('archived_at', fn ($value) => $value !== null);

        $this->actingAsClient($memberUser)
            ->getJson("/api/departments/{$department->id}/trainings/{$archived->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Training not found for this department.');

        $this->actingAsClient($otherLead)
            ->getJson("/api/departments/{$department->id}/trainings/{$archived->id}")
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'You do not have permission to view trainings for this department.',
            );
    }

    /**
     * @return array{Organization, User}
     */
    private function organizationWithRole(string $roleCode): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Organizers', 'code' => 'ORG']);
        $organization->forceFill(['organizers_department_id' => $department->id])->save();

        $team = Team::factory()->for($department)->create(['is_default' => true]);
        $user = $this->userOnTeam($team, $roleCode);

        return [$organization, $user];
    }

    /**
     * @return array{Department, User}
     */
    private function departmentWithRole(string $roleCode): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers', 'code' => 'RANGERS']);
        $team = Team::factory()->for($department)->create(['is_default' => true]);
        $user = $this->userOnTeam($team, $roleCode);

        return [$department, $user];
    }

    private function staffUserInTeam(Team $team, string $roleCode = 'staff'): User
    {
        return $this->userOnTeam($team, $roleCode);
    }

    private function userOnTeam(Team $team, string $roleCode): User
    {
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $organizationId = $team->department?->organization_id;
        if ($organizationId !== null) {
            StaffOrganizationStatus::query()->create([
                'organization_id' => $organizationId,
                'staff_id' => $staff->id,
                'status' => StaffOrganizationStatus::STATUS_ACTIVE,
                'status_reason' => 'Test setup.',
                'status_changed_at' => now(),
            ]);
        }

        $membership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
            // shift_lead authority requires a designated lead membership (M11.17).
            'membership_role' => $roleCode === 'shift_lead' ? 'lead' : 'member',
        ]);

        if ($roleCode !== 'staff') {
            TeamGrant::factory()->create([
                'team_id' => $team->id,
                'event_id' => null,
                'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
            ]);
        }

        return $user;
    }
}
