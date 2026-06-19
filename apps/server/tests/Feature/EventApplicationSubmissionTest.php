<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventApplicationSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_application_form_is_reachable_for_an_event(): void
    {
        $event = Event::factory()->create(['name' => 'Idaho Decompression 2026']);

        $response = $this->get(route('public.events.apply', $event->applyRouteParameters()));

        $response->assertOk();
        $response->assertSee('Idaho Decompression 2026');
        $response->assertSee('Submit application');
    }

    public function test_apply_route_is_scoped_by_organization_and_event_slug(): void
    {
        $organization = Organization::factory()->create(['slug' => 'idaho-burners']);
        $event = Event::factory()->for($organization)->create(['slug' => 'idaho-decompression-2026']);

        $this->assertSame(
            '/idaho-burners/idaho-decompression-2026/apply',
            parse_url(route('public.events.apply', $event->applyRouteParameters()), PHP_URL_PATH),
        );

        $this->get('/idaho-burners/idaho-decompression-2026/apply')->assertOk();
        $this->get('/idaho-decompression-2026/apply')->assertNotFound();
        $this->get('/events/idaho-decompression-2026/apply')->assertNotFound();
    }

    public function test_same_event_slug_resolves_to_the_correct_organization(): void
    {
        $firstOrganization = Organization::factory()->create(['slug' => 'org-alpha']);
        $secondOrganization = Organization::factory()->create(['slug' => 'org-beta']);
        $firstEvent = Event::factory()->for($firstOrganization)->create([
            'slug' => 'summer-fest',
            'name' => 'Alpha Summer Fest',
        ]);
        $secondEvent = Event::factory()->for($secondOrganization)->create([
            'slug' => 'summer-fest',
            'name' => 'Beta Summer Fest',
        ]);

        $this->get('/org-alpha/summer-fest/apply')->assertOk()->assertSee('Alpha Summer Fest');
        $this->get('/org-beta/summer-fest/apply')->assertOk()->assertSee('Beta Summer Fest');
        $this->get('/summer-fest/apply')->assertNotFound();

        $this->assertTrue($firstEvent->is($firstOrganization->events()->where('slug', 'summer-fest')->first()));
        $this->assertTrue($secondEvent->is($secondOrganization->events()->where('slug', 'summer-fest')->first()));
    }

    public function test_form_hides_department_interest_when_no_eligible_departments_exist(): void
    {
        $event = Event::factory()->create();

        $response = $this->get(route('public.events.apply', $event->applyRouteParameters()));

        $response->assertDontSee('Department interest');
        $response->assertDontSee('department_interest_ids', false);
        $response->assertDontSee('No preference');
        $response->assertDontSee('name="team', false);
        $response->assertDontSee('<select', false);
    }

    public function test_form_shows_optional_department_interest_for_eligible_departments_only(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $gate = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $rangers = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $unassigned = Department::factory()->for($organization)->create(['name' => 'DPW']);
        $archived = Department::factory()->archived()->for($organization)->create(['name' => 'Archived Ops']);
        $otherOrganizationDepartment = Department::factory()->create(['name' => 'Other Org']);

        EventDepartmentAssignment::factory()->create(['event_id' => $event->id, 'department_id' => $gate->id]);
        EventDepartmentAssignment::factory()->create(['event_id' => $event->id, 'department_id' => $rangers->id]);
        EventDepartmentAssignment::factory()->create(['event_id' => $event->id, 'department_id' => $archived->id]);
        EventDepartmentAssignment::factory()->create(['event_id' => $event->id, 'department_id' => $otherOrganizationDepartment->id]);

        $response = $this->get(route('public.events.apply', $event->applyRouteParameters()));

        $response->assertOk();
        $response->assertSee('Department interest');
        $response->assertSee('Optional and non-binding');
        $response->assertSee('leaving every option unchecked means no preference');
        $response->assertSee('name="department_interest_ids[]"', false);
        $response->assertSee('Gate');
        $response->assertSee('Rangers');
        $response->assertDontSee($unassigned->name);
        $response->assertDontSee($archived->name);
        $response->assertDontSee($otherOrganizationDepartment->name);
        $response->assertDontSee('No preference option');
        $response->assertDontSee('name="team', false);
    }

    public function test_submitting_valid_application_creates_a_submitted_record(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();

        $response = $this->post(route('public.events.apply.store', $event->applyRouteParameters()), [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'Pat.Prospective@Example.com',
        ]);

        $response->assertRedirect(route('public.events.apply.submitted', $event->applyRouteParameters()));

        $this->assertDatabaseHas('event_applications', [
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_email' => 'pat.prospective@example.com',
            'applicant_legal_name' => 'Pat Prospective',
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);

        $application = EventApplication::query()->firstOrFail();
        $this->assertNotNull($application->submitted_at);
        $this->assertNull($application->staff_id);
        $this->assertNull($application->reviewed_at);
        $this->assertNull($application->withdrawn_at);
        $this->assertDatabaseCount('event_application_department_interests', 0);
    }

    public function test_submitted_confirmation_page_renders_after_submission(): void
    {
        $event = Event::factory()->create();

        $this->post(route('public.events.apply.store', $event->applyRouteParameters()), [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'pat@example.com',
        ]);

        $response = $this->get(route('public.events.apply.submitted', $event->applyRouteParameters()));

        $response->assertOk();
        $response->assertSee('Application submitted');
    }

    public function test_validation_rejects_missing_name_and_invalid_email(): void
    {
        $event = Event::factory()->create();

        $response = $this->from(route('public.events.apply', $event->applyRouteParameters()))
            ->post(route('public.events.apply.store', $event->applyRouteParameters()), [
                'applicant_legal_name' => '',
                'applicant_email' => 'not-an-email',
            ]);

        $response->assertRedirect(route('public.events.apply', $event->applyRouteParameters()));
        $response->assertSessionHasErrors(['applicant_legal_name', 'applicant_email']);
        $this->assertDatabaseCount('event_applications', 0);
    }

    public function test_applications_are_blocked_for_archived_events(): void
    {
        $event = Event::factory()->archived()->create();

        $formResponse = $this->get(route('public.events.apply', $event->applyRouteParameters()));
        $formResponse->assertOk();
        $formResponse->assertSee('Applications closed');

        $response = $this->post(route('public.events.apply.store', $event->applyRouteParameters()), [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'pat@example.com',
        ]);

        $response->assertSessionHasErrors('applicant_email');
        $this->assertDatabaseCount('event_applications', 0);
    }

    public function test_duplicate_submitted_application_for_same_event_and_email_is_blocked(): void
    {
        $event = Event::factory()->create();

        $this->post(route('public.events.apply.store', $event->applyRouteParameters()), [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'pat@example.com',
        ]);

        $response = $this->post(route('public.events.apply.store', $event->applyRouteParameters()), [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'PAT@example.com',
        ]);

        $response->assertSessionHasErrors('applicant_email');
        $this->assertDatabaseCount('event_applications', 1);
    }

    public function test_same_email_may_apply_to_a_different_event(): void
    {
        $firstEvent = Event::factory()->create();
        $secondEvent = Event::factory()->create();

        $this->post(route('public.events.apply.store', $firstEvent->applyRouteParameters()), [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'pat@example.com',
        ]);

        $response = $this->post(route('public.events.apply.store', $secondEvent->applyRouteParameters()), [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'pat@example.com',
        ]);

        $response->assertRedirect(route('public.events.apply.submitted', $secondEvent->applyRouteParameters()));
        $this->assertDatabaseCount('event_applications', 2);
    }

    public function test_authenticated_user_can_also_submit(): void
    {
        $event = Event::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('public.events.apply.store', $event->applyRouteParameters()), [
                'applicant_legal_name' => 'Vera Staff',
                'applicant_email' => 'vera@example.com',
            ]);

        $response->assertRedirect(route('public.events.apply.submitted', $event->applyRouteParameters()));
        $this->assertDatabaseCount('event_applications', 1);
    }

    public function test_submitting_multiple_department_interests_stores_unordered_interest_rows(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $gate = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $rangers = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        EventDepartmentAssignment::factory()->create(['event_id' => $event->id, 'department_id' => $gate->id]);
        EventDepartmentAssignment::factory()->create(['event_id' => $event->id, 'department_id' => $rangers->id]);

        $this->post(route('public.events.apply.store', $event->applyRouteParameters()), [
            'applicant_legal_name' => 'Taylor Signal',
            'applicant_email' => 'taylor@example.com',
            'department_interest_ids' => [$rangers->id, $gate->id],
        ])->assertRedirect(route('public.events.apply.submitted', $event->applyRouteParameters()));

        $application = EventApplication::query()->where('applicant_email', 'taylor@example.com')->firstOrFail();
        $firstInterestSet = $application->departmentInterests()->pluck('departments.id')->sort()->values()->all();
        $expectedInterestSet = collect([$gate->id, $rangers->id])->sort()->values()->all();

        $this->post(route('public.events.apply.store', $event->applyRouteParameters()), [
            'applicant_legal_name' => 'Morgan Signal',
            'applicant_email' => 'morgan@example.com',
            'department_interest_ids' => [$gate->id, $rangers->id],
        ])->assertRedirect(route('public.events.apply.submitted', $event->applyRouteParameters()));

        $secondApplication = EventApplication::query()->where('applicant_email', 'morgan@example.com')->firstOrFail();
        $secondInterestSet = $secondApplication->departmentInterests()->pluck('departments.id')->sort()->values()->all();

        $this->assertSame($expectedInterestSet, $firstInterestSet);
        $this->assertSame($firstInterestSet, $secondInterestSet);
        $this->assertDatabaseCount('event_application_department_interests', 4);
    }

    public function test_duplicate_department_interest_ids_reject_submission_without_partial_records(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();
        EventDepartmentAssignment::factory()->create(['event_id' => $event->id, 'department_id' => $department->id]);

        $response = $this->from(route('public.events.apply', $event->applyRouteParameters()))
            ->post(route('public.events.apply.store', $event->applyRouteParameters()), [
                'applicant_legal_name' => 'Invalid Interest',
                'applicant_email' => 'invalid@example.com',
                'department_interest_ids' => [$department->id, $department->id],
            ]);

        $response->assertRedirect(route('public.events.apply', $event->applyRouteParameters()));
        $response->assertSessionHasErrors('department_interest_ids.1');
        $this->assertDatabaseCount('event_applications', 0);
        $this->assertDatabaseCount('event_application_department_interests', 0);
    }

    public function test_invalid_department_interest_ids_reject_submission_without_partial_records(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $eligibleDepartment = Department::factory()->for($organization)->create();
        $archivedDepartment = Department::factory()->archived()->for($organization)->create();
        $unassignedDepartment = Department::factory()->for($organization)->create();
        $otherOrganizationDepartment = Department::factory()->create();
        $removedDepartment = Department::factory()->for($organization)->create();
        $team = Team::factory()->for($eligibleDepartment)->create();
        EventDepartmentAssignment::factory()->create(['event_id' => $event->id, 'department_id' => $eligibleDepartment->id]);
        EventDepartmentAssignment::factory()->create(['event_id' => $event->id, 'department_id' => $archivedDepartment->id]);
        EventDepartmentAssignment::factory()->archived()->create(['event_id' => $event->id, 'department_id' => $removedDepartment->id]);

        foreach ([
            'archived@example.com' => $archivedDepartment->id,
            'unassigned@example.com' => $unassignedDepartment->id,
            'other-org@example.com' => $otherOrganizationDepartment->id,
            'removed@example.com' => $removedDepartment->id,
            'team@example.com' => $team->id,
        ] as $email => $invalidId) {
            $response = $this->from(route('public.events.apply', $event->applyRouteParameters()))
                ->post(route('public.events.apply.store', $event->applyRouteParameters()), [
                    'applicant_legal_name' => 'Invalid Interest',
                    'applicant_email' => $email,
                    'department_interest_ids' => [$invalidId],
                ]);

            $response->assertRedirect(route('public.events.apply', $event->applyRouteParameters()));
            $response->assertSessionHasErrors('department_interest_ids');
        }

        $this->assertDatabaseCount('event_applications', 0);
        $this->assertDatabaseCount('event_application_department_interests', 0);
    }

    public function test_department_interest_submission_has_no_membership_assignment_or_access_side_effects(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();
        EventDepartmentAssignment::factory()->create(['event_id' => $event->id, 'department_id' => $department->id]);

        $this->post(route('public.events.apply.store', $event->applyRouteParameters()), [
            'applicant_legal_name' => 'No Side Effect',
            'applicant_email' => 'no-side-effect@example.com',
            'department_interest_ids' => [$department->id],
        ])->assertRedirect(route('public.events.apply.submitted', $event->applyRouteParameters()));

        $application = EventApplication::query()->firstOrFail();

        $this->assertSame(EventApplication::STATUS_SUBMITTED, $application->status);
        $this->assertNull($application->staff_id);
        $this->assertDatabaseCount('department_memberships', 0);
        $this->assertDatabaseCount('team_memberships', 0);
        $this->assertSame(0, DepartmentMembership::query()->count());
        $this->assertSame(0, TeamMembership::query()->count());
    }

    public function test_event_slug_under_wrong_organization_returns_not_found(): void
    {
        $organization = Organization::factory()->create(['slug' => 'idaho-burners']);
        $otherOrganization = Organization::factory()->create(['slug' => 'other-org']);
        Event::factory()->for($organization)->create(['slug' => 'idaho-decompression-2026']);

        $this->get('/other-org/idaho-decompression-2026/apply')->assertNotFound();
        $this->get('/idaho-burners/idaho-decompression-2026/apply')->assertOk();
    }
}
