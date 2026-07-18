<?php

namespace App\Services\EventMode;

/**
 * Result of a single event-mode fail-closed check (technical spec 8.6, 26.2).
 *
 * The server owns the HTTPS and PowerSync checks. Each check fails closed:
 * when it does not pass, {@see EventModeGuard} treats event mode as blocked
 * and exposes {@see $reason} so the failure is human-readable.
 */
final class EventModeCheck
{
    public const HTTPS = 'https';

    public const POWERSYNC = 'powersync';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $passed,
        public readonly ?string $reason = null,
    ) {}

    /**
     * @return array{key: string, label: string, passed: bool, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'passed' => $this->passed,
            'reason' => $this->reason,
        ];
    }
}
