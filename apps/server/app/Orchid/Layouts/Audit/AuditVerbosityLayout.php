<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Audit;

use App\Domain\Audit\AuditVerbosity;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

/**
 * The five-step level, and what each step means.
 *
 * A `Select` rather than a slider control, deliberately. The five levels are
 * ordered and a slider would say so, but a slider cannot show what each stop
 * *contains* — and the difference between Standard and Detailed is entirely a
 * question of what it contains. The options are ordered least to most, so the
 * ordering is still visible; the descriptions below are what make the choice
 * possible. {@see AuditVerbosity::description()} is the source of both.
 */
class AuditVerbosityLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        $options = [];

        foreach (AuditVerbosity::options() as $option) {
            $options[$option['value']] = sprintf('%d — %s', $option['rank'] + 1, $option['label']);
        }

        return [
            Select::make('audit.verbosity')
                ->options($options)
                ->title(__('Level'))
                ->help(__('Least to most. Every level includes everything below it, plus the required entries.')),

            TextArea::make('verbosity_help')
                ->title(__('What each level adds'))
                ->rows(6)
                ->readonly(),

            TextArea::make('required_actions')
                ->title(__('Always recorded'))
                ->rows(4)
                ->readonly()
                ->help(__('Required by requirements 2.4 and data/API section 8. No level or exception removes these.')),
        ];
    }
}
