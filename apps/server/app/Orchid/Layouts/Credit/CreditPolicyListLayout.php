<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Credit;

use App\Models\CreditPolicy;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class CreditPolicyListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'creditPolicies';

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
                ->render(fn (CreditPolicy $policy) => Link::make($policy->name)
                    ->route('platform.credit-policies.edit', $policy->id)),

            TD::make('organization.name', __('Organization'))
                ->render(fn (CreditPolicy $policy) => $policy->organization?->name ?? __('Not configured')),

            TD::make('credit_multiplier', __('Credits per hour'))
                ->sort()
                ->align(TD::ALIGN_RIGHT)
                ->render(fn (CreditPolicy $policy) => (string) $policy->credit_multiplier),

            TD::make('is_default', __('Default'))
                ->render(fn (CreditPolicy $policy) => (string) $policy->organization?->default_credit_policy_id === (string) $policy->getKey()
                    ? __('Organization default')
                    : ''),

            TD::make('archived_at', __('State'))
                ->sort()
                ->render(fn (CreditPolicy $policy) => $policy->archived_at !== null
                    ? __('Archived')
                    : __('Active')),

            // Archiving a policy is not a quiet no-op when shifts name it.
            TD::make('shifts_count', __('Shifts'))
                ->align(TD::ALIGN_RIGHT)
                ->render(fn (CreditPolicy $policy) => (int) $policy->shifts_count),

            TD::make('created_at', __('Added'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),
        ];
    }
}
