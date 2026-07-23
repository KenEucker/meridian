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

        $orientation = $this->actingAs($lead)
            ->postJson('/api/commands/create-training', [
                'department_id' => $department->id,
                'name' => 'Ranger Orientation',
                'description' => 'Required before field work.',
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Ranger Orientation')
            ->assertJsonPath('requires_scheduled_attendance', false)
            ->json();

        $radio = $this->actingAs($lead)
            ->postJson('/api/commands/create-training', [
                'department_id' => $department->id,
                'name' => 'Radio Certification',
                'expires_after_days' => 365,
            ])
            ->assertCreated()
            ->assertJsonPath('expires_after_days', 365)
            ->json();

        $this->actingAs($lead)
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
        $this->actingAs($lead)
            ->postJson('/api/commands/add-training-prerequisite', [
                'training_id' => $orientation['id'],
                'prerequisite_training_id' => $radio['id'],
            ])
            ->assertStatus(422);

        $this->actingAs($lead)
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

        $training = $this->actingAs($organizer)
            ->postJson('/api/commands/create-training', [
                'department_id' => $department->id,
                'name' => 'Gate Basics',
            ])
            ->assertCreated()
            ->json();

        $this->actingAs($organizer)
            ->postJson('/api/commands/archive-training', ['training_id' => $training['id']])
            ->assertOk()
            ->assertJsonPath('archived_at', fn ($value) => $value !== null);

        $this->actingAs($organizer)
            ->postJson('/api/commands/restore-training', ['training_id' => $training['id']])
            ->assertOk()
            ->assertJsonPath('archived_at', null);

        [$otherDepartment, $staffUser] = $this->departmentWithRole('staff');

        $this->actingAs($staffUser)
            ->postJson('/api/commands/create-training', [
                'department_id' => $otherDepartment->id,
                'name' => 'Unauthorized Training',
            ])
            ->assertForbidden();

        $this->actingAs($staffUser)
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

        $this->actingAs($otherLead)
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

        $training = $this->actingAs($lead)
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

        $this->actingAs($memberUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $training['id']])
            ->assertCreated()
            ->assertJsonPath('active_signup_count', 1);

        // Duplicate signup is rejected.
        $this->actingAs($memberUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $training['id']])
            ->assertStatus(422);

        // Capacity is enforced for the second staff member.
        $this->actingAs($secondUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $training['id']])
            ->assertStatus(422);

        // The lead sees the roster; the staff member does not.
        $this->actingAs($lead)
            ->getJson("/api/departments/{$department->id}/trainings/{$training['id']}")
            ->assertOk()
            ->assertJsonCount(1, 'roster');

        $memberDetail = $this->actingAs($memberUser)
            ->getJson("/api/departments/{$department->id}/trainings/{$training['id']}")
            ->assertOk()
            ->assertJsonPath('viewer.is_signed_up', true)
            ->json();
        $this->assertArrayNotHasKey('roster', $memberDetail);

        // Cancelling frees the seat for the second staff member.
        $this->actingAs($memberUser)
            ->postJson('/api/commands/cancel-training-signup', ['training_id' => $training['id']])
            ->assertOk()
            ->assertJsonPath('active_signup_count', 0);

        $this->actingAs($secondUser)
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

        $this->actingAs($memberUser)
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

        $this->actingAs($lead)
            ->postJson('/api/commands/add-training-prerequisite', [
                'training_id' => $scheduled->id,
                'prerequisite_training_id' => $prerequisite->id,
            ])
            ->assertOk();

        // Prerequisite incomplete blocks signup (TRAIN-004, TRAIN-008 spirit).
        $this->actingAs($memberUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $scheduled->id])
            ->assertStatus(422);

        $memberStaff = $memberUser->staffProfiles()->firstOrFail();

        $this->actingAs($lead)
            ->postJson('/api/commands/record-training-completion', [
                'training_id' => $prerequisite->id,
                'staff_id' => $memberStaff->id,
            ])
            ->assertCreated();

        $this->actingAs($memberUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $scheduled->id])
            ->assertCreated();

        // Users outside the department cannot sign up.
        [, $outsideUser] = $this->departmentWithRole('staff');
        $this->actingAs($outsideUser)
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
        $this->actingAs($memberUser)
            ->postJson('/api/commands/record-training-completion', [
                'training_id' => $training->id,
                'staff_id' => $memberStaff->id,
            ])
            ->assertForbidden();

        $completion = $this->actingAs($lead)
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

        $this->actingAs($teamLeadUser)
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

        $result = $this->actingAs($lead)
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
        $this->actingAs($lead)
            ->postJson('/api/commands/import-training-completions', [
                'training_id' => $training->id,
                'csv' => "name\nAlex",
            ])
            ->assertStatus(422);

        // Staff cannot import completions.
        $this->actingAs($memberUser)
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
        $this->actingAs($lead)
            ->postJson('/api/commands/create-training', [
                'department_id' => $department->id,
                'name' => 'Radio Theory Online',
                'delivery' => 'online',
            ])
            ->assertStatus(422);

        $training = $this->actingAs($lead)
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
        $this->actingAs($memberUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $training['id']])
            ->assertStatus(422);

        // In-person trainings reject a training URL.
        $this->actingAs($lead)
            ->postJson('/api/commands/create-training', [
                'department_id' => $department->id,
                'name' => 'In Person With URL',
                'online_url' => 'https://training.example.org/nope',
            ])
            ->assertStatus(422);

        // The staff detail page payload carries the webpage fields.
        $this->actingAs($memberUser)
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
        $this->actingAs($lead)
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

        $training = $this->actingAs($lead)
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
        $this->actingAs($lead)
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
        $this->actingAs($memberUser)
            ->postJson('/api/commands/sign-up-for-training', ['training_id' => $training['id']])
            ->assertStatus(422);

        $this->actingAs($lead)
            ->postJson('/api/commands/record-training-completion', [
                'training_id' => $prerequisite->id,
                'staff_id' => $memberStaff->id,
            ])
            ->assertCreated();

        $this->actingAs($memberUser)
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
        $this->actingAs($lead)
            ->getJson("/api/departments/{$department->id}/trainings/{$training['id']}")
            ->assertOk()
            ->assertJsonCount(1, 'roster')
            ->assertJsonPath('roster.0.staff_id', (string) $memberStaff->id);

        $this->actingAs($lead)
            ->getJson("/api/departments/{$department->id}/trainings/{$prerequisite->id}")
            ->assertOk()
            ->assertJsonPath('unlocked_shifts.0.title', 'Training: Field Session');

        // Self-cancel withdraws the shift assignment.
        $this->actingAs($memberUser)
            ->postJson('/api/commands/cancel-training-signup', ['training_id' => $training['id']])
            ->assertOk()
            ->assertJsonPath('active_signup_count', 0);

        // Archiving the training cancels the linked shift.
        $this->actingAs($lead)
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

        $this->actingAs($lead)
            ->getJson("/api/departments/{$department->id}/trainings")
            ->assertOk()
            ->assertJsonCount(2, 'trainings');

        $this->actingAs($memberUser)
            ->getJson("/api/departments/{$department->id}/trainings")
            ->assertOk()
            ->assertJsonCount(1, 'trainings')
            ->assertJsonPath('trainings.0.name', 'Active Training')
            ->assertJsonPath('trainings.0.viewer.can_manage', false);

        // Users with no relationship to the department fail closed.
        [, $outsideUser] = $this->departmentWithRole('staff');
        $this->actingAs($outsideUser)
            ->getJson("/api/departments/{$department->id}/trainings")
            ->assertForbidden();
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
