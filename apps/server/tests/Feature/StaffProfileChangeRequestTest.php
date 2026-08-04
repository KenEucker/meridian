<?php

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\StaffProfileChangeRequest;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Staffing\StaffProfilePictureService;
use App\Services\Teams\TeamDesignationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Profile change requests: the model, the handle allowance, and picture
 * submissions (M18.20A, M18.20B, M18.20C; VOL-013, VOL-017 through VOL-026;
 * data/API 10.4; technical spec 18A).
 */
class StaffProfileChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    // ---- M18.20A: the shared model ------------------------------------

    public function test_the_review_capability_reaches_organizers_and_staff_coordinators_only(): void
    {
        $capability = PermissionCatalog::PERMISSION_STAFF_PROFILE_CHANGE_REQUESTS_REVIEW;

        $holders = collect(PermissionCatalog::rolePermissions())
            ->filter(fn (array $permissions): bool => in_array($capability, $permissions, true))
            ->keys()
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            PermissionCatalog::ROLE_LEAD_ORGANIZER,
            PermissionCatalog::ROLE_ORGANIZER,
            PermissionCatalog::ROLE_STAFF_COORDINATOR,
        ], $holders);
    }

    public function test_a_second_outstanding_request_of_the_same_kind_is_refused(): void
    {
        [$organization, $staff, $user] = $this->activeStaff();
        Storage::fake('attachments');

        $this->actingAsClient($user)
            ->postJson('/api/commands/submit-profile-picture', [
                'picture' => $this->imageUpload(),
            ])
            ->assertCreated();

        $this->actingAsClient($user)
            ->postJson('/api/commands/submit-profile-picture', [
                'picture' => $this->imageUpload(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You already have a profile picture waiting for review. Withdraw it before submitting another.');

        $this->assertSame(1, StaffProfileChangeRequest::query()
            ->where('staff_id', $staff->id)
            ->where('kind', StaffProfileChangeRequest::KIND_PROFILE_PICTURE)
            ->count());

        // The refused submission left no orphaned image behind.
        $this->assertCount(1, Storage::disk('attachments')->allFiles());
    }

    public function test_a_staff_member_withdraws_their_own_request_and_not_anothers(): void
    {
        [$organization, $staff, $user] = $this->activeStaff();
        [, $otherStaff, $otherUser] = $this->activeStaff($organization);
        Storage::fake('attachments');

        $mine = $this->actingAsClient($user)
            ->postJson('/api/commands/submit-profile-picture', ['picture' => $this->imageUpload()])
            ->assertCreated()
            ->json('request.id');

        // Somebody else's request is refused without saying whose it is.
        $this->actingAsClient($otherUser)
            ->postJson('/api/commands/withdraw-profile-change-request', ['request_id' => $mine])
            ->assertForbidden();

        $this->assertSame(
            StaffProfileChangeRequest::STATUS_PENDING,
            StaffProfileChangeRequest::query()->findOrFail($mine)->status,
        );

        $this->actingAsClient($user)
            ->postJson('/api/commands/withdraw-profile-change-request', ['request_id' => $mine])
            ->assertOk()
            ->assertJsonPath('request.status', StaffProfileChangeRequest::STATUS_WITHDRAWN);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'staff.profile_change_request.withdrawn',
            'entity_id' => $mine,
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_no_role_outside_organizer_and_staff_coordinator_can_review(): void
    {
        [$organization, $staff, $user] = $this->activeStaff();
        Storage::fake('attachments');

        $requestId = $this->actingAsClient($user)
            ->postJson('/api/commands/submit-profile-picture', ['picture' => $this->imageUpload()])
            ->assertCreated()
            ->json('request.id');

        $departmentLead = $this->departmentLeadIn($organization);

        $this->actingAsClient($departmentLead)
            ->getJson('/api/staff-profile-change-requests')
            ->assertForbidden();

        $this->actingAsClient($departmentLead)
            ->postJson('/api/commands/approve-profile-change-request', ['request_id' => $requestId])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You do not review profile change requests for this staff member.');

        // The Staff Coordinator of the same organization does review it.
        $coordinator = $this->staffCoordinatorIn($organization);

        $this->actingAsClient($coordinator)
            ->getJson('/api/staff-profile-change-requests')
            ->assertOk()
            ->assertJsonPath('requests.0.id', $requestId);
    }

    public function test_a_reviewer_sees_only_requests_from_organizations_they_review(): void
    {
        [$organization, , $user] = $this->activeStaff();
        [$otherOrganization, , $otherUser] = $this->activeStaff();
        Storage::fake('attachments');

        $mine = $this->actingAsClient($user)
            ->postJson('/api/commands/submit-profile-picture', ['picture' => $this->imageUpload()])
            ->assertCreated()
            ->json('request.id');
        $theirs = $this->actingAsClient($otherUser)
            ->postJson('/api/commands/submit-profile-picture', ['picture' => $this->imageUpload()])
            ->assertCreated()
            ->json('request.id');

        $ids = collect($this->actingAsClient($this->staffCoordinatorIn($organization))
            ->getJson('/api/staff-profile-change-requests')
            ->assertOk()
            ->json('requests'))
            ->pluck('id');

        $this->assertTrue($ids->contains($mine));
        $this->assertFalse($ids->contains($theirs));
    }

    // ---- M18.20B: handle changes and the allowance ---------------------

    public function test_a_first_handle_does_not_consume_the_allowance(): void
    {
        [, $staff, $user] = $this->activeStaff(handle: null);

        $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'first-handle'])
            ->assertCreated()
            ->assertJsonPath('request.status', StaffProfileChangeRequest::STATUS_APPROVED)
            ->assertJsonPath('request.self_service', true)
            ->assertJsonPath('request.previous_handle', null);

        $this->assertSame('first-handle', $staff->refresh()->handle);

        $this->actingAsClient($user)
            ->getJson('/api/me/profile')
            ->assertOk()
            ->assertJsonPath('profiles.0.remaining_self_service_handle_changes', 2);
    }

    public function test_the_first_two_changes_apply_and_the_third_becomes_a_request(): void
    {
        [, $staff, $user] = $this->activeStaff(handle: 'original');

        foreach (['second', 'third'] as $handle) {
            $this->actingAsClient($user)
                ->postJson('/api/commands/request-handle-change', ['handle' => $handle])
                ->assertCreated()
                ->assertJsonPath('request.status', StaffProfileChangeRequest::STATUS_APPROVED)
                ->assertJsonPath('request.self_service', true);
        }

        $this->assertSame('third', $staff->refresh()->handle);

        // The third change is the one that waits.
        $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'fourth'])
            ->assertCreated()
            ->assertJsonPath('request.status', StaffProfileChangeRequest::STATUS_PENDING)
            ->assertJsonPath('request.self_service', false);

        $this->assertSame('third', $staff->refresh()->handle);
    }

    public function test_a_rejected_and_a_withdrawn_request_leave_the_allowance_unchanged(): void
    {
        [$organization, $staff, $user] = $this->activeStaff(handle: 'original');
        $coordinator = $this->staffCoordinatorIn($organization);

        $this->spendAllowance($user);

        $rejected = $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'rejected-handle'])
            ->assertCreated()
            ->json('request.id');

        $this->actingAsClient($coordinator)
            ->postJson('/api/commands/reject-profile-change-request', [
                'request_id' => $rejected,
                'reason' => 'Too close to an existing handle.',
            ])
            ->assertOk()
            ->assertJsonPath('request.status', StaffProfileChangeRequest::STATUS_REJECTED);

        // A rejected handle is not applied.
        $this->assertSame('second-change', $staff->refresh()->handle);

        $withdrawn = $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'withdrawn-handle'])
            ->assertCreated()
            ->json('request.id');

        $this->actingAsClient($user)
            ->postJson('/api/commands/withdraw-profile-change-request', ['request_id' => $withdrawn])
            ->assertOk();

        // Neither consumed anything, so neither restored anything: the
        // allowance was already spent and stays spent.
        $this->actingAsClient($user)
            ->getJson('/api/me/profile')
            ->assertOk()
            ->assertJsonPath('profiles.0.remaining_self_service_handle_changes', 0);

        $this->assertSame('second-change', $staff->refresh()->handle);
    }

    public function test_an_approved_request_applies_the_handle_and_audits_it(): void
    {
        [$organization, $staff, $user] = $this->activeStaff(handle: 'original');
        $coordinator = $this->staffCoordinatorIn($organization);

        $this->spendAllowance($user);

        $requestId = $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'reviewed-handle'])
            ->assertCreated()
            ->json('request.id');

        $this->actingAsClient($coordinator)
            ->postJson('/api/commands/approve-profile-change-request', ['request_id' => $requestId])
            ->assertOk()
            ->assertJsonPath('request.status', StaffProfileChangeRequest::STATUS_APPROVED);

        $this->assertSame('reviewed-handle', $staff->refresh()->handle);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'staff.profile_change_request.approved',
            'entity_id' => $requestId,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'staff.profile.handle_changed',
            'entity_id' => (string) $staff->id,
        ]);
    }

    public function test_a_handle_collision_is_named_to_the_reviewer_and_does_not_block_the_decision(): void
    {
        [$organization, $staff, $user] = $this->activeStaff(handle: 'original');
        $this->activeStaff($organization, handle: 'taken-handle', legalName: 'Wren Incumbent');
        $coordinator = $this->staffCoordinatorIn($organization);

        $this->spendAllowance($user);

        $requestId = $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'taken-handle'])
            ->assertCreated()
            ->json('request.id');

        $row = collect($this->actingAsClient($coordinator)
            ->getJson('/api/staff-profile-change-requests')
            ->assertOk()
            ->json('requests'))
            ->firstWhere('id', $requestId);

        $this->assertSame(['Wren Incumbent'], $row['handle_collisions']);
        $this->assertSame('second-change', $row['previous_handle']);
        $this->assertSame('taken-handle', $row['requested_handle']);

        // Named, not blocking: the reviewer may still approve it.
        $this->actingAsClient($coordinator)
            ->postJson('/api/commands/approve-profile-change-request', ['request_id' => $requestId])
            ->assertOk();

        $this->assertSame('taken-handle', $staff->refresh()->handle);
    }

    // ---- M18.20C: picture change requests ------------------------------

    public function test_the_current_picture_is_unchanged_while_a_submission_is_pending(): void
    {
        [, $staff, $user] = $this->activeStaff();
        Storage::fake('attachments');
        Storage::fake('public');

        $staff->forceFill(['profile_picture_path' => 'staff/existing.webp'])->save();
        Storage::disk('public')->put('staff/existing.webp', 'existing-bytes');

        $this->actingAsClient($user)
            ->postJson('/api/commands/submit-profile-picture', ['picture' => $this->imageUpload()])
            ->assertCreated();

        $this->assertSame('staff/existing.webp', $staff->refresh()->profile_picture_path);
        $this->assertTrue(Storage::disk('public')->exists('staff/existing.webp'));

        // The submitted image is on the private disk, never the public one.
        $pending = StaffProfileChangeRequest::query()->sole();
        $this->assertNotNull($pending->pending_picture_path);
        $this->assertTrue(Storage::disk('attachments')->exists($pending->pending_picture_path));
    }

    public function test_a_pending_picture_is_unreadable_to_a_user_who_is_neither_submitter_nor_reviewer(): void
    {
        [$organization, , $user] = $this->activeStaff();
        [, , $stranger] = $this->activeStaff($organization);
        Storage::fake('attachments');

        $this->actingAsClient($user)
            ->postJson('/api/commands/submit-profile-picture', ['picture' => $this->imageUpload()])
            ->assertCreated();

        $pending = StaffProfileChangeRequest::query()->sole();
        $pictures = app(StaffProfilePictureService::class);

        $this->assertTrue($pictures->canReadPendingPicture($user, $pending));
        $this->assertTrue($pictures->canReadPendingPicture(
            $this->staffCoordinatorIn($organization),
            $pending,
        ));
        $this->assertFalse($pictures->canReadPendingPicture($stranger, $pending));

        // And the stranger's own read carries no URL for it.
        $this->actingAsClient($stranger)
            ->getJson('/api/me/profile')
            ->assertOk()
            ->assertJsonPath('profiles.0.pending_picture_request', null);
    }

    public function test_approval_promotes_the_submitted_picture_to_the_current_one(): void
    {
        [$organization, $staff, $user] = $this->activeStaff();
        Storage::fake('attachments');
        Storage::fake('public');

        $requestId = $this->actingAsClient($user)
            ->postJson('/api/commands/submit-profile-picture', ['picture' => $this->imageUpload()])
            ->assertCreated()
            ->json('request.id');

        $pendingPath = (string) StaffProfileChangeRequest::query()->findOrFail($requestId)->pending_picture_path;

        $this->actingAsClient($this->staffCoordinatorIn($organization))
            ->postJson('/api/commands/approve-profile-change-request', ['request_id' => $requestId])
            ->assertOk();

        $staff->refresh();
        $this->assertNotNull($staff->profile_picture_path);
        $this->assertTrue(Storage::disk('public')->exists($staff->profile_picture_path));
        $this->assertNotNull($staff->profile_picture_uploaded_at);
        $this->assertSame(1024, max($staff->profile_picture_width, $staff->profile_picture_height));

        // Nothing is left on the private disk, and the row no longer points at one.
        $this->assertFalse(Storage::disk('attachments')->exists($pendingPath));
        $this->assertNull(StaffProfileChangeRequest::query()->findOrFail($requestId)->pending_picture_path);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'staff.profile.picture_changed',
            'entity_id' => (string) $staff->id,
        ]);
    }

    public function test_rejection_leaves_no_stored_image_behind(): void
    {
        [$organization, $staff, $user] = $this->activeStaff();
        Storage::fake('attachments');
        Storage::fake('public');

        $requestId = $this->actingAsClient($user)
            ->postJson('/api/commands/submit-profile-picture', ['picture' => $this->imageUpload()])
            ->assertCreated()
            ->json('request.id');

        $pendingPath = (string) StaffProfileChangeRequest::query()->findOrFail($requestId)->pending_picture_path;

        $this->actingAsClient($this->staffCoordinatorIn($organization))
            ->postJson('/api/commands/reject-profile-change-request', [
                'request_id' => $requestId,
                'reason' => 'Please submit a photo showing your face.',
            ])
            ->assertOk()
            ->assertJsonPath('request.decision_reason', 'Please submit a photo showing your face.');

        $this->assertFalse(Storage::disk('attachments')->exists($pendingPath));
        $this->assertSame([], Storage::disk('attachments')->allFiles());
        $this->assertNull($staff->refresh()->profile_picture_path);
    }

    public function test_a_rejection_requires_a_reason_the_submitter_can_read(): void
    {
        [$organization, , $user] = $this->activeStaff();
        Storage::fake('attachments');

        $requestId = $this->actingAsClient($user)
            ->postJson('/api/commands/submit-profile-picture', ['picture' => $this->imageUpload()])
            ->assertCreated()
            ->json('request.id');

        $this->actingAsClient($this->staffCoordinatorIn($organization))
            ->postJson('/api/commands/reject-profile-change-request', ['request_id' => $requestId])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_non_active_staff_member_cannot_submit(): void
    {
        $organization = Organization::factory()->create();
        $staff = Staff::factory()->create();
        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_PROSPECTIVE,
        ]);
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);
        Storage::fake('attachments');

        $this->actingAsClient($user)
            ->postJson('/api/commands/submit-profile-picture', ['picture' => $this->imageUpload()])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Profile pictures can be submitted once you are an active staff member in an organization.',
            );

        $this->assertSame(0, StaffProfileChangeRequest::query()->count());
        $this->assertSame([], Storage::disk('attachments')->allFiles());

        // And the read tells the surface not to offer it.
        $this->actingAsClient($user)
            ->getJson('/api/me/profile')
            ->assertOk()
            ->assertJsonPath('profiles.0.can_submit_picture', false);
    }

    public function test_removing_your_own_picture_is_immediate_and_creates_no_request(): void
    {
        [, $staff, $user] = $this->activeStaff();
        Storage::fake('public');

        $staff->forceFill([
            'profile_picture_path' => 'staff/existing.webp',
            'profile_picture_uploaded_at' => now(),
        ])->save();
        Storage::disk('public')->put('staff/existing.webp', 'existing-bytes');

        $this->actingAsClient($user)
            ->postJson('/api/commands/remove-profile-picture', [])
            ->assertOk()
            ->assertJsonPath('profile.profile_picture_url', null);

        $this->assertNull($staff->refresh()->profile_picture_path);
        $this->assertFalse(Storage::disk('public')->exists('staff/existing.webp'));
        $this->assertSame(0, StaffProfileChangeRequest::query()->count());

        $this->assertDatabaseHas('audit_events', [
            'action' => 'staff.profile.picture_removed',
            'entity_id' => (string) $staff->id,
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_an_oversized_or_unsupported_upload_is_refused(): void
    {
        [, , $user] = $this->activeStaff();
        Storage::fake('attachments');

        $this->actingAsClient($user)
            ->postJson('/api/commands/submit-profile-picture', [
                'picture' => UploadedFile::fake()->create('notes.pdf', 16, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('picture');

        $this->actingAsClient($user)
            ->postJson('/api/commands/submit-profile-picture', [
                'picture' => UploadedFile::fake()->create('huge.jpg', 11 * 1024, 'image/jpeg'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('picture');

        $this->assertSame(0, StaffProfileChangeRequest::query()->count());
    }

    // ---- helpers -------------------------------------------------------

    /**
     * Spend both self-service handle changes, leaving the allowance at zero.
     */
    private function spendAllowance(User $user): void
    {
        foreach (['first-change', 'second-change'] as $handle) {
            $this->actingAsClient($user)
                ->postJson('/api/commands/request-handle-change', ['handle' => $handle])
                ->assertCreated()
                ->assertJsonPath('request.self_service', true);
        }
    }

    /** A real 1200 x 1200 JPEG, so processing has something to resize. */
    private function imageUpload(): UploadedFile
    {
        return UploadedFile::fake()->image('portrait.jpg', 1200, 1200);
    }

    /**
     * @return array{0: Organization, 1: Staff, 2: User}
     */
    private function activeStaff(
        ?Organization $organization = null,
        ?string $handle = 'staff-handle',
        string $legalName = 'Vera Example',
    ): array {
        $organization ??= Organization::factory()->create();
        $staff = Staff::factory()->create([
            'legal_name' => $legalName,
            'preferred_name' => null,
            'handle' => $handle,
        ]);
        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        return [$organization, $staff, $user];
    }

    private function staffCoordinatorIn(Organization $organization): User
    {
        $organizersDepartment = Department::factory()->for($organization)->create(['name' => 'Organizers']);
        $organization->forceFill(['organizers_department_id' => $organizersDepartment->id])->save();

        $team = Team::factory()->for($organizersDepartment)->create(['name' => 'Intake Desk']);
        $staff = Staff::factory()->create();
        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);
        $this->addStaffToTeam($staff, $team);

        app(TeamDesignationService::class)->designateStaffCoordinatorTeam(
            $organization,
            $team,
            User::factory()->create(),
        );

        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        return $user;
    }

    private function departmentLeadIn(Organization $organization): User
    {
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $team = Team::factory()->for($department)->create(['name' => 'Ranger Leads']);
        $staff = Staff::factory()->create();
        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);
        $this->addStaffToTeam($staff, $team);
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()
                ->where('code', PermissionCatalog::ROLE_DEPARTMENT_LEAD)
                ->firstOrFail()->id,
        ]);

        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        return $user;
    }

    private function addStaffToTeam(Staff $staff, Team $team): void
    {
        $membership = DepartmentMembership::factory()->create([
            'department_id' => $team->department_id,
            'staff_id' => $staff->id,
            'status' => DepartmentMembership::STATUS_ACTIVE,
        ]);

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);
    }
}
