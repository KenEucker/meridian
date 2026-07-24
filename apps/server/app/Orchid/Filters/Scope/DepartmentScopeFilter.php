<?php

declare(strict_types=1);

namespace App\Orchid\Filters\Scope;

use App\Models\Department;
use Orchid\Screen\Fields\Select;

/**
 * Narrows a God Mode list screen to one department. Options cascade from the
 * organization filter when one is selected.
 */
final class DepartmentScopeFilter extends ScopeFilter
{
    public const PARAMETER = 'scope_department';

    public function name(): string
    {
        return __('Department');
    }

    protected function scopeMethod(): string
    {
        return 'inDepartment';
    }

    protected function parameter(): string
    {
        return self::PARAMETER;
    }

    public function display(): iterable
    {
        return [
            Select::make(self::PARAMETER)
                ->options($this->options())
                ->empty(__('All departments'))
                ->value($this->selected())
                ->title(__('Department')),
        ];
    }

    public function value(): string
    {
        $department = Department::query()->find($this->selected());

        return $this->name().': '.($department?->name ?? __('All departments'));
    }

    /**
     * @return array<string, string>
     */
    private function options(): array
    {
        $organizationId = $this->selectedParameter(OrganizationScopeFilter::PARAMETER);

        return Department::query()
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
