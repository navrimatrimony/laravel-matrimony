<?php

use App\Http\Controllers\Suchak\DashboardController;
use App\Http\Middleware\EnforceCardOnboarding;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web — Suchak surface (authenticated users)
|--------------------------------------------------------------------------
| Phase-6 Day-2: route surface remains placeholder; account foundation gate only.
| Verification-specific blocking is intentionally deferred.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', EnforceCardOnboarding::class, 'suchak.account'])
    ->prefix('suchak')
    ->name('suchak.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    });
