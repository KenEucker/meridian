<?php

use App\Http\Controllers\Application\ApplicantPortalController;
use App\Http\Controllers\Application\EventApplicationController;
use App\Http\Controllers\Auth\DiscordOAuthController;
use App\Http\Controllers\Auth\GoogleOAuthController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Branding\BrandingAssetController;
use App\Http\Controllers\Branding\BrandingManifestController;
use App\Http\Controllers\Branding\BrandingStylesheetController;
use App\Http\Controllers\ClientAppController;
use App\Http\Controllers\Documents\DocumentExportController;
use App\Http\Controllers\FieldReports\FieldReportPhotoController;
use App\Http\Controllers\Incidents\IncidentPdfController;
use App\Http\Controllers\Marketing\MarketingSurfaceController;
use App\Http\Controllers\Marketing\OrganizationInterestController;
use App\Http\Controllers\Reporting\ReportingExportController;
use App\Http\Controllers\Setup\NodeSetupController;
use App\Http\Controllers\Staffing\StaffProfileChangeRequestController;
use Illuminate\Support\Facades\Route;

/*
 * The deployment root (M18.23; PUBLIC-001, PUBLIC-006).
 *
 * One address, two answers. A visitor holding no session gets the public
 * marketing surface — the page PUBLIC-001 puts here for "organizations that do
 * not yet use it" — and a browser that already holds a Meridian session gets
 * the client application it came for. A node that does not serve the marketing
 * surface at all, meaning an on-site node or a node locked to an event, serves
 * the client application to everybody, which is what those nodes are deployed
 * to do.
 *
 * The route keeps its `client.app` name because the client is still what it
 * resolves to for every signed-in caller, and links elsewhere in the
 * application mean the product when they point here.
 */
Route::get('/', MarketingSurfaceController::class)->name('client.app');

/*
 * The marketing surface at an address of its own, and the organization interest
 * form it carries (PUBLIC-002 through PUBLIC-005).
 *
 * `/platform` exists so the page is reachable by a signed-in user who followed
 * a link to it, and so the form has somewhere to return a validation error to
 * that does not answer differently depending on who is asking. Both refuse on a
 * node that does not serve the surface, and the refusal is a 404: there, the
 * page is not there rather than withheld.
 *
 * The submission route carries an ordinary route throttle as well as the two
 * PUBLIC-005 counters inside `OrganizationInterestThrottle`. The counters are
 * the limits the requirement asks for, per address and per client per hour; the
 * route throttle is the cheaper ceiling that stops a flood before it reaches
 * validation.
 */
Route::get('platform', [MarketingSurfaceController::class, 'show'])
    ->name('public.marketing.landing');
