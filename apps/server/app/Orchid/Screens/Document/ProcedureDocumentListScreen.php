<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Document;

use App\Models\ProcedureDocument;
use App\Orchid\Layouts\Document\DocumentListLayout;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

class ProcedureDocumentListScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'documents' => ProcedureDocument::query()
                ->with(['organization', 'organizationScope', 'departmentScope', 'teamScope'])
                ->filters()
                ->defaultSort('title')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Procedure Documents';
    }

    public function description(): ?string
    {
        return 'Markdown operational procedures, managed separately from policies.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return ['platform.procedure-documents'];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Add Procedure'))
                ->icon('bs.plus-circle')
                ->route('platform.procedure-documents.create'),
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            new DocumentListLayout('platform.procedure-documents.edit'),
        ];
    }
}
