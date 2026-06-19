<?php

declare(strict_types=1);

namespace App\Orchid;

use App\Models\User;
use Orchid\Platform\Dashboard;
use Orchid\Platform\ItemPermission;
use Orchid\Platform\Models\User as OrchidUser;
use Orchid\Platform\OrchidServiceProvider;
use Orchid\Screen\Actions\Menu;
use Orchid\Support\Color;

class PlatformProvider extends OrchidServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(Dashboard $dashboard): void
    {
        Dashboard::useModel(OrchidUser::class, User::class);

        parent::boot($dashboard);

        // Expose the shared Meridian semantic UI tokens (M2.5) on the admin
        // surface so admin components draw from the same baseline as the field
        // app. The served file mirrors packages/ui-tokens/tokens.css.
        $dashboard->registerResource('stylesheets', asset('css/meridian-tokens.css'));
        $dashboard->registerResource('scripts', asset('js/meridian-admin.js'));
    }

    /**
     * Register the application menu.
     *
     * @return Menu[]
     */
    public function menu(): array
    {
        return [
            Menu::make('Get Started')
                ->icon('bs.book')
                ->title('Navigation')
                ->route(config('platform.index'))
                ->divider(),

            Menu::make(__('Users'))
                ->icon('bs.people')
                ->route('platform.systems.users')
                ->permission('platform.systems.users')
                ->title(__('Access Controls')),

            Menu::make(__('Roles'))
                ->icon('bs.shield')
                ->route('platform.systems.roles')
                ->permission('platform.systems.roles')
                ->divider(),

            Menu::make(__('Organizations'))
                ->icon('bs.buildings')
                ->route('platform.organizations')
                ->permission('platform.organizations')
                ->title(__('Operations')),

            Menu::make(__('Events'))
                ->icon('bs.calendar-event')
                ->route('platform.events')
                ->permission('platform.events'),

            Menu::make(__('Applications'))
                ->icon('bs.inbox')
                ->route('platform.applications')
                ->permission('platform.applications'),

            Menu::make(__('Departments'))
                ->icon('bs.diagram-3')
                ->route('platform.departments')
                ->permission('platform.departments'),

            Menu::make(__('Teams'))
                ->icon('bs.people-fill')
                ->route('platform.teams')
                ->permission('platform.teams'),

            Menu::make(__('Staff'))
                ->icon('bs.person-lines-fill')
                ->route('platform.staff')
                ->permission('platform.staff'),

            Menu::make(__('Node Configuration'))
                ->icon('bs.server')
                ->route('platform.node.config')
                ->permission('platform.node.config')
                ->title(__('God Mode')),

            Menu::make('Documentation')
                ->title('Docs')
                ->icon('bs.box-arrow-up-right')
                ->url('https://orchid.software/en/docs')
                ->target('_blank'),

            Menu::make('Changelog')
                ->icon('bs.box-arrow-up-right')
                ->url('https://github.com/orchidsoftware/platform/blob/master/CHANGELOG.md')
                ->target('_blank')
                ->badge(fn () => Dashboard::version(), Color::DARK),
        ];
    }

    /**
     * Register permissions for the application.
     *
     * @return ItemPermission[]
     */
    public function permissions(): array
    {
        return [
            ItemPermission::group(__('System'))
                ->addPermission('platform.systems.roles', __('Roles'))
                ->addPermission('platform.systems.users', __('Users')),

            ItemPermission::group(__('Operations'))
                ->addPermission('platform.organizations', __('Organizations'))
                ->addPermission('platform.events', __('Events'))
                ->addPermission('platform.applications', __('Applications'))
                ->addPermission('platform.departments', __('Departments'))
                ->addPermission('platform.teams', __('Teams'))
                ->addPermission('platform.staff', __('Staff')),

            ItemPermission::group(__('God Mode'))
                ->addPermission('platform.node.config', __('Node configuration')),
        ];
    }
}
