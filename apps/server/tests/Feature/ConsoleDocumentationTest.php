<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Console\OperatorDocumentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Platform\Dashboard;
use Tests\TestCase;

/**
 * The in-console Documentation page (GOD-012, GOD-014 through GOD-017;
 * technical spec 22.6).
 */
class ConsoleDocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_repository_operator_tree_is_packaged_with_the_server(): void
    {
        $documentation = app(OperatorDocumentation::class);

        $this->assertTrue(
            $documentation->isPackaged(),
            'Operator documentation is missing. Run: corepack pnpm run docs:package',
        );

        $slugs = array_column($documentation->index(), 'slug');

        // GOD-013: deployment, node setup and pairing, configuration and config
        // source resolution, data repair, sync conflict resolution, and
        // break-glass procedures.
        foreach ([
            'index',
            'deployment',
            'node-setup-and-pairing',
            'configuration',
            'data-repair',
            'sync-conflict-resolution',
            'break-glass',
        ] as $slug) {
            $this->assertContains($slug, $slugs);
        }
    }

    public function test_the_packaged_copy_matches_the_repository_source(): void
    {
        $source = base_path('../../docs/operator');
        $packaged = app(OperatorDocumentation::class)->directory();

        $this->assertDirectoryExists($source);

        foreach (glob($source.'/*.md') ?: [] as $file) {
            $name = basename($file);

            $this->assertFileExists(
                $packaged.'/'.$name,
                "docs/operator/{$name} is not packaged. Run: corepack pnpm run docs:package",
            );
            $this->assertSame(
                file_get_contents($file),
                file_get_contents($packaged.'/'.$name),
                "Packaged {$name} is stale. Run: corepack pnpm run docs:package",
            );
        }
    }

    public function test_the_documentation_page_renders_the_index_with_markdown(): void
    {
        $response = $this->actingAs($this->godModeUser())->get(route('platform.documentation'));

        $response->assertOk();
        $response->assertSee('Meridian operator documentation packaged with this deployment.');
        $response->assertSee('Deployment');
        $response->assertSee('Break-glass procedures');

        $body = (string) $response->getContent();

        // GOD-016: headings, lists, tables, and fenced code.
        $this->assertStringContainsString('<h2>', $body);
        $this->assertStringContainsString('<table>', $body);
    }

    public function test_a_document_can_be_opened_by_slug(): void
    {
        $response = $this->actingAs($this->godModeUser())
            ->get(route('platform.documentation', ['doc' => 'sync-conflict-resolution']));

        $response->assertOk();
        $response->assertSee('What a conflict is');
        $response->assertSee('Accept on-site');
    }

    public function test_documents_can_be_filtered_by_title_and_by_heading(): void
    {
        $documentation = app(OperatorDocumentation::class);

        $byTitle = array_column($documentation->index('Deployment'), 'slug');
        $this->assertContains('deployment', $byTitle);
        $this->assertNotContains('data-repair', $byTitle);

        // "Reasons and audit" is a heading inside the data repair document and
        // appears in no document title.
        $byHeading = array_column($documentation->index('Reasons and audit'), 'slug');
        $this->assertSame(['data-repair'], $byHeading);

        $response = $this->actingAs($this->godModeUser())
            ->get(route('platform.documentation', ['filter' => 'pairing']));

        $response->assertOk();
        $response->assertSee('Node setup and pairing');
    }

    public function test_an_unmatched_filter_reports_no_match_rather_than_an_empty_page(): void
    {
        $response = $this->actingAs($this->godModeUser())
            ->get(route('platform.documentation', ['filter' => 'zzzz-no-such-heading']));

        $response->assertOk();
        $response->assertSee('No document title or heading matches that filter.');
    }

    public function test_the_page_shows_documentation_version_beside_build_version(): void
    {
        $documentation = app(OperatorDocumentation::class);

        $response = $this->actingAs($this->godModeUser())->get(route('platform.documentation'));

        $response->assertOk();
        $response->assertSee((string) $documentation->version());
        $response->assertSee($documentation->buildVersion());
    }

    public function test_a_documentation_version_mismatch_is_stated(): void
    {
        config()->set('meridian.version', '99.99.99');

        $response = $this->actingAs($this->godModeUser())->get(route('platform.documentation'));

        $response->assertOk();
        $response->assertSee('These do not match.');
    }

    /**
     * GOD-015: the Documentation page serves `docs/operator/` and nothing else.
     */
    public function test_specification_qa_plan_and_issue_documents_are_not_reachable(): void
    {
        $documentation = app(OperatorDocumentation::class);

        foreach ([
            'meridian-requirements-document',
            'meridian-technical-spec',
            'meridian-data-model-and-api-specification',
            'meridian-alpha-1-development-plan',
            'traceability-matrix',
            'QA-BOOT-01-fresh-checkout-boots',
            '001-monorepo-scaffold',
            '0001-shared-vue-client',
        ] as $slug) {
            $this->assertNull(
                $documentation->document($slug),
                "{$slug} must not be reachable from the console.",
            );

            $response = $this->actingAs($this->godModeUser())
                ->get(route('platform.documentation', ['doc' => $slug]));

            $response->assertOk();
            $response->assertDontSee('Meridian Traceability Matrix');
            $response->assertDontSee('Meridian Alpha 1 Development Plan');
        }

        // The boundary is enforced by what is packaged, so nothing outside the
        // operator tree is even present to be served.
        $packagedFiles = array_map('basename', glob($documentation->directory().'/*.md') ?: []);
        $sourceFiles = array_map('basename', glob(base_path('../../docs/operator/*.md')) ?: []);

        sort($packagedFiles);
        sort($sourceFiles);

        $this->assertSame($sourceFiles, $packagedFiles);
    }

    public function test_a_path_traversal_slug_resolves_to_nothing(): void
    {
        $documentation = app(OperatorDocumentation::class);

        foreach ([
            '../../../docs/meridian-technical-spec',
            '..\\..\\README',
            '/etc/passwd',
        ] as $slug) {
            $this->assertNull($documentation->document($slug));
        }
    }

    public function test_the_documentation_page_requires_its_god_mode_permission(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($user)
            ->get(route('platform.documentation'))
            ->assertForbidden();
    }

    public function test_the_console_navigation_offers_documentation_and_changelog_without_external_links(): void
    {
        $response = $this->actingAs($this->godModeUser())->get(route('platform.main'));

        $response->assertOk();
        $response->assertSee(route('platform.documentation'), false);
        $response->assertSee(route('platform.changelog'), false);

        // GOD-027: the console's own documentation and changelog entries no
        // longer point at the administrative framework's sites.
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('https://orchid.software/en/docs', $body);
        $this->assertStringNotContainsString('orchidsoftware/platform/blob/master/CHANGELOG.md', $body);

        // GOD-028: the version badged in navigation is Meridian's build
        // version. The framework version survives only in the vendor footer,
        // which M15C.5 replaces, so the assertion stops where that footer
        // starts.
        $footerAt = strpos($body, 'MIT license');
        $this->assertNotFalse($footerAt);

        $navigation = substr($body, 0, $footerAt);
        $this->assertStringContainsString((string) config('meridian.version'), $navigation);
        $this->assertStringNotContainsString(Dashboard::version(), $navigation);
    }

    /**
     * The vendor footer still carries the framework's license, copyright range,
     * and version. That is M15C.5's scope (GOD-032, GOD-033) and is deliberately
     * left in place here; this test records the boundary rather than asserting a
     * clean page.
     */
    public function test_the_remaining_framework_footer_is_left_to_the_visual_identity_task(): void
    {
        $response = $this->actingAs($this->godModeUser())->get(route('platform.main'));

        $response->assertOk();
        $this->assertStringContainsString('MIT license', (string) $response->getContent());
    }

    private function godModeUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.documentation' => true,
                'platform.changelog' => true,
            ],
        ]);
    }
}
