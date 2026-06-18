<?php

use App\Http\Controllers\Auth\DiscordOAuthController;
use App\Http\Controllers\Auth\GoogleOAuthController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MagicLinkController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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
    Route::get('home', function () {
        return view('home');
    })->name('home');

    Route::get('logout', [LogoutController::class, 'create'])->name('logout');
    Route::post('logout', [LogoutController::class, 'destroy'])->name('logout.destroy');
});
