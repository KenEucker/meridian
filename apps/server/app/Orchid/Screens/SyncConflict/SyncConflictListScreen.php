<?php

declare(strict_types=1);

namespace App\Orchid\Screens\SyncConflict;

use App\Models\SyncConflict;
use App\Orchid\Layouts\SyncConflict\SyncConflictListLayout;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

/**
 * God-mode sync conflict queue (technical spec 10.3; data/API 14.2; UI
 * contract `orchid.sync-conflicts`).
 *
 * Conflicts are visible only in God Mode for Alpha 1. The list is ordered by
 * entity type so reviewers can work through one entity class at a time, then
 * by creation time. Accept on-site / accept central resolution is M12.9.
 */
class SyncConflictListScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'conflicts' => SyncConflict::query()
                ->with('operation')
                ->filters()
                ->defaultSort('entity_type')
                ->orderByDesc('created_at')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Sync Conflicts';
    }

    public function description(): ?string
    {
        return 'God-mode queue of node operations that could not be safely applied. Unresolved conflicts do not block unrelated sync.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.sync-conflicts',
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            SyncConflictListLayout::class,
        ];
    }
}
