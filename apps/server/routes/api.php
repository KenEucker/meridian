<?php

use App\Http\Controllers\Attendance\AttendanceCommandController;
use App\Http\Controllers\Auth\ApiAuthController;
use App\Http\Controllers\Auth\SharedWorkstationLoginCodeController;
use App\Http\Controllers\Auth\SharedWorkstationSessionController;
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
use App\Http\Controllers\Node\NodeHealthReportController;
use App\Http\Controllers\Node\NodePairingController;
use App\Http\Controllers\Node\NodeSyncController;
use App\Http\Controllers\Reporting\ReportingExportController;
use App\Http\Controllers\Session\SessionController;
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

// Sanitized node health reports (technical spec 22A.11). Like the sync
// exchange, the reporting node's signature over the report is the credential.
// Reports arrive every ten minutes per node, so the throttle stays modest.
Route::post('/node-health-report', [NodeHealthReportController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('api.node-health-report.store');

/*
 * API login (AUTH-018, AUTH-019, AUTH-021, AUTH-024; technical spec 11.4;
 * data/API 5.4).
 *
 * A client posts an email address, the node mails a login code, and the client
 * posts that code back — with the device the token will be bound to — for a
 * bearer token, so verification completes without leaving the application.
 * Neither route can carry a session — that is the
 * point of them — so both are rate limited instead. Requesting a code sends
 * mail, and submitting one guesses a credential, so the request route is the
 * tighter of the two.
 */
Route::post('/auth/magic-link', [ApiAuthController::class, 'requestMagicLink'])
    ->middleware('throttle:5,1')
    ->name('api.auth.magic-link.store');

Route::post('/auth/magic-link/verify', [ApiAuthController::class, 'verifyMagicLink'])
    ->middleware('throttle:10,1')
    ->name('api.auth.magic-link.verify');

/*
 * Provider handoff (AUTH-020; technical spec 11.4; data/API 5.4).
 *
 * Starting a handoff opens a system browser at Google or Discord; exchanging its
 * result issues the token. Neither route can carry a session either, so both are
 * rate limited. Starting is the looser of the two — a person retrying a canceled
 * consent screen starts several — while the exchange presents a credential.
 *
 * The provider is constrained in the route rather than validated in the
 * controller, so an unsupported provider is a missing route instead of a login
 * refusal describing something Meridian does not offer. The constraint mirrors
 * the providers `App\Services\Auth\OAuthProviderRegistry::gateway()` resolves.
 */
Route::get('/auth/{provider}/start', [ApiAuthController::class, 'startProviderHandoff'])
    ->whereIn('provider', ['google', 'discord'])
    ->middleware('throttle:20,1')
    ->name('api.auth.provider.start');

Route::post('/auth/session', [ApiAuthController::class, 'createSession'])
    ->middleware('throttle:10,1')
    ->name('api.auth.session.store');

// A client disposing of its own token. God Mode revocation by token and by
// device (AUTH-022) lives in the console, at `platform.api-tokens`.
Route::delete('/auth/session', [ApiAuthController::class, 'destroySession'])
    ->middleware('auth:sanctum')
    ->name('api.auth.session.destroy');

/*
 * Self-service shared-workstation login codes (AUTH-026 through AUTH-029;
 * technical spec 13.2; data/API 12.4).
 *
 * The one login path that survives an on-site node with no route to central: a
 * person generates a code on a device where they already hold a session and
 * types it into the kiosk in front of them. Behind `auth:sanctum` because
 * holding that session is the whole authority for the request, and the code is
 * for whoever holds it — the route accepts no user field (AUTH-028).
 *
 * Throttled as well as domain rate limited (AUTH-029). The domain limits bound
 * how many codes may exist per user and per node; this bounds how hard one
 * caller may ask, which is a different question and the only one a refusal can
 * answer before any state is read.
 */
Route::post('/auth/shared-workstation-login-code', [SharedWorkstationLoginCodeController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:20,1'])
    ->name('api.auth.shared-workstation-login-code.store');

/*
 * Shared-workstation sessions (AUTH-030; technical spec 13.3; data/API 12.3).
 *
 * What entering a login code at a trusted shared workstation establishes: a
 * session scoped to one workstation and one event that ends five minutes after
 * the last thing its user did. It is not a bearer token and it trusts no device
 * (AUTH-030), which is why it arrives on its own routes and its own guard
 * instead of through `POST /auth/session`.
 *
 * Starting one carries no session — the typed code is the credential — so the
 * route is rate limited, on top of the per-workstation entry limit AUTH-029
 * already applies at the domain. Reading and ending one carry the session key,
 * so they sit behind the `workstation` guard; reading is also the "continue"
 * action behind the timeout warning, and resolving a session is activity.
 */
Route::post('/auth/shared-workstation-session', [SharedWorkstationSessionController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('api.auth.shared-workstation-session.store');

Route::get('/auth/shared-workstation-session', [SharedWorkstationSessionController::class, 'show'])
    ->middleware('auth:workstation')
    ->name('api.auth.shared-workstation-session.show');

Route::delete('/auth/shared-workstation-session', [SharedWorkstationSessionController::class, 'destroy'])
    ->middleware('auth:workstation')
    ->name('api.auth.shared-workstation-session.destroy');

/*
 * Session resolution (CLIENT-001 through CLIENT-003; technical spec 11A.2;
 * data/API 5.5).
 *
 * The first call a client makes after login: who the user is, the role codes
 * they hold, the capability codes those roles carry, and the organizations,
 * events, departments, and teams they are associated with. Behind
 * `auth:sanctum` rather than the local-field guard, because a session is
 * meaningless without the user whose token it belongs to.
 *
 * The `workstation` guard is accepted alongside it because a Kiosk needs the
 * same document and has no bearer token to ask for it with (AUTH-030). Which
 * credential a caller used changes nothing about the answer: the roles and
 * capabilities are the active user's, and the workstation's pinned context
 * grants no authority of its own (technical spec 13.3).
 */
Route::get('/me', [SessionController::class, 'show'])
    ->middleware('auth:sanctum,workstation')
    ->name('api.me');

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

    // Reporting exports (REPORT-001 through REPORT-005). Scope comes from the
    // caller's own authority; `department_id` may only narrow it.
    Route::get('/events/{event}/exports/credential-eligibility', [ReportingExportController::class, 'credentialEligibility'])
        ->name('api.events.exports.credential-eligibility');

    Route::get('/events/{event}/exports/shift-roster', [ReportingExportController::class, 'shiftRoster'])
        ->name('api.events.exports.shift-roster');

    Route::get('/events/{event}/exports/staff-contact', [ReportingExportController::class, 'staffContact'])
        ->name('api.events.exports.staff-contact');

    Route::get('/events/{event}/exports/hours-worked', [ReportingExportController::class, 'hoursWorked'])
        ->name('api.events.exports.hours-worked');

    Route::get('/events/{event}/exports/credits-earned', [ReportingExportController::class, 'creditsEarned'])
        ->name('api.events.exports.credits-earned');

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
