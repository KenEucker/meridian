<?php

declare(strict_types=1);

use App\Http\Controllers\Documents\DocumentExportController;
use App\Orchid\Screens\ApiToken\ApiTokenListScreen;
use App\Orchid\Screens\Application\ApplicationDetailScreen;
use App\Orchid\Screens\Application\ApplicationListScreen;
use App\Orchid\Screens\Audit\AuditDetailScreen;
use App\Orchid\Screens\Audit\AuditListScreen;
use App\Orchid\Screens\Audit\AuditSettingsEditScreen;
use App\Orchid\Screens\Audit\AuditSettingsListScreen;
use App\Orchid\Screens\Console\ChangelogScreen;
use App\Orchid\Screens\Console\DocumentationScreen;
use App\Orchid\Screens\Credit\CreditPolicyEditScreen;
use App\Orchid\Screens\Credit\CreditPolicyListScreen;
use App\Orchid\Screens\Department\DepartmentEditScreen;
use App\Orchid\Screens\Department\DepartmentListScreen;
use App\Orchid\Screens\Document\DocumentFragmentEditScreen;
use App\Orchid\Screens\Document\DocumentFragmentListScreen;
use App\Orchid\Screens\Document\PolicyDocumentEditScreen;
use App\Orchid\Screens\Document\PolicyDocumentListScreen;
use App\Orchid\Screens\Document\ProcedureDocumentEditScreen;
use App\Orchid\Screens\Document\ProcedureDocumentListScreen;
use App\Orchid\Screens\Equipment\EquipmentEditScreen;
use App\Orchid\Screens\Equipment\EquipmentListScreen;
use App\Orchid\Screens\Event\EventEditScreen;
use App\Orchid\Screens\Event\EventListScreen;
use App\Orchid\Screens\Import\AssignmentImportScreen;
use App\Orchid\Screens\Import\ShiftImportScreen;
use App\Orchid\Screens\Import\TeamImportScreen;
use App\Orchid\Screens\Import\UserImportScreen;
use App\Orchid\Screens\Incident\IncidentTypeEditScreen;
use App\Orchid\Screens\Incident\IncidentTypeListScreen;
use App\Orchid\Screens\Node\NodeConfigScreen;
use App\Orchid\Screens\Organization\OrganizationEditScreen;
use App\Orchid\Screens\Organization\OrganizationInquiryDetailScreen;
use App\Orchid\Screens\Organization\OrganizationInquiryListScreen;
use App\Orchid\Screens\Organization\OrganizationListScreen;
use App\Orchid\Screens\Permission\PermissionCatalogScreen;
use App\Orchid\Screens\PlatformScreen;
use App\Orchid\Screens\Role\RoleEditScreen;
use App\Orchid\Screens\Role\RoleListScreen;
use App\Orchid\Screens\SharedWorkstationLoginCode\SharedWorkstationLoginCodeListScreen;
use App\Orchid\Screens\Shift\ShiftEditScreen;
use App\Orchid\Screens\Shift\ShiftListScreen;
use App\Orchid\Screens\Staff\StaffEditScreen;
use App\Orchid\Screens\Staff\StaffListScreen;
use App\Orchid\Screens\SyncConflict\SyncConflictDetailScreen;
use App\Orchid\Screens\SyncConflict\SyncConflictListScreen;
use App\Orchid\Screens\System\NodeHealthScreen;
use App\Orchid\Screens\System\SystemConfigurationEditScreen;
use App\Orchid\Screens\System\SystemConfigurationScreen;
use App\Orchid\Screens\System\SystemDiagnosticsScreen;
use App\Orchid\Screens\Team\TeamEditScreen;
use App\Orchid\Screens\Team\TeamListScreen;
use App\Orchid\Screens\User\UserEditScreen;
use App\Orchid\Screens\User\UserListScreen;
use App\Orchid\Screens\User\UserProfileScreen;
use App\Services\Documents\DocumentExport;
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

// Platform > God Mode > Permission Catalog
Route::screen('permission-catalog', PermissionCatalogScreen::class)
    ->name('platform.permissions')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Permission Catalog'), route('platform.permissions')));

// Platform > God Mode > Imports (technical spec 22.2)
Route::screen('imports/users', UserImportScreen::class)
    ->name('platform.imports.users')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Import Users'), route('platform.imports.users')));

