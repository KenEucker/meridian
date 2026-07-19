<?php

use App\Http\Controllers\Application\EventApplicationController;
use App\Http\Controllers\Auth\DiscordOAuthController;
use App\Http\Controllers\Auth\GoogleOAuthController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\ClientAppController;
use App\Http\Controllers\FieldReports\FieldReportPhotoController;
use App\Http\Controllers\Setup\NodeSetupController;
use Illuminate\Support\Facades\Route;

Route::get('/', ClientAppController::class)->name('client.app');
Route::get('assets/{clientAssetPath}', [ClientAppController::class, 'asset'])
    ->where('clientAssetPath', '.*')
    ->name('client.assets');

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
    Route::get('login/google/callback', [GoogleOAuthController::class, 'callback'])
        ->name('auth.google.callback');
    Route::get('login/discord', [DiscordOAuthController::class, 'redirect'])
        ->middleware('throttle:10,1')
        ->name('auth.discord.redirect');
    Route::get('login/discord/callback', [DiscordOAuthController::class, 'callback'])
        ->name('auth.discord.callback');
});

Route::middleware('auth')->group(function (): void {
    Route::get('home', ClientAppController::class)->name('home');

    Route::get('logout', [LogoutController::class, 'create'])->name('logout');
    Route::post('logout', [LogoutController::class, 'destroy'])->name('logout.destroy');

    // Short-lived signed Field Report photo URLs (technical spec 18.6). Signature
    // expiry is enforced by signed:relative; authorization is re-checked on use.
    Route::get('field-report-photos/{attachment}/preview', [FieldReportPhotoController::class, 'preview'])
        ->middleware('signed:relative')
        ->name('field-report-photos.preview');
    Route::get('field-report-photos/{attachment}/download', [FieldReportPhotoController::class, 'download'])
        ->middleware('signed:relative')
        ->name('field-report-photos.download');
});

Route::get('{clientPath}', ClientAppController::class)
    ->where('clientPath', '^(?!admin(?:/|$)|api(?:/|$)|up$|css/|js/|favicon\.ico$|robots\.txt$)(?!.*(?:^|/)apply(?:/|$)).*$')
    ->name('client.app.route');
