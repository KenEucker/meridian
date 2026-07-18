<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Services\EventMode\EventModeCheck;
use App\Services\EventMode\EventModeGuard;
use App\Services\EventMode\EventModeNotReadyException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EventModeFailClosedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'meridian.event_mode.enabled' => null,
            'meridian.event_mode.require_https' => true,
            'meridian.event_mode.require_powersync' => true,
            'powersync.endpoint' => 'http://powersync.test',
            'powersync.liveness_path' => '/probes/liveness',
        ]);
    }

    private function fakePowerSync(int $status): void
    {
        Http::fake([
            'http://powersync.test/probes/liveness' => Http::response(
                $status < 400 ? ['status' => 'ok'] : [],
                $status,
            ),
        ]);
    }

    private function guard(): EventModeGuard
    {
        return app(EventModeGuard::class);
    }

    public function test_development_mode_never_blocks_even_without_https_or_powersync(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->fakePowerSync(503);

        $readiness = $this->guard()->evaluate(Node::ROLE_DEVELOPMENT);

        $this->assertFalse($readiness->eventMode);
        $this->assertFalse($readiness->blocked());
        $this->assertTrue($readiness->passed());
        $this->assertSame([], $readiness->checks);
    }

    public function test_event_mode_passes_with_https_and_powersync_available(): void
    {
        config(['app.url' => 'https://onsite.example.org']);
        $this->fakePowerSync(200);

        $readiness = $this->guard()->evaluate(Node::ROLE_ONSITE);

        $this->assertTrue($readiness->eventMode);
        $this->assertFalse($readiness->blocked());
        $this->assertSame([], $readiness->reasons());
    }

    public function test_event_mode_fails_closed_when_https_validation_fails(): void
    {
        config(['app.url' => 'http://onsite.example.org']);
        $this->fakePowerSync(200);

        $readiness = $this->guard()->evaluate(Node::ROLE_ONSITE);

        $this->assertTrue($readiness->blocked());
        $failureKeys = array_map(
            static fn ($check): string => $check->key,
            $readiness->failures(),
        );
        $this->assertContains(EventModeCheck::HTTPS, $failureKeys);
        $this->assertNotContains(EventModeCheck::POWERSYNC, $failureKeys);
        $this->assertStringContainsString('HTTPS', implode(' ', $readiness->reasons()));
    }

    public function test_event_mode_fails_closed_when_powersync_is_unavailable(): void
    {
        config(['app.url' => 'https://onsite.example.org']);
        $this->fakePowerSync(503);

        $readiness = $this->guard()->evaluate(Node::ROLE_ONSITE);

        $this->assertTrue($readiness->blocked());
        $failureKeys = array_map(
            static fn ($check): string => $check->key,
            $readiness->failures(),
        );
        $this->assertContains(EventModeCheck::POWERSYNC, $failureKeys);
        $this->assertNotContains(EventModeCheck::HTTPS, $failureKeys);
        $this->assertStringContainsString('PowerSync', implode(' ', $readiness->reasons()));
    }

    public function test_ensure_ready_throws_when_event_mode_is_blocked(): void
    {
        config(['app.url' => 'http://onsite.example.org']);
        $this->fakePowerSync(503);

        $this->expectException(EventModeNotReadyException::class);

        $this->guard()->ensureReady(Node::ROLE_ONSITE);
    }

    public function test_ensure_ready_passes_in_development_mode(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->fakePowerSync(503);

        $this->guard()->ensureReady(Node::ROLE_DEVELOPMENT);

        $this->assertTrue(true);
    }

    public function test_explicit_config_forces_event_mode_for_a_development_role(): void
    {
        config([
            'meridian.event_mode.enabled' => true,
            'app.url' => 'http://localhost',
        ]);
        $this->fakePowerSync(200);

        $readiness = $this->guard()->evaluate(Node::ROLE_DEVELOPMENT);

        $this->assertTrue($readiness->eventMode);
        $this->assertTrue($readiness->blocked());
    }

    public function test_explicit_config_disables_event_mode_for_an_onsite_role(): void
    {
        config([
            'meridian.event_mode.enabled' => false,
            'app.url' => 'http://onsite.example.org',
        ]);
        $this->fakePowerSync(503);

        $readiness = $this->guard()->evaluate(Node::ROLE_ONSITE);

        $this->assertFalse($readiness->eventMode);
        $this->assertFalse($readiness->blocked());
    }

    public function test_disabled_required_checks_are_not_evaluated(): void
    {
        config([
            'app.url' => 'http://onsite.example.org',
            'meridian.event_mode.require_https' => false,
            'meridian.event_mode.require_powersync' => false,
        ]);
        $this->fakePowerSync(503);

        $readiness = $this->guard()->evaluate(Node::ROLE_ONSITE);

        $this->assertTrue($readiness->eventMode);
        $this->assertSame([], $readiness->checks);
        $this->assertFalse($readiness->blocked());
    }
}
