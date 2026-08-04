<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventDepartmentAssignment;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\User;
use App\Services\Application\EventApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The public participation surface and organization-scoped applications
 * (M18.21A; APP-001, APP-011, APP-016, APP-017, APP-018).
 */
class PublicParticipationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_organization_page_is_readable_without_a_session(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Northwood Collective',
            'slug' => 'northwood-collective',
            'branding_display_name' => 'Northwood',
        ]);

        Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall-2026',
            'starts_at' => Carbon::now()->addMonth(),
        ]);
        Event::factory()->for($organization)->create([
            'name' => 'Archived Gathering',
            'slug' => 'archived-gathering',
            'archived_at' => Carbon::now()->subDay(),
        ]);

        $response = $this->getJson('/api/public/organizations/northwood-collective');

        $response->assertOk();
        $response->assertJsonPath('organization.name', 'Northwood');
        $response->assertJsonPath('organization.accepts_organization_applications', false);
        $response->assertJsonCount(1, 'events');
        $response->assertJsonPath('events.0.slug', 'emberfall-2026');
    }

    /** APP-016: the page publishes identity and open events, and nothing else. */
    public function test_the_organization_page_publishes_no_operational_data(): void
    {
        $organization = Organization::factory()->create(['slug' => 'northwood-collective']);
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $staff = Staff::factory()->create(['legal_name' => 'Robin Hale']);

        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);

        $body = $this->getJson('/api/public/organizations/northwood-collective')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Robin Hale', $body);
        $this->assertStringNotContainsString('Gate', $body);
        $this->assertStringNotContainsString((string) $department->id, $body);
        $this->assertStringNotContainsString((string) $staff->id, $body);
    }

    /** APP-018: off by default, and the form is absent while it is off. */
    public function test_organization_scoped_application_is_refused_unless_the_organization_accepts_them(): void
    {
        $organization = Organization::factory()->create(['slug' => 'northwood-collective']);

        $this->postJson('/api/public/organizations/northwood-collective/applications', [
            'applicant_legal_name' => 'Robin Hale',
            'applicant_email' => 'robin@example.test',
        ])->assertStatus(422);

        $this->assertSame(0, EventApplication::query()->count());

        $organization->forceFill(['accepts_organization_applications' => true])->save();

        $this->postJson('/api/public/organizations/northwood-collective/applications', [
            'applicant_legal_name' => 'Robin Hale',
            'applicant_email' => 'robin@example.test',
        ])->assertCreated()->assertJsonPath('scope', 'organization');

        $application = EventApplication::query()->firstOrFail();

        $this->assertNull($application->event_id);
        $this->assertTrue($application->isOrganizationScoped());
        $this->assertSame(EventApplication::STATUS_SUBMITTED, $application->status);
        $this->assertSame((string) $organization->id, (string) $application->organization_id);
    }

    public function test_an_event_scoped_application_records_its_event_and_interests(): void
    {
        [$organization, $event, $department] = $this->eventScaffold();

        $this->postJson('/api/public/organizations/northwood-collective/applications', [
            'event_slug' => 'emberfall-2026',
            'applicant_legal_name' => 'Robin Hale',
            'applicant_email' => 'robin@example.test',
            'department_interest_ids' => [(string) $department->id],
        ])->assertCreated()->assertJsonPath('scope', 'event');

        $application = EventApplication::query()->with('departmentInterests')->firstOrFail();

        $this->assertSame((string) $event->id, (string) $application->event_id);
        $this->assertFalse($application->isOrganizationScoped());
        $this->assertSame(1, $application->departmentInterests->count());
    }

    /**
     * APP-001: the two scopes are different offers, so having one open does not
     * refuse the other.
     */
    public function test_the_same_address_may_hold_an_event_and_an_organization_application(): void
    {
        [$organization] = $this->eventScaffold();
        $organization->forceFill(['accepts_organization_applications' => true])->save();

        $this->postJson('/api/public/organizations/northwood-collective/applications', [
            'event_slug' => 'emberfall-2026',
            'applicant_legal_name' => 'Robin Hale',
            'applicant_email' => 'robin@example.test',
        ])->assertCreated();

        $this->postJson('/api/public/organizations/northwood-collective/applications', [
            'applicant_legal_name' => 'Robin Hale',
            'applicant_email' => 'robin@example.test',
        ])->assertCreated();

        // A second application in the same scope is still refused.
        $this->postJson('/api/public/organizations/northwood-collective/applications', [
            'applicant_legal_name' => 'Robin Hale',
            'applicant_email' => 'robin@example.test',
        ])->assertStatus(422);

        $this->assertSame(2, EventApplication::query()->count());
    }

    /** STAT-006 and NOTIFY-002, on the organization scope too. */
    public function test_a_do_not_staff_address_auto_rejects_organization_applications_silently(): void
    {
        Mail::fake();

        $organization = Organization::factory()->create([
            'slug' => 'northwood-collective',
            'accepts_organization_applications' => true,
        ]);
        $staff = Staff::factory()->create(['email' => 'blocked@example.test']);

        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF,
        ]);

        // The response is indistinguishable from an ordinary submission.
        $this->postJson('/api/public/organizations/northwood-collective/applications', [
            'applicant_legal_name' => 'Blocked Applicant',
            'applicant_email' => 'blocked@example.test',
        ])->assertCreated()->assertJsonMissingPath('status');

        $application = EventApplication::query()->firstOrFail();

        $this->assertSame(EventApplication::STATUS_AUTO_REJECTED_DNS, $application->status);
        Mail::assertNothingSent();
        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    /** APP-016: an archived event says so rather than 404-ing as a broken link. */
    public function test_an_archived_event_reports_that_it_is_closed(): void
    {
        [$organization, $event] = $this->eventScaffold();
        $event->forceFill(['archived_at' => Carbon::now()])->save();

        $this->getJson('/api/public/organizations/northwood-collective/events/emberfall-2026')
            ->assertOk()
            ->assertJsonPath('event.accepting_applications', false);

        $this->postJson('/api/public/organizations/northwood-collective/applications', [
            'event_slug' => 'emberfall-2026',
            'applicant_legal_name' => 'Robin Hale',
            'applicant_email' => 'robin@example.test',
        ])->assertStatus(422);
    }

    public function test_an_event_from_another_organization_is_not_found(): void
    {
        $this->eventScaffold();

        $other = Organization::factory()->create(['slug' => 'other-collective']);
        Event::factory()->for($other)->create(['slug' => 'other-event']);

        $this->getJson('/api/public/organizations/northwood-collective/events/other-event')
            ->assertNotFound();
    }

    /**
     * An approved organization-scoped application notifies without naming an
     * event it does not have (NOTIFY-001, NOTIFY-004).
     */
    public function test_an_approved_organization_application_notifies_about_the_organization(): void
    {
        Mail::fake();

        $organization = Organization::factory()->create([
            'slug' => 'northwood-collective',
            'branding_display_name' => 'Northwood',
            'accepts_organization_applications' => true,
        ]);

        $this->postJson('/api/public/organizations/northwood-collective/applications', [
            'applicant_legal_name' => 'Robin Hale',
            'applicant_email' => 'robin@example.test',
        ])->assertCreated();

        $application = EventApplication::query()->firstOrFail();
        $reviewer = User::factory()->create();

        app(EventApplicationService::class)
            ->approve($application, $reviewer);

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail): bool {
            return ! array_key_exists('Event', $mail->notification->facts)
                && str_contains($mail->notification->paragraphs[0], 'join Northwood');
        });
    }

    /**
     * @return array{0: Organization, 1: Event, 2: Department}
     */
    private function eventScaffold(): array
    {
        $organization = Organization::factory()->create([
            'name' => 'Northwood Collective',
            'slug' => 'northwood-collective',
        ]);
        $event = Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall-2026',
        ]);
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);

        EventDepartmentAssignment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);

        return [$organization, $event, $department];
    }
}
