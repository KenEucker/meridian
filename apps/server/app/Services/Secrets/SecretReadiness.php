<?php

declare(strict_types=1);

namespace App\Services\Secrets;

/**
 * Aggregated secret evaluation for one node (technical spec 26.2).
 *
 * A node is blocked when any applicable secret is missing or is still a sample
 * value. Development nodes are evaluated the same way and refused nothing: the
 * decision about whether a failure blocks belongs to {@see SecretSafeguard},
 * which knows what mode the node is in, so this stays a plain report that the
 * God Mode attention list and diagnostics can render without applying a second
 * policy of their own.
 */
final class SecretReadiness
{
    /**
     * @param  list<SecretStatus>  $statuses
     */
    public function __construct(public readonly array $statuses) {}

    /**
     * @return list<SecretStatus>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->statuses,
            static fn (SecretStatus $status): bool => $status->failed(),
        ));
    }

    public function blocked(): bool
    {
        return $this->failures() !== [];
    }

    /**
     * The variable names that failed, in evaluation order. Names only; no
     * value ever leaves this namespace.
     *
     * @return list<string>
     */
    public function failedNames(): array
    {
        return array_map(
            static fn (SecretStatus $status): string => $status->requirement->name,
            $this->failures(),
        );
    }

    /**
     * @return list<string>
     */
    public function reasons(): array
    {
        return array_values(array_filter(array_map(
            static fn (SecretStatus $status): ?string => $status->reason,
            $this->failures(),
        )));
    }

    /**
     * @return array{blocked: bool, secrets: list<array{name: string, label: string, state: string, generatable: bool, reason: string|null}>, reasons: list<string>}
     */
    public function toArray(): array
    {
        return [
            'blocked' => $this->blocked(),
            'secrets' => array_map(
                static fn (SecretStatus $status): array => $status->toArray(),
                $this->statuses,
            ),
            'reasons' => $this->reasons(),
        ];
    }
}
