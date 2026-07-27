<?php

declare(strict_types=1);

namespace App\Orchid\Screens\SyncConflict;

use App\Models\SyncConflict;
use App\Models\User;
use App\Orchid\Layouts\SyncConflict\SyncConflictDetailLayout;
use App\Services\Node\SyncConflictResolutionException;
use App\Services\Node\SyncConflictResolver;
use Illuminate\Http\RedirectResponse;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

/**
 * God-mode sync conflict review and resolution (technical spec 10.3; data/API
 * 7.5, 14.2; UI contract `orchid.sync-conflicts`).
 *
 * Shows local and remote values side by side and offers the two choices
 * technical spec 10.3 allows: accept on-site or accept central. There is no
 * third action, because conflicts are not manually corrected inside the
 * resolver, and the values shown here are read-only for the same reason. The
 * recommended default is displayed but never applied automatically — the
 * specification has a human choose.
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
            'default_resolution_label' => $this->defaultResolutionLabel($conflict),
            'operation_uuid' => $conflict->operation?->uuid ?? __('Unknown'),
            'operation_type' => $conflict->operation?->operation_type ?? __('Unknown'),
            'operation_status' => $conflict->operation?->status ?? __('Unknown'),
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
        return 'Compare local and remote values for a conflicted node operation, then keep the on-site or the central version. Resolution is audited.';
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
        $canResolve = $this->conflict?->isOpen() === true;

        return [
            Link::make(__('Back to queue'))
                ->icon('bs.arrow-left-circle')
                ->route('platform.sync-conflicts'),

            Button::make(__('Accept on-site'))
                ->icon('bs.hdd-network')
                ->method('acceptOnsite')
                ->confirm(__('Keep the on-site node version of this record and discard the other version? This is audited and cannot be undone here.'))
                ->canSee($canResolve),

            Button::make(__('Accept central'))
                ->icon('bs.cloud')
                ->method('acceptCentral')
                ->confirm(__('Keep the central node version of this record and discard the other version? This is audited and cannot be undone here.'))
                ->canSee($canResolve),
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

    public function acceptOnsite(SyncConflict $conflict): RedirectResponse
    {
        return $this->resolve($conflict, SyncConflict::RESOLUTION_ACCEPT_ONSITE);
    }

    public function acceptCentral(SyncConflict $conflict): RedirectResponse
    {
        return $this->resolve($conflict, SyncConflict::RESOLUTION_ACCEPT_CENTRAL);
    }

    private function resolve(SyncConflict $conflict, string $resolution): RedirectResponse
    {
        $user = request()->user();

        abort_unless($user instanceof User, 403);
        abort_unless($user->hasAccess('platform.sync-conflicts'), 403);

        try {
            app(SyncConflictResolver::class)->resolve($conflict, $resolution, $user);

            Toast::info(__('Sync conflict resolved as ":resolution".', [
                'resolution' => SyncConflict::resolutionLabels()[$resolution] ?? $resolution,
            ]));
        } catch (SyncConflictResolutionException $exception) {
            Toast::warning(__($exception->getMessage()));
        }

        return redirect()->route('platform.sync-conflicts.show', $conflict);
    }

    private function defaultResolutionLabel(SyncConflict $conflict): string
    {
        if (! $conflict->isOpen()) {
            return __('Already resolved');
        }

        $default = app(SyncConflictResolver::class)->defaultResolutionFor($conflict);

        return SyncConflict::resolutionLabels()[$default] ?? $default;
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
