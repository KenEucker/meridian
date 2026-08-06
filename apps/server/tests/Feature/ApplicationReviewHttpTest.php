<?php

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
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
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Application review as a product surface (M18.21A; APP-005, APP-011,
 * APP-019).
 */
class ApplicationReviewHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organizer_reviews_only_their_own_organizations_applications(): void
    {
        [$organization, $organizer] = $this->organizerScaffold();
        $other = Organization::factory()->create(['name' => 'Other Collective']);

        $mine = $this->applicationFor($organization, 'mine@example.test');
        $theirs = $this->applicationFor($other, 'theirs@example.test');

        $response = $this->actingAsClient($organizer)->getJson('/api/applications');

        $response->assertOk();
        $response->assertJsonPath('can_review', true);

        $ids = collect($response->json('applications'))->pluck('id')->all();

        $this->assertContains((string) $mine->id, $ids);
        $this->assertNotContains((string) $theirs->id, $ids);
    }

    public function test_a_caller_with_no_review_authority_or_lead_visibility_is_refused(): void
    {
        $organization = Organization::factory()->create();
        $this->applicationFor($organization, 'someone@example.test');

        $this->actingAsClient(User::factory()->create())
            ->getJson('/api/applications')
            ->assertForbidden();
    }

    /** APP-005: approval creates Prospective organization status. */
    public function test_an_organizer_approves_an_application(): void
    {
        Mail::fake();

        [$organization, $organizer] = $this->organizerScaffold();
        $application = $this->applicationFor($organization, 'robin@example.test');

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/approve-application', [
                'application_id' => (string) $application->id,
            ])
            ->assertOk()
            ->assertJsonPath('application.status', EventApplication::STATUS_APPROVED);

        $this->assertDatabaseHas('staff_organization_statuses', [
            'organization_id' => $organization->id,
            'status' => 'prospective',
        ]);
    }

    public function test_a_rejection_carries_the_reason_it_was_given(): void
    {
        Mail::fake();

        [$organization, $organizer] = $this->organizerScaffold();
        $application = $this->applicationFor($organization, 'robin@example.test');

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/reject-application', [
                'application_id' => (string) $application->id,
                'reason' => 'We are fully staffed for this event.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', EventApplication::STATUS_REJECTED)
            ->assertJsonPath('application.decision_reason', 'We are fully staffed for this event.');
    }

    public function test_an_organization_scoped_application_reviews_the_same_way(): void
    {
        Mail::fake();

        [$organization, $organizer] = $this->organizerScaffold();
        $organization->forceFill(['accepts_organization_applications' => true])->save();

        $application = EventApplication::factory()->for($organization)->create([
            'event_id' => null,
            'status' => EventApplication::STATUS_SUBMITTED,
            'applicant_email' => 'robin@example.test',
        ]);

        $response = $this->actingAsClient($organizer)
            ->postJson('/api/commands/defer-application', [
                'application_id' => (string) $application->id,
            ]);

        $response->assertOk();
        $response->assertJsonPath('application.scope', 'organization');
        $response->assertJsonPath('application.event_name', null);
        $response->assertJsonPath('application.status', EventApplication::STATUS_DEFERRED);
    }

    /**
     * APP-011: a department lead sees an application naming their department,
     * read-only, and the node refuses the decision rather than the client
     * merely hiding the button.
     */
    public function test_a_department_lead_sees_the_application_read_only_and_cannot_decide_it(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $lead = $this->departmentLeadFor($department);

        $application = $this->applicationFor($organization, 'robin@example.test');

        EventApplicationDepartmentInterest::query()->create([
            'event_application_id' => $application->id,
            'department_id' => $department->id,
        ]);

        $response = $this->actingAsClient($lead)->getJson('/api/applications');

        $response->assertOk();
        $response->assertJsonPath('can_review', false);
        $response->assertJsonPath('has_department_lead_visibility', true);
        $response->assertJsonPath('applications.0.id', (string) $application->id);
        $response->assertJsonPath('applications.0.can_review', false);

        $this->actingAsClient($lead)
            ->postJson('/api/commands/approve-application', [
                'application_id' => (string) $application->id,
            ])
            ->assertForbidden();

        $this->assertSame(
            EventApplication::STATUS_SUBMITTED,
            $application->refresh()->status,
        );
    }

    /**
     * APP-005 / TEAM-014 / requirements 4.4: the Staff Coordinator is the other
     * half of the review population, and decides on the same path an organizer
     * does. The catalog half of this is
     * `PermissionCatalogTest::test_application_review_authority_covers_organizer_and_staff_coordinator_only`.
     */
    public function test_a_staff_coordinator_decides_an_application(): void
    {
        Mail::fake();

        [$organization] = $this->organizerScaffold();
        $coordinator = $this->staffCoordinatorFor($organization);
        $application = $this->applicationFor($organization, 'robin@example.test');

        $this->actingAsClient($coordinator)
            ->getJson('/api/applications')
            ->assertOk()
            ->assertJsonPath('can_review', true);

        $this->actingAsClient($coordinator)
            ->postJson('/api/commands/defer-application', [
                'application_id' => (string) $application->id,
                'reason' => 'Waiting on the department to confirm capacity.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', EventApplication::STATUS_DEFERRED);
    }

    /**
     * The detail read behind `organizer.application-detail` (M18.29; UI
     * contract 12.6, 12.10.2). A department lead reaches the same address for
     * the read-only visibility APP-011 grants them, and a caller with neither
     * authority meets a refusal rather than a page.
     */
    public function test_the_detail_read_answers_reviewers_and_named_department_leads_only(): void
    {
        $organization = Organization::factory()->create();
        $organizersDepartment = Department::factory()->for($organization)->create(['name' => 'Organizers']);
        $organization->forceFill(['organizers_department_id' => $organizersDepartment->id])->save();

        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $lead = $this->departmentLeadFor($department);
        $stranger = $this->departmentLeadFor(
            Department::factory()->for($organization)->create(['name' => 'DPW']),
        );

        $application = $this->applicationFor($organization, 'robin@example.test');
        EventApplicationDepartmentInterest::query()->create([
            'event_application_id' => $application->id,
            'department_id' => $department->id,
        ]);

        $this->actingAsClient($lead)
            ->getJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('application.id', (string) $application->id)
            ->assertJsonPath('application.can_review', false);

        $this->actingAsClient($stranger)
            ->getJson("/api/applications/{$application->id}")
            ->assertForbidden();
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function organizerScaffold(): array
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $organizersDepartment = Department::factory()->for($organization)->create([
            'name' => 'Organizers',
        ]);
        $organization->forceFill(['organizers_department_id' => $organizersDepartment->id])->save();

        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $team = Team::factory()->for($organizersDepartment)->create(['name' => 'Organizers']);
        $membership = DepartmentMembership::factory()
            ->for($organizersDepartment)
            ->for($staff)
            ->create();

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

        return [$organization->refresh(), $user];
    }

    /**
     * A Staff Coordinator, which is a designation on a team inside the
     * configured Organizers Department (TEAM-014).
     */
    private function staffCoordinatorFor(Organization $organization): User
    {
        $organizersDepartment = Department::query()
            ->whereKey($organization->organizers_department_id)
            ->firstOrFail();

        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $team = Team::factory()->for($organizersDepartment)->create(['name' => 'Staff Coordination']);
        $membership = DepartmentMembership::factory()
            ->for($organizersDepartment)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()
                ->where('code', PermissionCatalog::ROLE_STAFF_COORDINATOR)
                ->firstOrFail()->id,
        ]);

        return $user;
    }

    private function departmentLeadFor(Department $department): User
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $team = Team::factory()->for($department)->create(['name' => $department->name.' Leads']);
        $membership = DepartmentMembership::factory()->for($department)->for($staff)->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()
                ->where('code', PermissionCatalog::ROLE_DEPARTMENT_LEAD)
                ->firstOrFail()->id,
        ]);

        return $user;
    }

    private function applicationFor(Organization $organization, string $email): EventApplication
    {
        $event = Event::factory()->for($organization)->create();

        return EventApplication::factory()->for($organization)->for($event)->create([
            'status' => EventApplication::STATUS_SUBMITTED,
            'applicant_email' => $email,
        ]);
    }
}
