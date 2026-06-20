<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Document;

use App\Models\Department;
use App\Models\DocumentFragment;
use App\Models\Organization;
use App\Models\Team;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class DocumentFragmentEditLayout extends Rows
{
    /**
     * @return Field[]
     */
    protected function fields(): iterable
    {
        return [
            Select::make('fragment.organization_id')
                ->fromModel(Organization::class, 'name')
                ->required()
                ->title(__('Organization')),

            Select::make('fragment.scope_type')
                ->options(DocumentFragment::scopeTypeLabels())
                ->required()
                ->title(__('Scope type')),

            Select::make('fragment.scope_id')
                ->options($this->scopeTargets())
                ->required()
                ->title(__('Scope target'))
                ->help(__('Choose a target matching the selected scope type.')),

            Input::make('fragment.name')
                ->type('text')
                ->max(255)
                ->required()
                ->set('data-meridian-slug-target', 'fragment[slug]')
                ->title(__('Name')),

            Input::make('fragment.slug')
                ->type('text')
                ->max(255)
                ->required()
                ->title(__('Slug')),

            TextArea::make('fragment.markdown_source')
                ->rows(16)
                ->required()
                ->title(__('Markdown source'))
                ->help(__('Fragments use Markdown only and cannot reference other fragments.')),
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
