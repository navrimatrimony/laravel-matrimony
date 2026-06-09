<?php

use App\Http\Controllers\Admin\Suchak\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web — admin Suchak surface (auth + admin middleware)
|--------------------------------------------------------------------------
| Phase-6 Day-1 only: route surface placeholder.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'admin'])
    ->prefix('admin/suchak')
    ->name('admin.suchak.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    });
