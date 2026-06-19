<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventApplicationDepartmentInterest;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

class EventApplicationOrchidTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    public function test_orchid_application_list_displays_applications(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Idaho Burners',
        ]);

        $event = Event::factory()->for($organization)->create([
            'name' => 'Idaho Decompression 2026',
        ]);

        EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_legal_name' => 'Jordan Reed',
            'applicant_email' => 'jordan@example.org',
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);

        $response = $this->actingAs($this->applicationAdmin())->get(route('platform.applications'));

        $response->assertOk();
        $response->assertSee('Applications');
        $response->assertSee('Jordan Reed');
        $response->assertSee('jordan@example.org');
        $response->assertSee('Idaho Decompression 2026');
        $response->assertSee('Idaho Burners');
        $response->assertSee('Submitted');
        $response->assertSee('No department preference');
    }

    public function test_orchid_application_detail_displays_review_scaffold(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Idaho Burners',
        ]);

        $event = Event::factory()->for($organization)->create([
            'name' => 'Signal Camp 2026',
        ]);

        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_legal_name' => 'Casey Reed',
            'applicant_email' => 'casey@example.org',
            'status' => EventApplication::STATUS_SUBMITTED,
            'submitted_at' => '2026-06-01 10:00:00',
        ]);

        $response = $this->actingAs($this->applicationAdmin())
            ->get(route('platform.applications.show', $application));

        $response->assertOk();
        $response->assertSee('Review Application');
        $response->assertSee('Casey Reed');
        $response->assertSee('casey@example.org');
        $response->assertSee('Signal Camp 2026');
        $response->assertSee('Idaho Burners');
        $response->assertSee('Submitted');
        $response->assertSee('No department preference');
        $response->assertSee('Approval occurs at the organization level');
        $response->assertSee('Back');
        $response->assertDontSee('Save');
        $response->assertDontSee('Approve');
        $response->assertDontSee('Reject');
    }

    public function test_orchid_application_detail_shows_canonical_status_labels(): void
    {
        $application = EventApplication::factory()->create([
            'status' => EventApplication::STATUS_AUTO_REJECTED_DNS,
            'reviewed_at' => '2026-06-02 12:00:00',
            'decision_reason' => 'Applicant email domain matches DNS.',
        ]);

        $response = $this->actingAs($this->applicationAdmin())
            ->get(route('platform.applications.show', $application));

        $response->assertOk();
        $response->assertSee('Auto-rejected due to DNS');
        $response->assertSee('Applicant email domain matches DNS.');
    }

    public function test_orchid_application_list_and_detail_display_department_interest(): void
    {
        $organization = Organization::factory()->create(['name' => 'Idaho Burners']);
        $event = Event::factory()->for($organization)->create(['name' => 'Signal Camp 2026']);
        $gate = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $rangers = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_legal_name' => 'Taylor Signal',
        ]);
        $this->recordInterest($application, $gate);
        $this->recordInterest($application, $rangers);

        $listResponse = $this->actingAs($this->applicationAdmin())->get(route('platform.applications'));
        $listResponse->assertOk();
        $listResponse->assertSee('Department interest');
        $listResponse->assertSee('Gate, Rangers');

        $detailResponse = $this->actingAs($this->applicationAdmin())
            ->get(route('platform.applications.show', $application));
        $detailResponse->assertOk();
        $detailResponse->assertSee('Department interest');
        $detailResponse->assertSee('Gate, Rangers');
        $detailResponse->assertSee('Non-binding intake signal only');
        $detailResponse->assertDontSee('Assign');
    }

    public function test_orchid_application_list_filters_by_department_interest(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $gate = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $rangers = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $gateApplication = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_legal_name' => 'Gate Applicant',
        ]);
        $rangerApplication = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_legal_name' => 'Ranger Applicant',
        ]);
        $this->recordInterest($gateApplication, $gate);
        $this->recordInterest($rangerApplication, $rangers);

        $response = $this->actingAs($this->applicationAdmin())->get(route('platform.applications', [
            'department_interest' => $gate->id,
        ]));

        $response->assertOk();
        $response->assertSee('Gate Applicant');
        $response->assertDontSee('Ranger Applicant');
    }

    public function test_department_lead_can_view_only_read_only_interested_applications(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $ledDepartment = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $otherDepartment = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $interestedApplication = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_legal_name' => 'Gate Applicant',
        ]);
        $unrelatedApplication = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_legal_name' => 'Ranger Applicant',
        ]);
        $this->recordInterest($interestedApplication, $ledDepartment);
        $this->recordInterest($unrelatedApplication, $otherDepartment);
        $departmentLead = $this->departmentLeadUserFor($ledDepartment);

        $listResponse = $this->actingAs($departmentLead)->get(route('platform.applications'));
        $listResponse->assertOk();
        $listResponse->assertSee('Gate Applicant');
        $listResponse->assertDontSee('Ranger Applicant');

        $detailResponse = $this->actingAs($departmentLead)->get(route('platform.applications.show', $interestedApplication));
        $detailResponse->assertOk();
        $detailResponse->assertSee('Review Application');
        $detailResponse->assertSee('Gate Applicant');
        $detailResponse->assertSee('Gate');
        $detailResponse->assertDontSee('Save');
        $detailResponse->assertDontSee('Approve');
        $detailResponse->assertDontSee('Reject');
        $detailResponse->assertDontSee('Defer');
        $detailResponse->assertDontSee('Assign');

        $this->actingAs($departmentLead)
            ->get(route('platform.applications.show', $unrelatedApplication))
            ->assertForbidden();
    }

    public function test_department_lead_interest_visibility_excludes_dns_auto_rejected_applications(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_legal_name' => 'DNS Applicant',
            'status' => EventApplication::STATUS_AUTO_REJECTED_DNS,
            'reviewed_at' => now(),
            'decision_reason' => 'Applicant email matched an organization Do Not Staff status.',
        ]);
        $this->recordInterest($application, $department);
        $departmentLead = $this->departmentLeadUserFor($department);

        $leadListResponse = $this->actingAs($departmentLead)->get(route('platform.applications'));
        $leadListResponse->assertOk();
        $leadListResponse->assertDontSee('DNS Applicant');

        $this->actingAs($departmentLead)
            ->get(route('platform.applications.show', $application))
            ->assertForbidden();

        $reviewerResponse = $this->actingAs($this->applicationAdmin())
            ->get(route('platform.applications.show', $application));
        $reviewerResponse->assertOk();
        $reviewerResponse->assertSee('DNS Applicant');
        $reviewerResponse->assertSee('Auto-rejected due to DNS');
    }

    public function test_orchid_application_screens_require_permission(): void
    {
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
            ],
        ]);

        $application = EventApplication::factory()->create();

        $this->actingAs($user)->get(route('platform.applications'))->assertForbidden();
        $this->actingAs($user)->get(route('platform.applications.show', $application))->assertForbidden();
    }

    private function applicationAdmin(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.applications' => true,
            ],
        ]);
    }

    private function departmentLeadUserFor(Department $department): User
    {
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
            ],
        ]);
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
            'permission_role_id' => $this->role('department_lead')->id,
        ]);

        return $user;
    }

    private function recordInterest(EventApplication $application, Department $department): void
    {
        EventApplicationDepartmentInterest::factory()->create([
            'event_application_id' => $application->id,
            'department_id' => $department->id,
        ]);
    }

    private function role(string $code): PermissionRole
    {
        return PermissionRole::query()->where('code', $code)->firstOrFail();
    }
}
