<?php

use App\Http\Controllers\Admin\Suchak\AccountVerificationController;
use App\Http\Controllers\Admin\Suchak\AcademyController;
use App\Http\Controllers\Admin\Suchak\ApkSettingsController;
use App\Http\Controllers\Admin\Suchak\DashboardController;
use App\Http\Controllers\Admin\Suchak\PhotoReviewController;
use App\Http\Controllers\Admin\Suchak\PlanCatalogController;
use App\Http\Controllers\Admin\Suchak\PayoutController;
use App\Http\Controllers\Admin\Suchak\RetentionController;
use App\Http\Controllers\Admin\Suchak\SafetyController;
use App\Http\Controllers\Admin\Suchak\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web — admin Suchak surface (auth + admin middleware)
|--------------------------------------------------------------------------
| Phase-6 Suchak account verification and admin review surface.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'admin', 'admin.section'])
    ->prefix('admin/suchak')
    ->name('admin.suchak.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/photo-reviews', [PhotoReviewController::class, 'index'])->name('photo-reviews.index');
        Route::post('/photo-reviews/bulk', [PhotoReviewController::class, 'bulk'])->name('photo-reviews.bulk');
        Route::get('/safety', [SafetyController::class, 'index'])->name('safety.index');
        Route::post('/safety/disputes', [SafetyController::class, 'storeDispute'])->name('safety.disputes.store');
        Route::post('/safety/disputes/{dispute}/review', [SafetyController::class, 'reviewDispute'])->name('safety.disputes.review');
        Route::post('/safety/disputes/{dispute}/close', [SafetyController::class, 'closeDispute'])->name('safety.disputes.close');
        Route::post('/safety/disputes/{dispute}/payment-freeze', [SafetyController::class, 'freezePaymentAbility'])->name('safety.disputes.payment-freeze');
        Route::post('/safety/accounts/{suchakAccount}/freeze', [SafetyController::class, 'freezeAccount'])->name('safety.accounts.freeze');
        Route::post('/safety/accounts/{suchakAccount}/unfreeze', [SafetyController::class, 'unfreezeAccount'])->name('safety.accounts.unfreeze');
        Route::post('/safety/accounts/{suchakAccount}/pause', [SafetyController::class, 'pauseAccount'])->name('safety.accounts.pause');
        Route::post('/safety/accounts/{suchakAccount}/resume', [SafetyController::class, 'resumeAccount'])->name('safety.accounts.resume');
        Route::post('/safety/accounts/{suchakAccount}/feature-suspensions', [SafetyController::class, 'suspendFeature'])->name('safety.accounts.feature-suspensions.store');
        Route::post('/safety/feature-suspensions/{suspension}/release', [SafetyController::class, 'releaseFeature'])->name('safety.feature-suspensions.release');
        Route::post('/safety/representations/{representation}/revoke', [SafetyController::class, 'revokeRepresentation'])->name('safety.representations.revoke');
        Route::get('/plans', [PlanCatalogController::class, 'index'])->name('plans.index');
        Route::post('/plans', [PlanCatalogController::class, 'store'])->name('plans.store');
        Route::put('/plans/{suchakPlan}', [PlanCatalogController::class, 'update'])->name('plans.update');
        Route::post('/plans/accounts/{suchakAccount}/assign', [PlanCatalogController::class, 'assignAccountPlan'])->name('plans.accounts.assign');
        Route::get('/payouts', [PayoutController::class, 'index'])->name('payouts.index');
        Route::post('/payouts/settlements/generate', [PayoutController::class, 'generateSettlement'])->name('payouts.settlements.generate');
        Route::post('/payouts/{payout}/approve', [PayoutController::class, 'approve'])->name('payouts.approve');
        Route::post('/payouts/{payout}/pay', [PayoutController::class, 'pay'])->name('payouts.pay');
        Route::post('/payouts/{payout}/reverse', [PayoutController::class, 'reverse'])->name('payouts.reverse');
        Route::post('/payouts/{payout}/cancel', [PayoutController::class, 'cancel'])->name('payouts.cancel');
        Route::get('/retention', [RetentionController::class, 'index'])->name('retention.index');
        Route::post('/retention/campaign-rules', [RetentionController::class, 'storeCampaignRule'])->name('retention.campaign-rules.store');
        Route::post('/retention/campaign-rules/{campaignRule}/qualify', [RetentionController::class, 'qualifyCampaignBonus'])->name('retention.campaign-rules.qualify');
        Route::post('/retention/accounts/{suchakAccount}/reports', [RetentionController::class, 'generateReport'])->name('retention.accounts.reports.generate');
        Route::post('/retention/accounts/{suchakAccount}/offers', [RetentionController::class, 'createOffer'])->name('retention.accounts.offers.store');
        Route::get('/academy', [AcademyController::class, 'index'])->name('academy.index');
        Route::post('/academy/modules', [AcademyController::class, 'storeModule'])->name('academy.modules.store');
        Route::post('/academy/modules/{trainingModule}/completions', [AcademyController::class, 'completeModule'])->name('academy.modules.completions.store');
        Route::post('/academy/accounts/{suchakAccount}/certificates', [AcademyController::class, 'issueCertificate'])->name('academy.accounts.certificates.issue');
        Route::post('/academy/message-templates', [AcademyController::class, 'storeTemplate'])->name('academy.message-templates.store');
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::get('/apk-settings', [ApkSettingsController::class, 'index'])->name('apk-settings.index');
        Route::post('/apk-settings', [ApkSettingsController::class, 'update'])->name('apk-settings.update');
        Route::get('/accounts', [AccountVerificationController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/{suchakAccount}', [AccountVerificationController::class, 'show'])->name('accounts.show');
        Route::post('/accounts/{suchakAccount}/approve', [AccountVerificationController::class, 'approve'])->name('accounts.approve');
        Route::post('/accounts/{suchakAccount}/reject', [AccountVerificationController::class, 'reject'])->name('accounts.reject');
        Route::post('/accounts/{suchakAccount}/suspend', [AccountVerificationController::class, 'suspend'])->name('accounts.suspend');
        Route::post('/accounts/{suchakAccount}/archive', [AccountVerificationController::class, 'archive'])->name('accounts.archive');
        Route::post('/accounts/{suchakAccount}/reactivate', [AccountVerificationController::class, 'reactivate'])->name('accounts.reactivate');
        Route::post('/accounts/{suchakAccount}/public-status', [AccountVerificationController::class, 'updatePublicStatus'])->name('accounts.public-status.update');
        Route::get('/accounts/{suchakAccount}/verification-records/{verificationRecord}/document', [AccountVerificationController::class, 'viewVerificationDocument'])->name('accounts.verification-records.document');
        Route::post('/accounts/{suchakAccount}/verification-records/{verificationRecord}/approve', [AccountVerificationController::class, 'approveVerificationRecord'])->name('accounts.verification-records.approve');
        Route::post('/accounts/{suchakAccount}/verification-records/{verificationRecord}/reject', [AccountVerificationController::class, 'rejectVerificationRecord'])->name('accounts.verification-records.reject');
    });
