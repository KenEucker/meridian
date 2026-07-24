<?php

declare(strict_types=1);

namespace App\Orchid\Filters\Scope;

use App\Models\Team;
use Orchid\Screen\Fields\Select;

/**
 * Narrows a God Mode list screen to one team. Options cascade from the
 * department and organization filters when either is selected.
 */
final class TeamScopeFilter extends ScopeFilter
{
    public const PARAMETER = 'scope_team';

    public function name(): string
    {
        return __('Team');
    }

    protected function scopeMethod(): string
    {
        return 'inTeam';
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
                ->empty(__('All teams'))
                ->value($this->selected())
                ->title(__('Team')),
        ];
    }

    public function value(): string
    {
        $team = Team::query()->find($this->selected());

        return $this->name().': '.($team?->name ?? __('All teams'));
    }

    /**
     * @return array<string, string>
     */
    private function options(): array
    {
        $departmentId = $this->selectedParameter(DepartmentScopeFilter::PARAMETER);
        $organizationId = $this->selectedParameter(OrganizationScopeFilter::PARAMETER);

        return Team::query()
            ->with('department')
            ->when($departmentId !== null, fn ($query) => $query->where('department_id', $departmentId))
            ->when(
                $departmentId === null && $organizationId !== null,
                fn ($query) => $query->whereHas('department', fn ($department) => $department
                    ->where('organization_id', $organizationId)),
            )
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Team $team): array => [
                (string) $team->id => $team->department !== null
                    ? $team->department->name.' / '.$team->name
                    : $team->name,
            ])
            ->all();
    }
}
