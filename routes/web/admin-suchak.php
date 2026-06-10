<?php

use App\Http\Controllers\Admin\Suchak\AccountVerificationController;
use App\Http\Controllers\Admin\Suchak\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web — admin Suchak surface (auth + admin middleware)
|--------------------------------------------------------------------------
| Phase-6 Suchak account verification and admin review surface.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'admin'])
    ->prefix('admin/suchak')
    ->name('admin.suchak.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/accounts', [AccountVerificationController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/{suchakAccount}', [AccountVerificationController::class, 'show'])->name('accounts.show');
        Route::post('/accounts/{suchakAccount}/approve', [AccountVerificationController::class, 'approve'])->name('accounts.approve');
        Route::post('/accounts/{suchakAccount}/reject', [AccountVerificationController::class, 'reject'])->name('accounts.reject');
        Route::post('/accounts/{suchakAccount}/suspend', [AccountVerificationController::class, 'suspend'])->name('accounts.suspend');
        Route::post('/accounts/{suchakAccount}/archive', [AccountVerificationController::class, 'archive'])->name('accounts.archive');
        Route::post('/accounts/{suchakAccount}/reactivate', [AccountVerificationController::class, 'reactivate'])->name('accounts.reactivate');
        Route::post('/accounts/{suchakAccount}/public-status', [AccountVerificationController::class, 'updatePublicStatus'])->name('accounts.public-status.update');
        Route::post('/accounts/{suchakAccount}/verification-records/{verificationRecord}/approve', [AccountVerificationController::class, 'approveVerificationRecord'])->name('accounts.verification-records.approve');
        Route::post('/accounts/{suchakAccount}/verification-records/{verificationRecord}/reject', [AccountVerificationController::class, 'rejectVerificationRecord'])->name('accounts.verification-records.reject');
    });
