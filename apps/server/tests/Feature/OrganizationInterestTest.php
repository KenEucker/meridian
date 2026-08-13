<?php

namespace Tests\Feature;

use App\Http\Controllers\Marketing\OrganizationInterestController;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Node;
use App\Models\Organization;
use App\Models\OrganizationInquiry;
use App\Models\Staff;
use App\Models\User;
use App\Services\Marketing\OrganizationInterestFormToken;
use App\Services\Marketing\OrganizationInterestThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The public marketing surface's server side (M18.23; PUBLIC-002 through
 * PUBLIC-006).
 *
 * The surface itself is a client application view and is covered by
 * `MarketingView.spec.ts`. What is under test here is what the node owns:
 * whether the surface may be shown at all, what a submission is allowed to
 * create, and what is written down about it.
 */
class OrganizationInterestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('organization-interest:email:'.hash('sha256', 'rowan@harborlight.test'));
        RateLimiter::clear('organization-interest:client:'.hash('sha256', '127.0.0.1'));
    }

    /** PUBLIC-005, PUBLIC-006: the read that gates the surface and hands out a token. */
    public function test_the_availability_read_reports_the_surface_and_issues_a_form_token(): void
    {
        $response = $this->getJson('/api/public/marketing-surface');

        $response->assertOk();
        $response->assertJson(['available' => true]);
        $this->assertNotSame('', $response->json('form_token'));
        $this->assertSame(3, $response->json('minimum_seconds_on_form'));
    }

    /** PUBLIC-006: an on-site node does not serve it. */
    public function test_an_on_site_node_serves_neither_the_surface_nor_the_form(): void
    {
        $this->localNode(Node::ROLE_ONSITE);

        $this->getJson('/api/public/marketing-surface')->assertNotFound();
        $this->postJson('/api/public/organization-inquiries', $this->validSubmission())
            ->assertNotFound();

        $this->assertSame(0, OrganizationInquiry::query()->count());
    }

    /** PUBLIC-006: it is not reachable when the node is locked to an event. */
    public function test_a_node_locked_to_an_event_serves_neither(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $this->localNode(Node::ROLE_STANDALONE, $event);

        $this->getJson('/api/public/marketing-surface')->assertNotFound();
        $this->postJson('/api/public/organization-inquiries', $this->validSubmission())
            ->assertNotFound();

        $this->assertSame(0, OrganizationInquiry::query()->count());
    }

    public function test_a_central_node_serves_the_surface(): void
    {
        $this->localNode(Node::ROLE_CENTRAL);

        $this->getJson('/api/public/marketing-surface')->assertOk();
    }

    /**
     * The development node serves the surface even while its record names an
     * event (M20, product owner direction). The seeded scenario always names
     * one so field surfaces resolve, and refusing here made the deployment
     * root's normal flow — the landing page — unreachable in every seeded
     * development environment. The exemption is exactly the development role:
     * the standalone case above pins that a deployable node's lock still
     * refuses.
     */
    public function test_a_development_node_locked_to_an_event_still_serves_the_surface(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $this->localNode(Node::ROLE_DEVELOPMENT, $event);

        $this->getJson('/api/public/marketing-surface')->assertOk();
        $this->submitInterest()->assertStatus(201);

        $this->assertSame(1, OrganizationInquiry::query()->count());
    }

    /** PUBLIC-002, PUBLIC-003: the submission becomes an inquiry and nothing else. */
    public function test_a_submission_is_stored_as_an_inquiry(): void
    {
        $response = $this->submitInterest();

        $response->assertStatus(201);
        $response->assertJson(['submitted' => true]);

        $inquiry = OrganizationInquiry::query()->sole();
        $this->assertSame('Harborlight Collective', $inquiry->organization_name);
        $this->assertSame('Rowan Vale', $inquiry->contact_name);
        $this->assertSame('rowan@harborlight.test', $inquiry->contact_email);
        $this->assertStringContainsString('waterfront festival', $inquiry->description);
        $this->assertSame(OrganizationInquiry::STATUS_NEW, $inquiry->status);
        $this->assertNotNull($inquiry->submitted_at);
    }

    public function test_a_submission_creates_no_organization_user_or_staff_record(): void
    {
        $this->submitInterest();

        $this->assertSame(0, Organization::query()->count());
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Staff::query()->count());
    }

    public function test_the_contact_address_is_normalized(): void
    {
        $this->submitInterest(['contact_email' => '  Rowan@Harborlight.test ']);

        $this->assertSame('rowan@harborlight.test', OrganizationInquiry::query()->sole()->contact_email);
    }

    public function test_an_incomplete_submission_is_refused_and_stores_nothing(): void
    {
        $response = $this->postJson('/api/public/organization-inquiries', [
            'organization_name' => 'Harborlight Collective',
            'contact_name' => '',
            'contact_email' => 'not-an-address',
            'description' => '',
            'form_token' => $this->agedFormToken(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['contact_name', 'contact_email', 'description']);
        $this->assertSame(0, OrganizationInquiry::query()->count());
    }

    /** PUBLIC-005: audited. */
    public function test_a_submission_is_audited(): void
    {
        $this->submitInterest();

        $inquiry = OrganizationInquiry::query()->sole();
        $audit = AuditEvent::query()
            ->where('action', 'organization_inquiry.submitted')
            ->where('entity_id', (string) $inquiry->getKey())
            ->sole();

        $this->assertSame('organization_inquiry', $audit->entity_type);
        $this->assertSame('Harborlight Collective', $audit->after_json['organization_name']);
        $this->assertSame('rowan@harborlight.test', $audit->after_json['contact_email']);
        $this->assertNull($audit->organization_id);
        $this->assertNull($audit->actor_user_id);
        // The free-text description stays on the row it was written to.
        $this->assertArrayNotHasKey('description', $audit->after_json);
    }

    /**
     * PUBLIC-005: a client that filled in a field no visitor can see gets the
     * same answer a real submission gets, and leaves no inquiry behind.
     */
    public function test_a_submission_that_fills_the_hidden_field_is_discarded_without_saying_so(): void
    {
        $honest = $this->submitInterest();
        OrganizationInquiry::query()->delete();

        $trapped = $this->submitInterest([
            OrganizationInterestController::TRAP_FIELD => 'https://example.test/spam',
        ]);

        $trapped->assertStatus(201);
        $this->assertSame($honest->getContent(), $trapped->getContent());
        $this->assertSame(0, OrganizationInquiry::query()->count());

        $audit = AuditEvent::query()->where('action', 'organization_inquiry.discarded')->sole();
        $this->assertSame('hidden_field_completed', $audit->after_json['reason']);
    }

    /**
     * PUBLIC-005: a submission arriving faster than a person could type is
     * refused recoverably. The client still holds what the visitor wrote, so
     * asking again costs them a button press rather than their message.
     */
    public function test_a_submission_faster_than_a_person_is_refused_recoverably(): void
    {
        $response = $this->postJson('/api/public/organization-inquiries', array_merge(
            $this->validSubmission(),
            ['form_token' => app(OrganizationInterestFormToken::class)->issue()],
        ));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('description');
        $this->assertSame(0, OrganizationInquiry::query()->count());
    }

    /**
     * PUBLIC-005: the token is the half of the protection a bot posting
     * straight at the endpoint always fails, because it never asked for one.
     */
    public function test_a_submission_carrying_no_form_token_is_refused(): void
    {
        $response = $this->postJson('/api/public/organization-inquiries', $this->validSubmission());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('form_token');
        $this->assertSame(0, OrganizationInquiry::query()->count());
    }

    public function test_a_tampered_or_expired_form_token_is_refused(): void
    {
        $tampered = $this->postJson('/api/public/organization-inquiries', array_merge(
            $this->validSubmission(),
            ['form_token' => 'not-a-token-this-node-issued'],
        ));
        $tampered->assertJsonValidationErrors('form_token');

        $issued = app(OrganizationInterestFormToken::class)->issue();
        Carbon::setTestNow(now()->addDays(2));

        $expired = $this->postJson('/api/public/organization-inquiries', array_merge(
            $this->validSubmission(),
            ['form_token' => $issued],
        ));
        $expired->assertJsonValidationErrors('form_token');

        Carbon::setTestNow();
        $this->assertSame(0, OrganizationInquiry::query()->count());
    }

    /**
     * The token stays required with the timing floor switched off, so a
     * deployment that turns the timing rule down does not turn the whole
     * mechanism off.
     */
    public function test_the_timing_floor_can_be_switched_off_without_dropping_the_token(): void
    {
        config()->set('meridian.marketing.interest_minimum_seconds_on_form', 0);

        $this->postJson('/api/public/organization-inquiries', array_merge(
            $this->validSubmission(),
            ['form_token' => app(OrganizationInterestFormToken::class)->issue()],
        ))->assertStatus(201);

        $this->postJson('/api/public/organization-inquiries', array_merge(
            $this->validSubmission(),
            ['contact_email' => 'second@harborlight.test'],
        ))->assertJsonValidationErrors('form_token');

        $this->assertSame(1, OrganizationInquiry::query()->count());
    }

    /** PUBLIC-005: the per-address limit. */
    public function test_submissions_are_limited_per_contact_address(): void
    {
        config()->set('meridian.marketing.interest_submissions_per_email_per_hour', 2);
        config()->set('meridian.marketing.interest_submissions_per_client_per_hour', 100);

        $this->submitInterest();
        $this->submitInterest();
        $refused = $this->submitInterest();

        $refused->assertStatus(429);
        $this->assertSame(2, OrganizationInquiry::query()->count());
    }

    /** PUBLIC-005: the per-client limit, which is what a walked list of addresses meets. */
    public function test_submissions_are_limited_per_client(): void
    {
        config()->set('meridian.marketing.interest_submissions_per_email_per_hour', 100);
        config()->set('meridian.marketing.interest_submissions_per_client_per_hour', 2);

        $this->submitInterest(['contact_email' => 'one@harborlight.test']);
        $this->submitInterest(['contact_email' => 'two@harborlight.test']);
        $refused = $this->submitInterest(['contact_email' => 'three@harborlight.test']);

        $refused->assertStatus(429);
        $this->assertSame(2, OrganizationInquiry::query()->count());
    }

    /**
     * A discarded submission still costs the node a request, so it still counts
     * against the limits. Otherwise a client that trips a trap every time would
     * run without a ceiling.
     */
    public function test_a_discarded_submission_is_counted_against_the_limits(): void
    {
        config()->set('meridian.marketing.interest_submissions_per_email_per_hour', 1);

        $this->submitInterest([OrganizationInterestController::TRAP_FIELD => 'spam']);
        $refused = $this->submitInterest();

        $refused->assertStatus(429);
        $this->assertSame(0, OrganizationInquiry::query()->count());
    }

    public function test_the_documented_limits_are_used_when_configuration_is_missing_or_nonsense(): void
    {
        $throttle = app(OrganizationInterestThrottle::class);

        config()->set('meridian.marketing.interest_submissions_per_email_per_hour', 0);
        config()->set('meridian.marketing.interest_submissions_per_client_per_hour', 'unlimited');

        $this->assertSame(OrganizationInterestThrottle::DEFAULT_PER_EMAIL, $throttle->perEmail());
        $this->assertSame(OrganizationInterestThrottle::DEFAULT_PER_CLIENT, $throttle->perClient());
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function submitInterest(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/public/organization-inquiries', array_merge(
            $this->validSubmission(),
            ['form_token' => $this->agedFormToken()],
            $overrides,
        ));
    }

    /**
     * A token issued long enough ago to clear the timing floor, the way one
     * handed to a visitor who then filled the form in would be.
     */
    private function agedFormToken(): string
    {
        Carbon::setTestNow(now()->subMinute());
        $token = app(OrganizationInterestFormToken::class)->issue();
        Carbon::setTestNow();

        return $token;
    }

    /**
     * @return array<string, string>
     */
    private function validSubmission(): array
    {
        return [
            'organization_name' => 'Harborlight Collective',
            'contact_name' => 'Rowan Vale',
            'contact_email' => 'rowan@harborlight.test',
            'description' => 'We run a three-day waterfront festival every August with about 250 volunteers.',
        ];
    }

    private function localNode(string $role, ?Event $event = null): Node
    {
        return Node::factory()->create([
            'node_role' => $role,
            'is_local' => true,
            'event_id' => $event?->getKey(),
            'organization_id' => $event?->organization_id,
        ]);
    }
}
