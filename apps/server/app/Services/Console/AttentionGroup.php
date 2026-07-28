<?php

declare(strict_types=1);

namespace App\Services\Console;

/**
 * One of the three groups the God Mode landing screen surfaces attention items
 * in (GOD-005; technical spec 22.5.2).
 *
 * The grouping is fixed rather than derived, because the three groups fail for
 * different reasons and are fixed by different people: a deployment problem is
 * for whoever runs the node, a data gap is for whoever configures the
 * organization, and a conflict is for whoever reviews sync.
 */
final class AttentionGroup
{
    public const CONFIGURATION = 'configuration';

    public const DATA_GAPS = 'data_gaps';

    public const SYNC_CONFLICTS = 'sync_conflicts';

    /**
     * @param  list<AttentionItem>  $items
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly array $items,
    ) {}

    public function hasItems(): bool
    {
        return $this->items !== [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return array{key: string, label: string, description: string, count: int, items: list<array<string, string>>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'count' => $this->count(),
            'items' => array_map(
                static fn (AttentionItem $item): array => $item->toArray(),
                $this->items,
            ),
        ];
    }
}
