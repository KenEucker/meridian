<?php

namespace App\Services\EventMode;

/**
 * Aggregated event-mode fail-closed evaluation (technical spec 8.6, 26.2).
 *
 * When {@see $eventMode} is false the node is in development mode and is never
 * blocked. In event mode the node is blocked when any evaluated check fails, so
 * a node without browser-trusted HTTPS or a reachable PowerSync service fails
 * closed rather than starting insecurely.
 */
final class EventModeReadiness
{
    /**
     * @param  list<EventModeCheck>  $checks
     */
    public function __construct(
        public readonly bool $eventMode,
        public readonly array $checks,
    ) {}

    /**
     * Whether event mode is allowed: development mode is always allowed, and
     * event mode is allowed only when every evaluated check passes.
     */
    public function passed(): bool
    {
        if (! $this->eventMode) {
            return true;
        }

        foreach ($this->checks as $check) {
            if (! $check->passed) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether event mode must fail closed because a required check failed.
     */
    public function blocked(): bool
    {
        return ! $this->passed();
    }

    /**
     * @return list<EventModeCheck>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (EventModeCheck $check): bool => ! $check->passed,
        ));
    }

    /**
     * Human-readable reasons for each failed check, in evaluation order.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        return array_values(array_filter(array_map(
            static fn (EventModeCheck $check): ?string => $check->reason,
            $this->failures(),
        )));
    }

    /**
     * @return array{event_mode: bool, blocked: bool, checks: list<array{key: string, label: string, passed: bool, reason: string|null}>, reasons: list<string>}
     */
    public function toArray(): array
    {
        return [
            'event_mode' => $this->eventMode,
            'blocked' => $this->blocked(),
            'checks' => array_map(
                static fn (EventModeCheck $check): array => $check->toArray(),
                $this->checks,
            ),
            'reasons' => $this->reasons(),
        ];
    }
}
