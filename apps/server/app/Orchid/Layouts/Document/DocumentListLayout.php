<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Document;

use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class DocumentListLayout extends Table
{
    /**
     * @var string
     */
    protected $target = 'documents';

    public function __construct(private readonly string $editRoute) {}

    /**
     * @return TD[]
     */
    protected function columns(): iterable
    {
        return [
            TD::make('title', __('Title'))
                ->sort()
                ->cantHide()
                ->filter(Input::make())
                ->render(fn (PolicyDocument|ProcedureDocument $document) => Link::make($document->title)
                    ->route($this->editRoute, $document->id)),

            TD::make('state', __('State'))
                ->sort()
                ->filter(TD::FILTER_SELECT, PolicyDocument::stateLabels())
                ->render(fn (PolicyDocument|ProcedureDocument $document) => $document::stateLabels()[$document->state]),

            TD::make('scope_type', __('Scope'))
                ->sort()
                ->filter(TD::FILTER_SELECT, PolicyDocument::scopeTypeLabels())
                ->render(fn (PolicyDocument|ProcedureDocument $document) => $this->scopeLabel($document)),

            TD::make('version', __('Version'))
                ->render(fn (PolicyDocument|ProcedureDocument $document) => $document->version()),

            TD::make('organization.name', __('Organization'))
                ->render(fn (PolicyDocument|ProcedureDocument $document) => $document->organization?->name ?? __('Not configured')),

            TD::make('updated_at', __('Last edit'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),
        ];
    }

    private function scopeLabel(PolicyDocument|ProcedureDocument $document): string
    {
        return match ($document->scope_type) {
            PolicyDocument::SCOPE_ORGANIZATION => __('Organization').': '.($document->organizationScope?->name ?? __('Not configured')),
            PolicyDocument::SCOPE_DEPARTMENT => __('Department').': '.($document->departmentScope?->name ?? __('Not configured')),
            PolicyDocument::SCOPE_TEAM => __('Team').': '.($document->teamScope?->name ?? __('Not configured')),
            default => ucfirst($document->scope_type),
        };
    }
}
