<?php

declare(strict_types=1);

namespace App\Orchid\Layouts;

use App\Orchid\Filters\Scope\DepartmentScopeFilter;
use App\Orchid\Filters\Scope\OrganizationScopeFilter;
use App\Orchid\Filters\Scope\ScopeFilter;
use App\Orchid\Filters\Scope\TeamScopeFilter;
use Illuminate\Database\Eloquent\Model;
use Orchid\Screen\Layouts\Selection;

/**
 * Organization / department / team narrowing for God Mode list screens.
 *
 * Rendered as an always-visible filter bar rather than a dropdown, because
 * narrowing is the primary way these screens are used once a node holds more
 * than one organization.
 *
 * Screens pass the model they list; only the narrowing levels that model
 * declares as local scopes are shown. A model declares the levels *above*
 * itself, so the Teams screen offers organization and department but not team
 * (which would always return a single row). Use the same instance for the
 * screen layout and for the query so the displayed controls and the applied
 * narrowing always agree:
 *
 *     $scope = ScopeFiltersLayout::for(Shift::class);
 *     Shift::query()->filters($scope->filters())->paginate();
 */
final class ScopeFiltersLayout extends Selection
{
    /**
     * @var string
     */
    public $template = self::TEMPLATE_LINE;

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function __construct(private readonly string $modelClass) {}

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function for(string $modelClass): self
    {
        return new self($modelClass);
    }

    /**
     * @return list<ScopeFilter>
     */
    public function filters(): iterable
    {
        return collect([
            new OrganizationScopeFilter($this->modelClass),
            new DepartmentScopeFilter($this->modelClass),
            new TeamScopeFilter($this->modelClass),
        ])
            ->filter(fn (ScopeFilter $filter) => $filter->supportsScope())
            ->values()
            ->all();
    }
}
