<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Document;

use App\Models\DocumentFragment;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class DocumentFragmentListLayout extends Table
{
    /**
     * @var string
     */
    protected $target = 'fragments';

    /**
     * @return TD[]
     */
    protected function columns(): iterable
    {
        return [
            TD::make('name', __('Name'))
                ->sort()
                ->cantHide()
                ->filter(Input::make())
                ->render(fn (DocumentFragment $fragment) => Link::make($fragment->name)
                    ->route('platform.document-fragments.edit', $fragment->id)),

            TD::make('slug', __('Slug'))
                ->sort()
                ->filter(Input::make()),

            TD::make('scope_type', __('Scope'))
                ->sort()
                ->filter(TD::FILTER_SELECT, DocumentFragment::scopeTypeLabels())
                ->render(fn (DocumentFragment $fragment) => $this->scopeLabel($fragment)),

            TD::make('version', __('Version'))
                ->sort(),

            TD::make('organization.name', __('Organization'))
                ->render(fn (DocumentFragment $fragment) => $fragment->organization?->name ?? __('Not configured')),

            TD::make('updated_at', __('Last edit'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),
        ];
    }

    private function scopeLabel(DocumentFragment $fragment): string
    {
        return match ($fragment->scope_type) {
            DocumentFragment::SCOPE_ORGANIZATION => __('Organization').': '.($fragment->organizationScope?->name ?? __('Not configured')),
            DocumentFragment::SCOPE_DEPARTMENT => __('Department').': '.($fragment->departmentScope?->name ?? __('Not configured')),
            DocumentFragment::SCOPE_TEAM => __('Team').': '.($fragment->teamScope?->name ?? __('Not configured')),
            default => ucfirst($fragment->scope_type),
        };
    }
}
