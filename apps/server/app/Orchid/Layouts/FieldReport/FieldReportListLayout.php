<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\FieldReport;

use App\Models\FieldReport;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

/**
 * Field Report repair visibility (M18.34; UI contract 12.9).
 *
 * Newest first, because the report an operator is asked about is almost always
 * a recent one.
 *
 * No body column. The list answers "which report", the entry answers "what it
 * says", and a truncated account of an incident in a table row is the worst of
 * both — long enough to be read over a shoulder, short enough to be read wrong.
 */
class FieldReportListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'reports';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('created_at', __('Received'))
                ->usingComponent(DateTimeSplit::class)
                ->sort()
                ->defaultHidden(false)
                ->cantHide(),

            TD::make('fra_number', __('FRA'))
                ->sort()
                ->filter(Input::make())
                ->cantHide()
                ->render(fn (FieldReport $report) => Link::make(
                    $report->fra_number ?? $report->temporary_local_number ?? __('Unnumbered'),
                )->route('platform.field-reports.show', $report->id)),

            TD::make('title', __('Title'))
                ->sort()
                ->filter(Input::make()),

            TD::make('event', __('Event'))
                ->render(fn (FieldReport $report) => e($report->event?->name ?? '—')),

            TD::make('department', __('Department'))
                ->render(fn (FieldReport $report) => e($report->department?->name ?? '—')),

            TD::make('author', __('Author'))
                ->render(fn (FieldReport $report) => e($report->staff?->displayName() ?? '—')),

            // Named separately from the author, because on a report taken over
            // a radio they are two different people and FR-016 turns on which
            // is which (M18.24A).
            TD::make('submitted_by', __('Taken by'))
                ->render(fn (FieldReport $report) => e(
                    $report->wasTakenOnBehalf()
                        ? ($report->submittedByUser?->name ?? '—')
                        : '—',
                )),

            TD::make('sync_status', __('Sync'))
                ->sort()
                ->align(TD::ALIGN_RIGHT),
        ];
    }
}
