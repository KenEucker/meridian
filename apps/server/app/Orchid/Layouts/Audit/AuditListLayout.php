<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Audit;

use App\Models\AuditEvent;
use Illuminate\Support\Str;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

/**
 * The God Mode audit trail (M18.34; requirements 2.4; data/API 14.1).
 *
 * Ordered newest first by the screen, because the question an operator opens
 * this with is almost always "what just happened".
 *
 * The actor and record-type columns read from {@see AuditEvent::describeActor()}
 * and {@see AuditEvent::describeEntityType()}, which the product audit surface
 * uses too — so a scheduled job is called the same thing on both sides of the
 * God Mode boundary, and neither has its own idea of what
 * `App\Models\StaffOrganizationStatus` is called.
 */
class AuditListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'entries';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('created_at', __('When'))
                ->usingComponent(DateTimeSplit::class)
                ->sort()
                ->defaultHidden(false)
                ->cantHide(),

            TD::make('action', __('Action'))
                ->sort()
                ->filter(Input::make())
                ->cantHide()
                ->render(fn (AuditEvent $entry) => Link::make($entry->action)
                    ->route('platform.audit.show', $entry->id)),

            TD::make('entity_type', __('Record'))
                ->sort()
                ->filter(Input::make())
                ->render(fn (AuditEvent $entry) => e($entry->describeEntityType())),

            TD::make('actor', __('By'))
                ->render(fn (AuditEvent $entry) => e($entry->describeActor())),

            TD::make('organization', __('Organization'))
                ->render(fn (AuditEvent $entry) => e($entry->organization?->name ?? '—')),

            TD::make('department', __('Department'))
                ->render(fn (AuditEvent $entry) => e($entry->department?->name ?? '—')),

            TD::make('reason', __('Reason'))
                ->render(fn (AuditEvent $entry) => e(Str::limit((string) $entry->reason, 60))),

            TD::make('source_context', __('Source'))
                ->sort()
                ->align(TD::ALIGN_RIGHT),
        ];
    }

}
