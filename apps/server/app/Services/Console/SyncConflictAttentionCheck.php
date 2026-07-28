<?php

declare(strict_types=1);

namespace App\Services\Console;

use App\Models\SyncConflict;

/**
 * Unresolved sync conflicts for the God Mode landing screen (GOD-008; technical
 * spec 22.5.2, 10.3).
 *
 * One item, not one per conflict. Unresolved conflicts do not block unrelated
 * sync (technical spec 10.3), so what the landing screen owes an operator is
 * the outstanding count and the way into the queue — the queue itself is where
 * conflicts are read and resolved.
 */
final class SyncConflictAttentionCheck
{
    public const UNRESOLVED = 'sync_conflicts.unresolved';

    public function group(): AttentionGroup
    {
        return new AttentionGroup(
            key: AttentionGroup::SYNC_CONFLICTS,
            label: 'Unresolved sync conflicts',
            description: 'Node operations that could not be applied and are waiting for a God Mode decision.',
            items: $this->items(),
        );
    }

    /**
     * @return list<AttentionItem>
     */
    public function items(): array
    {
        $open = SyncConflict::query()
            ->where('status', SyncConflict::STATUS_OPEN)
            ->count();

        if ($open === 0) {
            return [];
        }

        return [
            new AttentionItem(
                key: self::UNRESOLVED,
                label: trans_choice(
                    '{1}One unresolved sync conflict|[2,*]:count unresolved sync conflicts',
                    $open,
                    ['count' => $open],
                ),
                detail: 'Each conflict holds an operation that local and remote state disagree about. Unrelated sync continues meanwhile, so these wait until somebody chooses a version.',
                resolveRoute: 'platform.sync-conflicts',
                resolveLabel: 'Sync Conflicts',
            ),
        ];
    }
}
