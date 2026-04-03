<?php

use App\Http\Controllers\Payments\PayuController;
use App\Models\Caste;
use App\Services\HomepageImageService;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web — public surface (no session auth required for these paths)
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| PayU
|--------------------------------------------------------------------------
*/
Route::post('/payments/payu/success', [PayuController::class, 'success'])->name('payu.success');
Route::post('/payments/payu/failure', [PayuController::class, 'failure'])->name('payu.failure');
Route::post('/payments/payu/webhook', [PayuController::class, 'webhook'])->name('payu.webhook');

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    $homepageImages = app(HomepageImageService::class)->allPaths();
    $castes = Caste::query()
        ->where(function ($q) {
            $q->where('is_active', true)->orWhereNull('is_active');
        })
        ->orderBy('label_en')
        ->orderBy('label')
        ->get();

    return view('welcome', compact('homepageImages', 'castes'));
});

// Local-only smoke route (not exposed in production)
if (app()->environment('local')) {
    Route::get('/phase5-test', function () {
        return 'Phase5 test ready';
    });
}
