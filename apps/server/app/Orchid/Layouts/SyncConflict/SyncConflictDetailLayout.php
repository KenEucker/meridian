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
                ->help(__('Choosing a side to keep is delivered by the conflict resolver task.')),

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
