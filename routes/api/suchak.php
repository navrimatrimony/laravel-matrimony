<?php

use App\Http\Controllers\Api\Suchak\SuchakBillingApiController;
use App\Http\Controllers\Api\Suchak\SuchakCollaborationsApiController;
use App\Http\Controllers\Api\Suchak\SuchakCollaborationsMutationsApiController;
use App\Http\Controllers\Api\Suchak\SuchakConsentsApiController;
use App\Http\Controllers\Api\Suchak\SuchakCustomerDetailApiController;
use App\Http\Controllers\Api\Suchak\SuchakCustomersApiController;
use App\Http\Controllers\Api\Suchak\SuchakDashboardApiController;
use App\Http\Controllers\Api\Suchak\SuchakIntakeApiController;
use App\Http\Controllers\Api\Suchak\SuchakLoginApiController;
use App\Http\Controllers\Api\Suchak\SuchakManualProfileApiController;
use App\Http\Controllers\Api\Suchak\SuchakMeApiController;
use App\Http\Controllers\Api\Suchak\SuchakMeetingsApiController;
use App\Http\Controllers\Api\Suchak\SuchakMeetingsMutationsApiController;
use App\Http\Controllers\Api\Suchak\SuchakCustomerOpsApiController;
use App\Http\Controllers\Api\Suchak\SuchakPaymentSetupApiController;
use App\Http\Controllers\Api\Suchak\SuchakPaymentIdentityApiController;
use App\Http\Controllers\Api\Suchak\SuchakPaymentRequestOptionsApiController;
use App\Http\Controllers\Api\Suchak\SuchakPaymentRequestsApiController;
use App\Http\Controllers\Api\Suchak\SuchakPaymentsApiController;
use App\Http\Controllers\Api\Suchak\SuchakPayuCheckoutApiController;
use App\Http\Controllers\Api\Suchak\SuchakRegisterApiController;
use App\Http\Controllers\Api\Suchak\SuchakSearchApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — Suchak mobile adapters (auth:sanctum + suchak.account)
|--------------------------------------------------------------------------
| Thin JSON transport over existing Suchak services. No new business rules.
|--------------------------------------------------------------------------
*/

/*
| Public Suchak auth + registration (User ≠ Suchak — separate from member OTP).
*/
Route::prefix('suchak')->group(function () {
    Route::post('/login/otp/send', [SuchakLoginApiController::class, 'sendOtp']);
    Route::post('/login/otp/verify', [SuchakLoginApiController::class, 'verifyOtp']);
    Route::post('/login/password', [SuchakLoginApiController::class, 'loginWithPassword']);
    Route::post('/login/google', [SuchakLoginApiController::class, 'loginWithGoogle']);

    Route::post('/register', [SuchakRegisterApiController::class, 'store']);
    Route::post('/register/resolve-location', [SuchakRegisterApiController::class, 'resolveLocation']);
});

Route::middleware(['auth:sanctum', 'suchak.account'])->prefix('suchak')->group(function () {
    Route::post('/register/otp/resend', [SuchakRegisterApiController::class, 'resendOtp']);
    Route::post('/register/otp/verify', [SuchakRegisterApiController::class, 'verifyOtp']);
    Route::post('/register/photo', [SuchakRegisterApiController::class, 'storePhoto']);
    Route::post('/register/documents', [SuchakRegisterApiController::class, 'storeDocument']);
    Route::get('/register/status', [SuchakRegisterApiController::class, 'status']);

    Route::get('/me', SuchakMeApiController::class);
    Route::get('/dashboard', SuchakDashboardApiController::class);
    Route::get('/customers', SuchakCustomersApiController::class);
    Route::get('/customers/{representation}', [SuchakCustomerDetailApiController::class, 'show']);
    Route::post('/customers/{representation}/consents', [SuchakConsentsApiController::class, 'store']);
    Route::post('/customers/{representation}/consents/renew', [SuchakConsentsApiController::class, 'renew']);
    Route::get('/customers/{representation}/payment-request-options', SuchakPaymentRequestOptionsApiController::class);
    Route::post('/customers/{representation}/payment-setup', SuchakPaymentSetupApiController::class);
    Route::post('/customers/{representation}/notes', [SuchakCustomerOpsApiController::class, 'storeNote']);
    Route::post('/customers/{representation}/exports', [SuchakCustomerOpsApiController::class, 'exportBiodata']);
    Route::post('/consents/{consent}/resend', [SuchakConsentsApiController::class, 'resend']);
    Route::post('/consents/{consent}/cancel-pending', [SuchakConsentsApiController::class, 'cancelPending']);
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
    Route::post('/plans/{plan}/payu/start', [SuchakPayuCheckoutApiController::class, 'start']);
    Route::get('/meetings', SuchakMeetingsApiController::class);
    Route::post('/meetings', [SuchakMeetingsMutationsApiController::class, 'schedule']);
    Route::post('/meetings/{visit}/complete', [SuchakMeetingsMutationsApiController::class, 'complete']);
    Route::post('/intakes', [SuchakIntakeApiController::class, 'store']);
    Route::get('/manual-profiles/meta', [SuchakManualProfileApiController::class, 'meta']);
    Route::post('/manual-profiles', [SuchakManualProfileApiController::class, 'store']);
});
