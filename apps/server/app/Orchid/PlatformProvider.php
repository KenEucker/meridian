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

        // The shared Meridian semantic UI tokens (M2.5) and the console token
        // bridge (M15C.1) are registered through `platform.resource.stylesheets`
        // in config/platform.php instead of here, so the console's visual
        // identity lives entirely in the framework's supported configuration
        // extension points (GOD-037).
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
            // The console entry point carries no group heading: it is the first
            // thing in the sidebar, so a heading above it would name a section
            // of one and say nothing the item does not already say.
            Menu::make(__('Getting Started'))
                ->icon('bs.compass')
                ->route(config('platform.index'))
                ->divider(),

            Menu::make(__('Users'))
                ->icon('bs.people')
                ->route('platform.systems.users')
                ->permission('platform.systems.users')
                ->title(__('Console Access')),

            // The administrative framework's own roles, which decide who may
            // open this console and nothing else. Labelled for what they do,
            // because "Roles" next to Meridian's Permission Catalog reads as
            // though one of them is the operational permission model.
            Menu::make(__('Console Roles'))
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

            Menu::make(__('Shifts'))
                ->icon('bs.calendar-week')
                ->route('platform.shifts')
                ->permission('platform.shifts'),

            Menu::make(__('Staff'))
                ->icon('bs.person-lines-fill')
                ->route('platform.staff')
                ->permission('platform.staff'),

            Menu::make(__('Equipment'))
                ->icon('bs.box-seam')
                ->route('platform.equipment')
                ->permission('platform.equipment'),

            Menu::make(__('Policy Documents'))
                ->icon('bs.file-earmark-text')
                ->route('platform.policy-documents')
                ->permission('platform.policy-documents')
                ->title(__('Policies & Procedures')),

            Menu::make(__('Procedure Documents'))
                ->icon('bs.journal-text')
                ->route('platform.procedure-documents')
                ->permission('platform.procedure-documents'),

            Menu::make(__('Document Fragments'))
                ->icon('bs.braces')
                ->route('platform.document-fragments')
                ->permission('platform.document-fragments'),

            Menu::make(__('Permission Catalog'))
                ->icon('bs.key')
                ->route('platform.permissions')
                ->permission('platform.permissions')
                ->title(__('God Mode')),

            // Node identity, pairing, and sync conflicts describe how this
            // deployment is wired together rather than how the organization
            // operates, so they carry their own heading instead of trailing the
            // previous section.
            //
            // A group heading in this framework lives on the first item of its
            // group, and a permission-hidden item takes its heading with it. An
            // operator holding only some of these permissions can therefore see
            // a later item filed under the previous heading, which is how these
            // two ended up reading as Policies & Procedures. Console
            // permissions are granted together in practice; if that stops being
            // true, the heading has to move to whichever item is always visible.
            Menu::make(__('Sync Conflicts'))
                ->icon('bs.exclamation-diamond')
                ->route('platform.sync-conflicts')
                ->permission('platform.sync-conflicts')
                ->title(__('Infrastructure')),

            Menu::make(__('Node Configuration'))
                ->icon('bs.server')
                ->route('platform.node.config')
                ->permission('platform.node.config'),

            // Meridian's own documentation and changelog, served from content
            // packaged with this deployment. These are console pages, not
            // external links, and they do not open an external browser context
            // (GOD-012, GOD-018, GOD-027). The badge is the Meridian build
            // version, not the administrative framework's (GOD-028).
            Menu::make(__('Documentation'))
                ->title(__('Meridian'))
                ->icon('bs.book')
                ->route('platform.documentation')
                ->permission('platform.documentation'),

            Menu::make(__('Changelog'))
                ->icon('bs.clock-history')
                ->route('platform.changelog')
                ->permission('platform.changelog')
                ->badge(fn (): string => (string) config('meridian.version'), Color::DARK),
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
            ItemPermission::group(__('Console Access'))
                ->addPermission('platform.systems.roles', __('Console roles'))
                ->addPermission('platform.systems.users', __('Users')),

            ItemPermission::group(__('Operations'))
                ->addPermission('platform.organizations', __('Organizations'))
                ->addPermission('platform.events', __('Events'))
                ->addPermission('platform.applications', __('Applications'))
                ->addPermission('platform.departments', __('Departments'))
                ->addPermission('platform.teams', __('Teams'))
                ->addPermission('platform.shifts', __('Shifts'))
                ->addPermission('platform.staff', __('Staff'))
                ->addPermission('platform.equipment', __('Equipment'))
                ->addPermission('platform.policy-documents', __('Policy documents'))
                ->addPermission('platform.procedure-documents', __('Procedure documents'))
                ->addPermission('platform.document-fragments', __('Document fragments')),

            // Grouped the way the sidebar is, so an operator granting console
            // access finds a capability under the heading they saw it under.
            ItemPermission::group(__('God Mode'))
                ->addPermission('platform.permissions', __('Permission catalog'))
                ->addPermission('platform.documentation', __('Operator documentation'))
                ->addPermission('platform.changelog', __('Changelog')),

            ItemPermission::group(__('Infrastructure'))
                ->addPermission('platform.sync-conflicts', __('Sync conflicts'))
                ->addPermission('platform.node.config', __('Node configuration')),
        ];
    }
}
