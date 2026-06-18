<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Team;

use App\Models\Team;
use App\Orchid\Layouts\Team\TeamListLayout;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

class TeamListScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'teams' => Team::query()
                ->with('department')
                ->filters()
                ->defaultSort('name')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Teams';
    }

    public function description(): ?string
    {
        return 'Persistent named groups within departments.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.teams',
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
                ->route('platform.teams.create'),
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            TeamListLayout::class,
        ];
    }
}
