<?php

declare(strict_types=1);

namespace App\Orchid\Screens\SyncConflict;

use App\Models\SyncConflict;
use App\Orchid\Layouts\SyncConflict\SyncConflictDetailLayout;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

/**
 * Read-only God-mode sync conflict review (technical spec 10.3; data/API
 * 14.2; UI contract `orchid.sync-conflicts`).
 *
 * Shows local and remote values side by side. Accept on-site / accept central
 * actions and their audit trail are M12.9; this screen does not mutate the
 * conflict or the underlying entity.
 */
class SyncConflictDetailScreen extends Screen
{
    /**
     * @var SyncConflict
     */
    public $conflict;

    /**
     * @return array<string, mixed>
     */
    public function query(SyncConflict $conflict): iterable
    {
        $conflict->load(['operation.originNode', 'operation.targetNode', 'reviewedBy']);

        return [
            'conflict' => $conflict,
            'status_label' => $conflict->statusLabel(),
            'resolution_label' => $conflict->resolutionLabel(),
            'operation_uuid' => $conflict->operation?->uuid ?? __('Unknown'),
            'operation_type' => $conflict->operation?->operation_type ?? __('Unknown'),
            'origin_node' => $conflict->operation?->originNode?->node_name ?? __('Unknown'),
            'reviewed_by_display' => $conflict->reviewedBy?->name ?? __('Not reviewed'),
            'reviewed_at_display' => $conflict->reviewed_at?->toDayDateTimeString() ?? __('Not reviewed'),
            'created_at_display' => $conflict->created_at?->toDayDateTimeString() ?? __('Unknown'),
            'local_value_display' => $this->formatJson($conflict->local_value_json),
            'remote_value_display' => $this->formatJson($conflict->remote_value_json),
            'operation_payload_display' => $this->formatJson($conflict->operation?->payload_json),
        ];
    }

    public function name(): ?string
    {
        return 'Review Sync Conflict';
    }

    public function description(): ?string
    {
        return 'Compare local and remote values for a conflicted node operation. Resolution actions arrive with the conflict resolver.';
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
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Back to queue'))
                ->icon('bs.arrow-left-circle')
                ->route('platform.sync-conflicts'),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            SyncConflictDetailLayout::class,
            Layout::view('orchid.sync-conflict-values'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    private function formatJson(?array $value): string
    {
        if ($value === null) {
            return __('None');
        }

        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : __('None');
    }
}
