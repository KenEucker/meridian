<?php

namespace Tests\Feature;

use App\Mail\MagicLinkLoginMail;
use App\Models\Department;
use App\Models\Event;
use App\Models\Node;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\User;
use App\Services\Branding\SystemMailIdentity;
use App\Services\Documents\DocumentExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where organization identity replaces Meridian, and where it must not
 * (M15A.8; BRAND-002, BRAND-003).
 *
 * The replacement half of this is easy to get right and easy to over-apply.
 * The boundary half is what these tests are for: login, the magic-link
 * landing, node first-run setup, Orchid, and desktop chrome keep Meridian's
 * identity no matter what an organization has configured.
 */
class BrandingIdentityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_generated_pdf_export_carries_the_organization_name(): void
    {
        [$organization, $document, $actor] = $this->brandedOrganizationWithDocument();

        $export = app(DocumentExportService::class)->export($document, $actor, 'markdown');

        $this->assertStringContainsString('Produced by: Deep Harbor Collective', $export->contents);
        $this->assertStringNotContainsString('Produced by: Meridian', $export->contents);
        $this->assertSame('Deep Harbor Collective', $organization->fresh()->brandingDisplayName());
    }

    public function test_an_unbranded_organization_export_still_says_meridian(): void
    {
        [, $document, $actor] = $this->brandedOrganizationWithDocument(branded: false);

        $export = app(DocumentExportService::class)->export($document, $actor, 'markdown');

        $this->assertStringContainsString('Produced by: Meridian', $export->contents);
    }

    public function test_the_magic_link_login_email_keeps_meridian_identity(): void
    {
        // BRAND-003 protects the login flow, and the recipient of a login link
        // may have no account at all — there is no organization identity that
        // would be truthful here.
        Organization::factory()->branded('Deep Harbor Collective')->create();

        $mail = new MagicLinkLoginMail('https://example.test/verify');

        $this->assertSame('Meridian login link', $mail->envelope()->subject);
        $this->assertFalse($mail->identity->isOrganizationIdentity);
    }

    public function test_an_organization_identified_email_uses_the_display_name(): void
    {
        $organization = Organization::factory()->branded('Deep Harbor Collective')->create();

        $identity = SystemMailIdentity::forOrganization($organization);

        $this->assertTrue($identity->isOrganizationIdentity);
        $this->assertSame('Deep Harbor Collective shift reminder', $identity->subjectFor('shift reminder'));
    }

    public function test_an_unbranded_organization_email_falls_back_to_meridian(): void
    {
        // A legal entity name staff have never seen is worse than "Meridian".
        $organization = Organization::factory()->create(['name' => 'Deep Harbor Collective LLC']);

        $identity = SystemMailIdentity::forOrganization($organization);

        $this->assertFalse($identity->isOrganizationIdentity);
        $this->assertSame('Meridian', $identity->displayName);
    }

    public function test_the_login_screen_keeps_meridian_identity(): void
    {
        Organization::factory()->branded('Deep Harbor Collective')->create();

        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('Meridian', false);
        $response->assertDontSee('Deep Harbor Collective', false);
    }

    public function test_node_first_run_setup_keeps_meridian_identity(): void
    {
        Organization::factory()->branded('Deep Harbor Collective')->create();

        $response = $this->get(route('setup.show'));

        // Setup redirects once a node exists; either way it must never render
        // an organization's name.
        $response->assertDontSee('Deep Harbor Collective', false);
    }

    public function test_the_branding_stylesheet_does_not_apply_without_the_document_attribute(): void
    {
        // The token layer is scoped to `[data-organization-branding="applied"]`
        // (UI implementation contract 10.3), which is what keeps a protected
        // surface Meridian-identified even if the stylesheet is loaded.
        $organization = Organization::factory()->branded('Deep Harbor Collective')->create();

        $css = $this->get(route('branding.stylesheet', ['organization' => $organization->id]))
            ->getContent();

        $this->assertStringContainsString(':root[data-organization-branding="applied"]', $css);
        $this->assertStringNotContainsString(":root {\n", $css);
    }

    public function test_health_reports_node_identity_so_the_desktop_can_brand_its_window(): void
    {
        // BRAND-003A: the desktop wrapper brands its window icon only while
        // the install is locked to an event, and the node is what knows that —
        // a Kiosk at a gate has no signed-in user to ask.
        $organization = Organization::factory()->branded('Deep Harbor Collective')->create();
        $event = Event::factory()->for($organization)->create();

        Node::factory()->create([
            'node_role' => Node::ROLE_ONSITE,
            'is_local' => true,
            'organization_id' => $organization->id,
            'event_id' => $event->id,
        ]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('node_role', Node::ROLE_ONSITE)
            ->assertJsonPath('organization_id', (string) $organization->id)
            ->assertJsonPath('event_id', (string) $event->id);
    }

    public function test_a_node_not_locked_to_an_event_reports_no_event(): void
    {
        // Without an event the desktop keeps Meridian's icon: the icon
        // identifies the software, not a deployment (BRAND-003, BRAND-003A).
        $organization = Organization::factory()->branded('Deep Harbor Collective')->create();

        Node::factory()->create([
            'node_role' => Node::ROLE_CENTRAL,
            'is_local' => true,
            'organization_id' => $organization->id,
            'event_id' => null,
        ]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('event_id', null);
    }

    public function test_the_orchid_console_serves_meridian_default_tokens(): void
    {
        // BRAND-003 keeps the administrative interface on Meridian's identity,
        // and it is served the shared token stylesheet rather than a branding
        // layer.
        $tokens = file_get_contents(public_path('css/meridian-tokens.css'));

        $this->assertIsString($tokens);
        $this->assertStringContainsString('--m-platform-primary: #475157;', $tokens);
        $this->assertStringNotContainsString('data-organization-branding', $tokens);
    }

    /**
     * @return array{0: Organization, 1: PolicyDocument, 2: User}
     */
    private function brandedOrganizationWithDocument(bool $branded = true): array
    {
        $organization = $branded
            ? Organization::factory()->branded('Deep Harbor Collective')->create()
            : Organization::factory()->create();

        Department::factory()->for($organization)->create();
        Event::factory()->for($organization)->create();

        $document = PolicyDocument::factory()->for($organization)->create([
            'state' => PolicyDocument::STATE_PUBLISHED,
        ]);

        $actor = User::factory()->create();
        $actor->forceFill(['permissions' => ['platform.policy-documents' => true]])->save();

        return [$organization, $document, $actor->fresh()];
    }
}
