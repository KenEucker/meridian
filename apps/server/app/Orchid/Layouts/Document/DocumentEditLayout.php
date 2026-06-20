<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Document;

use App\Models\Department;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Team;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class DocumentEditLayout extends Rows
{
    /**
     * @param  class-string<PolicyDocument|ProcedureDocument>  $documentClass
     */
    public function __construct(private readonly string $documentClass) {}

    /**
     * @return Field[]
     */
    protected function fields(): iterable
    {
        return [
            Select::make('document.organization_id')
                ->fromModel(Organization::class, 'name')
                ->required()
                ->title(__('Organization')),

            Select::make('document.scope_type')
                ->options(($this->documentClass)::scopeTypeLabels())
                ->required()
                ->title(__('Scope type')),

            Select::make('document.scope_id')
                ->options($this->scopeTargets())
                ->required()
                ->title(__('Scope target'))
                ->help(__('Choose a target matching the selected scope type.')),

            Input::make('document.title')
                ->type('text')
                ->max(255)
                ->required()
                ->set('data-meridian-slug-target', 'document[slug]')
                ->title(__('Title')),

            Input::make('document.slug')
                ->type('text')
                ->max(255)
                ->required()
                ->title(__('Slug'))
                ->help(__('Used by maintainers to identify this document.')),

            Select::make('document.state')
                ->options(($this->documentClass)::stateLabels())
                ->required()
                ->title(__('State')),

            TextArea::make('document.markdown_source')
                ->rows(20)
                ->required()
                ->title(__('Markdown source'))
                ->help(__('Use Markdown and {{fragment:fragment-slug}} references. Raw HTML is stripped from rendered previews.')),

            Input::make('document.reason')
                ->type('text')
                ->max(2000)
                ->title(__('Publish or archive reason'))
                ->help(__('Required when changing this document to Published or Archived.')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function scopeTargets(): array
    {
        $organizations = Organization::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, string $id): array => [$id => __('Organization').': '.$name]);

        $departments = Department::query()
            ->with('organization')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Department $department): array => [
                $department->id => __('Department').': '.($department->organization?->name ?? __('Unknown')).' / '.$department->name,
            ]);

        $teams = Team::query()
            ->with('department.organization')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Team $team): array => [
                $team->id => __('Team').': '.($team->department?->organization?->name ?? __('Unknown')).' / '.($team->department?->name ?? __('Unknown')).' / '.$team->name,
            ]);

        return $organizations
            ->union($departments)
            ->union($teams)
            ->all();
    }
}
