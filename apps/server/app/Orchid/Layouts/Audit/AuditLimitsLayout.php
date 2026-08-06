<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Audit;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

/**
 * How much audit history this organization keeps.
 *
 * The three limits are evaluated together and the union of what each condemns
 * is archived, so an organization can bound age and volume at once without one
 * rule quietly overriding the other.
 *
 * The measured figures are shown above the inputs rather than below, because a
 * limit typed without them is a guess. "Estimated" is the honest word for the
 * size: it measures the content an operator can influence — the payloads, the
 * reasons — and not the table's footprint on disk, which includes indexes and
 * is one number for every organization sharing the table.
 */
class AuditLimitsLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('usage_rows')
                ->title(__('Entries stored now'))
                ->readonly(),

            Input::make('usage_oldest')
                ->title(__('Oldest entry'))
                ->readonly(),

            Input::make('audit.max_rows')
                ->type('number')
                ->min(1)
                ->title(__('Maximum entries'))
                ->help(__('Empty for no limit. The oldest entries past this are archived.')),

            Input::make('audit.max_bytes')
                ->type('number')
                ->min(1)
                ->title(__('Maximum estimated size, in bytes'))
                ->help(__('Empty for no limit. Measured from the recorded payloads and reasons, not from the table on disk.')),

            Input::make('audit.retention_days')
                ->type('number')
                ->min(1)
                ->title(__('Keep for, in days'))
                ->help(__('Empty to keep indefinitely. Entries older than this are archived.')),
        ];
    }
}
