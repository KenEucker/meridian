<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Node;
use App\Services\EventMode\EventModeCheck;
use App\Services\EventMode\EventModeGuard;
use App\Services\Offline\OfflineReadSetProbe;
use App\Services\Secrets\DefaultSecretsException;
use App\Services\Secrets\SecretInventory;
use App\Services\Secrets\SecretSafeguard;
use App\Services\Secrets\SecretStatus;
use App\Services\SystemConfig\EnvExampleCatalog;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Production and event modes refuse to boot with default secrets, and sample
 * configurations carry fake values only (technical spec 7.4, 26.2).
 *
 * The suite runs with `MERIDIAN_EVENT_MODE_REQUIRE_CONFIGURED_SECRETS=false`
 * (see `phpunit.xml`) so the other event-mode tests assert the checks they are
 * about rather than whatever `.env` a checkout carries. This class switches it
 * back on and sets every value it asserts against, so what is under test is the
 * rule and not the machine it runs on.
 *
 * Nothing here touches the database, which is why `database.default` can be
 * moved to `pgsql`: the database password requirement only applies to a node
 * that uses PostgreSQL, and the suite itself runs on SQLite.
 */
class SecretSafeguardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'meridian.event_mode.require_configured_secrets' => true,
            // A configured baseline, so a test asserting a refusal is asserting
            // the one secret it changed.
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'database.default' => 'pgsql',
            'database.connections.pgsql.password' => 'a-real-database-password',
        ]);
    }

    private function safeguard(): SecretSafeguard
    {
        return app(SecretSafeguard::class);
    }

    private function inEventMode(bool $eventMode = true): void
    {
        config(['meridian.event_mode.enabled' => $eventMode]);
    }

    /**
     * @return array<string, SecretStatus>
     */
    private function statusesByName(): array
    {
        $statuses = [];

        foreach ($this->safeguard()->evaluate()->statuses as $status) {
            $statuses[$status->requirement->name] = $status;
        }

        return $statuses;
    }

    public function test_a_configured_node_is_not_refused(): void
    {
        $this->inEventMode();

        $this->assertFalse($this->safeguard()->evaluate()->blocked());
        $this->assertFalse($this->safeguard()->refusesToServe());

        $this->safeguard()->ensureServable();
    }

    public function test_event_mode_refuses_a_sample_database_password(): void
    {
        $this->inEventMode();
        config(['database.connections.pgsql.password' => 'meridian']);

        $readiness = $this->safeguard()->evaluate();

        $this->assertTrue($readiness->blocked());
        $this->assertSame([SecretInventory::DB_PASSWORD], $readiness->failedNames());
        $this->assertTrue($this->safeguard()->refusesToServe());

        $this->expectException(DefaultSecretsException::class);
        $this->safeguard()->ensureServable();
    }

    /**
     * A placeholder no sample file names is still a default secret: the values
     * an operator types to get past a required field are the same everywhere.
     */
    public function test_event_mode_refuses_a_universal_placeholder(): void
    {
        $this->inEventMode();
        config([
            'meridian.oauth.google.client_id' => 'a-real-client-id',
            'meridian.oauth.google.client_secret' => 'CHANGEME',
        ]);

        $this->assertSame(
            [SecretInventory::GOOGLE_OAUTH_CLIENT_SECRET],
            $this->safeguard()->evaluate()->failedNames(),
        );
    }

    public function test_event_mode_refuses_a_missing_application_key(): void
    {
        $this->inEventMode();
        config(['app.key' => '']);

        $readiness = $this->safeguard()->evaluate();

        $this->assertSame([SecretInventory::APP_KEY], $readiness->failedNames());
        $this->assertSame(
            SecretStatus::MISSING,
            $this->statusesByName()[SecretInventory::APP_KEY]->state,
        );
    }

    /**
     * The node keypair normally lives as a database override written by setup,
     * so a blank file value is the ordinary state of a working node. Refusing
     * every such node would refuse nearly all of them; the absent-keys case is
     * reported by the God Mode attention list, which can read the node record.
     */
    public function test_a_blank_node_private_key_is_not_a_refusal(): void
    {
        $this->inEventMode();
        config(['meridian.node.private_key' => '']);

        $this->assertNotContains(
            SecretInventory::NODE_PRIVATE_KEY,
            $this->safeguard()->evaluate()->failedNames(),
        );
    }

    public function test_development_mode_is_refused_nothing(): void
    {
        $this->inEventMode(false);
        config([
            'app.key' => '',
            'database.connections.pgsql.password' => 'meridian',
        ]);

        // The findings are still reported — the God Mode attention list and
        // diagnostics show them — and nothing is blocked.
        $this->assertTrue($this->safeguard()->evaluate()->blocked());
        $this->assertFalse($this->safeguard()->refusesToServe());

        $this->safeguard()->ensureServable();
    }

    /**
     * A secret for a service this node does not run is not a default secret.
     */
    public function test_secrets_for_unused_services_are_not_evaluated(): void
    {
        $this->inEventMode();
        config([
            'database.default' => 'sqlite',
            'database.connections.pgsql.password' => 'meridian',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'array',
            'broadcasting.default' => 'null',
            'database.redis.default.password' => 'password',
            'mail.default' => 'array',
            'mail.mailers.smtp.username' => 'someone',
            'mail.mailers.smtp.password' => 'secret',
            'meridian.oauth.google.client_id' => '',
            'meridian.oauth.google.client_secret' => 'changeme',
        ]);

        $evaluated = array_keys($this->statusesByName());

        $this->assertNotContains(SecretInventory::DB_PASSWORD, $evaluated);
        $this->assertNotContains(SecretInventory::REDIS_PASSWORD, $evaluated);
        $this->assertNotContains(SecretInventory::MAIL_PASSWORD, $evaluated);
        $this->assertNotContains(SecretInventory::GOOGLE_OAUTH_CLIENT_SECRET, $evaluated);
        $this->assertFalse($this->safeguard()->evaluate()->blocked());
    }

    /**
     * Every one of these strings is rendered somewhere: a log line, an HTTP
     * response, a console table. None of them may carry the value that failed.
     */
    public function test_no_reason_carries_the_value_it_is_about(): void
    {
        $this->inEventMode();
        config([
            'database.connections.pgsql.password' => 'ChangeMe',
            'meridian.changelog.refresh.token' => 'your-secret-here',
        ]);

        $readiness = $this->safeguard()->evaluate();
        $rendered = implode(' ', [
            ...$readiness->reasons(),
            (new DefaultSecretsException($readiness))->getMessage(),
            (string) json_encode($readiness->toArray()),
        ]);

        $this->assertStringNotContainsStringIgnoringCase('ChangeMe', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('your-secret-here', $rendered);
        $this->assertStringContainsString(SecretInventory::DB_PASSWORD, $rendered);
        $this->assertStringContainsString(SecretInventory::CHANGELOG_TOKEN, $rendered);
    }

    public function test_boot_enforcement_covers_serving_processes_and_leaves_artisan_repairable(): void
    {
        $safeguard = $this->safeguard();

        $this->assertTrue($safeguard->bootEnforcementApplies('queue:work'));
        $this->assertTrue($safeguard->bootEnforcementApplies('schedule:work'));
        $this->assertTrue($safeguard->bootEnforcementApplies('serve'));

        // The commands that fix a node its own boot refused.
        $this->assertFalse($safeguard->bootEnforcementApplies('meridian:secrets'));
        $this->assertFalse($safeguard->bootEnforcementApplies('key:generate'));
        $this->assertFalse($safeguard->bootEnforcementApplies('migrate'));
        $this->assertFalse($safeguard->bootEnforcementApplies('config:cache'));
    }

    /**
     * The refusal is thrown from provider boot, before routing, so what an
     * operator sees is whatever the registered renderer produces — including on
     * `/up`, because a node that will not serve must not report itself healthy.
     */
    public function test_the_refusal_renders_as_a_503_naming_the_variables(): void
    {
        $this->inEventMode();
        config(['database.connections.pgsql.password' => 'meridian']);

        $exception = new DefaultSecretsException($this->safeguard()->evaluate());
        $handler = app(ExceptionHandler::class);

        $webResponse = $handler->render(Request::create('/home'), $exception);
        $this->assertSame(503, $webResponse->getStatusCode());
        $this->assertStringContainsString(SecretInventory::DB_PASSWORD, (string) $webResponse->getContent());

        $apiResponse = $handler->render(Request::create('/api/session'), $exception);
        $this->assertSame(503, $apiResponse->getStatusCode());
        $payload = json_decode((string) $apiResponse->getContent(), true);
        $this->assertSame('default_secrets', $payload['reason_code']);
        $this->assertSame([SecretInventory::DB_PASSWORD], $payload['secrets']);
    }

    public function test_the_event_mode_guard_reports_the_same_finding(): void
    {
        config([
            'app.url' => 'https://onsite.example.org',
            'database.connections.pgsql.password' => 'meridian',
        ]);

        $this->instance(OfflineReadSetProbe::class, new class extends OfflineReadSetProbe
        {
            public function __construct() {}

            public function isAvailable(): bool
            {
                return true;
            }
        });

        $readiness = app(EventModeGuard::class)->evaluate(Node::ROLE_ONSITE);

        $this->assertTrue($readiness->blocked());
        $this->assertSame(
            [EventModeCheck::CONFIGURED_SECRETS],
            array_map(static fn ($check): string => $check->key, $readiness->failures()),
        );
    }

    public function test_the_event_mode_guard_reports_nothing_in_development(): void
    {
        config([
            'app.url' => 'http://localhost',
            'database.connections.pgsql.password' => 'meridian',
        ]);

        $readiness = app(EventModeGuard::class)->evaluate(Node::ROLE_DEVELOPMENT);

        $this->assertFalse($readiness->eventMode);
        $this->assertFalse($readiness->blocked());
    }

    /**
     * "Sample configs must contain fake values only" (technical spec 7.4, 26.2).
     *
     * Asserted through the inventory rather than by pattern-matching the file: a
     * real secret committed to `.env.example` would have to be added to a list
     * named `defaultValues` — and thereby be refused on every event node — for
     * this to pass, which is not something anybody does by accident. The same
     * pass is what keeps the inventory covering every secret the catalogue knows
     * about, so a new `@secret` variable cannot arrive unclassified.
     */
    public function test_every_catalogued_secret_is_classified_and_its_sample_is_fake(): void
    {
        $inventory = app(SecretInventory::class);
        $catalogued = 0;

        foreach (app(EnvExampleCatalog::class)->entries() as $entry) {
            if (! $entry->secret) {
                continue;
            }

            $catalogued++;
            $requirement = $inventory->requirement($entry->name);

            $this->assertNotNull(
                $requirement,
                $entry->name.' is marked @secret in .env.example but is not classified in SecretInventory.',
            );

            $sample = trim((string) $entry->exampleValue);

            if ($sample === '' || strtolower($sample) === 'null') {
                continue;
            }

            $this->assertTrue(
                $requirement->isDefaultValue($sample),
                $entry->name.' carries a sample value in .env.example that Meridian does not treat as a default. Sample configs must contain fake values only (technical spec 7.4).',
            );
        }

        $this->assertGreaterThan(0, $catalogued, 'The catalogue parsed no secrets at all.');
    }
}
