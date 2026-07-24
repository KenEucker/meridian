<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Staff;

use App\Models\Staff;
use App\Orchid\Layouts\ScopeFiltersLayout;
use App\Orchid\Layouts\Staff\StaffListLayout;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

class StaffListScreen extends Screen
{
    private ?ScopeFiltersLayout $scopeFilters = null;

    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'staff' => Staff::query()
                ->filters($this->scopeFilters()->filters())
                ->defaultSort('legal_name')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Staff';
    }

    public function description(): ?string
    {
        return 'Organization staff profile records.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.staff',
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
                ->route('platform.staff.create'),
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            $this->scopeFilters(),
            StaffListLayout::class,
        ];
    }

    /**
     * Organization / department / team narrowing shared by the query and the
     * rendered filter controls.
     */
    private function scopeFilters(): ScopeFiltersLayout
    {
        return $this->scopeFilters ??= ScopeFiltersLayout::for(Staff::class);
    }
}
