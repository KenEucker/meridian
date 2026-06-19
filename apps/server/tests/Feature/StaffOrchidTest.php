<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

class StaffOrchidTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    public function test_orchid_staff_list_displays_staff_profiles(): void
    {
        Staff::factory()->create([
            'legal_name' => 'Jordan Reed',
            'preferred_name' => 'Jordan',
            'handle' => 'Signal',
            'email' => 'signal@example.org',
            'city' => 'Boise',
            'state' => 'ID',
            'profile_picture_path' => 'staff/profile-pictures/signal.webp',
        ]);

        $response = $this->actingAs($this->staffAdmin())->get(route('platform.staff'));

        $response->assertOk();
        $response->assertSee('Staff');
        $response->assertSee('Jordan Reed');
        $response->assertSee('Signal');
        $response->assertSee('signal@example.org');
        $response->assertSee('Boise');
        $response->assertSee('staff/profile-pictures/signal.webp');
        $response->assertDontSee('Organization Staff');
    }

    public function test_orchid_staff_detail_displays_edit_scaffold(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Idaho Burners',
        ]);
        $staff = Staff::factory()->create([
            'legal_name' => 'Alex Kim',
            'preferred_name' => 'Alex',
            'handle' => 'Relay',
            'email' => 'relay@example.org',
            'emergency_contact_name' => 'Morgan Kim',
        ]);
        StaffOrganizationStatus::factory()
            ->for($organization)
            ->for($staff)
            ->active()
            ->create([
                'status_reason' => 'Ready for Alpha 1 testing.',
            ]);

        $response = $this->actingAs($this->staffAdmin())
            ->get(route('platform.staff.edit', $staff));

        $response->assertOk();
        $response->assertSee('Edit Staff');
        $response->assertSee('Alex Kim');
        $response->assertSee('Relay');
        $response->assertSee('relay@example.org');
        $response->assertSee('Morgan Kim');
        $response->assertSee('Profile picture');
        $response->assertSee('Organization statuses');
        $response->assertSee('Idaho Burners');
        $response->assertSee('Active');
        $response->assertSee('Ready for Alpha 1 testing.');
        $response->assertSee('Archive');
        $response->assertSee('Save');
        $response->assertSee('Cancel');
    }

    public function test_orchid_does_not_expose_separate_organization_staff_routes(): void
    {
        $this->assertFalse(Route::has('platform.organization-staff'));
        $this->assertFalse(Route::has('platform.organization-staff.create'));
        $this->assertFalse(Route::has('platform.organization-staff.edit'));
    }

    public function test_orchid_staff_screen_requires_permission(): void
    {
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('platform.staff'));

        $response->assertForbidden();
    }

    public function test_orchid_staff_save_creates_profile(): void
    {
        $response = $this->screen('platform.staff.create')
            ->actingAs($this->staffAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'staff' => [
                    'legal_name' => 'Jordan Reed',
                    'preferred_name' => 'Jordan',
                    'handle' => 'Signal',
                    'formerly_known_as' => 'Beacon',
                    'email' => 'signal@example.org',
                    'phone' => '555-0100',
                    'city' => 'Boise',
                    'state' => 'ID',
                    'date_of_birth' => '1990-01-15',
                    'emergency_contact_name' => 'Casey Reed',
                    'emergency_contact_phone' => '555-0101',
                    'profile_picture_path' => 'staff/profile-pictures/signal.webp',
                ],
            ]);

        $response->assertRedirect(route('platform.staff'));

        $this->assertDatabaseHas('staff', [
            'legal_name' => 'Jordan Reed',
            'preferred_name' => 'Jordan',
            'handle' => 'Signal',
            'formerly_known_as' => 'Beacon',
            'email' => 'signal@example.org',
            'city' => 'Boise',
            'state' => 'ID',
            'emergency_contact_name' => 'Casey Reed',
            'profile_picture_path' => 'staff/profile-pictures/signal.webp',
        ]);
    }

    public function test_orchid_staff_save_requires_only_legal_name_and_email(): void
    {
        $response = $this->screen('platform.staff.create')
            ->actingAs($this->staffAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'staff' => [
                    'legal_name' => '',
                    'email' => 'not-an-email',
                ],
            ]);

        $response->assertSessionHasErrors([
            'staff.legal_name',
            'staff.email',
        ]);

        $response->assertSessionDoesntHaveErrors([
            'staff.preferred_name',
            'staff.handle',
            'staff.phone',
            'staff.city',
            'staff.state',
            'staff.date_of_birth',
            'staff.emergency_contact_name',
            'staff.emergency_contact_phone',
        ]);
    }

    public function test_orchid_staff_save_allows_sparse_profile(): void
    {
        $response = $this->screen('platform.staff.create')
            ->actingAs($this->staffAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'staff' => [
                    'legal_name' => 'Sparse Staff',
                    'email' => 'sparse@example.org',
                ],
            ]);

        $response->assertRedirect(route('platform.staff'));

        $this->assertDatabaseHas('staff', [
            'legal_name' => 'Sparse Staff',
            'email' => 'sparse@example.org',
            'preferred_name' => null,
            'handle' => null,
            'phone' => null,
            'city' => null,
            'state' => null,
            'date_of_birth' => null,
            'emergency_contact_name' => null,
            'emergency_contact_phone' => null,
        ]);
    }

    public function test_orchid_staff_archive_and_restore_preserves_profile(): void
    {
        $staff = Staff::factory()->create();

        $archiveResponse = $this->screen('platform.staff.edit', [
            'staff' => $staff->id,
        ])
            ->actingAs($this->staffAdmin())
            ->withoutFollowingRedirects()
            ->method('archive');

        $archiveResponse->assertRedirect(route('platform.staff'));
        $this->assertTrue($staff->refresh()->isArchived());

        $restoreResponse = $this->screen('platform.staff.edit', [
            'staff' => $staff->id,
        ])
            ->actingAs($this->staffAdmin())
            ->withoutFollowingRedirects()
            ->method('restore');

        $restoreResponse->assertRedirect(route('platform.staff'));
        $this->assertFalse($staff->refresh()->isArchived());
    }

    private function staffAdmin(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.staff' => true,
            ],
        ]);
    }
}
