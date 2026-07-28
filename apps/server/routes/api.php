<?php

use App\Http\Controllers\Attendance\AttendanceCommandController;
use App\Http\Controllers\Branding\BrandingCommandController;
use App\Http\Controllers\Branding\BrandingReadController;
use App\Http\Controllers\Departments\DepartmentCommandController;
use App\Http\Controllers\Departments\DepartmentReadController;
use App\Http\Controllers\Departments\DepartmentSelfAdminCommandController;
use App\Http\Controllers\Deployments\DeploymentCommandController;
use App\Http\Controllers\Documents\DocumentCommandController;
use App\Http\Controllers\Documents\DocumentExportController;
use App\Http\Controllers\Documents\DocumentReadController;
use App\Http\Controllers\Equipment\EquipmentCommandController;
use App\Http\Controllers\Equipment\EquipmentInventoryCommandController;
use App\Http\Controllers\Equipment\EquipmentInventoryReadController;
use App\Http\Controllers\Events\EventInfoReadController;
use App\Http\Controllers\FieldReports\FieldReportCommandController;
use App\Http\Controllers\FieldReports\FieldReportPhotoController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Incidents\IncidentCommandController;
use App\Http\Controllers\Incidents\IncidentListPresetController;
use App\Http\Controllers\Incidents\IncidentPdfController;
use App\Http\Controllers\Incidents\IncidentReadController;
use App\Http\Controllers\Node\NodePairingController;
use App\Http\Controllers\Node\NodeSyncController;
use App\Http\Controllers\Shifts\ShiftAdminCommandController;
use App\Http\Controllers\Shifts\ShiftAdminReadController;
use App\Http\Controllers\Staffing\OrganizerStaffCommandController;
use App\Http\Controllers\Staffing\OrganizerStaffReadController;
use App\Http\Controllers\Teams\TeamCommandController;
use App\Http\Controllers\Teams\TeamReadController;
use App\Http\Controllers\Teams\TeamStaffCommandController;
use App\Http\Controllers\Trainings\TrainingCommandController;
use App\Http\Controllers\Trainings\TrainingReadController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'show'])->name('api.health');

