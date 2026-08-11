<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\EventMode\EventModeCheck;
use App\Services\EventMode\EventModeGuard;
use App\Services\EventMode\EventModeNotReadyException;
use App\Services\Node\NodeSetupService;
use App\Services\Offline\OfflineReadSetProbe;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

/**
 * The event-mode fail-closed checks hold on a running node, not only at setup
 * (M19.4; technical spec 8.2, 8.6, 26.2).
 *
 * Setup refusing an event role is {@see EventModeSetupFailClosedTest}. This
 * class is about the node that is already in one: boot enforcement refuses the
 * serving processes, the refusal renders as a 503 naming the failed checks —
 * on `/up` as much as anywhere — and `meridian:event-mode` reports the same
 * evaluation with the exit code the deployment entrypoint stops a container on.
 */
class EventModeBootFailClosedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'meridian.event_mode.enabled' => null,
            'meridian.event_mode.require_https' => true,
            'meridian.event_mode.require_offline_read_set' => true,
            // The secret safeguards have their own tests (M19.3); off so this
            // class asserts the two checks M19.4 is about.
            'meridian.event_mode.require_configured_secrets' => false,
        ]);
    }

    private function markReadSetServable(bool $available): void
    {
        $this->instance(OfflineReadSetProbe::class, new class($available) extends OfflineReadSetProbe
        {
            public function __construct(private readonly bool $available) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }
        });
    }

    private function inEventMode(bool $eventMode = true): void
    {
        config(['meridian.event_mode.enabled' => $eventMode]);
    }

    private function guard(): EventModeGuard
    {
        return app(EventModeGuard::class);
    }

    /**
     * The suite itself is proof of half the rule: PHPUnit is a console process
     * that is not a serving command, so enforcement must not throw here even
     * with every check failing. Which processes are refused is the secret
     * safeguards' rule, asserted against serving commands in
     * {@see SecretSafeguardTest}; the guard deliberately shares it rather than
     * keeping a second list.
     */
    public function test_boot_enforcement_leaves_non_serving_console_processes_alone(): void
    {
        $this->inEventMode();
        config(['app.url' => 'http://onsite.example.org']);
        $this->markReadSetServable(false);

        $this->guard()->enforceAtBoot();

        $this->assertTrue(true);
    }

    public function test_ensure_ready_carries_the_readiness_the_renderer_needs(): void
    {
        $this->inEventMode();
        config(['app.url' => 'http://onsite.example.org']);
        $this->markReadSetServable(false);

        try {
            $this->guard()->ensureReady();
            $this->fail('Event mode with failing checks did not refuse.');
        } catch (EventModeNotReadyException $exception) {
            $keys = array_map(
                static fn (EventModeCheck $check): string => $check->key,
                $exception->readiness->failures(),
            );

            $this->assertSame([EventModeCheck::HTTPS, EventModeCheck::OFFLINE_READ_SET], $keys);
        }
    }

    /**
     * The refusal is thrown from boot, before routing, so what an operator or
     * a waiting container sees is what the registered renderer produces —
     * including on `/up`, because a node that will not serve must not report
     * itself healthy.
     */
    public function test_the_refusal_renders_as_a_503_naming_the_failed_checks(): void
    {
        $this->inEventMode();
        config(['app.url' => 'http://onsite.example.org']);
        $this->markReadSetServable(false);

        $exception = new EventModeNotReadyException($this->guard()->evaluate());
        $handler = app(ExceptionHandler::class);

        $webResponse = $handler->render(Request::create('/up'), $exception);
        $this->assertSame(503, $webResponse->getStatusCode());
        $this->assertStringContainsString('HTTPS', (string) $webResponse->getContent());

        $apiResponse = $handler->render(Request::create('/api/session'), $exception);
        $this->assertSame(503, $apiResponse->getStatusCode());
        $payload = json_decode((string) $apiResponse->getContent(), true);
        $this->assertSame('event_mode_not_ready', $payload['reason_code']);
        $this->assertSame(
            [EventModeCheck::HTTPS, EventModeCheck::OFFLINE_READ_SET],
            $payload['checks'],
        );
        $this->assertStringContainsString('offline read set', $payload['message']);
    }

    /**
     * Boot has to be able to ask "is this event mode?" before the database
     * exists — the deployment entrypoint runs migrations in the same boot that
     * is being guarded. File config is the boot layer (technical spec 7.3), so
     * the role degrades to it rather than the question failing the boot.
     */
    public function test_the_effective_role_degrades_to_file_config_without_a_database(): void
    {
        $this->mock(NodeSetupService::class, function ($mock): void {
            $mock->shouldReceive('activeNode')
                ->andThrow(new RuntimeException('no such table: nodes'));
        });
        config([
            'meridian.node.role' => 'onsite',
            'app.url' => 'http://onsite.example.org',
        ]);
        $this->markReadSetServable(true);

        $readiness = $this->guard()->evaluate();

        $this->assertTrue($readiness->eventMode);
        $this->assertTrue($readiness->blocked());
    }

    public function test_the_command_succeeds_in_development_mode(): void
    {
        $this->inEventMode(false);
        config(['app.url' => 'http://localhost']);
        $this->markReadSetServable(false);

        $this->artisan('meridian:event-mode')
            ->expectsOutputToContain('development mode')
            ->assertSuccessful();
    }

    public function test_the_command_succeeds_when_every_check_passes(): void
    {
        $this->inEventMode();
        config(['app.url' => 'https://onsite.example.org']);
        $this->markReadSetServable(true);

        $this->artisan('meridian:event-mode')
            ->expectsOutputToContain('Every event-mode fail-closed check passes.')
            ->assertSuccessful();
    }

    /**
     * The non-zero exit is what stops the deployment container at start
     * (`set -e` in deploy/docker/entrypoint.sh), so it is the contract under
     * test rather than an incidental detail.
     */
    public function test_the_command_fails_and_names_the_finding_when_a_check_fails(): void
    {
        $this->inEventMode();
        config(['app.url' => 'http://onsite.example.org']);
        $this->markReadSetServable(true);

        $this->artisan('meridian:event-mode')
            ->expectsOutputToContain('HTTPS validation failed')
            ->assertFailed();
    }

    public function test_the_command_emits_the_evaluation_as_json(): void
    {
        $this->inEventMode();
        config(['app.url' => 'http://onsite.example.org']);
        $this->markReadSetServable(false);

        $exitCode = Artisan::call('meridian:event-mode', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertTrue($payload['event_mode']);
        $this->assertTrue($payload['blocked']);
        $this->assertSame(
            [EventModeCheck::HTTPS, EventModeCheck::OFFLINE_READ_SET],
            array_column(
                array_values(array_filter($payload['checks'], static fn (array $check): bool => ! $check['passed'])),
                'key',
            ),
        );
    }
}
