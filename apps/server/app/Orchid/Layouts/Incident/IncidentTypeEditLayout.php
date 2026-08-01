<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Incident;

use App\Models\Organization;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Relation;
use Orchid\Screen\Layouts\Rows;

class IncidentTypeEditLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Relation::make('incidentType.organization_id')
                ->title(__('Organization'))
                ->fromModel(Organization::class, 'name')
                ->required()
                // An incident type belongs to one organization for its whole
                // life; moving it would move every incident that carries it.
                ->canSee(! $this->query->get('incidentType')->exists),

            Input::make('incidentType.name')
                ->title(__('Name'))
                ->maxlength(100)
                ->required()
                ->help(__('Renaming re-labels this type on every incident that already carries it.')),
        ];
    }
}
