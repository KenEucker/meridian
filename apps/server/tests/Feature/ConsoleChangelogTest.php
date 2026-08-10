<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Node;
use App\Models\User;
use App\Services\Console\Changelog;
use App\Services\Console\ChangelogRefresh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The in-console Changelog page and its central-node-only refresh (GOD-018
 * through GOD-026; technical spec 22.7).
 */
class ConsoleChangelogTest extends TestCase
{
    use RefreshDatabase;

    private string $changelogPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->changelogPath = sys_get_temp_dir().'/meridian-changelog-'.uniqid().'.json';
        config()->set('meridian.changelog.path', $this->changelogPath);
        config()->set('meridian.changelog.refresh.token', 'test-source-repository-token');
        config()->set('meridian.changelog.refresh.repository', 'KenEucker/meridian');
    }

    protected function tearDown(): void
    {
        if (is_file($this->changelogPath)) {
            unlink($this->changelogPath);
        }

        parent::tearDown();
    }

    public function test_the_generator_output_renders_grouped_by_meridian_version(): void
    {
        $this->packageBaseline();

        $response = $this->actingAs($this->godModeUser())->get(route('platform.changelog'));

        $response->assertOk();
        $response->assertSee('Version 0.0.44');
        $response->assertSee('Version 0.0.43');
        $response->assertSee('add event and team marks');
        $response->assertSee('#152');
        $response->assertSee('Ken Eucker');

        $body = (string) $response->getContent();
        $this->assertLessThan(
            strpos($body, 'Version 0.0.43'),
            strpos($body, 'Version 0.0.44'),
            'Newest version should be listed first.',
        );
    }

    /**
     * GOD-020: every merged pull request appears, with no filtering by
     * conventional-commit type or change category.
     */
    public function test_chore_and_fix_entries_are_not_filtered_out(): void
    {
        $this->packageBaseline();

        $response = $this->actingAs($this->godModeUser())->get(route('platform.changelog'));

        $response->assertOk();
        $response->assertSee('chore(ci): tighten the release workflow');
        $response->assertSee('fix(shifts): stop double-counting overnight hours');
    }

    /**
     * GOD-021: the packaged baseline renders completely without network access.
     */
    public function test_the_page_renders_offline_from_the_packaged_baseline(): void
    {
        $this->packageBaseline();
        $this->onNode(Node::ROLE_ONSITE);

        // Any outbound HTTP would raise rather than be faked.
        Http::preventStrayRequests();

        $response = $this->actingAs($this->godModeUser())->get(route('platform.changelog'));

        $response->assertOk();
        $response->assertSee('Version 0.0.44');
        $response->assertSee('Packaged baseline');
        $response->assertSee('Only the central node refreshes the changelog.');
    }

    /**
     * GOD-024: only the central node refreshes.
     */
    public function test_an_onsite_node_does_not_refresh(): void
    {
        $this->packageBaseline();
        $this->onNode(Node::ROLE_ONSITE);

        $refresh = app(ChangelogRefresh::class);

        $this->assertSame(
            'Only the central node refreshes the changelog. This node renders the packaged baseline.',
            $refresh->unavailableReason(),
        );
        $this->assertFalse($refresh->refresh());
    }

    /**
     * GOD-023: a missing credential degrades to the baseline.
     */
    public function test_a_missing_credential_degrades_to_the_baseline(): void
    {
        $this->packageBaseline();
        $this->onNode(Node::ROLE_CENTRAL);
        config()->set('meridian.changelog.refresh.token', null);

        $refresh = app(ChangelogRefresh::class);

        $this->assertSame(
            'No source-repository credential is configured, so the packaged baseline is shown.',
            $refresh->unavailableReason(),
        );

        $response = $this->actingAs($this->godModeUser())->get(route('platform.changelog'));

        $response->assertOk();
        $response->assertSee('Version 0.0.44');
        $response->assertSee('No source-repository credential is configured');
    }

    /**
     * GOD-023: a failed refresh degrades to the baseline rather than failing the
     * page.
     */
    public function test_a_failed_refresh_degrades_to_the_baseline(): void
    {
        $this->packageBaseline();
        $this->onNode(Node::ROLE_CENTRAL);

        Http::fake(['*' => Http::response('', 503)]);

        $this->assertFalse(app(ChangelogRefresh::class)->refresh());

        $response = $this->actingAs($this->godModeUser())->get(route('platform.changelog'));

        $response->assertOk();
        $response->assertSee('Version 0.0.44');
        $response->assertSee('The source repository refused the request.');
    }

    public function test_an_unreachable_source_repository_degrades_to_the_baseline(): void
    {
        $this->packageBaseline();
        $this->onNode(Node::ROLE_CENTRAL);

        Http::fake(fn () => throw new ConnectionException('offline'));

        $this->assertFalse(app(ChangelogRefresh::class)->refresh());

        $response = $this->actingAs($this->godModeUser())->get(route('platform.changelog'));

        $response->assertOk();
        $response->assertSee('Version 0.0.44');
        $response->assertSee('The source repository could not be reached.');
    }

    /**
     * GOD-022: a successful refresh merges newer entries into the baseline.
     */
    public function test_a_successful_refresh_merges_newer_entries_and_records_the_time(): void
    {
        $this->packageBaseline();
        $this->onNode(Node::ROLE_CENTRAL);

        Http::fake(['*' => Http::response([
            [
                'number' => 153,
                'title' => 'feat(console): add the God Mode landing screen',
                'body' => 'Replaces the framework welcome content.',
                'user' => ['login' => 'KenEucker'],
                'merged_at' => '2026-07-28T10:00:00Z',
            ],
            [
                // An unmerged pull request is not a changelog entry.
                'number' => 154,
                'title' => 'wip',
                'body' => '',
                'user' => ['login' => 'KenEucker'],
                'merged_at' => null,
            ],
        ])]);

        $refresh = app(ChangelogRefresh::class);

        $this->assertTrue($refresh->refresh());
        $this->assertNotNull($refresh->lastSuccessAt());

        $entries = $refresh->entries();
        $this->assertCount(1, $entries);
        $this->assertSame(153, $entries[0]['number']);

        $response = $this->actingAs($this->godModeUser())->get(route('platform.changelog'));

        $response->assertOk();
        $response->assertSee('add the God Mode landing screen');
        $response->assertSee('Replaces the framework welcome content.');
        $response->assertSee('Refreshed from the source repository');
        // The packaged baseline is still there underneath.
        $response->assertSee('add event and team marks');
    }

    /**
     * GOD-025: refresh is skipped during the active event window.
     */
    public function test_refresh_is_skipped_during_the_active_event_window(): void
    {
        $this->packageBaseline();
        $this->onNode(Node::ROLE_CENTRAL);

        Event::factory()->create([
            'active_event_window_starts_at' => now()->subDay(),
            'active_event_window_ends_at' => now()->addDay(),
        ]);

        $refresh = app(ChangelogRefresh::class);

        $this->assertSame(
            'An event is in its active window. Refresh is skipped until the window closes.',
            $refresh->unavailableReason(),
        );

        Http::preventStrayRequests();

        $this->assertFalse($refresh->refresh());
    }

    /**
     * GOD-025: rendering does not wait on the source repository. The page is
     * built before any refresh is attempted, so a hung request cannot delay it.
     */
    public function test_rendering_does_not_wait_for_the_refresh(): void
    {
        $this->packageBaseline();
        $this->onNode(Node::ROLE_CENTRAL);

        $requested = false;

        Http::fake(function () use (&$requested) {
            $requested = true;

            return Http::response([]);
        });

        $response = $this->actingAs($this->godModeUser())->get(route('platform.changelog'));

        $response->assertOk();
        $response->assertSee('Version 0.0.44');
        $this->assertTrue(
            $requested,
            'The deferred refresh should still run, just not before the response.',
        );
    }

    /**
     * GOD-026: the credential is never displayed in the console and never
     * written to logs.
     */
    public function test_the_source_repository_credential_is_never_displayed_or_logged(): void
    {
        $this->packageBaseline();
        $this->onNode(Node::ROLE_CENTRAL);

        $logged = [];
        Log::listen(function ($message) use (&$logged): void {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        Http::fake(fn () => throw new ConnectionException(
            'cURL error 6 for https://x:test-source-repository-token@api.github.com/repos',
        ));

        app(ChangelogRefresh::class)->refresh();

        $response = $this->actingAs($this->godModeUser())->get(route('platform.changelog'));

        $response->assertOk();
        $response->assertDontSee('test-source-repository-token');

        $state = app(ChangelogRefresh::class)->state();
        $this->assertStringNotContainsString('test-source-repository-token', json_encode($state));

        foreach ($logged as $line) {
            $this->assertStringNotContainsString('test-source-repository-token', $line);
        }
    }

    public function test_a_build_without_a_packaged_changelog_says_so_instead_of_failing(): void
    {
        $response = $this->actingAs($this->godModeUser())->get(route('platform.changelog'));

        $response->assertOk();
        $response->assertSee('No changelog is packaged with this build.');
    }

    /**
     * Each entry links to the pull request it names (GOD-019).
     *
     * Derived from the configured repository rather than stored per entry: the
     * packaged file is generated from git history, which carries numbers and
     * not addresses.
     */
    public function test_each_entry_links_to_its_pull_request(): void
    {
        $this->packageBaseline();

        $this->actingAs($this->godModeUser())
            ->get(route('platform.changelog'))
            ->assertOk()
            ->assertSee('https://github.com/KenEucker/meridian/pull/152', false);
    }

    public function test_a_configured_repository_url_wins_over_the_derived_one(): void
    {
        config()->set('meridian.changelog.repository_url', 'https://git.example.test/meridian/');

        $this->assertSame(
            'https://git.example.test/meridian/pull/152',
            app(Changelog::class)->pullRequestUrl(152),
        );
    }

    /**
     * A build with nothing to point at says the number and stops.
     *
     * The alternative is a link to `https://github.com//pull/152`, which is a
     * page that does not exist dressed as one that does.
     */
    public function test_an_entry_is_not_linked_when_no_repository_is_configured(): void
    {
        config()->set('meridian.changelog.repository_url', null);
        config()->set('meridian.changelog.refresh.repository', '');
        $this->packageBaseline();

        $response = $this->actingAs($this->godModeUser())->get(route('platform.changelog'));

        $response->assertOk();
        $response->assertSee('#152');
        $response->assertDontSee('/pull/152', false);
    }

    public function test_the_changelog_page_requires_its_god_mode_permission(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($user)
            ->get(route('platform.changelog'))
            ->assertForbidden();
    }

    public function test_merging_prefers_the_refreshed_entry_for_the_same_pull_request(): void
    {
        $this->packageBaseline();

        $merged = app(Changelog::class)->merge(
            app(Changelog::class)->releases(),
            [[
                'number' => 152,
                'title' => 'The real pull request title',
                'body' => 'The real pull request body.',
                'author' => 'KenEucker',
                'merged_at' => '2026-07-27T23:44:58-07:00',
                'version' => '0.0.44',
            ]],
        );

        $titles = [];

        foreach ($merged as $release) {
            foreach ($release['entries'] as $entry) {
                $titles[$entry['number']] = $entry['title'];
            }
        }

        $this->assertSame('The real pull request title', $titles[152]);
        $this->assertCount(
            1,
            array_filter($merged[0]['entries'], static fn (array $entry): bool => $entry['number'] === 152),
        );
    }

    private function packageBaseline(): void
    {
        file_put_contents($this->changelogPath, json_encode([
            'version' => '0.0.44',
            'source_ref' => 'production',
            'releases' => [
                [
                    'version' => '0.0.43',
                    'entries' => [[
                        'number' => 151,
                        'title' => 'feat(branding): add organization and department branding',
                        'body' => '',
                        'author' => 'Ken Eucker',
                        'merged_at' => '2026-07-27T18:17:21-07:00',
                    ]],
                ],
                [
                    'version' => '0.0.44',
                    'entries' => [
                        [
                            'number' => 152,
                            'title' => 'feat(branding): add event and team marks',
                            'body' => '',
                            'author' => 'Ken Eucker',
                            'merged_at' => '2026-07-27T23:44:58-07:00',
                        ],
                        [
                            'number' => 149,
                            'title' => 'chore(ci): tighten the release workflow',
                            'body' => '',
                            'author' => 'Ken Eucker',
                            'merged_at' => '2026-07-27T12:00:00-07:00',
                        ],
                        [
                            'number' => 147,
                            'title' => 'fix(shifts): stop double-counting overnight hours',
                            'body' => '',
                            'author' => 'Ken Eucker',
                            'merged_at' => '2026-07-27T11:00:00-07:00',
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function onNode(string $role): Node
    {
        config()->set('meridian.node.role', $role);
        config()->set('meridian.node.name', 'meridian-test');

        return Node::factory()->create([
            'node_name' => 'meridian-test',
            'node_role' => $role,
            'is_local' => true,
        ]);
    }

    private function godModeUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.changelog' => true,
            ],
        ]);
    }
}
