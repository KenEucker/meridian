<?php

use App\Http\Controllers\Application\ApplicationReviewController;
use App\Http\Controllers\Application\PublicParticipationController;
use App\Http\Controllers\Attendance\AttendanceCommandController;
use App\Http\Controllers\Audit\AuditReviewController;
use App\Http\Controllers\Auth\ApiAuthController;
use App\Http\Controllers\Auth\SharedWorkstationLoginCodeController;
use App\Http\Controllers\Auth\SharedWorkstationSessionController;
use App\Http\Controllers\Branding\BrandingCommandController;
use App\Http\Controllers\Branding\BrandingReadController;
use App\Http\Controllers\Credentials\EventCredentialAdminController;
use App\Http\Controllers\Credits\CreditPolicyAdminController;
use App\Http\Controllers\Dashboard\DashboardReadController;
use App\Http\Controllers\DepartmentOps\DepartmentOperationsReadController;
use App\Http\Controllers\Departments\DepartmentCommandController;
use App\Http\Controllers\Departments\DepartmentReadController;
use App\Http\Controllers\Departments\DepartmentSelfAdminCommandController;
use App\Http\Controllers\Deployments\DeploymentCommandController;
use App\Http\Controllers\Documents\DocumentAcknowledgmentController;
use App\Http\Controllers\Documents\DocumentCommandController;
use App\Http\Controllers\Documents\DocumentExportController;
use App\Http\Controllers\Documents\DocumentReadController;
use App\Http\Controllers\Equipment\EquipmentCommandController;
use App\Http\Controllers\Equipment\EquipmentInventoryCommandController;
use App\Http\Controllers\Equipment\EquipmentInventoryReadController;
use App\Http\Controllers\Equipment\EquipmentLookupController;
use App\Http\Controllers\Events\EventAdministrationController;
use App\Http\Controllers\Events\EventInfoReadController;
use App\Http\Controllers\FieldReports\FieldReportCommandController;
use App\Http\Controllers\FieldReports\FieldReportDictationReadController;
use App\Http\Controllers\FieldReports\FieldReportPhotoController;
use App\Http\Controllers\FieldReports\FieldReportReadController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Incidents\IncidentCommandController;
use App\Http\Controllers\Incidents\IncidentListPresetController;
use App\Http\Controllers\Incidents\IncidentPdfController;
use App\Http\Controllers\Incidents\IncidentReadController;
use App\Http\Controllers\Incidents\IncidentTypeAdminController;
use App\Http\Controllers\Marketing\OrganizationInterestController;
use App\Http\Controllers\Node\NodeHealthReportController;
use App\Http\Controllers\Node\NodePairingController;
use App\Http\Controllers\Node\NodeSyncController;
use App\Http\Controllers\Organizations\OrganizationConfigurationController;
use App\Http\Controllers\Presence\DepartmentPresenceCommandController;
use App\Http\Controllers\Reporting\ReportingExportController;
use App\Http\Controllers\Session\SessionController;
use App\Http\Controllers\Shifts\ShiftAdminCommandController;
use App\Http\Controllers\Shifts\ShiftAdminReadController;
use App\Http\Controllers\Shifts\ShiftAssignmentCommandController;
use App\Http\Controllers\Shifts\ShiftBoardReadController;
use App\Http\Controllers\Shifts\ShiftSignupCommandController;
use App\Http\Controllers\Staffing\MyProfileController;
use App\Http\Controllers\Staffing\OrganizerStaffCommandController;
use App\Http\Controllers\Staffing\OrganizerStaffReadController;
use App\Http\Controllers\Staffing\StaffProfileChangeRequestController;
use App\Http\Controllers\Teams\OrganizationDesignationController;
use App\Http\Controllers\Teams\TeamCommandController;
use App\Http\Controllers\Teams\TeamDesignationCommandController;
use App\Http\Controllers\Teams\TeamReadController;
use App\Http\Controllers\Teams\TeamStaffCommandController;
use App\Http\Controllers\Trainings\TrainingCommandController;
use App\Http\Controllers\Trainings\TrainingReadController;
use App\Http\Controllers\Waivers\WaiverAdminController;
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
// resolves it before a session exists, which is why this one read carries no
// credential at all.
Route::get('/organizations/{organization}/branding', [BrandingReadController::class, 'show'])
    ->name('api.organizations.branding.show');