Route::screen('imports/teams', TeamImportScreen::class)
    ->name('platform.imports.teams')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Import Teams'), route('platform.imports.teams')));

Route::screen('imports/shifts', ShiftImportScreen::class)
    ->name('platform.imports.shifts')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Import Shifts'), route('platform.imports.shifts')));

Route::screen('imports/assignments', AssignmentImportScreen::class)
    ->name('platform.imports.assignments')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Import Assignments'), route('platform.imports.assignments')));

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

/*
 * Platform > Operations > Organization Inquiries (M18.23; PUBLIC-004).
 *
 * Filed beside Organizations because that is where an operator goes next when
 * they decide to act on one — and no further than beside it: there is no path
 * from an inquiry to a created organization, because PUBLIC-004 keeps creation
 * a deliberate act rather than a consequence of reading a message.
 */
Route::screen('organization-inquiries/{inquiry}', OrganizationInquiryDetailScreen::class)
    ->name('platform.organization-inquiries.show')
    ->breadcrumbs(fn (Trail $trail, $inquiry) => $trail
        ->parent('platform.organization-inquiries')
        ->push($inquiry->organization_name, route('platform.organization-inquiries.show', $inquiry)));

Route::screen('organization-inquiries', OrganizationInquiryListScreen::class)
    ->name('platform.organization-inquiries')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Organization Inquiries'), route('platform.organization-inquiries')));

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

// Platform > Operations > Applications > Application
Route::screen('applications/{application}', ApplicationDetailScreen::class)
    ->name('platform.applications.show')
    ->breadcrumbs(fn (Trail $trail, $application) => $trail
        ->parent('platform.applications')
        ->push($application->applicant_legal_name, route('platform.applications.show', $application)));

// Platform > Operations > Applications
Route::screen('applications', ApplicationListScreen::class)
    ->name('platform.applications')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Applications'), route('platform.applications')));

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

// Platform > Operations > Shifts > Shift
Route::screen('shifts/{shift}/edit', ShiftEditScreen::class)
    ->name('platform.shifts.edit')
    ->breadcrumbs(fn (Trail $trail, $shift) => $trail
        ->parent('platform.shifts')
        ->push($shift->title, route('platform.shifts.edit', $shift)));

// Platform > Operations > Shifts > Create
Route::screen('shifts/create', ShiftEditScreen::class)
    ->name('platform.shifts.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.shifts')
        ->push(__('Create'), route('platform.shifts.create')));

// Platform > Operations > Shifts
Route::screen('shifts', ShiftListScreen::class)
    ->name('platform.shifts')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Shifts'), route('platform.shifts')));

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

// Platform > Operations > Incident types > Incident type
Route::screen('incident-types/{incidentType}/edit', IncidentTypeEditScreen::class)
    ->name('platform.incident-types.edit')
    ->breadcrumbs(fn (Trail $trail, $incidentType) => $trail
        ->parent('platform.incident-types')
        ->push($incidentType->name, route('platform.incident-types.edit', $incidentType)));

// Platform > Operations > Incident types > Create
Route::screen('incident-types/create', IncidentTypeEditScreen::class)
    ->name('platform.incident-types.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.incident-types')
        ->push(__('Create'), route('platform.incident-types.create')));

// Platform > Operations > Incident types
Route::screen('incident-types', IncidentTypeListScreen::class)
    ->name('platform.incident-types')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Incident types'), route('platform.incident-types')));

// Platform > Operations > Credit policies > Credit policy
Route::screen('credit-policies/{creditPolicy}/edit', CreditPolicyEditScreen::class)
    ->name('platform.credit-policies.edit')
    ->breadcrumbs(fn (Trail $trail, $creditPolicy) => $trail
        ->parent('platform.credit-policies')
        ->push($creditPolicy->name, route('platform.credit-policies.edit', $creditPolicy)));

// Platform > Operations > Credit policies > Create
Route::screen('credit-policies/create', CreditPolicyEditScreen::class)
    ->name('platform.credit-policies.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.credit-policies')
        ->push(__('Create'), route('platform.credit-policies.create')));

