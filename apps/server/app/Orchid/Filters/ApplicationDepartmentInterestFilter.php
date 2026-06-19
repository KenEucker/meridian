<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use App\Models\Department;
use App\Models\User;
use App\Services\Application\ApplicationReviewAccess;
use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Select;

class ApplicationDepartmentInterestFilter extends Filter
{
    public function name(): string
    {
        return __('Department interest');
    }

    public function parameters(): array
    {
        return ['department_interest'];
    }

    public function run(Builder $builder): Builder
    {
        return $builder->whereHas('departmentInterests', fn (Builder $query) => $query
            ->where('departments.id', $this->request->get('department_interest')));
    }

    public function display(): array
    {
        return [
            Select::make('department_interest')
                ->options($this->departmentOptions())
                ->empty()
                ->value($this->request->get('department_interest'))
                ->title(__('Department interest')),
        ];
    }

    public function value(): string
    {
        $department = Department::query()->find($this->request->get('department_interest'));

        return $this->name().': '.($department?->name ?? __('Unknown department'));
    }

    /**
     * @return array<string, string>
     */
    private function departmentOptions(): array
    {
        $query = Department::query();
        $user = $this->request->user();

        if ($user instanceof User) {
            $access = app(ApplicationReviewAccess::class);

            if (! $access->canReviewApplications($user)) {
                $query->whereIn('id', $access->departmentLeadDepartmentIds($user));
            }
        }

        return $query
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
