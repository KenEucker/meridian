<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
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
            'name' => 'Northwood Collective',
            'slug' => 'northwood-collective',
            'calendar_year_start_month' => 10,
            'calendar_year_start_day' => 3,
        ]);

        $response = $this->actingAs($this->organizationAdmin())->get(route('platform.organizations'));

        $response->assertOk();
        $response->assertSee('Organizations');
        $response->assertSee('Northwood Collective');
        $response->assertSee('northwood-collective');
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
        $response->assertSee('name="organization[default_ic_department_id]"', false);
        $response->assertSee('meridian-admin.js');
        $response->assertSee('data-meridian-slug-target="organization[slug]"', false);
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
                    'name' => 'Northwood Collective',
                    'slug' => 'northwood-collective',
                    'active_inactive_threshold_years' => 2,
                    'prospective_inactive_threshold_years' => 1,
                    'calendar_year_start_month' => 10,
                    'calendar_year_start_day' => 3,
                ],
            ]);

        $response->assertRedirect(route('platform.organizations'));

        $this->assertDatabaseHas('organizations', [
            'name' => 'Northwood Collective',
            'slug' => 'northwood-collective',
            'active_inactive_threshold_years' => 2,
            'prospective_inactive_threshold_years' => 1,
            'calendar_year_start_month' => 10,
            'calendar_year_start_day' => 3,
        ]);
    }

    public function test_orchid_organization_save_validates_unique_slug(): void
    {
        Organization::factory()->create([
            'slug' => 'northwood-collective',
        ]);

        $response = $this->screen('platform.organizations.create')
            ->actingAs($this->organizationAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'organization' => [
                    'name' => 'Duplicate Organization',
                    'slug' => 'northwood-collective',
                ],
            ]);

        $response->assertSessionHasErrors('organization.slug');
    }

    public function test_orchid_organization_save_configures_default_ic_department_with_audit(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Northwood Collective',
            'slug' => 'northwood-collective',
        ]);
        $department = Department::factory()->for($organization)->create([
            'name' => 'Rangers',
        ]);

        $response = $this->screen('platform.organizations.edit', [
            'organization' => $organization->id,
        ])
            ->actingAs($this->organizationAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'organization' => [
                    'name' => 'Northwood Collective',
                    'slug' => 'northwood-collective',
                    'default_ic_department_id' => $department->id,
                ],
            ]);

        $response->assertRedirect(route('platform.organizations'));

        $this->assertSame($department->id, $organization->refresh()->default_ic_department_id);

        $audit = AuditEvent::query()->where('action', 'organization.default_ic_department_changed')->sole();
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame($department->id, $audit->department_id);
        $this->assertSame(AuditEvent::SOURCE_ORCHID, $audit->source_context);
    }

    public function test_orchid_organization_save_rejects_other_organization_default_ic_department(): void
    {
        $organization = Organization::factory()->create();
        $otherDepartment = Department::factory()->create();

        $response = $this->screen('platform.organizations.edit', [
            'organization' => $organization->id,
        ])
            ->actingAs($this->organizationAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'organization' => [
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'default_ic_department_id' => $otherDepartment->id,
                ],
            ]);

        $response->assertSessionHasErrors('organization.default_ic_department_id');
        $this->assertNull($organization->refresh()->default_ic_department_id);
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