Route::post('platform/interest', [OrganizationInterestController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('public.marketing.interest.store');
Route::get('platform/interest/received', [OrganizationInterestController::class, 'received'])
    ->name('public.marketing.interest.received');

Route::get('assets/{clientAssetPath}', [ClientAppController::class, 'asset'])
    ->where('clientAssetPath', '.*')
    ->name('client.assets');

// Branding layer and logo assets (M15A.4, M15A.5; BRAND-006, BRAND-022,
// BRAND-023). Both are unauthenticated: the stylesheet is chrome that has to
// resolve before a session does, and a logo is the identity that appears in
// generated PDFs and system email. Neither exposes operational content, and
// the stylesheet applies only where a surface has declared itself branded.
Route::get('branding/{organization}/tokens.css', [BrandingStylesheetController::class, 'show'])
    ->name('branding.stylesheet');
Route::get('branding/{organization}/manifest.json', [BrandingManifestController::class, 'show'])
    ->name('branding.manifest');
Route::get('branding/assets/{attachment}', [BrandingAssetController::class, 'show'])
    ->name('branding.asset');

Route::get('setup', [NodeSetupController::class, 'show'])->name('setup.show');
Route::post('setup', [NodeSetupController::class, 'store'])->name('setup.store');

// Public event application form (public.apply). Accessible to public or
// authenticated applicants (UI implementation contract section 12.1). Scoped by
// organization slug + event slug because event slugs are unique per organization.
Route::get('{organization:slug}/{event:slug}/apply', [EventApplicationController::class, 'create'])
    ->scopeBindings()
    ->name('public.events.apply');
Route::post('{organization:slug}/{event:slug}/apply', [EventApplicationController::class, 'store'])
    ->middleware('throttle:10,1')
    ->scopeBindings()
    ->name('public.events.apply.store');
Route::get('{organization:slug}/{event:slug}/apply/submitted', [EventApplicationController::class, 'submitted'])
    ->scopeBindings()
    ->name('public.events.apply.submitted');
Route::post('{organization:slug}/{event:slug}/apply/{application}/withdraw', [EventApplicationController::class, 'withdraw'])
    ->middleware('throttle:10,1')
    ->scopeBindings()
    ->name('public.events.apply.withdraw');

/*
 * The applicant portal (M18.22; APP-004, APP-012 through APP-015; AUTH-010).
 *
 * Unauthenticated, and named for what it holds rather than for who is looking:
 * an applicant has no account, so there is no user these applications hang off.
 * Reaching them takes a signed link mailed to the address they were submitted
 * with, which is the magic-link mechanism proving control of an address without
 * signing anybody in.
 *
 * These routes are declared above the client fallback so the portal is served
 * by the node rather than by the Vue shell, which has no way to hold a portal
 * session.
 *
 * Rate limiting is APP-015's two limits, applied inside
 * `ApplicantPortalThrottle` rather than by route middleware, so the API entry
 * point beside this one is bound by the same counters. `open` carries an
 * ordinary route throttle as well: it is the one route here that is not behind
 * those counters, and a signature is checked before anything else it does.
 */
Route::get('applications/link', [ApplicantPortalController::class, 'requestForm'])
    ->name('applicant-portal.request');
Route::post('applications/link', [ApplicantPortalController::class, 'requestLink'])
    ->name('applicant-portal.request.store');
Route::get('applications/link/sent', [ApplicantPortalController::class, 'sent'])
    ->name('applicant-portal.request.sent');
Route::get('applications/open', [ApplicantPortalController::class, 'open'])
    ->middleware(['signed:relative', 'throttle:20,1'])
    ->name('applicant-portal.open');
Route::get('applications', [ApplicantPortalController::class, 'show'])
    ->name('applicant-portal.show');
Route::post('applications/{application}/withdraw', [ApplicantPortalController::class, 'withdraw'])
    ->middleware('throttle:10,1')
    ->name('applicant-portal.withdraw');
Route::post('applications/close', [ApplicantPortalController::class, 'close'])
    ->name('applicant-portal.close');

Route::middleware('guest')->group(function (): void {
    Route::get('login', [MagicLinkController::class, 'create'])->name('login');
    Route::post('login/magic-link', [MagicLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('auth.magic-link.store');
    Route::get('login/magic-link/sent', [MagicLinkController::class, 'sent'])->name('auth.magic-link.sent');
    Route::get('login/magic-link/verify', [MagicLinkController::class, 'verify'])
        ->middleware('signed:relative')
        ->name('auth.magic-link.verify');
    Route::get('login/google', [GoogleOAuthController::class, 'redirect'])
        ->middleware('throttle:10,1')
        ->name('auth.google.redirect');
    Route::get('login/discord', [DiscordOAuthController::class, 'redirect'])
        ->middleware('throttle:10,1')
        ->name('auth.discord.redirect');
});

/*
 * Provider callbacks. A provider holds one registered redirect URI per node, so
 * these routes answer both an ordinary browser login and a client application's
 * system-browser handoff (AUTH-020; technical spec 11.4).
 *
 * They sit outside the `guest` group because the browser completing a handoff may
 * already hold a Meridian session of its own — on the web client it is the same
 * browser — and bouncing it to `/home` would abandon a sign-in a client
 * application is waiting on. A handoff callback establishes no session either
 * way; an ordinary browser login still does.
 */
Route::get('login/google/callback', [GoogleOAuthController::class, 'callback'])
    ->name('auth.google.callback');
Route::get('login/discord/callback', [DiscordOAuthController::class, 'callback'])
    ->name('auth.discord.callback');

Route::middleware('auth')->group(function (): void {
    Route::get('home', ClientAppController::class)->name('home');

    Route::get('logout', [LogoutController::class, 'create'])->name('logout');
    Route::post('logout', [LogoutController::class, 'destroy'])->name('logout.destroy');
});

/*
 * The far end of a short-lived scoped download URL (CLIENT-019, CLIENT-020;
 * technical spec 11A.6; data/API 5.7).
 *
 * These are navigations, not API calls: a browser follows them with no session
 * cookie and no bearer token, because being unable to attach a bearer token to
 * a navigation is the reason the pattern exists. The signature is the
 * credential. It covers the route and its parameters, so a URL issued for one
 * resource cannot be edited into a URL for another, and it carries the user it
 * was issued to, so each of these serves that person's file under that person's
 * authorization rather than whoever happens to be holding the link.
 *
 * `signed:relative` enforces both the signature and the expiry.
 */
Route::middleware('signed:relative')->group(function (): void {
    Route::get('downloads/events/{event}/exports/credential-eligibility', [ReportingExportController::class, 'signedCredentialEligibility'])
        ->name('downloads.exports.credential-eligibility');

    Route::get('downloads/events/{event}/incidents/{incident}/pdf', [IncidentPdfController::class, 'signedDownload'])
        ->name('downloads.incidents.pdf');

    Route::get('downloads/policy-documents/{policyDocument}/export/{format}', [DocumentExportController::class, 'signedPolicy'])
        ->whereIn('format', ['markdown', 'pdf'])
        ->name('downloads.policy-documents.export');

    Route::get('downloads/procedure-documents/{procedureDocument}/export/{format}', [DocumentExportController::class, 'signedProcedure'])
        ->whereIn('format', ['markdown', 'pdf'])
        ->name('downloads.procedure-documents.export');

    Route::get('field-report-photos/{attachment}/preview', [FieldReportPhotoController::class, 'preview'])
        ->name('field-report-photos.preview');
    Route::get('field-report-photos/{attachment}/download', [FieldReportPhotoController::class, 'download'])
        ->name('field-report-photos.download');

    /*
     * A submitted profile picture awaiting review (M18.20C; VOL-021). It lives
     * on the private disk rather than the public one the approved picture uses,
     * precisely so that reaching it requires this route — readable by the
     * staff member who submitted it and the users who may review it, and by
     * nobody else.
     */
    Route::get('staff-profile-pictures/{changeRequest}/pending', [StaffProfileChangeRequestController::class, 'pendingPicture'])
        ->name('staff-profile-pictures.pending');
});

Route::get('{clientPath}', ClientAppController::class)
    ->where('clientPath', '^(?!admin(?:/|$)|api(?:/|$)|up$|css/|js/|img/|favicon\.ico$|robots\.txt$)(?!.*(?:^|/)apply(?:/|$)).*$')
    ->name('client.app.route');
