<?php

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
use App\Http\Controllers\Reporting\ReportingExportController;
use App\Http\Controllers\Setup\NodeSetupController;
use Illuminate\Support\Facades\Route;

Route::get('/', ClientAppController::class)->name('client.app');
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
});

Route::get('{clientPath}', ClientAppController::class)
    ->where('clientPath', '^(?!admin(?:/|$)|api(?:/|$)|up$|css/|js/|img/|favicon\.ico$|robots\.txt$)(?!.*(?:^|/)apply(?:/|$)).*$')
    ->name('client.app.route');
