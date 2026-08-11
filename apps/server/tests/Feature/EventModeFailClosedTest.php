<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Services\EventMode\EventModeCheck;
use App\Services\EventMode\EventModeGuard;
use App\Services\EventMode\EventModeNotReadyException;
use App\Services\Offline\OfflineReadSetProbe;
use Tests\TestCase;

/**
 * Event-mode fail-closed behavior against the offline read set probe
 * (ADR-0003; technical spec 8.6, 26.2).
 *
 * The second server-owned check used to be a PowerSync liveness request over
 * HTTP. It is now an in-process question about whether this node can serve
 * `GET /api/offline-read-set`, so the probe is stubbed rather than the HTTP
 * client faked — there is no request to fake, which is the point of the change.
 */
class EventModeFailClosedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'meridian.event_mode.enabled' => null,
            'meridian.event_mode.require_https' => true,
            'meridian.event_mode.require_offline_read_set' => true,
            // The secret safeguards have their own tests (M19.3); stated here
            // so this class keeps asserting the two checks it is about.
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

    private function guard(): EventModeGuard
    {
        return app(EventModeGuard::class);
    }

    public function test_development_mode_never_blocks_even_without_https_or_the_read_set(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->markReadSetServable(false);

        $readiness = $this->guard()->evaluate(Node::ROLE_DEVELOPMENT);

        $this->assertFalse($readiness->eventMode);
        $this->assertFalse($readiness->blocked());
        $this->assertTrue($readiness->passed());
        $this->assertSame([], $readiness->checks);
    }

    public function test_event_mode_passes_with_https_and_a_servable_read_set(): void
    {
        config(['app.url' => 'https://onsite.example.org']);
        $this->markReadSetServable(true);

        $readiness = $this->guard()->evaluate(Node::ROLE_ONSITE);

        $this->assertTrue($readiness->eventMode);
        $this->assertFalse($readiness->blocked());
        $this->assertSame([], $readiness->reasons());
    }

    /**
     * A real node satisfies the read-set check without any stubbing: the route
     * is registered and the composer resolves. This is what keeps the check
     * from being a rule only the test harness can pass.
     */
    public function test_the_read_set_probe_answers_on_an_unstubbed_node(): void
    {
        config(['app.url' => 'https://onsite.example.org']);

        $readiness = $this->guard()->evaluate(Node::ROLE_ONSITE);

        $this->assertTrue($readiness->eventMode);
        $this->assertFalse($readiness->blocked());
    }

    public function test_event_mode_fails_closed_when_https_validation_fails(): void
    {
        config(['app.url' => 'http://onsite.example.org']);
        $this->markReadSetServable(true);

        $readiness = $this->guard()->evaluate(Node::ROLE_ONSITE);

        $this->assertTrue($readiness->blocked());
        $failureKeys = array_map(
            static fn ($check): string => $check->key,
            $readiness->failures(),
        );
        $this->assertContains(EventModeCheck::HTTPS, $failureKeys);
        $this->assertNotContains(EventModeCheck::OFFLINE_READ_SET, $failureKeys);
        $this->assertStringContainsString('HTTPS', implode(' ', $readiness->reasons()));
    }

    public function test_event_mode_fails_closed_when_the_offline_read_set_cannot_be_served(): void
    {
        config(['app.url' => 'https://onsite.example.org']);
        $this->markReadSetServable(false);

        $readiness = $this->guard()->evaluate(Node::ROLE_ONSITE);

        $this->assertTrue($readiness->blocked());
        $failureKeys = array_map(
            static fn ($check): string => $check->key,
            $readiness->failures(),
        );
        $this->assertContains(EventModeCheck::OFFLINE_READ_SET, $failureKeys);
        $this->assertNotContains(EventModeCheck::HTTPS, $failureKeys);
        $this->assertStringContainsString('offline read set', implode(' ', $readiness->reasons()));
    }

    public function test_ensure_ready_throws_when_event_mode_is_blocked(): void
    {
        config(['app.url' => 'http://onsite.example.org']);
        $this->markReadSetServable(false);

        $this->expectException(EventModeNotReadyException::class);

        $this->guard()->ensureReady(Node::ROLE_ONSITE);
    }

    public function test_ensure_ready_passes_in_development_mode(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->markReadSetServable(false);

        $this->guard()->ensureReady(Node::ROLE_DEVELOPMENT);

        $this->assertTrue(true);
    }

    public function test_explicit_config_forces_event_mode_for_a_development_role(): void
    {
        config([
            'meridian.event_mode.enabled' => true,
            'app.url' => 'http://localhost',
        ]);
        $this->markReadSetServable(true);

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
        $this->markReadSetServable(false);

        $readiness = $this->guard()->evaluate(Node::ROLE_ONSITE);

        $this->assertFalse($readiness->eventMode);
        $this->assertFalse($readiness->blocked());
    }

    public function test_disabled_required_checks_are_not_evaluated(): void
    {
        config([
            'app.url' => 'http://onsite.example.org',
            'meridian.event_mode.require_https' => false,
            'meridian.event_mode.require_offline_read_set' => false,
        ]);
        $this->markReadSetServable(false);

        $readiness = $this->guard()->evaluate(Node::ROLE_ONSITE);

        $this->assertTrue($readiness->eventMode);
        $this->assertSame([], $readiness->checks);
        $this->assertFalse($readiness->blocked());
    }
}
