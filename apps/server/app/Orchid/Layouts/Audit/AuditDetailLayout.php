<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Audit;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

/**
 * One audit entry, in full (M18.34; requirements 2.4; data/API 14.1).
 *
 * Every field is read-only, and not as a precaution: {@see \App\Models\AuditEvent}
 * throws on update and delete, so there is nothing a writable control here
 * could accomplish except a confusing error.
 *
 * This is where the recorded values live. The product surface at
 * `organizer.audit` names the fields that changed and withholds their values,
 * because an audit payload is a verbatim copy of whatever the writing path
 * snapshotted and serving it there would make an organizer's page an unscoped
 * read of every field every audited path records. God Mode is where that
 * boundary is deliberately lifted, behind `platform.audit`.
 */
class AuditDetailLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('entry.action')
                ->title(__('Action'))
                ->readonly(),

            Input::make('entry.entity_type')
                ->title(__('Record type'))
                ->readonly(),

            Input::make('entry.entity_id')
                ->title(__('Record'))
                ->readonly(),

            Input::make('recorded_at_display')
                ->title(__('When'))
                ->readonly(),

            Input::make('actor_display')
                ->title(__('By'))
                ->readonly()
                ->help(__('A row with no user behind it was written by a scheduled job, a device, or a node; the identifiers below say which.')),

            Input::make('actor_user_display')
                ->title(__('Actor user'))
                ->readonly(),

            Input::make('actor_device_display')
                ->title(__('Actor device'))
                ->readonly(),

            Input::make('actor_node_display')
                ->title(__('Actor node'))
                ->readonly(),

            Input::make('organization_display')
                ->title(__('Organization'))
                ->readonly(),

            Input::make('event_display')
                ->title(__('Event'))
                ->readonly(),

            Input::make('department_display')
                ->title(__('Department'))
                ->readonly(),

            Input::make('entry.source_context')
                ->title(__('Recorded from'))
                ->readonly(),

            TextArea::make('entry.reason')
                ->title(__('Reason'))
                ->rows(3)
                ->readonly(),

            TextArea::make('before_display')
                ->title(__('Before'))
                ->rows(12)
                ->readonly()
                ->help(__('The values as the writing path snapshotted them. A creation has no before.')),

            TextArea::make('after_display')
                ->title(__('After'))
                ->rows(12)
                ->readonly(),

            TextArea::make('signature_display')
                ->title(__('Signature metadata'))
                ->rows(6)
                ->readonly()
                ->help(__('Present where the operation behind this entry was signed by a device or a node.')),
        ];
    }
}
