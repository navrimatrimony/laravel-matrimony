<?php

use App\Http\Controllers\Suchak\AccountRequestController;
use App\Http\Controllers\Suchak\BiodataExportController;
use App\Http\Controllers\Suchak\CollaborationController;
use App\Http\Controllers\Suchak\ConsentController;
use App\Http\Controllers\Suchak\CrmLedgerController;
use App\Http\Controllers\Suchak\CrossSearchController;
use App\Http\Controllers\Suchak\CustomerPortalController;
use App\Http\Controllers\Suchak\DashboardController;
use App\Http\Controllers\Suchak\DirectPaymentComplaintController;
use App\Http\Controllers\Suchak\ExportRetentionController;
use App\Http\Controllers\Suchak\IntakeSourceController;
use App\Http\Controllers\Suchak\OfflineCampController;
use App\Http\Controllers\Suchak\PaymentRequestPublicController;
use App\Http\Controllers\Suchak\PlanPaymentController;
use App\Http\Controllers\Suchak\ProfileUpdateSuggestionController;
use App\Http\Controllers\Suchak\PublicMarketplaceController;
use App\Http\Controllers\Suchak\QrScanController;
use App\Http\Controllers\Suchak\ReceiptVerificationController;
use App\Http\Controllers\Suchak\TrainingAcademyController;
use App\Http\Middleware\EnforceCardOnboarding;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web — Suchak surface (authenticated users)
|--------------------------------------------------------------------------
| Phase-6 Suchak MVP routes. Verification-specific action blocking remains inside
| the governed services used by these thin web controllers.
|--------------------------------------------------------------------------
*/
Route::get('/r/{token}', [QrScanController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('suchak.qr.show');

Route::get('/suchak', [AccountRequestController::class, 'home'])->name('suchak.home');

Route::prefix('suchak')
    ->name('suchak.')
    ->group(function () {
        Route::get('/register', [AccountRequestController::class, 'registrationInfo'])->name('register.info');
        Route::post('/register', [AccountRequestController::class, 'storeRegistration'])
            ->middleware('throttle:5,1')
            ->name('register.store');
        Route::get('/marketplace', [PublicMarketplaceController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('marketplace.index');
        Route::get('/marketplace/{suchakAccount}', [PublicMarketplaceController::class, 'show'])
            ->middleware('throttle:60,1')
            ->name('marketplace.show');
        Route::post('/plans/payu/success', [PlanPaymentController::class, 'success'])->name('plans.payu.success');
        Route::post('/plans/payu/failure', [PlanPaymentController::class, 'failure'])->name('plans.payu.failure');
        Route::post('/plans/payu/webhook', [PlanPaymentController::class, 'webhook'])->name('plans.payu.webhook');
        Route::get('/payment-requests/{token}', [PaymentRequestPublicController::class, 'show'])
            ->where('token', '[A-Za-z0-9]{64}')
            ->middleware('throttle:30,1')
            ->name('payment-requests.show');
        Route::get('/customer-portal/{token}', [CustomerPortalController::class, 'show'])
            ->where('token', '[A-Za-z0-9]{64}')
            ->middleware('throttle:30,1')
            ->name('customer-portal.show');
        Route::post('/customer-portal/{token}/claim', [CustomerPortalController::class, 'claim'])
            ->where('token', '[A-Za-z0-9]{64}')
            ->middleware('throttle:10,1')
            ->name('customer-portal.claim');
        Route::post('/customer-portal/{token}/revoke', [CustomerPortalController::class, 'revoke'])
            ->where('token', '[A-Za-z0-9]{64}')
            ->middleware('throttle:10,1')
            ->name('customer-portal.revoke');
        Route::get('/receipts/verify/{code}', [ReceiptVerificationController::class, 'show'])
            ->where('code', '[A-Za-z0-9]{32}')
            ->middleware('throttle:30,1')
            ->name('receipts.verify');
    });

Route::middleware('auth')
    ->prefix('suchak')
    ->name('suchak.')
    ->group(function () {
        Route::post('/direct-payment-complaints', [DirectPaymentComplaintController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('direct-payment-complaints.store');
        Route::get('/register/verify', [AccountRequestController::class, 'verify'])->name('register.verify');
        Route::post('/register/verify', [AccountRequestController::class, 'verifyRegistrationOtp'])
            ->middleware('throttle:10,1')
            ->name('register.verify.submit');
        Route::post('/register/otp/resend', [AccountRequestController::class, 'resendRegistrationOtp'])
            ->middleware('throttle:5,1')
            ->name('register.otp.resend');
        Route::get('/register/status', [AccountRequestController::class, 'status'])->name('register.status');
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
        Route::post('/plans/{suchakPlan}/payu/start', [PlanPaymentController::class, 'start'])
            ->middleware('throttle:10,1')
            ->name('plans.payu.start');
        Route::get('/plans/{suchakPlan}/payu/test-success', [PlanPaymentController::class, 'testSuccessSimulate'])
            ->middleware('throttle:10,1')
            ->name('plans.payu.test-success');
        Route::get('/intakes/create', [IntakeSourceController::class, 'create'])->name('intakes.create');
        Route::post('/intakes', [IntakeSourceController::class, 'store'])->name('intakes.store');
        Route::get('/search', [CrossSearchController::class, 'index'])->name('search.index');
        Route::post('/representations/{representation}/exports', [BiodataExportController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('representations.exports.store');
        Route::post('/representations/{representation}/consents', [ConsentController::class, 'request'])
            ->middleware('throttle:10,1')
            ->name('representations.consents.request');
        Route::post('/representations/{representation}/consents/renew', [ConsentController::class, 'renew'])
            ->middleware('throttle:10,1')
            ->name('representations.consents.renew');
        Route::post('/consents/{consent}/resend', [ConsentController::class, 'resend'])
            ->middleware('throttle:10,1')
            ->name('consents.resend');
        Route::post('/consents/{consent}/send-otp', [ConsentController::class, 'sendOtp'])
            ->middleware('throttle:10,1')
            ->name('consents.send-otp');
        Route::post('/consents/{consent}/verify-otp', [ConsentController::class, 'verifyOtp'])
            ->middleware('throttle:10,1')
            ->name('consents.verify-otp');
        Route::post('/consents/{consent}/manual-accept', [ConsentController::class, 'acceptManual'])
            ->middleware('throttle:10,1')
            ->name('consents.manual-accept');
        Route::post('/consents/{consent}/revoke', [ConsentController::class, 'revoke'])
            ->middleware('throttle:10,1')
            ->name('consents.revoke');
        Route::get('/exports/{export}/download', [BiodataExportController::class, 'download'])
            ->middleware('throttle:30,1')
            ->name('exports.download');
        Route::post('/exports/{export}/mark-shared', [BiodataExportController::class, 'markShared'])
            ->middleware('throttle:30,1')
            ->name('exports.mark-shared');
        Route::post('/qr-tokens/{qrToken}/revoke', [BiodataExportController::class, 'revokeQrToken'])
            ->middleware('throttle:30,1')
            ->name('qr-tokens.revoke');
        Route::post('/representations/{representation}/profile-update-suggestions', [ProfileUpdateSuggestionController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('representations.profile-update-suggestions.store');
        Route::post('/representations/{representation}/crm-notes', [CrmLedgerController::class, 'storeNote'])
            ->middleware('throttle:20,1')
            ->name('representations.crm-notes.store');
        Route::post('/representations/{representation}/ledger-entries', [CrmLedgerController::class, 'storeLedgerEntry'])
            ->middleware('throttle:20,1')
            ->name('representations.ledger-entries.store');
        Route::get('/collaborations', [CollaborationController::class, 'index'])
            ->name('collaborations.index');
        Route::get('/training-academy', [TrainingAcademyController::class, 'index'])
            ->name('training-academy.index');
        Route::post('/training-academy/message-templates/{messageTemplate}/use', [TrainingAcademyController::class, 'useTemplate'])
            ->middleware('throttle:20,1')
            ->name('training-academy.message-templates.use');
        Route::get('/export-retention', [ExportRetentionController::class, 'index'])
            ->name('export-retention.index');
        Route::post('/export-retention/exports', [ExportRetentionController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('export-retention.exports.store');
        Route::get('/export-retention/exports/{businessExport}/download', [ExportRetentionController::class, 'download'])
            ->middleware('throttle:30,1')
            ->name('export-retention.exports.download');
        Route::get('/offline-camps', [OfflineCampController::class, 'index'])
            ->name('offline-camps.index');
        Route::post('/offline-camps', [OfflineCampController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('offline-camps.store');
        Route::post('/offline-camps/{offlineCamp}/intakes', [OfflineCampController::class, 'uploadIntake'])
            ->middleware('throttle:20,1')
            ->name('offline-camps.intakes.store');
        Route::post('/offline-camps/{offlineCamp}/source-links', [OfflineCampController::class, 'linkSourceLinks'])
            ->middleware('throttle:20,1')
            ->name('offline-camps.source-links.store');
        Route::post('/offline-camps/intake-links/{campIntakeLink}/package-assignments', [OfflineCampController::class, 'assignPackage'])
            ->middleware('throttle:20,1')
            ->name('offline-camps.intake-links.package-assignments.store');
        Route::post('/offline-camps/{offlineCamp}/conversion-reports', [OfflineCampController::class, 'generateReport'])
            ->middleware('throttle:10,1')
            ->name('offline-camps.conversion-reports.generate');
        Route::post('/collaborations', [CollaborationController::class, 'store'])
            ->middleware('throttle:15,1')
            ->name('collaborations.store');
        Route::post('/collaborations/{collaborationRequest}/commission', [CollaborationController::class, 'updateCommission'])
            ->middleware('throttle:15,1')
            ->name('collaborations.commission.update');
        Route::post('/collaborations/{collaborationRequest}/accept', [CollaborationController::class, 'accept'])
            ->middleware('throttle:15,1')
            ->name('collaborations.accept');
        Route::post('/collaborations/{collaborationRequest}/reject', [CollaborationController::class, 'reject'])
            ->middleware('throttle:15,1')
            ->name('collaborations.reject');
        Route::post('/collaborations/{collaborationRequest}/expire', [CollaborationController::class, 'expire'])
            ->middleware('throttle:15,1')
            ->name('collaborations.expire');
        Route::post('/collaborations/{collaborationRequest}/ledger-entries', [CollaborationController::class, 'storeLedgerEntry'])
            ->middleware('throttle:20,1')
            ->name('collaborations.ledger-entries.store');
    });
