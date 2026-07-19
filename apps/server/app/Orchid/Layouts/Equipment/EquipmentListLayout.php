<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Equipment;

use App\Models\EquipmentItem;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class EquipmentListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'equipmentItems';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('name', __('Name'))
                ->sort()
                ->cantHide()
                ->filter(Input::make())
                ->render(fn (EquipmentItem $equipmentItem) => Link::make($equipmentItem->name)
                    ->route('platform.equipment.edit', $equipmentItem->id)),

            TD::make('asset_tag', __('Asset tag'))
                ->sort()
                ->filter(Input::make())
                ->render(fn (EquipmentItem $equipmentItem) => $equipmentItem->asset_tag ?? __('Not set')),

            TD::make('status', __('Status'))
                ->sort()
                ->filter(Input::make())
                ->render(fn (EquipmentItem $equipmentItem) => EquipmentItem::statusLabel($equipmentItem->status)),

            TD::make('organization.name', __('Organization'))
                ->render(fn (EquipmentItem $equipmentItem) => $equipmentItem->organization?->name ?? __('Not configured')),

            TD::make('event.name', __('Event'))
                ->render(fn (EquipmentItem $equipmentItem) => $equipmentItem->event?->name ?? __('All events')),

            TD::make('department.name', __('Department'))
                ->render(fn (EquipmentItem $equipmentItem) => $equipmentItem->department?->name ?? __('All departments')),

            TD::make('archived_at', __('Archived'))
                ->render(fn (EquipmentItem $equipmentItem) => $equipmentItem->isArchived() ? __('Archived') : __('Active')),

            TD::make('updated_at', __('Last edit'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),
        ];
    }
}
