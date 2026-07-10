<?php

use App\Http\Controllers\Payments\PayuController;
use App\Http\Controllers\PublicProfileShareController;
use App\Http\Controllers\SubscriptionController;
use App\Models\Caste;
use App\Models\Country;
use App\Models\District;
use App\Models\HomepageSuccessStory;
use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\Religion;
use App\Services\Admin\HomepageContentService;
use App\Services\Admin\HomepageImageService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

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
    $homepageSettings = app(HomepageContentService::class)->settings();
    $castes = Caste::query()
        ->where(function ($q) {
            $q->where('is_active', true)->orWhereNull('is_active');
        })
        ->orderBy('label_en')
        ->orderBy('label')
        ->get();

    $religions = Religion::query()
        ->where(function ($q) {
            $q->where('is_active', true)->orWhereNull('is_active');
        })
        ->orderBy('label_en')
        ->orderBy('label')
        ->get();

    $genders = MasterGender::query()
        ->where('is_active', true)
        ->whereIn('key', ['male', 'female'])
        ->orderByRaw("CASE WHEN `key` = 'female' THEN 1 WHEN `key` = 'male' THEN 2 ELSE 3 END")
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

    $maritalStatuses = MasterMaritalStatus::query()
        ->where('is_active', true)
        ->orderBy('label')
        ->get();

    $successStories = Schema::hasTable('homepage_success_stories')
        ? HomepageSuccessStory::query()
            ->where('is_published', true)
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->latest('id')
            ->limit((int) ($homepageSettings['story_limit'] ?? 6))
            ->get()
        : collect();

    $homepageStats = [
        'profiles' => Schema::hasTable('matrimony_profiles')
            ? MatrimonyProfile::query()->where('is_suspended', false)->count()
            : 0,
        'success_stories' => $successStories->count(),
        'plans' => Schema::hasTable('plans')
            ? Plan::query()->where('is_active', true)->where('is_visible', true)->count()
            : 0,
    ];

    $homepagePlans = Schema::hasTable('plans')
        ? Plan::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->orderByDesc('highlight')
            ->orderBy('sort_order')
            ->limit(3)
            ->get()
        : collect();

    return view('public.welcome', compact(
        'homepageImages',
        'homepageSettings',
        'genders',
        'religions',
        'castes',
        'addressStates',
        'addressDistricts',
        'maritalStatuses',
        'successStories',
        'homepageStats',
        'homepagePlans',
        'defaultCountry',
    ));
});

Route::get('/share/profile/{id}', [PublicProfileShareController::class, 'show'])
    ->whereNumber('id')
    ->name('profile.share.public');

Route::get('/register/biodata/{token}', [\App\Http\Controllers\BulkIntakePublicRegistrationController::class, 'show'])
    ->name('bulk-intake.register.show');
Route::get('/register/biodata/{token}/candidate-photo', [\App\Http\Controllers\BulkIntakePublicRegistrationController::class, 'candidatePhoto'])
    ->name('bulk-intake.register.candidate-photo');
Route::post('/register/biodata/{token}', [\App\Http\Controllers\BulkIntakePublicRegistrationController::class, 'store'])
    ->name('bulk-intake.register.store');

// Local-only smoke route (not exposed in production)
if (app()->environment('local')) {
    Route::get('/phase5-test', function () {
        return 'Phase5 test ready';
    });
}
