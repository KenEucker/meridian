<?php

namespace Tests\Feature;

use App\Mail\ApplicantPortalLinkMail;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\User;
use App\Services\Application\ApplicantPortalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The applicant portal (M18.22; APP-004, APP-012 through APP-015; AUTH-010).
 */
class ApplicantPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        RateLimiter::clear('applicant-portal:link-request:email:'.hash('sha256', 'robin@example.test'));
    }

    /**
     * APP-014: the request response cannot be used to learn whether an address
     * has ever applied.
     */
    public function test_the_request_response_is_identical_for_known_and_unknown_addresses(): void
    {
        $this->submittedApplication('robin@example.test');

        $known = $this->requestLink('robin@example.test');
        $unknown = $this->requestLink('nobody@example.test');

        $known->assertRedirect(route('applicant-portal.request.sent'));
        $unknown->assertRedirect(route('applicant-portal.request.sent'));

        $this->assertSame(
            $this->sentPageFor('robin@example.test'),
            str_replace('nobody@example.test', 'robin@example.test', $this->sentPageFor('nobody@example.test')),
        );
    }

    public function test_the_api_request_response_is_identical_for_known_and_unknown_addresses(): void
    {
        $this->submittedApplication('robin@example.test');

        $known = $this->postJson('/api/public/applicant-portal/link-requests', [
            'email' => 'robin@example.test',
        ]);
        $unknown = $this->postJson('/api/public/applicant-portal/link-requests', [
            'email' => 'nobody@example.test',
        ]);

        $known->assertStatus(202);
        $unknown->assertStatus(202);
        $this->assertSame($known->getContent(), $unknown->getContent());
    }

    /** APP-012: a link is mailed where there is something to show. */
    public function test_a_link_is_mailed_to_an_address_that_has_applications(): void
    {
        $this->submittedApplication('robin@example.test');

        $this->requestLink('robin@example.test');

        Mail::assertSent(ApplicantPortalLinkMail::class, fn (ApplicantPortalLinkMail $mail): bool => $mail->hasTo('robin@example.test'));
    }

    public function test_no_link_is_mailed_to_an_address_with_no_applications(): void
    {
        $this->requestLink('nobody@example.test');

        Mail::assertNothingSent();
    }

    /**
     * APP-014 and STAT-006: an auto-rejected Do Not Staff application is not in
     * the portal and does not make an address look like one that applied.
     */
    public function test_a_do_not_staff_auto_rejected_application_is_absent_and_produces_no_link(): void
    {
        $organization = Organization::factory()->create(['slug' => 'northwood-collective']);
        $event = Event::factory()->for($organization)->create(['name' => 'Emberfall 2026']);
        $staff = Staff::factory()->create(['email' => 'blocked@example.test']);

        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF,
        ]);

        EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_email' => 'blocked@example.test',
            'status' => EventApplication::STATUS_AUTO_REJECTED_DNS,
            'submitted_at' => Carbon::now()->subDay(),
        ]);

        $this->requestLink('blocked@example.test');

        Mail::assertNothingSent();
        $this->assertSame(0, AuditEvent::query()->where('action', 'applicant_portal.link_issued')->count());

        // Even holding a signed link, the auto-rejected application is absent.
        $this->openPortal('blocked@example.test')
            ->assertOk()
            ->assertSee('There are no applications under this address.')
            ->assertDontSee('Emberfall 2026');
    }

    /** APP-013: event, submission date, and current status. */
    public function test_the_portal_lists_the_applications_made_with_the_address(): void
    {
        $application = $this->submittedApplication('robin@example.test');

        $response = $this->openPortal('robin@example.test');

        $response->assertOk();
        $response->assertSee('Emberfall 2026');
        $response->assertSee('Submitted');
        $response->assertSee($application->submitted_at->toDayDateTimeString());
        $response->assertSee('Withdraw this application');
    }

    public function test_the_portal_shows_an_organization_scoped_application_without_an_event(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Northwood Collective',
            'slug' => 'northwood-collective',
            'accepts_organization_applications' => true,
        ]);

        EventApplication::factory()->create([
            'event_id' => null,
            'organization_id' => $organization->id,
            'applicant_email' => 'robin@example.test',
            'status' => EventApplication::STATUS_SUBMITTED,
            'submitted_at' => Carbon::now()->subDay(),
        ]);

        $this->openPortal('robin@example.test')
            ->assertOk()
            ->assertSee('Joining Northwood Collective');
    }

    /** The portal is one address's applications and nobody else's. */
    public function test_the_portal_shows_no_other_applicants_applications(): void
    {
        $this->submittedApplication('robin@example.test');
        $other = $this->submittedApplication('someone-else@example.test', 'Winterfall 2026');

        $response = $this->openPortal('robin@example.test');

        $response->assertOk();
        $response->assertDontSee('Winterfall 2026');
        $response->assertDontSee((string) $other->id);
    }

    public function test_the_portal_refuses_an_unsigned_or_tampered_link(): void
    {
        $this->submittedApplication('robin@example.test');

        $this->get('/applications/open?email=robin@example.test')->assertForbidden();

        $signed = app(ApplicantPortalService::class)->createPortalUrl('robin@example.test');
        $tampered = str_replace('robin%40example.test', 'someone-else%40example.test', $signed);

        $this->get($tampered)->assertForbidden();
    }

    public function test_an_expired_link_does_not_open_the_portal(): void
    {
        $this->submittedApplication('robin@example.test');

        $url = app(ApplicantPortalService::class)->createPortalUrl('robin@example.test');

        $this->travel(config('meridian.applicant_portal.link_expires_minutes') + 1)->minutes();

        $this->get($url)->assertForbidden();
    }

    public function test_the_portal_closes_when_its_session_runs_out(): void
    {
        $this->submittedApplication('robin@example.test');

        $this->openPortal('robin@example.test')->assertOk();

        $this->travel(config('meridian.applicant_portal.session_minutes') + 1)->minutes();

        $this->get('/applications')->assertRedirect(route('applicant-portal.request'));
    }

    public function test_the_portal_is_not_reachable_without_following_a_link(): void
    {
        $this->submittedApplication('robin@example.test');

        $this->get('/applications')->assertRedirect(route('applicant-portal.request'));
    }

    /** APP-004 and APP-013: the applicant withdraws their own application. */
    public function test_an_applicant_withdraws_a_withdrawable_application(): void
    {
        $application = $this->submittedApplication('robin@example.test');

        $this->openPortal('robin@example.test');

        $this->post('/applications/'.$application->id.'/withdraw')
            ->assertRedirect(route('applicant-portal.show'));

        $application->refresh();

        $this->assertSame(EventApplication::STATUS_WITHDRAWN, $application->status);
        $this->assertNotNull($application->withdrawn_at);
    }

    /** APP-015: the withdrawal is audited. */
    public function test_a_portal_withdrawal_is_audited(): void
    {
        $application = $this->submittedApplication('robin@example.test');

        $this->openPortal('robin@example.test');
        $this->post('/applications/'.$application->id.'/withdraw');

        $audit = AuditEvent::query()
            ->where('action', 'event_application.withdrawn')
            ->where('entity_id', (string) $application->id)
            ->firstOrFail();

        $this->assertSame(EventApplication::STATUS_SUBMITTED, $audit->before_json['status']);
        $this->assertSame(EventApplication::STATUS_WITHDRAWN, $audit->after_json['status']);
        $this->assertStringContainsString('applicant portal', (string) $audit->reason);
    }

    /** APP-015: issuance is audited, and records nothing it does not need. */
    public function test_link_issuance_is_audited(): void
    {
        $this->submittedApplication('robin@example.test');

        $this->requestLink('robin@example.test');

        $audit = AuditEvent::query()
            ->where('action', 'applicant_portal.link_issued')
            ->firstOrFail();

        $this->assertSame('applicant_portal_link', $audit->entity_type);
        $this->assertSame('robin@example.test', $audit->after_json['applicant_email']);
        $this->assertSame(1, $audit->after_json['application_count']);
        $this->assertNull($audit->actor_user_id);
    }

    /**
     * The browser a portal opens in may hold somebody else's session, so the
     * audit credits the signed-in user only where it is the applicant.
     */
    public function test_a_portal_withdrawal_does_not_credit_an_unrelated_signed_in_user(): void
    {
        $application = $this->submittedApplication('robin@example.test');
        $bystander = User::factory()->create(['email' => 'someone-else@example.test']);

        $this->actingAs($bystander);
        $this->openPortal('robin@example.test');
        $this->post('/applications/'.$application->id.'/withdraw');

        $audit = AuditEvent::query()
            ->where('action', 'event_application.withdrawn')
            ->where('entity_id', (string) $application->id)
            ->firstOrFail();

        $this->assertNull($audit->actor_user_id);
        $this->assertSame(EventApplication::STATUS_WITHDRAWN, $application->refresh()->status);
    }

    public function test_a_portal_withdrawal_credits_the_applicants_own_user(): void
    {
        $application = $this->submittedApplication('robin@example.test');
        $applicant = User::factory()->create(['email' => 'robin@example.test']);

        $this->actingAs($applicant);
        $this->openPortal('robin@example.test');
        $this->post('/applications/'.$application->id.'/withdraw');

        $audit = AuditEvent::query()
            ->where('action', 'event_application.withdrawn')
            ->where('entity_id', (string) $application->id)
            ->firstOrFail();

        $this->assertSame((string) $applicant->id, (string) $audit->actor_user_id);
    }

    public function test_a_decided_application_cannot_be_withdrawn_from_the_portal(): void
    {
        $application = $this->submittedApplication('robin@example.test');
        $application->forceFill(['status' => EventApplication::STATUS_APPROVED])->save();

        $this->openPortal('robin@example.test')
            ->assertOk()
            ->assertDontSee('Withdraw this application');

        $this->post('/applications/'.$application->id.'/withdraw')->assertNotFound();

        $this->assertSame(EventApplication::STATUS_APPROVED, $application->refresh()->status);
    }

    /**
     * APP-004: an applicant withdraws their own and nobody else's, and the
     * refusal does not distinguish somebody else's application from one that
     * does not exist.
     */
    public function test_the_portal_cannot_withdraw_another_applicants_application(): void
    {
        $this->submittedApplication('robin@example.test');
        $other = $this->submittedApplication('someone-else@example.test', 'Winterfall 2026');

        $this->openPortal('robin@example.test');

        $this->post('/applications/'.$other->id.'/withdraw')->assertNotFound();
        $this->post('/applications/'.Str::uuid().'/withdraw')->assertNotFound();

        $this->assertSame(EventApplication::STATUS_SUBMITTED, $other->refresh()->status);
    }

    public function test_withdrawal_needs_an_open_portal(): void
    {
        $application = $this->submittedApplication('robin@example.test');

        $this->post('/applications/'.$application->id.'/withdraw')
            ->assertRedirect(route('applicant-portal.request'));

        $this->assertSame(EventApplication::STATUS_SUBMITTED, $application->refresh()->status);
    }

    public function test_closing_the_portal_ends_it(): void
    {
        $this->submittedApplication('robin@example.test');

        $this->openPortal('robin@example.test')->assertOk();

        $this->post('/applications/close')->assertRedirect(route('applicant-portal.request'));
        $this->get('/applications')->assertRedirect(route('applicant-portal.request'));
    }

    /** APP-015: per address, and counted for addresses Meridian does not know. */
    public function test_link_requests_are_rate_limited_per_email_address(): void
    {
        $this->submittedApplication('robin@example.test');

        $limit = config('meridian.applicant_portal.link_requests_per_email_per_hour');

        for ($attempt = 0; $attempt < $limit; $attempt++) {
            $this->requestLink('robin@example.test')
                ->assertRedirect(route('applicant-portal.request.sent'));
        }

        $this->requestLink('robin@example.test')
            ->assertSessionHasErrors('email');

        Mail::assertSentCount($limit);
    }

    public function test_link_requests_are_rate_limited_per_requesting_client(): void
    {
        $limit = config('meridian.applicant_portal.link_requests_per_client_per_hour');

        for ($attempt = 0; $attempt < $limit; $attempt++) {
            $this->requestLink('applicant'.$attempt.'@example.test')
                ->assertRedirect(route('applicant-portal.request.sent'));
        }

        // A different address entirely, so only the per-client limit can refuse
        // it.
        $this->requestLink('one-more@example.test')->assertSessionHasErrors('email');
    }

    public function test_the_api_reports_a_rate_limited_request_without_naming_the_address(): void
    {
        $limit = config('meridian.applicant_portal.link_requests_per_email_per_hour');

        for ($attempt = 0; $attempt < $limit; $attempt++) {
            $this->postJson('/api/public/applicant-portal/link-requests', [
                'email' => 'nobody@example.test',
            ])->assertStatus(202);
        }

        $refused = $this->postJson('/api/public/applicant-portal/link-requests', [
            'email' => 'nobody@example.test',
        ]);

        $refused->assertStatus(429);
        $this->assertStringNotContainsString('nobody@example.test', $refused->getContent());
    }

    /** APP-012: the affordance is on the public application surface. */
    public function test_the_public_application_form_offers_the_portal(): void
    {
        $organization = Organization::factory()->create(['slug' => 'northwood-collective']);
        Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall-2026',
        ]);

        $this->get('/northwood-collective/emberfall-2026/apply')
            ->assertOk()
            ->assertSee(route('applicant-portal.request'));
    }

    private function requestLink(string $email): TestResponse
    {
        return $this->post('/applications/link', ['email' => $email]);
    }

    private function sentPageFor(string $email): string
    {
        return (string) $this->withSession(['applicant_portal_requested_email' => $email])
            ->get('/applications/link/sent')
            ->assertOk()
            ->getContent();
    }

    private function openPortal(string $email): TestResponse
    {
        $url = app(ApplicantPortalService::class)->createPortalUrl($email);

        $this->get($url)->assertRedirect(route('applicant-portal.show'));

        return $this->get('/applications');
    }

    private function submittedApplication(string $email, string $eventName = 'Emberfall 2026'): EventApplication
    {
        $organization = Organization::query()->where('slug', 'northwood-collective')->first()
            ?? Organization::factory()->create([
                'name' => 'Northwood Collective',
                'slug' => 'northwood-collective',
            ]);

        $event = Event::factory()->for($organization)->create(['name' => $eventName]);

        return EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_email' => $email,
            'status' => EventApplication::STATUS_SUBMITTED,
            'submitted_at' => Carbon::now()->subDay(),
        ]);
    }
}
