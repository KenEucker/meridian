<?php

namespace Tests\Feature;

use App\Mail\MagicLinkLoginMail;
use App\Models\Event;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Host-aware organization link generation (M19.9; ORG-023, ORG-024; technical
 * spec 8.7): a visitor on an organization subdomain stays on it.
 */
class OrganizationSubdomainLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://localhost']);
    }

    private function organizationWithEvent(): Event
    {
        $organization = Organization::factory()->create(['slug' => 'northwood']);

        return Event::factory()->for($organization)->create([
            'slug' => 'emberfall-2026',
            'name' => 'Emberfall 2026',
        ]);
    }

    public function test_the_apply_form_on_a_subdomain_submits_to_the_subdomain_form_of_the_route(): void
    {
        $this->organizationWithEvent();

        $response = $this->get('http://northwood.localhost/emberfall-2026/apply');

        $response->assertOk();
        $response->assertSee('action="http://northwood.localhost/emberfall-2026/apply"', false);
        $response->assertDontSee('action="http://northwood.localhost/northwood/', false);
    }

    public function test_the_apply_form_at_the_deployment_root_keeps_the_path_form_action(): void
    {
        $event = $this->organizationWithEvent();

        $this->get(route('public.events.apply', $event->applyRouteParameters()))
            ->assertOk()
            ->assertSee('/northwood/emberfall-2026/apply"', false);
    }

    public function test_a_subdomain_submission_redirects_within_the_subdomain(): void
    {
        $this->organizationWithEvent();

        $response = $this->post('http://northwood.localhost/emberfall-2026/apply', [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'pat@example.org',
        ]);

        $response->assertRedirect('http://northwood.localhost/emberfall-2026/apply/submitted');

        $this->get('http://northwood.localhost/emberfall-2026/apply/submitted')
            ->assertOk()
            ->assertSee('Application submitted');
    }

    public function test_a_path_form_submission_at_the_root_redirects_within_the_path_form(): void
    {
        $event = $this->organizationWithEvent();

        $this->post(route('public.events.apply.store', $event->applyRouteParameters()), [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'pat@example.org',
        ])->assertRedirect(route('public.events.apply.submitted', $event->applyRouteParameters()));
    }

    public function test_a_magic_link_requested_on_a_subdomain_stays_on_that_host(): void
    {
        Mail::fake();
        $this->organizationWithEvent();

        $this->post('http://northwood.localhost/login/magic-link', [
            'email' => 'pat@example.org',
        ])->assertRedirect();

        Mail::assertSent(MagicLinkLoginMail::class, function (MagicLinkLoginMail $mail): bool {
            return parse_url($mail->verificationUrl, PHP_URL_HOST) === 'northwood.localhost';
        });
    }
}
