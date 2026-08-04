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
use App\Services\Marketing\OrganizationInterestThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The public marketing surface and the organization interest form (M18.23;
 * PUBLIC-001 through PUBLIC-006).
 */
class MarketingSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private ?string $clientDistPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('organization-interest:email:'.hash('sha256', 'jamie@northwood.test'));
        RateLimiter::clear('organization-interest:client:'.hash('sha256', '127.0.0.1'));
    }

    protected function tearDown(): void
    {
        if ($this->clientDistPath !== null) {
            File::deleteDirectory($this->clientDistPath);
        }

        parent::tearDown();
    }

    /** PUBLIC-001: the surface is at the deployment root. */
    public function test_the_deployment_root_serves_the_marketing_surface_to_a_visitor_with_no_session(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee("Run your event's volunteer operations in one place", false);
        $response->assertSee('Tell us about your organization');
    }

    public function test_the_marketing_surface_is_also_reachable_at_its_own_address(): void
    {
        $this->get(route('public.marketing.landing'))
            ->assertOk()
            ->assertSee('Tell us about your organization');
    }

    /**
     * PUBLIC-001 describes the surface as being for organizations that do not
     * yet use Meridian. A browser holding a session is not that visitor, and
     * still reaches the product at the address it has always used.
     */
    public function test_a_signed_in_browser_still_reaches_the_client_application_at_the_root(): void
    {
        $this->installClientDist('<!doctype html><div id="app">Shared Vue client</div>');

        $response = $this->actingAs(User::factory()->create())->get('/');

        $response->assertOk();
        $this->assertFileResponseContains($response, 'Shared Vue client');
    }

    /**
     * PUBLIC-001, BRAND-003: Meridian identity is not replaced by an
     * organization branding profile.
     */
    public function test_meridian_identity_is_not_replaced_by_an_organization_profile(): void
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Meridian');
        $response->assertSee('/css/meridian-tokens.css', false);
        $response->assertSee('/css/meridian-surface.css', false);
        // The branding stylesheet and manifest are the two ways an organization
        // profile reaches a page. Neither resolves here, for any organization.
        $response->assertDontSee('branding/'.$organization->getKey().'/tokens.css', false);
        $response->assertDontSee('tokens.css?', false);
        $response->assertDontSee('Northwood Collective');
    }

    /** PUBLIC-006: an on-site node does not serve it. */
    public function test_an_on_site_node_does_not_serve_the_marketing_surface(): void
    {
        $this->installClientDist('<!doctype html><div id="app">Shared Vue client</div>');
        $this->localNode(Node::ROLE_ONSITE);

        $root = $this->get('/');
        $root->assertOk();
        $this->assertFileResponseContains($root, 'Shared Vue client');

        $this->get(route('public.marketing.landing'))->assertNotFound();
        $this->post(route('public.marketing.interest.store'), $this->validSubmission())->assertNotFound();
    }

    /** PUBLIC-006: it is not reachable when the node is locked to an event. */
    public function test_a_node_locked_to_an_event_does_not_serve_the_marketing_surface(): void
    {
        $this->installClientDist('<!doctype html><div id="app">Shared Vue client</div>');
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $this->localNode(Node::ROLE_STANDALONE, $event);

        $root = $this->get('/');
        $root->assertOk();
        $this->assertFileResponseContains($root, 'Shared Vue client');

        $this->get(route('public.marketing.landing'))->assertNotFound();
        $this->post(route('public.marketing.interest.store'), $this->validSubmission())->assertNotFound();
    }

    public function test_a_central_node_serves_the_marketing_surface(): void
    {
        $this->localNode(Node::ROLE_CENTRAL);

        $this->get('/')->assertOk()->assertSee('Tell us about your organization');
    }

    /** PUBLIC-002, PUBLIC-003: the submission becomes an inquiry and nothing else. */
    public function test_an_organization_interest_submission_is_stored_as_an_inquiry(): void
    {
        $response = $this->submitInterest();

        $response->assertRedirect(route('public.marketing.interest.received'));

        $inquiry = OrganizationInquiry::query()->sole();
        $this->assertSame('Northwood Collective', $inquiry->organization_name);
        $this->assertSame('Jamie Rivera', $inquiry->contact_name);
        $this->assertSame('jamie@northwood.test', $inquiry->contact_email);
        $this->assertStringContainsString('two festivals', $inquiry->description);
        $this->assertSame(OrganizationInquiry::STATUS_NEW, $inquiry->status);
        $this->assertNotNull($inquiry->submitted_at);

        $this->followRedirects($response)->assertSee('Northwood Collective');
    }

    public function test_a_submission_creates_no_organization_user_or_staff_record(): void
    {
        $this->submitInterest();

        $this->assertSame(0, Organization::query()->count());
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Staff::query()->count());
    }

    /** PUBLIC-002: the contact address is stored as typed, lowercased. */
    public function test_the_contact_address_is_normalized(): void
    {
        $this->submitInterest(['contact_email' => '  Jamie@Northwood.test ']);

        $this->assertSame('jamie@northwood.test', OrganizationInquiry::query()->sole()->contact_email);
    }

    public function test_an_incomplete_submission_is_refused_and_stores_nothing(): void
    {
        $this->startedForm();

        $response = $this->post(route('public.marketing.interest.store'), [
            'organization_name' => 'Northwood Collective',
            'contact_name' => '',
            'contact_email' => 'not-an-address',
            'description' => '',
        ]);

        $response->assertSessionHasErrors(['contact_name', 'contact_email', 'description']);
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
        $this->assertSame('Northwood Collective', $audit->after_json['organization_name']);
        $this->assertSame('jamie@northwood.test', $audit->after_json['contact_email']);
        $this->assertNull($audit->organization_id);
        $this->assertNull($audit->actor_user_id);
        // The free-text description stays on the row it was written to.
        $this->assertArrayNotHasKey('description', $audit->after_json);
    }

    /**
     * PUBLIC-005: automated submission is deterred without a challenge. A
     * client that filled in a field no visitor can see gets the same answer a
     * real submission gets, and leaves no inquiry behind.
     */
    public function test_a_submission_that_fills_the_hidden_field_is_discarded_without_saying_so(): void
    {
        $honest = $this->submitInterest();
        OrganizationInquiry::query()->delete();

        $trapped = $this->submitInterest([
            OrganizationInterestController::TRAP_FIELD => 'https://example.test/spam',
        ]);

        $trapped->assertRedirect(route('public.marketing.interest.received'));
        $this->assertSame($honest->headers->get('Location'), $trapped->headers->get('Location'));
        $this->assertSame(0, OrganizationInquiry::query()->count());

        $audit = AuditEvent::query()->where('action', 'organization_inquiry.discarded')->sole();
        $this->assertSame('hidden_field_completed', $audit->after_json['reason']);
    }

    /**
     * PUBLIC-005: the timing trap must not block legitimate use, so a
     * submission that trips it comes back to the visitor with what they wrote
     * still in it rather than disappearing.
     */
    public function test_a_submission_faster_than_a_person_is_returned_rather_than_discarded(): void
    {
        $this->startedForm();

        $response = $this->post(route('public.marketing.interest.store'), $this->validSubmission());

        $response->assertSessionHasErrors('description');
        $response->assertSessionHasInput('organization_name', 'Northwood Collective');
        $this->assertSame(0, OrganizationInquiry::query()->count());
    }

    public function test_the_timing_trap_can_be_switched_off(): void
    {
        config()->set('meridian.marketing.interest_minimum_seconds_on_form', 0);
        $this->startedForm();

        $this->post(route('public.marketing.interest.store'), $this->validSubmission())
            ->assertRedirect(route('public.marketing.interest.received'));

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

        $refused->assertSessionHasErrors('contact_email');
        $this->assertSame(2, OrganizationInquiry::query()->count());
    }

    /** PUBLIC-005: the per-client limit, which is what a walked list of addresses meets. */
    public function test_submissions_are_limited_per_client(): void
    {
        config()->set('meridian.marketing.interest_submissions_per_email_per_hour', 100);
        config()->set('meridian.marketing.interest_submissions_per_client_per_hour', 2);

        $this->submitInterest(['contact_email' => 'one@northwood.test']);
        $this->submitInterest(['contact_email' => 'two@northwood.test']);
        $refused = $this->submitInterest(['contact_email' => 'three@northwood.test']);

        $refused->assertSessionHasErrors('contact_email');
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

        $refused->assertSessionHasErrors('contact_email');
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

    public function test_the_confirmation_page_is_not_reachable_without_a_submission(): void
    {
        $this->get(route('public.marketing.interest.received'))
            ->assertRedirect(route('public.marketing.landing'));
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function submitInterest(array $overrides = []): TestResponse
    {
        $this->startedForm(now()->subMinute());

        return $this->post(
            route('public.marketing.interest.store'),
            array_merge($this->validSubmission(), $overrides),
        );
    }

    /**
     * Put a form render time into the session, the way the landing page does.
     */
    private function startedForm(?\DateTimeInterface $renderedAt = null): void
    {
        $this->withSession([
            'organization_interest_form_rendered_at' => ($renderedAt ?? now())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function validSubmission(): array
    {
        return [
            'organization_name' => 'Northwood Collective',
            'contact_name' => 'Jamie Rivera',
            'contact_email' => 'jamie@northwood.test',
            'description' => 'We run two festivals a year with about three hundred volunteers between them.',
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

    private function installClientDist(string $indexHtml): void
    {
        $this->clientDistPath = sys_get_temp_dir().'/meridian-client-'.Str::uuid();
        File::ensureDirectoryExists($this->clientDistPath);
        File::put($this->clientDistPath.'/index.html', $indexHtml);
        config()->set('meridian.client.dist_path', $this->clientDistPath);
    }

    private function assertFileResponseContains(TestResponse $response, string $expected): void
    {
        if (! method_exists($response->baseResponse, 'getFile')) {
            $this->assertStringContainsString($expected, (string) $response->getContent());

            return;
        }

        $file = $response->baseResponse->getFile();

        $this->assertStringContainsString($expected, (string) file_get_contents($file->getPathname()));
    }
}