/*
 * The public participation surface (M18.21A; APP-016, APP-017, APP-018).
 *
 * Unauthenticated, because an application is made by somebody who has no
 * account yet — a credential cannot be the gate on the one surface whose whole
 * purpose is to reach people who hold none. What bounds it instead is what the
 * reads publish: an organization's own identity and the events it is recruiting
 * for, and nothing about staff, departments, teams, or how many people have
 * applied.
 *
 * Submission is throttled per client, matching the Blade form it sits beside.
 * The throttle bounds submission volume rather than enumeration, which is
 * handled by the responses being identical whatever Meridian already knows
 * about the address (STAT-006).
 */
Route::get('/public/organizations/{organization:slug}', [PublicParticipationController::class, 'organization'])
    ->name('api.public.organizations.show');

Route::get('/public/organizations/{organization:slug}/events/{event:slug}', [PublicParticipationController::class, 'event'])
    ->scopeBindings()
    ->name('api.public.organizations.events.show');

Route::post('/public/organizations/{organization:slug}/applications', [PublicParticipationController::class, 'submit'])
    ->middleware('throttle:10,1')
    ->name('api.public.organizations.applications.store');

/*
 * The applicant portal link request (M18.22; APP-012, APP-014, APP-015).
 *
 * Outside the organization scope the reads above sit in, because an address may
 * hold applications to more than one organization and the link opens all of
 * them. The APP-015 limits are counted in the domain service, which is what
 * makes them the same limits the server-rendered request form is bound by; the
 * route throttle here bounds request volume the way it does for submission.
 */
Route::post('/public/applicant-portal/link-requests', [PublicParticipationController::class, 'requestPortalLink'])
    ->middleware('throttle:10,1')
    ->name('api.public.applicant-portal.link-requests.store');

/*
 * The public marketing surface's server side (M18.23; PUBLIC-002 through
 * PUBLIC-006).
 *
 * The surface is a client application view; these are the two things a client
 * cannot decide for itself. The read says whether this node serves the surface
 * at all — it is a 404 on an on-site node and on a node locked to an event
 * (PUBLIC-006) — and hands out the form token a submission has to carry. The
 * write creates one inquiry row and nothing else (PUBLIC-003).
 *
 * Unauthenticated, like the participation surface above, because the person
 * filling this in has no account and is writing in to ask whether they should.
 *
 * The route throttle is the cheap ceiling. The limits PUBLIC-005 actually asks
 * for — per contact address and per submitting client, per hour — are counted
 * in `OrganizationInterestThrottle`, so they stay the same limits whatever
 * calls this.
 */
Route::get('/public/marketing-surface', [OrganizationInterestController::class, 'show'])
    ->name('api.public.marketing-surface.show');

