<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Organization;

use App\Services\Modules\ModuleState;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

/**
 * One entitlement control per catalogue module, plus the reason (MOD-006,
 * MOD-011, MOD-021).
 *
 * Every module in the catalogue gets a row, always. MOD-021 makes that the
 * central console's rule rather than a styling preference: this is the surface
 * an operator uses to turn a module back on, so a module that is off is
 * precisely the one that must not disappear from it.
 *
 * Only entitlement is editable. Enablement is the organization's decision
 * (MOD-008) and is shown beside it as context, because "not entitled" and "the
 * organization turned it off" produce the same absence for a member and
 * different work for an operator — one of them is fixed by the control on this
 * screen and the other is not.
 *
 * The reason is one field for the whole submission rather than one per module.
 * A narrowing is a decision about an organization, taken once, and eight boxes
 * asking for it eight times would collect one sentence and seven blanks.
 */
class OrganizationModuleEntitlementLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->moduleState() as $state) {
            $fields[] = CheckBox::make('modules.'.$state->key().'.entitled')
                ->sendTrueOrFalse()
                ->title($state->label())
                ->placeholder(__('Entitled — the platform makes this module available to this organization'))
                ->help($state->statusLabel().' '.$state->entitlementHistory())
                ->disabled(! $this->isEditable());
        }

        $fields[] = TextArea::make('reason')
            ->rows(3)
            ->maxlength(1000)
            ->title(__('Reason'))
            ->help(__(
                'Optional, and recorded with the change. Every entitlement transition is audited with its previous '
                .'and new state and the operator who made it whether or not a reason is given; this is the part only '
                .'a person can supply.'
            ))
            ->disabled(! $this->isEditable());

        return $fields;
    }

    /**
     * @return list<ModuleState>
     */
    private function moduleState(): array
    {
        $state = $this->query->get('module_state');

        return is_array($state) ? array_values(array_filter(
            $state,
            static fn (mixed $entry): bool => $entry instanceof ModuleState,
        )) : [];
    }

    private function isEditable(): bool
    {
        return (bool) $this->query->get('editable', true);
    }
}
