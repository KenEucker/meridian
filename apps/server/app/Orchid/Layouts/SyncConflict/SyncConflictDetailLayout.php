<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\SyncConflict;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class SyncConflictDetailLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('conflict.entity_type')
                ->title(__('Entity type'))
                ->readonly(),

            Input::make('conflict.entity_id')
                ->title(__('Entity ID'))
                ->readonly(),

            Input::make('conflict.conflict_type')
                ->title(__('Conflict type'))
                ->readonly(),

            Input::make('status_label')
                ->title(__('Status'))
                ->readonly(),

            Input::make('resolution_label')
                ->title(__('Resolution'))
                ->readonly()
                ->help(__('Resolution keeps one of the two versions shown below. Values are never edited here.')),

            Input::make('default_resolution_label')
                ->title(__('Recommended default'))
                ->readonly()
                ->help(__('Event-scoped records during an active event window default to accept on-site; central and global records default to accept central. The reviewer still chooses.')),

            TextArea::make('conflict.reason')
                ->title(__('Reason'))
                ->rows(3)
                ->readonly(),

            Input::make('operation_uuid')
                ->title(__('Operation UUID'))
                ->readonly(),

            Input::make('operation_type')
                ->title(__('Operation type'))
                ->readonly(),

            Input::make('operation_status')
                ->title(__('Operation status'))
                ->readonly()
                ->help(__('An operation stays conflicted when the local version was kept, because it was received and never applied.')),

            Input::make('origin_node')
                ->title(__('Origin node'))
                ->readonly(),

            Input::make('created_at_display')
                ->title(__('Created'))
                ->readonly(),

            Input::make('reviewed_by_display')
                ->title(__('Reviewed by'))
                ->readonly(),

            Input::make('reviewed_at_display')
                ->title(__('Reviewed at'))
                ->readonly(),
        ];
    }
}
