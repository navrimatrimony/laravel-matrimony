<?php

use App\Http\Controllers\Api\Suchak\SuchakCollaborationsApiController;
use App\Http\Controllers\Api\Suchak\SuchakCustomersApiController;
use App\Http\Controllers\Api\Suchak\SuchakDashboardApiController;
use App\Http\Controllers\Api\Suchak\SuchakIntakeApiController;
use App\Http\Controllers\Api\Suchak\SuchakManualProfileApiController;
use App\Http\Controllers\Api\Suchak\SuchakMeApiController;
use App\Http\Controllers\Api\Suchak\SuchakPaymentsApiController;
use App\Http\Controllers\Api\Suchak\SuchakSearchApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — Suchak mobile adapters (auth:sanctum + suchak.account)
|--------------------------------------------------------------------------
| Thin JSON transport over existing Suchak services. No new business rules.
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'suchak.account'])->prefix('suchak')->group(function () {
    Route::get('/me', SuchakMeApiController::class);
    Route::get('/dashboard', SuchakDashboardApiController::class);
    Route::get('/customers', SuchakCustomersApiController::class);
    Route::get('/search', SuchakSearchApiController::class);
    Route::get('/collaborations', SuchakCollaborationsApiController::class);
    Route::get('/payments', SuchakPaymentsApiController::class);
    Route::post('/intakes', [SuchakIntakeApiController::class, 'store']);
    Route::get('/manual-profiles/meta', [SuchakManualProfileApiController::class, 'meta']);
    Route::post('/manual-profiles', [SuchakManualProfileApiController::class, 'store']);
});
