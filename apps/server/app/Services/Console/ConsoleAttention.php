<?php

declare(strict_types=1);

namespace App\Services\Console;

/**
 * The God Mode landing screen's attention list (GOD-005, GOD-010, GOD-011;
 * technical spec 22.5.2).
 *
 * Three groups, always in the same order, always evaluated at view time. Order
 * is fixed deliberately: a node that is not configured makes every data gap
 * below it unreadable, so configuration is read first.
 *
 * Every check reads. None writes. The landing screen reports what is true right
 * now and repairs nothing as a side effect of being looked at (GOD-010).
 */
final class ConsoleAttention
{
    public function __construct(
        private readonly ConfigurationReadinessCheck $configuration,
        private readonly OrganizationalDataGapCheck $dataGaps,
        private readonly SyncConflictAttentionCheck $syncConflicts,
    ) {}

    /**
     * @return list<AttentionGroup>
     */
    public function groups(): array
    {
        return [
            $this->configuration->group(),
            $this->dataGaps->group(),
            $this->syncConflicts->group(),
        ];
    }

    /**
     * @return array{groups: list<array<string, mixed>>, count: int, all_clear: bool}
     */
    public function describe(): array
    {
        $groups = $this->groups();

        $count = array_sum(array_map(
            static fn (AttentionGroup $group): int => $group->count(),
            $groups,
        ));

        return [
            // Empty groups are dropped rather than rendered as headings with
            // nothing under them (GOD-011).
            'groups' => array_values(array_map(
                static fn (AttentionGroup $group): array => $group->toArray(),
                array_filter(
                    $groups,
                    static fn (AttentionGroup $group): bool => $group->hasItems(),
                ),
            )),
            'count' => $count,
            'all_clear' => $count === 0,
        ];
    }
}