Route::post('/public/organization-inquiries', [OrganizationInterestController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('api.public.organization-inquiries.store');

/*
 * Everything a client application reads and writes (AUTH-018; technical spec
 * 11.4; M16.11).
 *
 * The credential is a device-bound bearer token. Until this task these routes
 * sat behind `local.field`, a shared token configured on the node that
 * authenticated every caller as one seeded fixture user; that middleware and its
 * configuration are gone, so there is no longer any way to reach an operational
 * endpoint without a token issued to a person.
 *
 * The `workstation` guard is accepted alongside `sanctum` for the same reason
 * `GET /api/me` accepts it: a Kiosk holds a shared-workstation session key
 * rather than a personal token (AUTH-030), and a shared workstation that could
 * not check anybody in would not be a shared workstation. Which credential a
 * caller presents changes nothing about what follows — every endpoint in here
 * authorizes the resolved user against the same policies, and the workstation's
 * pinned context grants no authority of its own (technical spec 13.3).
 */
Route::middleware('auth:sanctum,workstation')->group(function (): void {
    Route::post('/commands/check-in-staff', [AttendanceCommandController::class, 'checkIn'])
        ->name('api.commands.check-in-staff');

    Route::post('/commands/check-out-staff', [AttendanceCommandController::class, 'checkOut'])
        ->name('api.commands.check-out-staff');

    Route::post('/commands/mark-no-show', [AttendanceCommandController::class, 'markNoShow'])
        ->name('api.commands.mark-no-show');

    /*
     * Hours correction (M18.4; SLB-007, SLB-031, SLB-032; HOURS-007,
     * HOURS-008). Its three neighbours above are offline writes and this one is
     * not: a correction is weighed against a grace period the node's clock
     * owns, so it is sent now or refused now rather than queued against a
     * window that may have closed while the device was away.
     */
    Route::post('/commands/correct-hours', [AttendanceCommandController::class, 'correctHours'])
        ->name('api.commands.correct-hours');

    /*
     * Credential revocation (M18.5; CRED-011 through CRED-013). Restricted to
     * organizers and Incident Command leads by the service itself, and
     * connected-only: it removes future shifts other people are being scheduled
     * around, so a copy held on a device would be a roster somebody is still
     * working from.
     */
    Route::post('/commands/revoke-credential', [EventCredentialAdminController::class, 'revoke'])
        ->name('api.commands.revoke-credential');

    Route::post('/commands/set-current-deployment', [DeploymentCommandController::class, 'setCurrent'])
        ->name('api.commands.set-current-deployment');

    Route::post('/commands/mark-staff-on-site', [DepartmentPresenceCommandController::class, 'markOnSite'])
        ->name('api.commands.mark-staff-on-site');

    Route::post('/commands/mark-staff-off-site', [DepartmentPresenceCommandController::class, 'markOffSite'])
        ->name('api.commands.mark-staff-off-site');

    Route::post('/commands/add-staff-to-shift', [ShiftAssignmentCommandController::class, 'addUnscheduledStaff'])
        ->name('api.commands.add-staff-to-shift');

    /*
     * Staff self-service on their own schedule (M18.2; SHIFT-011, SHIFT-013,
     * SHIFT-018). The neighbours above act on somebody else and are authorized
     * accordingly; these two act on the caller's own staff profile, which is
     * what the services check rather than a role.
     */
    Route::post('/commands/sign-up-for-shift', [ShiftSignupCommandController::class, 'signUp'])
        ->name('api.commands.sign-up-for-shift');

    Route::post('/commands/withdraw-from-shift', [ShiftSignupCommandController::class, 'withdraw'])
        ->name('api.commands.withdraw-from-shift');

    /*
     * Staff self-service on their own profile (M18.20; VOL-015, VOL-016,
     * VOL-026). Self-scoped like the two shift commands above: the record
     * written is one the caller's login speaks for, and no role is checked
     * because none is relevant. Preferred name, phone, and city/state apply
     * immediately; a submitted legal name, email, or date of birth is refused
     * with the assisted path named, never silently dropped.
     */
    Route::post('/commands/update-my-profile', [MyProfileController::class, 'update'])
        ->name('api.commands.update-my-profile');

    /*
     * The staff member's own handle and picture (M18.20B, M18.20C; VOL-017,
     * VOL-021, VOL-023, VOL-024).
     *
     * Self-scoped like the profile update above. Each of these acts on a staff
     * record the caller's login speaks for, and what happens next is the
     * domain's answer rather than the caller's: a handle applies now or waits
     * for review depending on the allowance, a picture always waits, and a
     * removal never does.
     */
    Route::post('/commands/request-handle-change', [MyProfileController::class, 'requestHandle'])
        ->name('api.commands.request-handle-change');

    Route::post('/commands/submit-profile-picture', [MyProfileController::class, 'submitPicture'])
        ->name('api.commands.submit-profile-picture');

    Route::post('/commands/remove-profile-picture', [MyProfileController::class, 'removePicture'])
        ->name('api.commands.remove-profile-picture');

    Route::post('/commands/withdraw-profile-change-request', [MyProfileController::class, 'withdrawRequest'])
        ->name('api.commands.withdraw-profile-change-request');

    // Clearing a decided request from your own surface (VOL-029). Withdrawal's
    // counterpart, for a request nobody is deciding any more.
    Route::post('/commands/dismiss-profile-change-request', [MyProfileController::class, 'dismissRequest'])
        ->name('api.commands.dismiss-profile-change-request');

    /*
     * The reviewer's two decisions (M18.20A, M18.20D; VOL-019, VOL-022,
     * VOL-025). Authorized by `staff.profile-change-requests.review` resolved
     * for the organization the request belongs to, which organizers, Lead
     * Organizers, and Staff Coordinators hold and nobody else does.
     */
    Route::post('/commands/approve-profile-change-request', [StaffProfileChangeRequestController::class, 'approve'])
        ->name('api.commands.approve-profile-change-request');

    Route::post('/commands/reject-profile-change-request', [StaffProfileChangeRequestController::class, 'reject'])
        ->name('api.commands.reject-profile-change-request');

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

    Route::post('/commands/create-incident-type', [IncidentTypeAdminController::class, 'create'])
        ->name('api.commands.create-incident-type');

    Route::post('/commands/rename-incident-type', [IncidentTypeAdminController::class, 'rename'])
        ->name('api.commands.rename-incident-type');

    Route::post('/commands/archive-incident-type', [IncidentTypeAdminController::class, 'archive'])
        ->name('api.commands.archive-incident-type');

    Route::post('/commands/restore-incident-type', [IncidentTypeAdminController::class, 'restore'])
        ->name('api.commands.restore-incident-type');

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

    /*
     * Application review as a product surface (M18.21A; APP-005, APP-011,
     * APP-019). One list read for both populations the access rules resolve —
     * reviewers and the department leads APP-011 grants read-only visibility —
     * because a client that asked for "all applications" and filtered would be
     * a client deciding scope.
     */
    Route::get('/applications', [ApplicationReviewController::class, 'index'])
        ->name('api.applications.index');

    Route::get('/applications/{application}', [ApplicationReviewController::class, 'show'])
        ->name('api.applications.show');

    Route::post('/commands/approve-application', [ApplicationReviewController::class, 'approve'])
        ->name('api.commands.approve-application');

    Route::post('/commands/reject-application', [ApplicationReviewController::class, 'reject'])
        ->name('api.commands.reject-application');

    Route::post('/commands/defer-application', [ApplicationReviewController::class, 'defer'])
        ->name('api.commands.defer-application');

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

    /*
     * Team designations (M18.12; TEAM-016). Department functions are
     * department administration; the Staff Coordinator designation is
     * organization configuration, and each path answers to its own authority.
     */
    Route::post('/commands/designate-department-team', [TeamDesignationCommandController::class, 'designate'])
        ->name('api.commands.designate-department-team');

    Route::post('/commands/remove-department-team-designation', [TeamDesignationCommandController::class, 'remove'])
        ->name('api.commands.remove-department-team-designation');

    Route::post('/commands/designate-staff-coordinator-team', [OrganizationDesignationController::class, 'designateStaffCoordinator'])
        ->name('api.commands.designate-staff-coordinator-team');

    Route::post('/commands/remove-staff-coordinator-team', [OrganizationDesignationController::class, 'removeStaffCoordinator'])
        ->name('api.commands.remove-staff-coordinator-team');

    /*
     * Organization configuration (M18.14; ORG-018, ORG-020, ORG-021). One
     * partial-update command rather than one command per field: the fields are
     * one governance record, edited by one authority, audited as one change.
     */
    Route::post('/commands/update-organization-configuration', [OrganizationConfigurationController::class, 'update'])
        ->name('api.commands.update-organization-configuration');

    /*
     * Event administration as a product surface (M18.29; UI contract 12.6
     * `organizer.events`; ORG-006). One read carries the organization's events
     * with each one's eligible Incident Command departments and whether this
     * node may write it, because ORG-006 admits only a department assigned to
     * that event and an organization-wide list would offer refusals.
     *
     * Create and update are separate commands rather than one upsert: they are
     * different acts with different audit entries, and an endpoint that decided
     * which from the presence of an id would turn a typo'd id into a second
     * event.
     */
    Route::get('/organizations/{organization}/events', [EventAdministrationController::class, 'index'])
        ->name('api.organizations.events.index');

    Route::post('/commands/create-event', [EventAdministrationController::class, 'create'])
        ->name('api.commands.create-event');

    Route::post('/commands/update-event', [EventAdministrationController::class, 'update'])
        ->name('api.commands.update-event');

    /*
     * Audit review as a product surface (M18.29; requirements 2.4; UI contract
     * 12.6 `organizer.audit`). Organization-scoped, and narrowed by
     * `AuditReviewAccess` rather than here: ORG-015 keeps incident and Field
     * Report history out of what organizing reaches, and a filter this route
     * applied would be a rule one endpoint kept.
     */
    Route::get('/organizations/{organization}/audit', [AuditReviewController::class, 'index'])
        ->name('api.organizations.audit.index');

    /*
     * Credit policies and calculation runs (M18.16; ORG-009, ORG-020;
     * CREDIT-001 through CREDIT-003). Policy edits answer to configuration
     * governance — central-owned, frozen during the active event window — and
     * the calculation run is the CREDIT-001 product entry point.
     */
    Route::post('/commands/create-credit-policy', [CreditPolicyAdminController::class, 'create'])
        ->name('api.commands.create-credit-policy');

    Route::post('/commands/update-credit-policy', [CreditPolicyAdminController::class, 'update'])
        ->name('api.commands.update-credit-policy');

    Route::post('/commands/archive-credit-policy', [CreditPolicyAdminController::class, 'archive'])
        ->name('api.commands.archive-credit-policy');

    Route::post('/commands/restore-credit-policy', [CreditPolicyAdminController::class, 'restore'])
        ->name('api.commands.restore-credit-policy');

    Route::post('/commands/calculate-event-credits', [CreditPolicyAdminController::class, 'calculate'])
        ->name('api.commands.calculate-event-credits');

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

    /*
     * The document acknowledgment path (M18.6; POL-023 through POL-027,
     * POL-043 through POL-047).
     *
     * All three are connected-only, and acceptance is the one that matters.
     * Technical spec 21.9 and the M6.10 domain service both restrict
     * acknowledgment creation to the connected write path: the record has to
     * name the document version that was actually shown, and a device holding an
     * acceptance for later would be holding an acceptance of whichever version
     * it last cached rather than the one standing when it arrives.
     */
    Route::post('/commands/acknowledge-document', [DocumentAcknowledgmentController::class, 'acknowledge'])
        ->name('api.commands.acknowledge-document');

    Route::post('/commands/create-document-acknowledgment-requirement', [DocumentAcknowledgmentController::class, 'createRequirement'])
        ->name('api.commands.create-document-acknowledgment-requirement');

    Route::post('/commands/set-document-acknowledgment-requirement-active', [DocumentAcknowledgmentController::class, 'setRequirementActive'])
        ->name('api.commands.set-document-acknowledgment-requirement-active');

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

    /*
     * Waiver administration (M18.18; WAIVER-001 through WAIVER-006,
     * WAIVER-010). Authority follows the scope of the waiver, matching the
     * policy/procedure maintenance rule — organization-scoped waivers by
     * organizers, department-scoped by department leads, team-scoped by team
     * leads — so there is no waiver capability in the catalog to check here;
     * every endpoint resolves the caller's maintainable scopes instead.
     */
    Route::post('/commands/create-waiver', [WaiverAdminController::class, 'create'])
        ->name('api.commands.create-waiver');

    Route::post('/commands/update-waiver', [WaiverAdminController::class, 'update'])
        ->name('api.commands.update-waiver');

    Route::post('/commands/archive-waiver', [WaiverAdminController::class, 'archive'])
        ->name('api.commands.archive-waiver');

    Route::post('/commands/restore-waiver', [WaiverAdminController::class, 'restore'])
        ->name('api.commands.restore-waiver');

    Route::post('/commands/record-waiver-completion', [WaiverAdminController::class, 'recordCompletion'])
        ->name('api.commands.record-waiver-completion');

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

    Route::get('/organizations/{organization}/incident-types', [IncidentTypeAdminController::class, 'index'])
        ->name('api.organizations.incident-types.index');

    Route::get('/organizations/{organization}/designations', [OrganizationDesignationController::class, 'index'])
        ->name('api.organizations.designations.index');

    Route::get('/organizations/{organization}/configuration', [OrganizationConfigurationController::class, 'show'])
        ->name('api.organizations.configuration.show');

    Route::get('/organizations/{organization}/credit-policies', [CreditPolicyAdminController::class, 'index'])
        ->name('api.organizations.credit-policies.index');

    Route::get('/organizations/{organization}/documents', [DocumentReadController::class, 'index'])
        ->name('api.organizations.documents.index');

    Route::get('/organizations/{organization}/waivers', [WaiverAdminController::class, 'index'])
        ->name('api.organizations.waivers.index');

    Route::get('/organizations/{organization}/waivers/{waiver}', [WaiverAdminController::class, 'show'])
        ->name('api.organizations.waivers.show');

    /*
     * The two acknowledgment reads (M18.6). Deliberately a pair rather than one
     * endpoint with a filter: they answer different questions and carry
     * different authority. `/me` is a fact about the caller and needs none — a
     * person may always read what they have been asked — while the review is an
     * organizer's read of other people and answers to
     * `documents.acknowledgments.review`.
     */
    Route::get('/document-acknowledgments/me', [DocumentAcknowledgmentController::class, 'mine'])
        ->name('api.document-acknowledgments.me');

    /*
     * The caller's own staff profile (M18.20; VOL-009, VOL-014; UI contract
     * 12.3). A fact about the caller, like the acknowledgment read above, so it
     * needs no authority beyond a credential: it answers with the staff records
     * this login speaks for and nobody else's.
     */
    Route::get('/me/profile', [MyProfileController::class, 'show'])
        ->name('api.me.profile');

    /*
     * The reviewer's queue (M18.20D; VOL-019, VOL-020; UI contract 12.6). The
     * counterpart of the read above: that one is a fact about the caller, this
     * one is other people's requests and answers to the review capability. A
     * caller holding it in no organization is refused rather than shown an
     * empty list, because those are different answers.
     */
    Route::get('/staff-profile-change-requests', [StaffProfileChangeRequestController::class, 'index'])
        ->name('api.staff-profile-change-requests.index');

    Route::get('/organizations/{organization}/document-acknowledgments', [DocumentAcknowledgmentController::class, 'review'])
        ->name('api.organizations.document-acknowledgments.index');

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

    /*
     * The staff shift board (M18.2; SHIFT-018). Event-scoped and self-scoped at
     * once: the event is in the path, and who the board is about is the caller's
     * own staff profiles rather than anything the request may name.
     */
    Route::get('/events/{event}/shift-board', [ShiftBoardReadController::class, 'index'])
        ->name('api.events.shift-board');

    /*
     * The department operations surfaces (SLB-001 through SLB-022; technical
     * spec 20.5; UI contract 12.4).
     *
     * Event and department both sit in the path because that is the scope these
     * surfaces work in: presence is per event, department, and staff member, and
     * a shift belongs to exactly one department. One read per surface, each
     * carrying the same authority block the commands enforce.
     */
    Route::get('/events/{event}/departments/{department}/overview', [DepartmentOperationsReadController::class, 'overview'])
        ->name('api.events.departments.overview');

    Route::get('/events/{event}/departments/{department}/logistics', [DepartmentOperationsReadController::class, 'logistics'])
        ->name('api.events.departments.logistics');

    Route::get('/events/{event}/departments/{department}/operations', [DepartmentOperationsReadController::class, 'operations'])
        ->name('api.events.departments.operations');

    Route::get('/events/{event}/departments/{department}/planning', [DepartmentOperationsReadController::class, 'planning'])
        ->name('api.events.departments.planning');

    /*
     * The dashboard read (M18.28; UI contract 13.1 through 13.6).
     *
     * One read behind every dashboard surface — `staff.dashboard`,
     * `department.dashboard`, `organizer.dashboard`, `ims.dashboard`, and
     * `kiosk.home` — because they are five presentations of one question and
     * five endpoints would be five places for the answers to disagree. The
     * department-scoped groups compile against `?department_id=`; the rest are
     * event-scoped and need none. A group the caller does not hold is absent
     * from the response rather than empty.
     */
    Route::get('/events/{event}/dashboard', [DashboardReadController::class, 'show'])
        ->name('api.events.dashboard');

    /*
     * Equipment lookup at checkout (M18.24C; EQUIP-012, EQUIP-013, EQUIP-015).
     * The desk resolves the same value against its cached inventory when no
     * node is reachable; this is the node's answer when one is.
     */
    Route::get('/events/{event}/departments/{department}/equipment-lookup', EquipmentLookupController::class)
        ->name('api.events.departments.equipment-lookup');

    /*
     * Event credential administration (M18.5; CRED-009 through CRED-014; UI
     * contract 12.6).
     *
     * It sits above the exports rather than among them on purpose. The
     * eligibility export below reads the same records and answers to
     * `reports.credential_eligibility.export`, which a department lead holds for
     * their own department; this one answers to `event.credentials.revoke`,
     * which only organizers and Incident Command leads hold, because it is the
     * list a revocation is aimed from.
     */
    Route::get('/events/{event}/credentials', [EventCredentialAdminController::class, 'index'])
        ->name('api.events.credentials.index');

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

    Route::get('/events/{event}/field-reports', [FieldReportReadController::class, 'index'])
        ->name('api.events.field-reports.index');

    /*
     * The staff an operator may name when taking a Field Report for somebody
     * else (M18.24A; FR-015, FR-017).
     */
    Route::get('/events/{event}/field-report-dictation', FieldReportDictationReadController::class)
        ->name('api.events.field-report-dictation');

    Route::get('/events/{event}/incidents', [IncidentReadController::class, 'index'])
        ->name('api.events.incidents.index');

    Route::get('/events/{event}/incidents/{incident}', [IncidentReadController::class, 'show'])
        ->name('api.events.incidents.show');

    Route::get('/events/{event}/incidents/{incident}/pdf', [IncidentPdfController::class, 'download'])
        ->name('api.events.incidents.pdf');

    /*
     * Short-lived scoped download URLs (CLIENT-019, CLIENT-020; technical spec
     * 11A.6; data/API 5.7).
     *
     * A bearer token cannot ride along on a plain browser navigation, so a
     * client asks one of these for a URL and then navigates to it. Each issues
     * a URL for exactly the resource named in its own path, under the same
     * authorization the direct request for that resource applies; the file
     * itself is served by the matching signed route in `routes/web.php`.
     *
     * The Field Report photo pair established this shape and keeps its paths.
     */
    Route::post('/events/{event}/exports/credential-eligibility/download-url', [ReportingExportController::class, 'issueCredentialEligibilityDownloadUrl'])
        ->name('api.events.exports.credential-eligibility.download-url');

    /*
     * The remaining four Alpha 1 exports on the same path (M18.25; REPORT-002
     * through REPORT-005, REPORT-015).
     *
     * Their plain GETs above have existed since Milestone 13; what they lacked
     * was this half, which is the only way a token-holding client can save one.
     * The reporting surfaces of M18.26 offer all five through one path rather
     * than one export that downloads differently from its four siblings.
     */
    Route::post('/events/{event}/exports/shift-roster/download-url', [ReportingExportController::class, 'issueShiftRosterDownloadUrl'])
        ->name('api.events.exports.shift-roster.download-url');

    Route::post('/events/{event}/exports/staff-contact/download-url', [ReportingExportController::class, 'issueStaffContactDownloadUrl'])
        ->name('api.events.exports.staff-contact.download-url');

    Route::post('/events/{event}/exports/hours-worked/download-url', [ReportingExportController::class, 'issueHoursWorkedDownloadUrl'])
        ->name('api.events.exports.hours-worked.download-url');

    Route::post('/events/{event}/exports/credits-earned/download-url', [ReportingExportController::class, 'issueCreditsEarnedDownloadUrl'])
        ->name('api.events.exports.credits-earned.download-url');

    Route::post('/events/{event}/incidents/{incident}/pdf/download-url', [IncidentPdfController::class, 'issueDownloadUrl'])
        ->name('api.events.incidents.pdf.download-url');

    Route::post('/policy-documents/{policyDocument}/export/{format}/download-url', [DocumentExportController::class, 'issuePolicyDownloadUrl'])
        ->whereIn('format', ['markdown', 'pdf'])
        ->name('api.policy-documents.export.download-url');

    Route::post('/procedure-documents/{procedureDocument}/export/{format}/download-url', [DocumentExportController::class, 'issueProcedureDownloadUrl'])
        ->whereIn('format', ['markdown', 'pdf'])
        ->name('api.procedure-documents.export.download-url');

    Route::post('/field-report-photos/{attachment}/preview-url', [FieldReportPhotoController::class, 'issuePreviewUrl'])
        ->name('api.field-report-photos.preview-url');

    Route::post('/field-report-photos/{attachment}/download-url', [FieldReportPhotoController::class, 'issueDownloadUrl'])
        ->name('api.field-report-photos.download-url');
});
