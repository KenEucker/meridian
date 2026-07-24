<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Shift;

use App\Models\Shift;
use App\Orchid\Layouts\ScopeFiltersLayout;
use App\Orchid\Layouts\Shift\ShiftListLayout;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

class ShiftListScreen extends Screen
{
    private ?ScopeFiltersLayout $scopeFilters = null;

    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'shifts' => Shift::query()
                ->with(['event', 'department', 'eligibleTeam'])
                ->filters($this->scopeFilters()->filters())
                ->defaultSort('starts_at')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Shifts';
    }

    public function description(): ?string
    {
        return 'Planned staff coverage blocks for an event department.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.shifts',
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Add'))
                ->icon('bs.plus-circle')
                ->route('platform.shifts.create'),
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            $this->scopeFilters(),
            ShiftListLayout::class,
        ];
    }

    /**
     * Organization / department / team narrowing shared by the query and the
     * rendered filter controls.
     */
    private function scopeFilters(): ScopeFiltersLayout
    {
        return $this->scopeFilters ??= ScopeFiltersLayout::for(Shift::class);
    }
}
