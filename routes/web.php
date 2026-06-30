<?php

use App\Models\Caste;
use App\Models\SubCaste;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes — Phase 1 surface loaders
|--------------------------------------------------------------------------
| Order: public → member → suchak → admin → admin-suchak → auth, then legacy web JSON.
| Admin intake suggestion queue: routes/web/admin.php → prefix admin/intake (names admin.intake.*).
| Member matches: routes/web/member.php → GET /matches, GET /profiles/{id}/matches.
| Member plans: GET /plans + coupon validate are auth-only (avoids guest catalog / gender edge cases); POST /subscribe uses auth + card onboarding.
| Match boost: routes/web/admin.php → GET/PUT /admin/match-boost; MatchingService applies boosts after base score.
|--------------------------------------------------------------------------
*/

require __DIR__.'/web/public.php';
require __DIR__.'/web/member.php';
require __DIR__.'/web/suchak.php';
require __DIR__.'/web/admin.php';
require __DIR__.'/web/admin-suchak.php';
require __DIR__.'/auth.php';

use App\Http\Controllers\Api\MobileBiodataExportApiController;
use App\Http\Controllers\MobilePlanCheckoutBridgeController;
use App\Http\Controllers\PlansController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Middleware\EnforceCardOnboarding;

Route::get('/mobile/biodata/export', [MobileBiodataExportApiController::class, 'download'])
    ->middleware(['throttle:20,1'])
    ->name('mobile.biodata.export.download');

Route::get('/mobile/plans/checkout', MobilePlanCheckoutBridgeController::class)
    ->middleware(['throttle:20,1'])
    ->name('mobile.plans.checkout.bridge');

Route::middleware('auth')->group(function () {
    Route::get('/plans', [PlansController::class, 'index'])->name('plans.index');
    Route::post('/plans/coupon/validate', [PlansController::class, 'validateCoupon'])->name('plans.coupon.validate');
});
Route::match(['get', 'post'], '/subscribe', [SubscriptionController::class, 'subscribe'])
    ->middleware(['auth', EnforceCardOnboarding::class])
    ->name('plans.subscribe');

// Temporary debug route — Phase-5 Day-12 verification. Remove before production.

Route::get('/api/castes/{religionId}', function ($religionId) {
    return Caste::where('religion_id', $religionId)
        ->where('is_active', true)
        ->orderBy('label')
        ->get(['id', 'label', 'label_en', 'label_mr'])
        ->map(function (\App\Models\Caste $c) {
            return [
                'id' => $c->id,
                'label' => $c->display_label,
                'label_en' => $c->label_en ?? $c->label,
                'label_mr' => $c->label_mr,
            ];
        });
});

Route::get('/api/subcastes/{casteId}', function ($casteId) {
    return SubCaste::where('caste_id', $casteId)
        ->where('is_active', true)
        ->where('status', 'approved')
        ->orderBy('label')
        ->get(['id', 'label', 'label_en', 'label_mr'])
        ->map(function (\App\Models\SubCaste $s) {
            return [
                'id' => $s->id,
                'label' => $s->display_label,
                'label_en' => $s->label_en ?? $s->label,
                'label_mr' => $s->label_mr,
            ];
        });
});
