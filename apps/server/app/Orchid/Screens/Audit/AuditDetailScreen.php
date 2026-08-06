<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Audit;

use App\Models\AuditEvent;
use App\Orchid\Layouts\Audit\AuditDetailLayout;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

/**
 * One audit entry, in full (M18.34; requirements 2.4; data/API 14.1; UI
 * contract 12.9).
 *
 * The list answers "what happened"; this answers "what exactly changed". It is
 * the reason the God Mode trail exists separately from the product surface: an
 * operator asked why a record looks the way it does needs the values, and
 * `organizer.audit` deliberately does not carry them.
 *
 * There is no command bar beyond going back. An audit entry is immutable at the
 * model, so a screen offering an edit would be offering an exception the
 * database does not have.
 */
class AuditDetailScreen extends Screen
{
    /**
     * @var AuditEvent
     */
    public $entry;

    /**
     * @return array<string, mixed>
     */
    public function query(AuditEvent $entry): iterable
    {
        $entry->load(['actorUser', 'actorDevice', 'actorNode', 'organization', 'event', 'department']);

        return [
            'entry' => $entry,
            'recorded_at_display' => $entry->created_at?->toDayDateTimeString() ?? __('Unknown'),
            'actor_display' => $entry->describeActor(),
            'actor_user_display' => $entry->actorUser?->name ?? __('None recorded'),
            'actor_device_display' => $entry->actorDevice?->device_label
                ?? ($entry->actor_device_id !== null ? (string) $entry->actor_device_id : __('None recorded')),
            'actor_node_display' => $entry->actorNode?->node_name
                ?? ($entry->actor_node_id !== null ? (string) $entry->actor_node_id : __('None recorded')),
            'organization_display' => $entry->organization?->name ?? __('Not organization-scoped'),
            'event_display' => $entry->event?->name ?? __('Not event-scoped'),
            'department_display' => $entry->department?->name ?? __('Not department-scoped'),
            'before_display' => $this->formatJson($entry->before_json),
            'after_display' => $this->formatJson($entry->after_json),
            'signature_display' => $this->formatJson($entry->signature_metadata_json),
        ];
    }

    public function name(): ?string
    {
        return 'Audit Entry';
    }

    public function description(): ?string
    {
        return 'One recorded change, with the values as the writing path snapshotted them. Audit entries are append-only and cannot be edited or removed.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.audit',
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Back to the trail'))
                ->icon('bs.arrow-left')
                ->route('platform.audit'),
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            AuditDetailLayout::class,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    private function formatJson(?array $value): string
    {
        if ($value === null || $value === []) {
            return __('None recorded');
        }

        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