// Platform > Operations > Credit policies
Route::screen('credit-policies', CreditPolicyListScreen::class)
    ->name('platform.credit-policies')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Credit policies'), route('platform.credit-policies')));

// Platform > Operations > Equipment > Equipment
Route::screen('equipment/{equipmentItem}/edit', EquipmentEditScreen::class)
    ->name('platform.equipment.edit')
    ->breadcrumbs(fn (Trail $trail, $equipmentItem) => $trail
        ->parent('platform.equipment')
        ->push($equipmentItem->name, route('platform.equipment.edit', $equipmentItem)));

// Platform > Operations > Equipment > Create
Route::screen('equipment/create', EquipmentEditScreen::class)
    ->name('platform.equipment.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.equipment')
        ->push(__('Create'), route('platform.equipment.create')));

// Platform > Operations > Equipment
Route::screen('equipment', EquipmentListScreen::class)
    ->name('platform.equipment')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Equipment'), route('platform.equipment')));

// Platform > Policies & Procedures > Policies > Policy
Route::get('policy-documents/{policyDocument}/export/{format}', [DocumentExportController::class, 'policy'])
    ->whereIn('format', DocumentExport::formats())
    ->name('platform.policy-documents.export');

Route::screen('policy-documents/{policyDocument}/edit', PolicyDocumentEditScreen::class)
    ->name('platform.policy-documents.edit')
    ->breadcrumbs(fn (Trail $trail, $policyDocument) => $trail
        ->parent('platform.policy-documents')
        ->push($policyDocument->title, route('platform.policy-documents.edit', $policyDocument)));

// Platform > Policies & Procedures > Policies > Create
Route::screen('policy-documents/create', PolicyDocumentEditScreen::class)
    ->name('platform.policy-documents.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.policy-documents')
        ->push(__('Create'), route('platform.policy-documents.create')));

// Platform > Policies & Procedures > Policies
Route::screen('policy-documents', PolicyDocumentListScreen::class)
    ->name('platform.policy-documents')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Policy Documents'), route('platform.policy-documents')));

// Platform > Policies & Procedures > Procedures > Procedure
Route::get('procedure-documents/{procedureDocument}/export/{format}', [DocumentExportController::class, 'procedure'])
    ->whereIn('format', DocumentExport::formats())
    ->name('platform.procedure-documents.export');

Route::screen('procedure-documents/{procedureDocument}/edit', ProcedureDocumentEditScreen::class)
    ->name('platform.procedure-documents.edit')
    ->breadcrumbs(fn (Trail $trail, $procedureDocument) => $trail
        ->parent('platform.procedure-documents')
        ->push($procedureDocument->title, route('platform.procedure-documents.edit', $procedureDocument)));

// Platform > Policies & Procedures > Procedures > Create
Route::screen('procedure-documents/create', ProcedureDocumentEditScreen::class)
    ->name('platform.procedure-documents.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.procedure-documents')
        ->push(__('Create'), route('platform.procedure-documents.create')));

// Platform > Policies & Procedures > Procedures
Route::screen('procedure-documents', ProcedureDocumentListScreen::class)
    ->name('platform.procedure-documents')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Procedure Documents'), route('platform.procedure-documents')));

// Platform > Policies & Procedures > Fragments > Fragment
Route::screen('document-fragments/{fragment}/edit', DocumentFragmentEditScreen::class)
    ->name('platform.document-fragments.edit')
    ->breadcrumbs(fn (Trail $trail, $fragment) => $trail
        ->parent('platform.document-fragments')
        ->push($fragment->name, route('platform.document-fragments.edit', $fragment)));

// Platform > Policies & Procedures > Fragments > Create
Route::screen('document-fragments/create', DocumentFragmentEditScreen::class)
    ->name('platform.document-fragments.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.document-fragments')
        ->push(__('Create'), route('platform.document-fragments.create')));

// Platform > Policies & Procedures > Fragments
Route::screen('document-fragments', DocumentFragmentListScreen::class)
    ->name('platform.document-fragments')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Document Fragments'), route('platform.document-fragments')));

/*
 * Platform > God Mode > Audit Trail (M18.34; requirements 2.4; UI contract
 * 12.9). The entry route is declared before the list so `audit/{entry}` is
 * matched as an entry rather than swallowed by a later pattern, matching the
 * order every other list/detail pair here uses.
 */
