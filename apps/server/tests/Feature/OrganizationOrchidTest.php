<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

class OrganizationOrchidTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    public function test_orchid_organization_list_displays_organizations(): void
    {
        Organization::factory()->create([
            'name' => 'Idaho Burners',
            'slug' => 'idaho-burners',
            'calendar_year_start_month' => 10,
            'calendar_year_start_day' => 3,
        ]);

        $response = $this->actingAs($this->organizationAdmin())->get(route('platform.organizations'));

        $response->assertOk();
        $response->assertSee('Organizations');
        $response->assertSee('Idaho Burners');
        $response->assertSee('idaho-burners');
        $response->assertSee('10/03');
    }

    public function test_orchid_organization_detail_displays_edit_scaffold(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Signal Camp',
            'slug' => 'signal-camp',
        ]);

        $response = $this->actingAs($this->organizationAdmin())
            ->get(route('platform.organizations.edit', $organization));

        $response->assertOk();
        $response->assertSee('Edit Organization');
        $response->assertSee('Signal Camp');
        $response->assertSee('signal-camp');
        $response->assertSee('Save');
        $response->assertSee('Cancel');
    }

    public function test_orchid_organization_screen_requires_permission(): void
    {
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('platform.organizations'));

        $response->assertForbidden();
    }

    public function test_orchid_organization_save_creates_organization(): void
    {
        $response = $this->screen('platform.organizations.create')
            ->actingAs($this->organizationAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'organization' => [
                    'name' => 'Idaho Burners',
                    'slug' => 'idaho-burners',
                    'active_inactive_threshold_years' => 2,
                    'prospective_inactive_threshold_years' => 1,
                    'calendar_year_start_month' => 10,
                    'calendar_year_start_day' => 3,
                ],
            ]);

        $response->assertRedirect(route('platform.organizations'));

        $this->assertDatabaseHas('organizations', [
            'name' => 'Idaho Burners',
            'slug' => 'idaho-burners',
            'active_inactive_threshold_years' => 2,
            'prospective_inactive_threshold_years' => 1,
            'calendar_year_start_month' => 10,
            'calendar_year_start_day' => 3,
        ]);
    }

    public function test_orchid_organization_save_validates_unique_slug(): void
    {
        Organization::factory()->create([
            'slug' => 'idaho-burners',
        ]);

        $response = $this->screen('platform.organizations.create')
            ->actingAs($this->organizationAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'organization' => [
                    'name' => 'Duplicate Organization',
                    'slug' => 'idaho-burners',
                ],
            ]);

        $response->assertSessionHasErrors('organization.slug');
    }

    private function organizationAdmin(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.organizations' => true,
            ],
        ]);
    }
}
