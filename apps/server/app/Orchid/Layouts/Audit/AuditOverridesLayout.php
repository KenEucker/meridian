<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Audit;

use App\Domain\Audit\AuditActionCatalog;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

/**
 * The per-action exceptions to the level.
 *
 * Two multi-selects rather than a switch per action. There are around ninety
 * catalogued actions and an operator setting exceptions has two or three in
 * mind; a grid of ninety switches would make them hunt for those three, and
 * would present "leave it to the level" — much the commonest answer — as
 * something you have to actively choose ninety times.
 *
 * Only non-required actions are offered. A required action cannot be switched
 * off, so listing it here would be offering a control that does nothing.
 */
class AuditOverridesLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        $options = $this->options();

        return [
            Select::make('audit.always')
                ->options($options)
                ->multiple()
                ->title(__('Always record'))
                ->help(__('Recorded even when the level would omit them.')),

            Select::make('audit.never')
                ->options($options)
                ->multiple()
                ->title(__('Never record'))
                ->help(__('Omitted even when the level would include them. Required entries cannot be listed here and are not offered.')),
        ];
    }

    /**
     * The catalogued, non-required actions, labelled with the level each first
     * appears at so an operator can see what they are overriding.
     *
     * @return array<string, string>
     */
    private function options(): array
    {
        $options = [];

        foreach (AuditActionCatalog::inventory() as $entry) {
            if ($entry['required']) {
                continue;
            }

            $options[$entry['action']] = sprintf('%s (%s)', $entry['action'], (string) $entry['level']);
        }

        return $options;
    }
}
