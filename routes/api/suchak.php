<?php

use App\Http\Controllers\Api\Suchak\SuchakBillingApiController;
use App\Http\Controllers\Api\Suchak\SuchakCollaborationsApiController;
use App\Http\Controllers\Api\Suchak\SuchakCollaborationsMutationsApiController;
use App\Http\Controllers\Api\Suchak\SuchakCustomerDetailApiController;
use App\Http\Controllers\Api\Suchak\SuchakCustomersApiController;
use App\Http\Controllers\Api\Suchak\SuchakDashboardApiController;
use App\Http\Controllers\Api\Suchak\SuchakIntakeApiController;
use App\Http\Controllers\Api\Suchak\SuchakManualProfileApiController;
use App\Http\Controllers\Api\Suchak\SuchakMeApiController;
use App\Http\Controllers\Api\Suchak\SuchakMeetingsApiController;
use App\Http\Controllers\Api\Suchak\SuchakMeetingsMutationsApiController;
use App\Http\Controllers\Api\Suchak\SuchakPaymentIdentityApiController;
use App\Http\Controllers\Api\Suchak\SuchakPaymentRequestsApiController;
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
    Route::get('/customers/{representation}', [SuchakCustomerDetailApiController::class, 'show']);
    Route::get('/search', SuchakSearchApiController::class);
    Route::get('/collaborations', SuchakCollaborationsApiController::class);
    Route::post('/collaborations', [SuchakCollaborationsMutationsApiController::class, 'store']);
    Route::post('/collaborations/{collaboration}/accept', [SuchakCollaborationsMutationsApiController::class, 'accept']);
    Route::post('/collaborations/{collaboration}/reject', [SuchakCollaborationsMutationsApiController::class, 'reject']);
    Route::get('/payments', SuchakPaymentsApiController::class);
    Route::get('/payment-identity', [SuchakPaymentIdentityApiController::class, 'show']);
    Route::post('/payment-identity', [SuchakPaymentIdentityApiController::class, 'update']);
    Route::post('/payment-requests', [SuchakPaymentRequestsApiController::class, 'store']);
    Route::post('/payment-requests/{paymentRequest}/mark-paid', [SuchakPaymentRequestsApiController::class, 'markPaid']);
    Route::get('/plans', [SuchakBillingApiController::class, 'plans']);
    Route::get('/billing', [SuchakBillingApiController::class, 'status']);
    Route::get('/meetings', SuchakMeetingsApiController::class);
    Route::post('/meetings', [SuchakMeetingsMutationsApiController::class, 'schedule']);
    Route::post('/meetings/{visit}/complete', [SuchakMeetingsMutationsApiController::class, 'complete']);
    Route::post('/intakes', [SuchakIntakeApiController::class, 'store']);
    Route::get('/manual-profiles/meta', [SuchakManualProfileApiController::class, 'meta']);
    Route::post('/manual-profiles', [SuchakManualProfileApiController::class, 'store']);
});
