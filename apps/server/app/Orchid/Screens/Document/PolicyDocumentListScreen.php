<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Document;

use App\Models\PolicyDocument;
use App\Orchid\Layouts\ScopeFiltersLayout;
use App\Orchid\Layouts\Document\DocumentListLayout;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

class PolicyDocumentListScreen extends Screen
{
    private ?ScopeFiltersLayout $scopeFilters = null;

    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'documents' => PolicyDocument::query()
                ->with(['organization', 'organizationScope', 'departmentScope', 'teamScope'])
                ->filters($this->scopeFilters()->filters())
                ->defaultSort('title')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Policy Documents';
    }

    public function description(): ?string
    {
        return 'Markdown governance policies, managed separately from procedures.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return ['platform.policy-documents'];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Add Policy'))
                ->icon('bs.plus-circle')
                ->route('platform.policy-documents.create'),
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            $this->scopeFilters(),
            new DocumentListLayout('platform.policy-documents.edit'),
        ];
    }

    /**
     * Organization / department / team narrowing shared by the query and the
     * rendered filter controls.
     */
    private function scopeFilters(): ScopeFiltersLayout
    {
        return $this->scopeFilters ??= ScopeFiltersLayout::for(PolicyDocument::class);
    }
}
