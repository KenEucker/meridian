<?php

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Domain\Staffing\ProfileChangePolicy;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Per-organization approval policy for handles and pictures, the configurable
 * allowance, and the state of the most recent submission (VOL-027, VOL-028,
 * VOL-029; data/API 10.1, 10.4).
 */
class ProfileChangePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organization_that_never_chose_reads_as_the_documented_defaults(): void
    {
        $organization = Organization::factory()->create();

        $this->assertSame(ProfileChangePolicy::OrganizerOnly, $organization->handleChangePolicy());
        $this->assertSame(ProfileChangePolicy::OrganizerOnly, $organization->profilePictureChangePolicy());
        $this->assertSame(2, $organization->handleSelfServiceChangeLimit());

        // Stored as null rather than as the default, so a later change of
        // default reaches an organization that never chose.
        $this->assertNull($organization->handle_change_policy);
        $this->assertNull($organization->handle_self_service_change_limit);
    }

    public function test_the_default_policy_reviews_every_handle_change_including_the_first(): void
    {
        [, $staff, $user] = $this->activeStaff(handle: null);

        $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'first-handle'])
            ->assertCreated()
            ->assertJsonPath('request.status', StaffProfileChangeRequest::STATUS_PENDING)
            ->assertJsonPath('request.self_service', false);

        $this->assertNull($staff->refresh()->handle);

        // No allowance is reported under a policy that would refuse to spend
        // one, so the surface cannot promise a change it will not apply.
        $this->actingAsClient($user)
            ->getJson('/api/me/profile')
            ->assertOk()
            ->assertJsonPath('profiles.0.remaining_self_service_handle_changes', 0)
            ->assertJsonPath('profiles.0.handle_change_policy', 'organizer_only');
    }

    public function test_auto_approved_applies_changes_up_to_the_configured_allowance(): void
    {
        [$organization, $staff, $user] = $this->activeStaff(handle: 'original');
        $this->configure($organization, [
            'handle_change_policy' => ProfileChangePolicy::AutoApproved->value,
            'handle_self_service_change_limit' => 3,
        ]);

        foreach (['one', 'two', 'three'] as $handle) {
            $this->actingAsClient($user)
                ->postJson('/api/commands/request-handle-change', ['handle' => $handle])
                ->assertCreated()
                ->assertJsonPath('request.self_service', true);
        }

        $this->assertSame('three', $staff->refresh()->handle);

        // The fourth exceeds the configured three and is reviewed.
        $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'four'])
            ->assertCreated()
            ->assertJsonPath('request.status', StaffProfileChangeRequest::STATUS_PENDING);

        $this->assertSame('three', $staff->refresh()->handle);
    }

    public function test_a_zero_allowance_switches_self_service_off_without_changing_the_policy(): void
    {
        [$organization, $staff, $user] = $this->activeStaff(handle: 'original');
        $this->configure($organization, [
            'handle_change_policy' => ProfileChangePolicy::AutoApproved->value,
            'handle_self_service_change_limit' => 0,
        ]);

        $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'nope'])
            ->assertCreated()
            ->assertJsonPath('request.status', StaffProfileChangeRequest::STATUS_PENDING);

        $this->assertSame('original', $staff->refresh()->handle);
    }

    public function test_staff_sets_first_frees_the_first_handle_and_reviews_the_rest(): void
    {
        [$organization, $staff, $user] = $this->activeStaff(handle: null);
        $this->configure($organization, [
            'handle_change_policy' => ProfileChangePolicy::StaffSetsFirst->value,
        ]);

        $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'mine'])
            ->assertCreated()
            ->assertJsonPath('request.self_service', true);

        $this->assertSame('mine', $staff->refresh()->handle);

        $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'mine-again'])
            ->assertCreated()
            ->assertJsonPath('request.status', StaffProfileChangeRequest::STATUS_PENDING);

        $this->assertSame('mine', $staff->refresh()->handle);
    }

    public function test_organizer_sets_first_refuses_a_first_handle_and_says_to_wait(): void
    {
        [$organization, $staff, $user] = $this->activeStaff(handle: null);
        $this->configure($organization, [
            'handle_change_policy' => ProfileChangePolicy::OrganizerSetsFirst->value,
        ]);

        $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'mine'])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Your organization issues first handles. Ask an organizer to set yours, and you can request changes to it after that.',
            );

        $this->assertSame(0, StaffProfileChangeRequest::query()->count());

        // Once an organizer has set one, the staff member may ask to change it.
        $staff->forceFill(['handle' => 'issued'])->save();

        $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'preferred'])
            ->assertCreated()
            ->assertJsonPath('request.status', StaffProfileChangeRequest::STATUS_PENDING);
    }

    public function test_auto_approved_pictures_apply_without_review_and_are_not_rationed(): void
    {
        [$organization, $staff, $user] = $this->activeStaff();
        $this->configure($organization, [
            'profile_picture_change_policy' => ProfileChangePolicy::AutoApproved->value,
        ]);
        Storage::fake('attachments');
        Storage::fake('public');

        foreach (range(1, 3) as $ignored) {
            $this->actingAsClient($user)
                ->postJson('/api/commands/submit-profile-picture', ['picture' => $this->imageUpload()])
                ->assertCreated()
                ->assertJsonPath('request.status', StaffProfileChangeRequest::STATUS_APPROVED)
                ->assertJsonPath('request.self_service', true);
        }

        $staff->refresh();
        $this->assertNotNull($staff->profile_picture_path);
        $this->assertTrue(Storage::disk('public')->exists($staff->profile_picture_path));

        // Applied means applied: nothing is waiting, and only the newest image
        // survives — Alpha 1 keeps one picture.
        $this->assertSame(0, StaffProfileChangeRequest::query()->pending()->count());
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('attachments')->allFiles());
    }

    public function test_organizer_sets_first_refuses_a_first_picture(): void
    {
        [$organization, , $user] = $this->activeStaff();
        $this->configure($organization, [
            'profile_picture_change_policy' => ProfileChangePolicy::OrganizerSetsFirst->value,
        ]);
        Storage::fake('attachments');

        $this->actingAsClient($user)
            ->postJson('/api/commands/submit-profile-picture', ['picture' => $this->imageUpload()])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Your organization sets first profile pictures. Ask an organizer to add yours, and you can submit a replacement after that.',
            );

        $this->assertSame([], Storage::disk('attachments')->allFiles());

        // And the read tells the surface not to offer the control.
        $this->actingAsClient($user)
            ->getJson('/api/me/profile')
            ->assertOk()
            ->assertJsonPath('profiles.0.can_submit_picture', false)
            ->assertJsonPath('profiles.0.profile_picture_change_policy', 'organizer_sets_first');
    }

    public function test_the_staff_member_sees_a_rejection_and_its_reason_and_can_dismiss_it(): void
    {
        [$organization, $staff, $user] = $this->activeStaff(handle: 'original');
        $coordinator = $this->organizerIn($organization);

        $requestId = $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'rejected-one'])
            ->assertCreated()
            ->json('request.id');

        $this->actingAsClient($coordinator)
            ->postJson('/api/commands/reject-profile-change-request', [
                'request_id' => $requestId,
                'reason' => 'That handle is reserved for the medical team.',
            ])
            ->assertOk();

        // The decision stays on the submitter's own surface, with its reason.
        $this->actingAsClient($user)
            ->getJson('/api/me/profile')
            ->assertOk()
            ->assertJsonPath('profiles.0.latest_handle_request.id', $requestId)
            ->assertJsonPath('profiles.0.latest_handle_request.status', StaffProfileChangeRequest::STATUS_REJECTED)
            ->assertJsonPath(
                'profiles.0.latest_handle_request.decision_reason',
                'That handle is reserved for the medical team.',
            );

        $this->actingAsClient($user)
            ->postJson('/api/commands/dismiss-profile-change-request', ['request_id' => $requestId])
            ->assertOk();

        // Cleared from the surface, and still on the record.
        $this->actingAsClient($user)
            ->getJson('/api/me/profile')
            ->assertOk()
            ->assertJsonPath('profiles.0.latest_handle_request', null);

        $this->assertNotNull(StaffProfileChangeRequest::query()->findOrFail($requestId)->dismissed_at);
        $this->assertSame('original', $staff->refresh()->handle);
    }

    public function test_a_rejected_request_can_be_replaced_by_a_new_submission(): void
    {
        [$organization, , $user] = $this->activeStaff(handle: 'original');
        $organizer = $this->organizerIn($organization);

        $first = $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'first-try'])
            ->assertCreated()
            ->json('request.id');

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/reject-profile-change-request', [
                'request_id' => $first,
                'reason' => 'Try something shorter.',
            ])
            ->assertOk();

        // A decided request is not outstanding, so a replacement is accepted
        // without withdrawing anything first (VOL-024, VOL-029).
        $second = $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'second-try'])
            ->assertCreated()
            ->json('request.id');

        $this->actingAsClient($user)
            ->getJson('/api/me/profile')
            ->assertOk()
            ->assertJsonPath('profiles.0.latest_handle_request.id', $second)
            ->assertJsonPath('profiles.0.latest_handle_request.status', StaffProfileChangeRequest::STATUS_PENDING);
    }

    public function test_a_pending_request_is_withdrawn_rather_than_dismissed(): void
    {
        [, , $user] = $this->activeStaff(handle: 'original');

        $requestId = $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'waiting'])
            ->assertCreated()
            ->json('request.id');

        $this->actingAsClient($user)
            ->postJson('/api/commands/dismiss-profile-change-request', ['request_id' => $requestId])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This request is still waiting for a decision. Withdraw it instead.');
    }

    public function test_a_staff_member_cannot_dismiss_anothers_request(): void
    {
        [$organization, , $user] = $this->activeStaff(handle: 'original');
        [, , $other] = $this->activeStaff($organization);
        $organizer = $this->organizerIn($organization);

        $requestId = $this->actingAsClient($user)
            ->postJson('/api/commands/request-handle-change', ['handle' => 'mine'])
            ->assertCreated()
            ->json('request.id');

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/reject-profile-change-request', [
                'request_id' => $requestId,
                'reason' => 'No.',
            ])
            ->assertOk();

        $this->actingAsClient($other)
            ->postJson('/api/commands/dismiss-profile-change-request', ['request_id' => $requestId])
            ->assertForbidden();

        $this->assertNull(StaffProfileChangeRequest::query()->findOrFail($requestId)->dismissed_at);
    }

    public function test_organizers_configure_both_policies_and_the_allowance(): void
    {
        [$organization, , ] = $this->activeStaff();
        $organizer = $this->organizerIn($organization);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-configuration', [
                'organization_id' => (string) $organization->id,
                'handle_change_policy' => ProfileChangePolicy::AutoApproved->value,
                'profile_picture_change_policy' => ProfileChangePolicy::StaffSetsFirst->value,
                'handle_self_service_change_limit' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('configuration.handle_change_policy', 'auto_approved')
            ->assertJsonPath('configuration.profile_picture_change_policy', 'staff_sets_first')
            ->assertJsonPath('configuration.handle_self_service_change_limit', 5);

        // The four choices come from the node with the words that explain them.
        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/configuration")
            ->assertOk()
            ->assertJsonCount(4, 'options.change_policies')
            ->assertJsonPath('options.change_policies.0.value', 'organizer_only');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'organization.configuration_updated',
            'entity_id' => (string) $organization->id,
        ]);
    }

    public function test_an_unrecognised_policy_is_refused_rather_than_becoming_the_default(): void
    {
        [$organization, , ] = $this->activeStaff();
        $organizer = $this->organizerIn($organization);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-configuration', [
                'organization_id' => (string) $organization->id,
                'handle_change_policy' => 'whatever-i-typed',
            ])
            ->assertStatus(422);

        $this->assertNull($organization->refresh()->handle_change_policy);
    }

    public function test_a_staff_member_cannot_configure_the_policy(): void
    {
        [$organization, , $user] = $this->activeStaff();

        $this->actingAsClient($user)
            ->postJson('/api/commands/update-organization-configuration', [
                'organization_id' => (string) $organization->id,
                'handle_change_policy' => ProfileChangePolicy::AutoApproved->value,
            ])
            ->assertForbidden();

        $this->assertNull($organization->refresh()->handle_change_policy);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function configure(Organization $organization, array $values): void
    {
        $organization->forceFill($values)->save();
    }

    private function imageUpload(): UploadedFile
    {
        return UploadedFile::fake()->image('portrait.jpg', 800, 800);
    }

    /**
     * @return array{0: Organization, 1: Staff, 2: User}
     */
    private function activeStaff(
        ?Organization $organization = null,
        ?string $handle = 'staff-handle',
    ): array {
        $organization ??= Organization::factory()->create();
        $staff = Staff::factory()->create(['handle' => $handle]);
        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        return [$organization, $staff, $user];
    }

    /**
     * An organizer of this organization, who holds both the configuration
     * capability and profile change request review.
     */
    private function organizerIn(Organization $organization): User
    {
        $department = Department::factory()->for($organization)->create(['name' => 'Organizers']);
        $team = Team::factory()->for($department)->create(['name' => 'Organizer Team']);
        $staff = Staff::factory()->create();
        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);

        $membership = DepartmentMembership::factory()->create([
            'department_id' => $department->id,
            'staff_id' => $staff->id,
            'status' => DepartmentMembership::STATUS_ACTIVE,
        ]);
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()
                ->where('code', PermissionCatalog::ROLE_ORGANIZER)
                ->firstOrFail()->id,
        ]);

        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        return $user;
    }
}
