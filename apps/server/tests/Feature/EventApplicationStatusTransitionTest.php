<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\User;
use App\Services\Application\ApplicationReviewException;
use App\Services\Application\ApplicationWithdrawalException;
use App\Services\Application\EventApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EventApplicationStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_rejecting_submitted_application_records_reviewer_metadata_and_audit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 12:00:00'));

        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $reviewer = User::factory()->create(['name' => 'Review Organizer']);
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'status' => EventApplication::STATUS_SUBMITTED,
            'reviewed_at' => null,
            'reviewed_by_user_id' => null,
            'decision_reason' => null,
        ]);

        $rejected = app(EventApplicationService::class)->reject($application, $reviewer);

        $this->assertSame(EventApplication::STATUS_REJECTED, $rejected->status);
        $this->assertSame($reviewer->id, $rejected->reviewed_by_user_id);
        $this->assertSame('Rejected at the organization level.', $rejected->decision_reason);
        $this->assertNull($rejected->withdrawn_at);

        $audit = AuditEvent::query()
            ->where('action', 'event_application.rejected')
            ->where('entity_id', $application->id)
            ->firstOrFail();

        $this->assertSame(AuditEvent::SOURCE_ORCHID, $audit->source_context);
        $this->assertSame(EventApplication::STATUS_SUBMITTED, $audit->before_json['status']);
        $this->assertSame(EventApplication::STATUS_REJECTED, $audit->after_json['status']);
    }

    public function test_deferring_submitted_application_records_reviewer_metadata_and_audit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 12:30:00'));

        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $reviewer = User::factory()->create();
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);

        $deferred = app(EventApplicationService::class)->defer($application, $reviewer);

        $this->assertSame(EventApplication::STATUS_DEFERRED, $deferred->status);
        $this->assertSame($reviewer->id, $deferred->reviewed_by_user_id);
        $this->assertSame('Deferred at the organization level.', $deferred->decision_reason);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'event_application.deferred',
            'entity_id' => $application->id,
            'actor_user_id' => $reviewer->id,
            'source_context' => AuditEvent::SOURCE_ORCHID,
        ]);
    }

    public function test_withdrawing_submitted_application_records_withdrawn_timestamp_and_audit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 13:00:00'));

        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $applicant = User::factory()->create(['email' => 'applicant@example.org']);
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_email' => 'applicant@example.org',
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);

        $withdrawn = app(EventApplicationService::class)->withdraw($application, $applicant);

        $this->assertSame(EventApplication::STATUS_WITHDRAWN, $withdrawn->status);
        $this->assertSame('2026-06-19 13:00:00', $withdrawn->withdrawn_at?->format('Y-m-d H:i:s'));
        $this->assertSame('Withdrawn by the applicant.', $withdrawn->decision_reason);
        $this->assertNull($withdrawn->reviewed_by_user_id);

        $audit = AuditEvent::query()
            ->where('action', 'event_application.withdrawn')
            ->where('entity_id', $application->id)
            ->firstOrFail();

        $this->assertSame(AuditEvent::SOURCE_WEB, $audit->source_context);
        $this->assertSame($applicant->id, $audit->actor_user_id);
        $this->assertSame(EventApplication::STATUS_WITHDRAWN, $audit->after_json['status']);
    }

    public function test_non_submitted_application_cannot_be_rejected(): void
    {
        $application = EventApplication::factory()->create([
            'status' => EventApplication::STATUS_APPROVED,
        ]);

        $this->expectException(ApplicationReviewException::class);

        app(EventApplicationService::class)->reject($application, User::factory()->create());
    }

    public function test_non_submitted_application_cannot_be_deferred(): void
    {
        $application = EventApplication::factory()->create([
            'status' => EventApplication::STATUS_DEFERRED,
        ]);

        $this->expectException(ApplicationReviewException::class);

        app(EventApplicationService::class)->defer($application, User::factory()->create());
    }

    public function test_non_submitted_application_cannot_be_withdrawn(): void
    {
        $application = EventApplication::factory()->create([
            'status' => EventApplication::STATUS_WITHDRAWN,
        ]);

        $this->expectException(ApplicationWithdrawalException::class);

        app(EventApplicationService::class)->withdraw($application);
    }

    public function test_public_applicant_can_withdraw_from_submitted_confirmation_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 14:00:00'));

        $organization = Organization::factory()->create(['slug' => 'northwood-collective']);
        $event = Event::factory()->for($organization)->create(['slug' => 'signal-camp-2026']);
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_email' => 'withdraw.me@example.org',
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);

        $response = $this->withSession([
            'application_submitted' => true,
            'submitted_application_id' => $application->id,
        ])->post(route('public.events.apply.withdraw', array_merge(
            $event->applyRouteParameters(),
            ['application' => $application->id],
        )));

        $response->assertRedirect(route('public.events.apply.submitted', $event->applyRouteParameters()));
        $response->assertSessionHas('application_withdrawn', true);

        $application->refresh();
        $this->assertSame(EventApplication::STATUS_WITHDRAWN, $application->status);
        $this->assertNotNull($application->withdrawn_at);
    }

    public function test_authenticated_applicant_can_withdraw_matching_application_by_email(): void
    {
        $organization = Organization::factory()->create(['slug' => 'northwood-collective']);
        $event = Event::factory()->for($organization)->create(['slug' => 'signal-camp-2026']);
        $user = User::factory()->create(['email' => 'Matched.Applicant@Example.org']);
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_email' => 'matched.applicant@example.org',
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($user)
            ->withSession(['submitted_application_id' => $application->id])
            ->post(route('public.events.apply.withdraw', array_merge(
                $event->applyRouteParameters(),
                ['application' => $application->id],
            )))
            ->assertRedirect(route('public.events.apply.submitted', $event->applyRouteParameters()));

        $this->assertSame(EventApplication::STATUS_WITHDRAWN, $application->refresh()->status);
    }

    public function test_authenticated_applicant_can_withdraw_matching_application_by_staff_profile_email(): void
    {
        $organization = Organization::factory()->create(['slug' => 'northwood-collective']);
        $event = Event::factory()->for($organization)->create(['slug' => 'signal-camp-2026']);
        $user = User::factory()->create(['email' => 'login-only@example.org']);
        $staff = Staff::factory()->create(['email' => 'staff.profile@example.org']);
        $user->staffProfiles()->attach($staff->id);
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_email' => 'staff.profile@example.org',
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($user)
            ->post(route('public.events.apply.withdraw', array_merge(
                $event->applyRouteParameters(),
                ['application' => $application->id],
            )))
            ->assertRedirect(route('public.events.apply.submitted', $event->applyRouteParameters()));

        $this->assertSame(EventApplication::STATUS_WITHDRAWN, $application->refresh()->status);
    }

    public function test_non_applicant_cannot_withdraw_application(): void
    {
        $organization = Organization::factory()->create(['slug' => 'northwood-collective']);
        $event = Event::factory()->for($organization)->create(['slug' => 'signal-camp-2026']);
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_email' => 'real.applicant@example.org',
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);
        $otherUser = User::factory()->create(['email' => 'other.person@example.org']);

        $this->actingAs($otherUser)
            ->post(route('public.events.apply.withdraw', array_merge(
                $event->applyRouteParameters(),
                ['application' => $application->id],
            )))
            ->assertForbidden();

        $this->assertSame(EventApplication::STATUS_SUBMITTED, $application->refresh()->status);
    }

    public function test_organizer_cannot_withdraw_someone_elses_application_without_applicant_identity(): void
    {
        $organization = Organization::factory()->create(['slug' => 'northwood-collective']);
        $event = Event::factory()->for($organization)->create(['slug' => 'signal-camp-2026']);
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_email' => 'real.applicant@example.org',
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);
        $organizer = User::factory()->create([
            'email' => 'organizer@example.org',
            'permissions' => [
                'platform.index' => true,
                'platform.applications' => true,
            ],
        ]);

        $this->actingAs($organizer)
            ->post(route('public.events.apply.withdraw', array_merge(
                $event->applyRouteParameters(),
                ['application' => $application->id],
            )))
            ->assertForbidden();

        $this->assertSame(EventApplication::STATUS_SUBMITTED, $application->refresh()->status);
    }

    public function test_submitted_confirmation_shows_withdraw_action_for_session_submitter(): void
    {
        $organization = Organization::factory()->create(['slug' => 'northwood-collective']);
        $event = Event::factory()->for($organization)->create(['slug' => 'signal-camp-2026']);
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);

        $response = $this->withSession([
            'application_submitted' => true,
            'submitted_application_id' => $application->id,
        ])->get(route('public.events.apply.submitted', $event->applyRouteParameters()));

        $response->assertOk();
        $response->assertSee('Withdraw application');
    }
}
