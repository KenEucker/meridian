<?php

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
});

Route::middleware('auth')->group(function (): void {
    Route::get('home', function () {
        return view('home');
    })->name('home');

    Route::get('logout', [LogoutController::class, 'create'])->name('logout');
    Route::post('logout', [LogoutController::class, 'destroy'])->name('logout.destroy');
});
