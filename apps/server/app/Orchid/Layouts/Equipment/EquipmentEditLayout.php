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

            Select::make('equipmentItem.tracking')
                ->options(EquipmentItem::trackingLabels())
                ->required()
                ->title(__('Tracking'))
                ->help(__('Tracked equipment is one unit per record with an identifier on it. Pooled equipment is a quantity of interchangeable units and carries no asset tag or serial number.')),

            Input::make('equipmentItem.asset_tag')
                ->type('text')
                ->max(255)
                ->title(__('Asset tag'))
                ->placeholder(__('RDO-14'))
                ->help(__('Tracked equipment only.')),

            Input::make('equipmentItem.serial_number')
                ->type('text')
                ->max(255)
                ->title(__('Serial number'))
                ->help(__('Tracked equipment only.')),

            Input::make('equipmentItem.quantity_total')
                ->type('number')
                ->min(0)
                ->title(__('Pool quantity'))
                ->help(__('The serviceable total for a pooled kind. Tracked equipment is always one. Availability is this total less the units currently checked out.')),

            Select::make('equipmentItem.status')
                ->options(EquipmentItem::statusLabels())
                ->required()
                ->title(__('Status')),
        ];
    }
}