// Node-to-node pairing (technical spec 7.3, 7.4). The one-time pairing token
// issued by central is the credential, so this route carries no user session;
// it is rate limited instead.
Route::post('/node-pairing', [NodePairingController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('api.node-pairing.store');

// Node-to-node sync exchange (technical spec 10.1, 10.2). The calling node's
// signature over the exchange is the credential, so this route carries no user
// session either. The throttle is looser than pairing's because a paired node
// syncs continuously while the internet exists, and a backlog drains over
// several exchanges in one run.
Route::post('/node-sync', [NodeSyncController::class, 'store'])
    ->middleware('throttle:120,1')
    ->name('api.node-sync.store');

// Branding is chrome, not operational content: every signed-in user of an
// organization sees its identity on every screen (BRAND-002), and a device
// resolves it before a session exists. Keeping this behind the local-field
// guard meant the client could not read branding without a dev API token.
Route::get('/organizations/{organization}/branding', [BrandingReadController::class, 'show'])
    ->name('api.organizations.branding.show');

Route::middleware('local.field')->group(function (): void {
    Route::post('/commands/check-in-staff', [AttendanceCommandController::class, 'checkIn'])
        ->name('api.commands.check-in-staff');

    Route::post('/commands/check-out-staff', [AttendanceCommandController::class, 'checkOut'])
        ->name('api.commands.check-out-staff');

    Route::post('/commands/mark-no-show', [AttendanceCommandController::class, 'markNoShow'])
        ->name('api.commands.mark-no-show');

    Route::post('/commands/set-current-deployment', [DeploymentCommandController::class, 'setCurrent'])
        ->name('api.commands.set-current-deployment');

    Route::post('/commands/checkout-equipment', [EquipmentCommandController::class, 'checkout'])
        ->name('api.commands.checkout-equipment');

    Route::post('/commands/return-equipment', [EquipmentCommandController::class, 'returnEquipment'])
        ->name('api.commands.return-equipment');

    Route::post('/commands/create-equipment-item', [EquipmentInventoryCommandController::class, 'create'])
        ->name('api.commands.create-equipment-item');

    Route::post('/commands/update-equipment-item', [EquipmentInventoryCommandController::class, 'update'])
        ->name('api.commands.update-equipment-item');

    Route::post('/commands/archive-equipment-item', [EquipmentInventoryCommandController::class, 'archive'])
        ->name('api.commands.archive-equipment-item');

    Route::post('/commands/restore-equipment-item', [EquipmentInventoryCommandController::class, 'restore'])
        ->name('api.commands.restore-equipment-item');

    Route::post('/commands/import-equipment-inventory', [EquipmentInventoryCommandController::class, 'import'])
        ->name('api.commands.import-equipment-inventory');

    Route::post('/commands/submit-field-report', [FieldReportCommandController::class, 'submit'])
        ->name('api.commands.submit-field-report');

    Route::post('/commands/upload-field-report-photo', [FieldReportPhotoController::class, 'upload'])
        ->name('api.commands.upload-field-report-photo');

    Route::post('/commands/create-incident', [IncidentCommandController::class, 'create'])
        ->name('api.commands.create-incident');

    Route::post('/commands/update-incident', [IncidentCommandController::class, 'update'])
        ->name('api.commands.update-incident');

    Route::post('/commands/link-incident', [IncidentCommandController::class, 'linkIncident'])
        ->name('api.commands.link-incident');

    Route::post('/commands/unlink-incident', [IncidentCommandController::class, 'unlinkIncident'])
        ->name('api.commands.unlink-incident');

    Route::post('/commands/link-field-report', [IncidentCommandController::class, 'linkFieldReport'])
        ->name('api.commands.link-field-report');

    Route::post('/commands/unlink-field-report', [IncidentCommandController::class, 'unlinkFieldReport'])
        ->name('api.commands.unlink-field-report');

    Route::post('/commands/strike-incident-attachment', [IncidentCommandController::class, 'strikeAttachment'])
        ->name('api.commands.strike-incident-attachment');

    Route::post('/commands/append-incident-note', [IncidentCommandController::class, 'appendNote'])
        ->name('api.commands.append-incident-note');

    Route::post('/commands/strike-incident-note', [IncidentCommandController::class, 'strikeNote'])
        ->name('api.commands.strike-incident-note');

    Route::post('/commands/save-incident-list-preset', [IncidentListPresetController::class, 'save'])
        ->name('api.commands.save-incident-list-preset');

    Route::post('/commands/delete-incident-list-preset', [IncidentListPresetController::class, 'delete'])
        ->name('api.commands.delete-incident-list-preset');

    Route::post('/commands/update-organization-branding', [BrandingCommandController::class, 'updateOrganization'])
        ->name('api.commands.update-organization-branding');

    Route::post('/commands/update-department-branding', [BrandingCommandController::class, 'updateDepartment'])
        ->name('api.commands.update-department-branding');

    Route::post('/commands/preview-branding', [BrandingCommandController::class, 'preview'])
        ->name('api.commands.preview-branding');

    Route::post('/commands/upload-branding-asset', [BrandingCommandController::class, 'uploadAsset'])
        ->name('api.commands.upload-branding-asset');

    Route::post('/commands/remove-branding-asset', [BrandingCommandController::class, 'removeAsset'])
        ->name('api.commands.remove-branding-asset');

    /*
     * The organization's events and their marks, for the branding surface
     * (BRAND-028).
     *
     * Authenticated and permission-gated, unlike the branding profile read.
     * That read has to resolve before a session does and so is open, which is
     * exactly why it publishes only the one event an install is locked to — the
     * roster of everything an organization is running belongs behind a session.
     */
    Route::get('/organizations/{organization}/branding/events', [BrandingCommandController::class, 'events'])
        ->name('api.organizations.branding.events');

    Route::post('/commands/create-department', [DepartmentCommandController::class, 'create'])
        ->name('api.commands.create-department');

    Route::post('/commands/update-department', [DepartmentCommandController::class, 'update'])
        ->name('api.commands.update-department');

    Route::post('/commands/archive-department', [DepartmentCommandController::class, 'archive'])
        ->name('api.commands.archive-department');

    Route::post('/commands/restore-department', [DepartmentCommandController::class, 'restore'])
        ->name('api.commands.restore-department');

    Route::post('/commands/update-department-details', [DepartmentSelfAdminCommandController::class, 'updateDetails'])
        ->name('api.commands.update-department-details');

    Route::post('/commands/create-team', [TeamCommandController::class, 'create'])
        ->name('api.commands.create-team');

    Route::post('/commands/update-team', [TeamCommandController::class, 'update'])
        ->name('api.commands.update-team');

    Route::post('/commands/archive-team', [TeamCommandController::class, 'archive'])
        ->name('api.commands.archive-team');

    Route::post('/commands/restore-team', [TeamCommandController::class, 'restore'])
        ->name('api.commands.restore-team');

    Route::post('/commands/select-team-lead', [TeamStaffCommandController::class, 'selectTeamLead'])
        ->name('api.commands.select-team-lead');

    Route::post('/commands/remove-team-lead', [TeamStaffCommandController::class, 'removeTeamLead'])
        ->name('api.commands.remove-team-lead');

    Route::post('/commands/assign-staff-to-team', [TeamStaffCommandController::class, 'assignStaffToTeam'])
        ->name('api.commands.assign-staff-to-team');

    Route::post('/commands/remove-staff-from-team', [TeamStaffCommandController::class, 'removeStaffFromTeam'])
        ->name('api.commands.remove-staff-from-team');

    Route::post('/commands/create-shift', [ShiftAdminCommandController::class, 'create'])
        ->name('api.commands.create-shift');

    Route::post('/commands/update-shift', [ShiftAdminCommandController::class, 'update'])
        ->name('api.commands.update-shift');

    Route::post('/commands/cancel-shift', [ShiftAdminCommandController::class, 'cancel'])
        ->name('api.commands.cancel-shift');

    Route::post('/commands/restore-shift', [ShiftAdminCommandController::class, 'restore'])
        ->name('api.commands.restore-shift');

    Route::post('/commands/create-policy-document', [DocumentCommandController::class, 'createPolicy'])
        ->name('api.commands.create-policy-document');

    Route::post('/commands/update-policy-document', [DocumentCommandController::class, 'updatePolicy'])
        ->name('api.commands.update-policy-document');

    Route::post('/commands/publish-policy-document', [DocumentCommandController::class, 'publishPolicy'])
        ->name('api.commands.publish-policy-document');

    Route::post('/commands/archive-policy-document', [DocumentCommandController::class, 'archivePolicy'])
        ->name('api.commands.archive-policy-document');

    Route::post('/commands/create-procedure-document', [DocumentCommandController::class, 'createProcedure'])
        ->name('api.commands.create-procedure-document');

    Route::post('/commands/update-procedure-document', [DocumentCommandController::class, 'updateProcedure'])
        ->name('api.commands.update-procedure-document');

    Route::post('/commands/publish-procedure-document', [DocumentCommandController::class, 'publishProcedure'])
        ->name('api.commands.publish-procedure-document');

    Route::post('/commands/archive-procedure-document', [DocumentCommandController::class, 'archiveProcedure'])
        ->name('api.commands.archive-procedure-document');

    Route::post('/commands/create-document-fragment', [DocumentCommandController::class, 'createFragment'])
        ->name('api.commands.create-document-fragment');

    Route::post('/commands/update-document-fragment', [DocumentCommandController::class, 'updateFragment'])
        ->name('api.commands.update-document-fragment');

    Route::post('/commands/create-training', [TrainingCommandController::class, 'create'])
        ->name('api.commands.create-training');

    Route::post('/commands/update-training', [TrainingCommandController::class, 'update'])
        ->name('api.commands.update-training');

    Route::post('/commands/archive-training', [TrainingCommandController::class, 'archive'])
        ->name('api.commands.archive-training');

    Route::post('/commands/restore-training', [TrainingCommandController::class, 'restore'])
        ->name('api.commands.restore-training');

    Route::post('/commands/add-training-prerequisite', [TrainingCommandController::class, 'addPrerequisite'])
        ->name('api.commands.add-training-prerequisite');

    Route::post('/commands/remove-training-prerequisite', [TrainingCommandController::class, 'removePrerequisite'])
        ->name('api.commands.remove-training-prerequisite');

    Route::post('/commands/sign-up-for-training', [TrainingCommandController::class, 'signUp'])
        ->name('api.commands.sign-up-for-training');

    Route::post('/commands/cancel-training-signup', [TrainingCommandController::class, 'cancelSignup'])
        ->name('api.commands.cancel-training-signup');

    Route::post('/commands/record-training-completion', [TrainingCommandController::class, 'recordCompletion'])
        ->name('api.commands.record-training-completion');

    Route::post('/commands/import-training-completions', [TrainingCommandController::class, 'importCompletions'])
        ->name('api.commands.import-training-completions');

    Route::post('/commands/add-organization-staff', [OrganizerStaffCommandController::class, 'addStaff'])
        ->name('api.commands.add-organization-staff');

    Route::post('/commands/select-department-lead', [OrganizerStaffCommandController::class, 'selectDepartmentLead'])
        ->name('api.commands.select-department-lead');

    Route::post('/commands/remove-department-lead', [OrganizerStaffCommandController::class, 'removeDepartmentLead'])
        ->name('api.commands.remove-department-lead');

    Route::get('/organizations/{organization}/departments', [DepartmentReadController::class, 'index'])
        ->name('api.organizations.departments.index');

    Route::get('/organizations/{organization}/departments/{department}', [DepartmentReadController::class, 'show'])
        ->name('api.organizations.departments.show');

    Route::get('/organizations/{organization}/staff', [OrganizerStaffReadController::class, 'index'])
        ->name('api.organizations.staff.index');

    Route::get('/organizations/{organization}/documents', [DocumentReadController::class, 'index'])
        ->name('api.organizations.documents.index');

    Route::get('/policy-documents/{policyDocument}', [DocumentReadController::class, 'policy'])
        ->name('api.policy-documents.show');

    Route::get('/policy-documents/{policyDocument}/export/{format}', [DocumentExportController::class, 'apiPolicy'])
        ->whereIn('format', ['markdown', 'pdf'])
        ->name('api.policy-documents.export');

    Route::get('/procedure-documents/{procedureDocument}', [DocumentReadController::class, 'procedure'])
        ->name('api.procedure-documents.show');

    Route::get('/procedure-documents/{procedureDocument}/export/{format}', [DocumentExportController::class, 'apiProcedure'])
        ->whereIn('format', ['markdown', 'pdf'])
        ->name('api.procedure-documents.export');

    Route::get('/document-fragments/{fragment}', [DocumentReadController::class, 'fragment'])
        ->name('api.document-fragments.show');

    Route::get('/departments/{department}/trainings', [TrainingReadController::class, 'index'])
        ->name('api.departments.trainings.index');

    Route::get('/departments/{department}/trainings/{training}', [TrainingReadController::class, 'show'])
        ->name('api.departments.trainings.show');

    Route::get('/departments/{department}/equipment', [EquipmentInventoryReadController::class, 'index'])
        ->name('api.departments.equipment.index');

    Route::get('/departments/{department}/teams', [TeamReadController::class, 'index'])
        ->name('api.departments.teams.index');

    Route::get('/departments/{department}/teams/{team}', [TeamReadController::class, 'show'])
        ->name('api.departments.teams.show');

    Route::get('/departments/{department}/shifts', [ShiftAdminReadController::class, 'index'])
        ->name('api.departments.shifts.index');

    Route::get('/departments/{department}/shifts/{shift}', [ShiftAdminReadController::class, 'show'])
        ->name('api.departments.shifts.show');

    Route::get('/events/{event}/info', [EventInfoReadController::class, 'show'])
        ->name('api.events.info');

    Route::get('/events/{event}/incidents', [IncidentReadController::class, 'index'])
        ->name('api.events.incidents.index');

    Route::get('/events/{event}/incidents/{incident}', [IncidentReadController::class, 'show'])
        ->name('api.events.incidents.show');

    Route::get('/events/{event}/incidents/{incident}/pdf', [IncidentPdfController::class, 'download'])
        ->name('api.events.incidents.pdf');

    Route::post('/field-report-photos/{attachment}/preview-url', [FieldReportPhotoController::class, 'issuePreviewUrl'])
        ->name('api.field-report-photos.preview-url');

    Route::post('/field-report-photos/{attachment}/download-url', [FieldReportPhotoController::class, 'issueDownloadUrl'])
        ->name('api.field-report-photos.download-url');
});
