<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeConfigValue;
use App\Services\Node\NodeKeyProvider;
use App\Services\Node\NodeSetupService;
use App\Services\Secrets\SecretInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Setup generates the secrets Meridian owns and refuses the ones it does not
 * (technical spec 7.4, 26.2).
 *
 * Meridian owns two: Laravel's `APP_KEY` and this node's signing keypair. The
 * service secrets in the same sentence of the specification belong to the
 * services that issued them, and the honest thing setup can do about a sample
 * database password is name it, which is what the command does.
 */
class SecretGenerationTest extends TestCase
{
    use RefreshDatabase;

    private ?string $environmentDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'meridian.event_mode.require_configured_secrets' => true,
            // Generation must never touch the checkout's own environment file,
            // so every test starts with a configured key and takes the
            // "already set; left alone" path unless it deliberately does not.
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'meridian.node.private_key' => '',
            'meridian.node.public_key' => '',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->environmentDirectory !== null && is_dir($this->environmentDirectory)) {
            @unlink($this->environmentDirectory.DIRECTORY_SEPARATOR.'.env');
            @rmdir($this->environmentDirectory);
            $this->environmentDirectory = null;
        }

        parent::tearDown();
    }

    /**
     * Point the application at a throwaway environment file, so a test about
     * writing one never writes the developer's.
     */
    private function useThrowawayEnvironmentFile(string $contents): string
    {
        $this->environmentDirectory = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'meridian-secrets-'.bin2hex(random_bytes(6));

        mkdir($this->environmentDirectory);
        $path = $this->environmentDirectory.DIRECTORY_SEPARATOR.'.env';
        file_put_contents($path, $contents);

        $this->app->useEnvironmentPath($this->environmentDirectory);
        $this->app->loadEnvironmentFrom('.env');

        return $path;
    }

    private function nodeWithoutKeys(): Node
    {
        return Node::query()->create([
            'node_name' => 'qa.2027.onsite',
            'node_role' => Node::ROLE_ONSITE,
            'is_local' => true,
            // `nodes.public_key` is not nullable: a node record always carries
            // the column, and "no key yet" is an empty one.
            'public_key' => '',
        ]);
    }

    public function test_generation_gives_a_configured_node_the_keypair_it_is_missing(): void
    {
        $node = $this->nodeWithoutKeys();
        $nodes = app(NodeSetupService::class);

        $this->assertSame(NodeSetupService::KEYS_GENERATED, $nodes->ensureNodeKeys($node));

        $node->refresh();
        $this->assertNotNull($node->public_key);
        $this->assertTrue(app(NodeKeyProvider::class)->hasPrivateKey($node));
        $this->assertDatabaseHas('node_config_values', [
            'node_id' => $node->getKey(),
            'key' => NodeKeyProvider::CONFIG_PRIVATE_KEY,
            'source' => NodeConfigValue::SOURCE_DATABASE,
        ]);
    }

    /**
     * Replacing a key orphans every operation this node has signed, so a node
     * that already holds one is left exactly as it is.
     */
    public function test_generation_never_replaces_an_existing_keypair(): void
    {
        $node = $this->nodeWithoutKeys();
        $nodes = app(NodeSetupService::class);

        $nodes->ensureNodeKeys($node);
        $node->refresh();
        $publicKey = $node->public_key;
        $privateKey = app(NodeKeyProvider::class)->privateKeyFor($node);

        $this->assertSame(NodeSetupService::KEYS_PRESENT, $nodes->ensureNodeKeys($node->fresh()));

        $node->refresh();
        $this->assertSame($publicKey, $node->public_key);
        $this->assertSame($privateKey, app(NodeKeyProvider::class)->privateKeyFor($node));
    }

    /**
     * Half a keypair is reported rather than repaired: restoring the missing
     * half and re-pairing with central are different decisions, and only an
     * operator can make them.
     */
    public function test_a_half_configured_keypair_is_reported_rather_than_replaced(): void
    {
        $node = $this->nodeWithoutKeys();
        $node->forceFill(['public_key' => 'a-registered-public-key'])->save();

        $this->assertSame(
            NodeSetupService::KEYS_INCOMPLETE,
            app(NodeSetupService::class)->ensureNodeKeys($node),
        );

        $node->refresh();
        $this->assertSame('a-registered-public-key', $node->public_key);
        $this->assertFalse(app(NodeKeyProvider::class)->hasPrivateKey($node));
    }

    public function test_the_command_generates_the_application_key_into_the_environment_file(): void
    {
        $path = $this->useThrowawayEnvironmentFile("APP_NAME=Meridian\nAPP_KEY=\n");
        config(['app.key' => '']);

        $this->artisan('meridian:secrets --generate')->assertExitCode(0);

        $this->assertMatchesRegularExpression('/^APP_KEY=base64:.+$/m', (string) file_get_contents($path));
        $this->assertNotSame('', trim((string) config('app.key')));
    }

    /**
     * A deployed container has no environment file: its values arrive as process
     * environment, and the image ships without one on purpose. Writing a key
     * that the next container start would not read is worse than saying so.
     */
    public function test_the_command_reports_when_a_generated_key_could_not_be_persisted(): void
    {
        config(['app.key' => '']);
        $this->app->useEnvironmentPath(sys_get_temp_dir().DIRECTORY_SEPARATOR.'meridian-no-such-directory');
        $this->app->loadEnvironmentFrom('.env');

        $this->artisan('meridian:secrets --generate')
            ->expectsOutputToContain('No writable environment file')
            ->assertExitCode(0);

        $this->assertSame('', trim((string) config('app.key')));
    }

    public function test_the_command_exits_non_zero_when_this_node_would_refuse_to_serve(): void
    {
        config([
            'meridian.event_mode.enabled' => true,
            'meridian.oauth.google.client_id' => 'a-real-client-id',
            'meridian.oauth.google.client_secret' => 'changeme',
        ]);

        $this->artisan('meridian:secrets')
            ->expectsOutputToContain(SecretInventory::GOOGLE_OAUTH_CLIENT_SECRET)
            ->assertExitCode(1);
    }

    /**
     * The same findings on a development node are reported and block nothing: a
     * laptop running the sample database password is doing what the sample is
     * for.
     */
    public function test_the_command_succeeds_in_development_with_the_same_findings(): void
    {
        config([
            'meridian.event_mode.enabled' => false,
            'meridian.oauth.google.client_id' => 'a-real-client-id',
            'meridian.oauth.google.client_secret' => 'changeme',
        ]);

        $this->artisan('meridian:secrets')
            ->expectsOutputToContain(SecretInventory::GOOGLE_OAUTH_CLIENT_SECRET)
            ->assertExitCode(0);
    }

    /**
     * Setting a node up into an event role has to fail for the same reason its
     * own boot would, or setup succeeds into a node that will not start.
     */
    public function test_node_setup_refuses_an_event_role_on_a_default_secret(): void
    {
        config([
            'app.url' => 'https://onsite.example.org',
            'meridian.oauth.google.client_id' => 'a-real-client-id',
            'meridian.oauth.google.client_secret' => 'changeme',
        ]);

        $response = $this->post('/setup', [
            'node_name' => 'onsite-node',
            'node_role' => Node::ROLE_ONSITE,
        ]);

        $response->assertRedirect(route('setup.show'));
        $response->assertSessionHasErrors('setup');
        $this->assertDatabaseCount('nodes', 0);
    }

    public function test_node_setup_allows_a_development_role_on_a_default_secret(): void
    {
        config([
            'app.url' => 'http://localhost',
            'meridian.oauth.google.client_id' => 'a-real-client-id',
            'meridian.oauth.google.client_secret' => 'changeme',
        ]);

        $response = $this->post('/setup', [
            'node_name' => 'dev-node',
            'node_role' => Node::ROLE_DEVELOPMENT,
        ]);

        $response->assertSessionHas('status', 'Node setup complete.');
        $this->assertDatabaseCount('nodes', 1);
    }
}
