<?php

namespace App\Services\EventMode;

use App\Models\Node;
use App\Services\Node\NodeConfigResolver;
use App\Services\Node\NodeSetupService;
use App\Services\Offline\OfflineReadSetProbe;
use App\Services\Secrets\SecretSafeguard;

/**
 * Evaluates and enforces event-mode fail-closed safeguards on the server
 * (technical spec 8.6, 26.2).
 *
 * Event mode is derived from the effective node role: any non-development role
 * (standalone/central/onsite) is treated as event/production mode, unless
 * `meridian.event_mode.enabled` explicitly forces it on or off. The server owns
 * two checks:
 *
 *   - HTTPS validation: the configured application URL must use HTTPS
 *     (technical spec 8.2 "production/event mode never uses plain HTTP").
 *   - Offline read set availability: the node must be able to serve the set a
 *     device caches to work without signal (ADR-0003). This replaced the
 *     PowerSync liveness probe, which failed event mode closed on a service no
 *     client ever connected to.
 *   - Configured secrets: no secret this node uses may be missing or still set
 *     to a sample value (technical spec 26.2). The evaluation belongs to
 *     {@see SecretSafeguard}, which is also what refuses the boot; the guard
 *     reports it so setting a node up into an event role is refused for the
 *     same reason rather than succeeding into a node that cannot start.
 *
 * Local encryption and device signing are client-side checks and are evaluated
 * on the client (technical spec 8.6). Development mode never blocks.
 */
class EventModeGuard
{
    public function __construct(
        private readonly OfflineReadSetProbe $offlineReadSet,
        private readonly NodeSetupService $nodes,
        private readonly NodeConfigResolver $configResolver,
        private readonly SecretSafeguard $secrets,
    ) {}

    /**
     * Whether the node is in event/production mode. An explicit config value
     * wins; otherwise any non-development effective node role is event mode.
     */
    public function isEventMode(?string $nodeRole = null): bool
    {
        $configured = config('meridian.event_mode.enabled');

        if ($configured !== null && $configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_BOOL);
        }

        $role = $nodeRole ?? $this->effectiveNodeRole();

        return $role !== Node::ROLE_DEVELOPMENT;
    }

    /**
     * Evaluate the server-owned event-mode checks. In development mode no checks
     * are run and the result always passes.
     */
    public function evaluate(?string $nodeRole = null): EventModeReadiness
    {
        if (! $this->isEventMode($nodeRole)) {
            return new EventModeReadiness(eventMode: false, checks: []);
        }

        $checks = [];

        if ((bool) config('meridian.event_mode.require_https', true)) {
            $checks[] = $this->evaluateHttps();
        }

        if ((bool) config('meridian.event_mode.require_offline_read_set', true)) {
            $checks[] = $this->evaluateOfflineReadSet();
        }

        if ((bool) config('meridian.event_mode.require_configured_secrets', true)) {
            $checks[] = $this->evaluateConfiguredSecrets();
        }

        return new EventModeReadiness(eventMode: true, checks: $checks);
    }

    /**
     * Fail closed when event mode is entered while a required check fails.
     *
     * @throws EventModeNotReadyException
     */
    public function ensureReady(?string $nodeRole = null): void
    {
        $readiness = $this->evaluate($nodeRole);

        if ($readiness->blocked()) {
            throw new EventModeNotReadyException($readiness);
        }
    }

    private function evaluateHttps(): EventModeCheck
    {
        $appUrl = (string) config('app.url', '');
        $scheme = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME));
        $passed = $scheme === 'https';

        return new EventModeCheck(
            key: EventModeCheck::HTTPS,
            label: 'HTTPS validation',
            passed: $passed,
            reason: $passed
                ? null
                : 'HTTPS validation failed: event mode requires the application URL to use HTTPS, but the configured URL does not.',
        );
    }

    private function evaluateOfflineReadSet(): EventModeCheck
    {
        $passed = $this->offlineReadSet->isAvailable();

        return new EventModeCheck(
            key: EventModeCheck::OFFLINE_READ_SET,
            label: 'Offline read set availability',
            passed: $passed,
            reason: $passed
                ? null
                : 'The offline read set is unavailable: event mode requires this node to be able to serve the set devices cache from.',
        );
    }

    /**
     * The secret safeguards, reported rather than re-decided (technical spec
     * 26.2). {@see SecretSafeguard} owns the inventory and the boot refusal; the
     * guard asks it what it found so a node cannot be set up into an event role
     * that its own boot would then refuse.
     *
     * The reason names variables and never values.
     */
    private function evaluateConfiguredSecrets(): EventModeCheck
    {
        $readiness = $this->secrets->evaluate();
        $passed = ! $readiness->blocked();

        return new EventModeCheck(
            key: EventModeCheck::CONFIGURED_SECRETS,
            label: 'Configured secrets',
            passed: $passed,
            reason: $passed
                ? null
                : 'Event mode refuses to run on default secrets: '.implode(' ', $readiness->reasons()),
        );
    }

    /**
     * Resolve the effective node role using the same file-first, database-second
     * precedence as the rest of the app, defaulting to development.
     */
    private function effectiveNodeRole(): string
    {
        $node = $this->nodes->activeNode();

        foreach ($this->configResolver->valuesFor($node) as $value) {
            if ($value['key'] === 'node_role') {
                $role = $value['value'];

                return is_string($role) && $role !== '' ? $role : Node::ROLE_DEVELOPMENT;
            }
        }

        return Node::ROLE_DEVELOPMENT;
    }
}
