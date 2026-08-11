<?php

declare(strict_types=1);

namespace App\Services\Secrets;

use App\Models\Node;
use App\Services\EventMode\EventModeGuard;
use Throwable;

/**
 * Evaluates and enforces the production/event secret safeguards (technical spec
 * 7.4, 26.2).
 *
 * Two of the section 26.2 safeguards live here. "Refuse to boot with default
 * secrets" is {@see enforceAtBoot()}, which runs on every boot that is about to
 * serve something and throws rather than letting the node start. "Generate
 * APP_KEY, node keys, and service secrets if missing or default" is the
 * `meridian:secrets --generate` command, which is what setup runs (technical
 * spec 7.4) and what the deployment entrypoint runs before it hands off to
 * php-fpm.
 *
 * The refusal deliberately does not apply to every console command. A node that
 * cannot boot cannot be repaired, so `artisan` stays usable — that is where
 * `meridian:secrets`, `key:generate`, `migrate`, and `config:cache` live — and
 * only the processes that actually serve are refused: HTTP requests, the queue
 * worker, and the scheduler. The result is a node that answers nothing and
 * processes nothing while an operator still has every tool needed to fix it.
 *
 * Protected mode is production or event mode. It is resolved from an explicit
 * `meridian.event_mode.enabled`, then from `APP_ENV=production`, then from the
 * effective node role the way {@see EventModeGuard} resolves it. That last step
 * reads the database, which may not exist yet on a first boot, so it degrades to
 * the file-config role rather than failing — file config is the boot layer
 * (technical spec 7.3) and is what a deployment sets.
 */
class SecretSafeguard
{
    /**
     * Commands that serve. Everything else on `artisan` is a tool for fixing
     * the node, and a node that refuses to boot must stay fixable.
     */
    private const SERVING_COMMANDS = [
        'serve',
        'queue:work',
        'queue:listen',
        'schedule:work',
        'schedule:run',
        'octane:start',
    ];

    public function __construct(private readonly SecretInventory $inventory) {}

    /**
     * Evaluate every applicable secret. This applies no policy about mode: a
     * development node with a sample database password gets the same report,
     * and is refused nothing.
     */
    public function evaluate(): SecretReadiness
    {
        $statuses = [];

        foreach ($this->inventory->applicable() as $requirement) {
            $statuses[] = $this->evaluateRequirement($requirement);
        }

        return new SecretReadiness($statuses);
    }

    /**
     * Whether this node would refuse to serve as configured right now.
     */
    public function refusesToServe(): bool
    {
        return $this->inProtectedMode() && $this->evaluate()->blocked();
    }

    /**
     * @throws DefaultSecretsException
     */
    public function ensureServable(): void
    {
        if (! $this->inProtectedMode()) {
            return;
        }

        $readiness = $this->evaluate();

        if ($readiness->blocked()) {
            throw new DefaultSecretsException($readiness);
        }
    }

    /**
     * The boot hook. Refuses only the processes that serve; see the class
     * comment for why `artisan` is otherwise left alone.
     *
     * @throws DefaultSecretsException
     */
    public function enforceAtBoot(): void
    {
        if (! $this->bootEnforcementApplies()) {
            return;
        }

        $this->ensureServable();
    }

    /**
     * Whether this process is one the refusal applies to.
     *
     * @param  string|null  $command  The console command being run; resolved from `argv` when omitted.
     */
    public function bootEnforcementApplies(?string $command = null): bool
    {
        if (! app()->runningInConsole()) {
            return true;
        }

        return in_array($command ?? $this->consoleCommand(), self::SERVING_COMMANDS, true);
    }

    /**
     * Production or event mode (technical spec 26.1, 26.2).
     */
    public function inProtectedMode(): bool
    {
        $configured = config('meridian.event_mode.enabled');

        if ($configured !== null && $configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_BOOL);
        }

        if (app()->environment('production')) {
            return true;
        }

        try {
            // Resolved rather than injected: EventModeGuard asks this service
            // for its secret check, and constructor injection in both
            // directions is a resolution cycle.
            return app(EventModeGuard::class)->isEventMode();
        } catch (Throwable) {
            // A boot before the database exists still has to answer this, and
            // file config is the boot layer (technical spec 7.3).
            $role = trim((string) config('meridian.node.role', ''));

            return $role !== '' && $role !== Node::ROLE_DEVELOPMENT;
        }
    }

    private function evaluateRequirement(SecretRequirement $requirement): SecretStatus
    {
        $value = trim((string) config($requirement->configKey, ''));

        if ($value === '') {
            return $requirement->required
                ? SecretStatus::missing($requirement)
                : SecretStatus::ok($requirement);
        }

        return $requirement->isDefaultValue($value)
            ? SecretStatus::default($requirement)
            : SecretStatus::ok($requirement);
    }

    private function consoleCommand(): string
    {
        $argv = $_SERVER['argv'] ?? [];

        return is_array($argv) && isset($argv[1]) && is_string($argv[1]) ? $argv[1] : '';
    }
}
