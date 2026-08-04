<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Credit;

use App\Models\CreditPolicy;
use App\Models\Organization;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

class CreditPolicyEditLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        $policy = $this->query->get('creditPolicy');
        $exists = $policy instanceof CreditPolicy && $policy->exists;

        return [
            Select::make('creditPolicy.organization_id')
                ->fromModel(Organization::class, 'name')
                ->required()
                ->disabled($exists)
                ->title(__('Organization'))
                ->help(__('A policy prices one organization\'s work and cannot move between them.')),

            Input::make('creditPolicy.name')
                ->type('text')
                ->max(100)
                ->required()
                ->title(__('Name'))
                ->placeholder(__('Standard Hour')),

            Input::make('creditPolicy.credit_multiplier')
                ->type('number')
                ->min(0)
                ->max(1000)
                ->step(0.001)
                ->required()
                ->title(__('Credits per hour'))
                ->help(__('Rates below one are the ordinary pre/post-event shape; zero is a deliberate price. Frozen ledger entries keep the rate they were calculated at, so re-rating changes only future runs.')),
        ];
    }
}
