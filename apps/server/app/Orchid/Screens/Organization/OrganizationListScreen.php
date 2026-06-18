<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Organization;

use App\Models\Organization;
use App\Orchid\Layouts\Organization\OrganizationListLayout;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

class OrganizationListScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'organizations' => Organization::query()
                ->filters()
                ->defaultSort('name')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Organizations';
    }

    public function description(): ?string
    {
        return 'Volunteer-producing organizations and their core configuration.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.organizations',
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
                ->route('platform.organizations.create'),
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            OrganizationListLayout::class,
        ];
    }
}
