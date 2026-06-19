<?php

declare(strict_types=1);

use App\Orchid\Screens\Department\DepartmentEditScreen;
use App\Orchid\Screens\Department\DepartmentListScreen;
use App\Orchid\Screens\Event\EventEditScreen;
use App\Orchid\Screens\Event\EventListScreen;
use App\Orchid\Screens\Node\NodeConfigScreen;
use App\Orchid\Screens\Organization\OrganizationEditScreen;
use App\Orchid\Screens\Organization\OrganizationListScreen;
use App\Orchid\Screens\PlatformScreen;
use App\Orchid\Screens\Role\RoleEditScreen;
use App\Orchid\Screens\Role\RoleListScreen;
use App\Orchid\Screens\Staff\StaffEditScreen;
use App\Orchid\Screens\Staff\StaffListScreen;
use App\Orchid\Screens\Team\TeamEditScreen;
use App\Orchid\Screens\Team\TeamListScreen;
use App\Orchid\Screens\User\UserEditScreen;
use App\Orchid\Screens\User\UserListScreen;
use App\Orchid\Screens\User\UserProfileScreen;
use Illuminate\Support\Facades\Route;
use Tabuna\Breadcrumbs\Trail;

/*
|--------------------------------------------------------------------------
| Dashboard Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the need "dashboard" middleware group. Now create something great!
|
*/

// Main
Route::screen('/main', PlatformScreen::class)
    ->name('platform.main');

// Platform > Profile
Route::screen('profile', UserProfileScreen::class)
    ->name('platform.profile')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Profile'), route('platform.profile')));

// Platform > System > Users > User
Route::screen('users/{user}/edit', UserEditScreen::class)
    ->name('platform.systems.users.edit')
    ->breadcrumbs(fn (Trail $trail, $user) => $trail
        ->parent('platform.systems.users')
        ->push($user->name, route('platform.systems.users.edit', $user)));

// Platform > System > Users > Create
Route::screen('users/create', UserEditScreen::class)
    ->name('platform.systems.users.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.systems.users')
        ->push(__('Create'), route('platform.systems.users.create')));

// Platform > System > Users
Route::screen('users', UserListScreen::class)
    ->name('platform.systems.users')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Users'), route('platform.systems.users')));

// Platform > System > Roles > Role
Route::screen('roles/{role}/edit', RoleEditScreen::class)
    ->name('platform.systems.roles.edit')
    ->breadcrumbs(fn (Trail $trail, $role) => $trail
        ->parent('platform.systems.roles')
        ->push($role->name, route('platform.systems.roles.edit', $role)));

// Platform > System > Roles > Create
Route::screen('roles/create', RoleEditScreen::class)
    ->name('platform.systems.roles.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.systems.roles')
        ->push(__('Create'), route('platform.systems.roles.create')));

// Platform > System > Roles
Route::screen('roles', RoleListScreen::class)
    ->name('platform.systems.roles')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Roles'), route('platform.systems.roles')));

// Platform > Operations > Organizations > Organization
Route::screen('organizations/{organization}/edit', OrganizationEditScreen::class)
    ->name('platform.organizations.edit')
    ->breadcrumbs(fn (Trail $trail, $organization) => $trail
        ->parent('platform.organizations')
        ->push($organization->name, route('platform.organizations.edit', $organization)));

// Platform > Operations > Organizations > Create
Route::screen('organizations/create', OrganizationEditScreen::class)
    ->name('platform.organizations.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.organizations')
        ->push(__('Create'), route('platform.organizations.create')));

// Platform > Operations > Organizations
Route::screen('organizations', OrganizationListScreen::class)
    ->name('platform.organizations')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Organizations'), route('platform.organizations')));

// Platform > Operations > Events > Event
Route::screen('events/{event}/edit', EventEditScreen::class)
    ->name('platform.events.edit')
    ->breadcrumbs(fn (Trail $trail, $event) => $trail
        ->parent('platform.events')
        ->push($event->name, route('platform.events.edit', $event)));

// Platform > Operations > Events > Create
Route::screen('events/create', EventEditScreen::class)
    ->name('platform.events.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.events')
        ->push(__('Create'), route('platform.events.create')));

// Platform > Operations > Events
Route::screen('events', EventListScreen::class)
    ->name('platform.events')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Events'), route('platform.events')));

// Platform > Operations > Departments > Department
Route::screen('departments/{department}/edit', DepartmentEditScreen::class)
    ->name('platform.departments.edit')
    ->breadcrumbs(fn (Trail $trail, $department) => $trail
        ->parent('platform.departments')
        ->push($department->name, route('platform.departments.edit', $department)));

// Platform > Operations > Departments > Create
Route::screen('departments/create', DepartmentEditScreen::class)
    ->name('platform.departments.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.departments')
        ->push(__('Create'), route('platform.departments.create')));

// Platform > Operations > Departments
Route::screen('departments', DepartmentListScreen::class)
    ->name('platform.departments')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Departments'), route('platform.departments')));

// Platform > Operations > Teams > Team
Route::screen('teams/{team}/edit', TeamEditScreen::class)
    ->name('platform.teams.edit')
    ->breadcrumbs(fn (Trail $trail, $team) => $trail
        ->parent('platform.teams')
        ->push($team->name, route('platform.teams.edit', $team)));

// Platform > Operations > Teams > Create
Route::screen('teams/create', TeamEditScreen::class)
    ->name('platform.teams.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.teams')
        ->push(__('Create'), route('platform.teams.create')));

// Platform > Operations > Teams
Route::screen('teams', TeamListScreen::class)
    ->name('platform.teams')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Teams'), route('platform.teams')));

// Platform > Operations > Staff > Staff
Route::screen('staff/{staff}/edit', StaffEditScreen::class)
    ->name('platform.staff.edit')
    ->breadcrumbs(fn (Trail $trail, $staff) => $trail
        ->parent('platform.staff')
        ->push($staff->legal_name, route('platform.staff.edit', $staff)));

// Platform > Operations > Staff > Create
Route::screen('staff/create', StaffEditScreen::class)
    ->name('platform.staff.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.staff')
        ->push(__('Create'), route('platform.staff.create')));

// Platform > Operations > Staff
Route::screen('staff', StaffListScreen::class)
    ->name('platform.staff')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Staff'), route('platform.staff')));

// Platform > God Mode > Node Configuration
Route::screen('node-config', NodeConfigScreen::class)
    ->name('platform.node.config')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Node Configuration'), route('platform.node.config')));
