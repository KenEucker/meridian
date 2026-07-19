<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Equipment;

use App\Models\Department;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\Organization;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

class EquipmentEditLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Select::make('equipmentItem.organization_id')
                ->fromModel(Organization::class, 'name')
                ->required()
                ->title(__('Organization'))
                ->help(__('Equipment belongs to one organization. Event and department scope are optional.')),

            Select::make('equipmentItem.event_id')
                ->fromModel(Event::class, 'name')
                ->empty(__('All events'), '')
                ->title(__('Event')),

            Select::make('equipmentItem.department_id')
                ->fromModel(Department::class, 'name')
                ->empty(__('All departments'), '')
                ->title(__('Department')),

            Input::make('equipmentItem.name')
                ->type('text')
                ->max(255)
                ->required()
                ->title(__('Name'))
                ->placeholder(__('Radio 14')),

            Input::make('equipmentItem.asset_tag')
                ->type('text')
                ->max(255)
                ->title(__('Asset tag'))
                ->placeholder(__('RDO-14')),

            Input::make('equipmentItem.serial_number')
                ->type('text')
                ->max(255)
                ->title(__('Serial number')),

            Select::make('equipmentItem.status')
                ->options(EquipmentItem::statusLabels())
                ->required()
                ->title(__('Status')),
        ];
    }
}
