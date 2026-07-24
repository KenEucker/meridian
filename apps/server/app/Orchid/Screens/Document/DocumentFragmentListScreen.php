<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Document;

use App\Models\DocumentFragment;
use App\Orchid\Layouts\ScopeFiltersLayout;
use App\Orchid\Layouts\Document\DocumentFragmentListLayout;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

class DocumentFragmentListScreen extends Screen
{
    private ?ScopeFiltersLayout $scopeFilters = null;

    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'fragments' => DocumentFragment::query()
                ->with(['organization', 'organizationScope', 'departmentScope', 'teamScope'])
                ->filters($this->scopeFilters()->filters())
                ->defaultSort('name')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Document Fragments';
    }

    public function description(): ?string
    {
        return 'Reusable Markdown content for policy and procedure documents.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return ['platform.document-fragments'];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Add Fragment'))
                ->icon('bs.plus-circle')
                ->route('platform.document-fragments.create'),
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            $this->scopeFilters(),
            DocumentFragmentListLayout::class,
        ];
    }

    /**
     * Organization / department / team narrowing shared by the query and the
     * rendered filter controls.
     */
    private function scopeFilters(): ScopeFiltersLayout
    {
        return $this->scopeFilters ??= ScopeFiltersLayout::for(DocumentFragment::class);
    }
}
