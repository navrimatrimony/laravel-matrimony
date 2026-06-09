<?php

use App\Http\Controllers\Suchak\DashboardController;
use App\Http\Middleware\EnforceCardOnboarding;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web — Suchak surface (authenticated users)
|--------------------------------------------------------------------------
| Phase-6 Day-1 only: route surface placeholder.
| TODO Phase-6 Day-2: add Suchak account verification gating.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', EnforceCardOnboarding::class])
    ->prefix('suchak')
    ->name('suchak.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    });