/*
 * Platform > God Mode > Audit Settings (data/API 14.1).
 *
 * `audit-settings` rather than `audit/settings`, which is not cosmetic. Nesting
 * it under the trail's path made `settings` indistinguishable from an entry id
 * without careful route ordering, and made the navigation highlight both
 * entries at once: a menu item is active for its own href plus `href/*`, so
 * everything under the trail's address lit the trail up as well. A sibling path
 * has neither problem and needs no rule to keep it that way.
 */
Route::screen('audit-settings/{organization}', AuditSettingsEditScreen::class)
    ->name('platform.audit.settings.edit')
    ->breadcrumbs(fn (Trail $trail, $organization) => $trail
        ->parent('platform.audit.settings')
        ->push(__('Organization'), route('platform.audit.settings.edit', $organization)));

Route::screen('audit-settings', AuditSettingsListScreen::class)
    ->name('platform.audit.settings')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Audit Settings'), route('platform.audit.settings')));

Route::screen('audit/{entry}', AuditDetailScreen::class)
    ->name('platform.audit.show')
    ->breadcrumbs(fn (Trail $trail, $entry) => $trail
        ->parent('platform.audit')
        ->push(__('Entry'), route('platform.audit.show', $entry)));

Route::screen('audit', AuditListScreen::class)
    ->name('platform.audit')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Audit Trail'), route('platform.audit')));

// Platform > Infrastructure > Sync Conflicts > Conflict
Route::screen('sync-conflicts/{conflict}', SyncConflictDetailScreen::class)
    ->name('platform.sync-conflicts.show')
    ->breadcrumbs(fn (Trail $trail, $conflict) => $trail
        ->parent('platform.sync-conflicts')
        ->push(__('Review'), route('platform.sync-conflicts.show', $conflict)));

// Platform > Infrastructure > Sync Conflicts
Route::screen('sync-conflicts', SyncConflictListScreen::class)
    ->name('platform.sync-conflicts')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Sync Conflicts'), route('platform.sync-conflicts')));

// Platform > Infrastructure > API Tokens
Route::screen('api-tokens', ApiTokenListScreen::class)
    ->name('platform.api-tokens')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('API Tokens'), route('platform.api-tokens')));

// Platform > Infrastructure > Workstation Login Codes
Route::screen('workstation-login-codes', SharedWorkstationLoginCodeListScreen::class)
    ->name('platform.shared-workstation-login-codes')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Workstation Login Codes'), route('platform.shared-workstation-login-codes')));

// Platform > Infrastructure > Node Configuration
Route::screen('node-config', NodeConfigScreen::class)
    ->name('platform.node.config')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Node Configuration'), route('platform.node.config')));

// Platform > Infrastructure > System Configuration (technical spec 22A.7)
Route::screen('system/configuration/{variable}', SystemConfigurationEditScreen::class)
    ->name('platform.system.configuration.edit')
    ->breadcrumbs(fn (Trail $trail, string $variable) => $trail
        ->parent('platform.system.configuration')
        ->push($variable, route('platform.system.configuration.edit', $variable)));

Route::screen('system/configuration', SystemConfigurationScreen::class)
    ->name('platform.system.configuration')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('System Configuration'), route('platform.system.configuration')));

// Platform > Infrastructure > System Diagnostics (technical spec 22A.8, 22A.9)
Route::screen('system/diagnostics', SystemDiagnosticsScreen::class)
    ->name('platform.system.diagnostics')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('System Diagnostics'), route('platform.system.diagnostics')));

// Platform > Infrastructure > Node Health (technical spec 22A.11)
Route::screen('system/node-health', NodeHealthScreen::class)
    ->name('platform.system.node-health')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Node Health'), route('platform.system.node-health')));

// Platform > Meridian > Documentation
// Meridian's own operator documentation, served from content packaged with the
// deployment rather than linked to an external framework site (GOD-012,
// GOD-027).
Route::screen('documentation', DocumentationScreen::class)
    ->name('platform.documentation')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Documentation'), route('platform.documentation')));

// Platform > Meridian > Changelog
Route::screen('changelog', ChangelogScreen::class)
    ->name('platform.changelog')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Changelog'), route('platform.changelog')));
