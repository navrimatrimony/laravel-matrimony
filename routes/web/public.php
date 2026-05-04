<?php

use App\Http\Controllers\Payments\PayuController;
use App\Http\Controllers\SubscriptionController;
use App\Models\Caste;
use App\Models\Country;
use App\Models\District;
use App\Services\Admin\HomepageImageService;
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
Route::post('/payment/start', [PayuController::class, 'start'])
    ->middleware('auth')
    ->name('payment.start');
Route::post('/payment/success', [PayuController::class, 'success'])->name('payment.success');
Route::post('/payment/failure', [PayuController::class, 'failure'])->name('payment.failure');

Route::post('/payments/payu/success', [SubscriptionController::class, 'success'])->name('payu.success');
Route::post('/payments/payu/failure', [SubscriptionController::class, 'failure'])->name('payu.failure');
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

    $defaultCountry = Country::query()
        ->where('name', 'like', '%India%')
        ->orderBy('name')
        ->first()
        ?? Country::query()->orderBy('name')->first();

    $addressStates = $defaultCountry
        ? $defaultCountry->states()->orderBy('name')->get()
        : collect();

    $welcomeStateId = request()->get('state_id');
    $addressDistricts = ($welcomeStateId !== null && $welcomeStateId !== '' && is_numeric($welcomeStateId))
        ? District::query()->where('parent_id', (int) $welcomeStateId)->orderBy('name')->get()
        : collect();

    return view('public.welcome', compact(
        'homepageImages',
        'castes',
        'addressStates',
        'addressDistricts',
        'defaultCountry',
    ));
});

// Local-only smoke route (not exposed in production)
if (app()->environment('local')) {
    Route::get('/phase5-test', function () {
        return 'Phase5 test ready';
    });
}
