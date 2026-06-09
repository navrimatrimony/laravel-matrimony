<?php

use App\Http\Controllers\Suchak\AccountRequestController;
use App\Http\Controllers\Suchak\CrossSearchController;
use App\Http\Controllers\Suchak\DashboardController;
use App\Http\Controllers\Suchak\IntakeSourceController;
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
Route::prefix('suchak')
    ->name('suchak.')
    ->group(function () {
        Route::get('/register', [AccountRequestController::class, 'registrationInfo'])->name('register.info');
    });

Route::middleware(['auth', EnforceCardOnboarding::class])
    ->prefix('suchak')
    ->name('suchak.')
    ->group(function () {
        Route::get('/apply', [AccountRequestController::class, 'create'])->name('apply.create');
        Route::post('/apply', [AccountRequestController::class, 'store'])->name('apply.store');
    });

Route::middleware(['auth', EnforceCardOnboarding::class, 'suchak.account'])
    ->prefix('suchak')
    ->name('suchak.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/intakes/create', [IntakeSourceController::class, 'create'])->name('intakes.create');
        Route::post('/intakes', [IntakeSourceController::class, 'store'])->name('intakes.store');
        Route::get('/search', [CrossSearchController::class, 'index'])->name('search.index');
    });
