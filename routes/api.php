<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EducationDegreeSearchController;
use App\Http\Controllers\Api\GenderLookupController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\LocationSuggestionController as ApiLocationSuggestionController;
use App\Http\Controllers\Api\MasterEducationController;
use App\Http\Controllers\Api\MobileAccountController;
use App\Http\Controllers\Api\MobileOnboardingController;
use App\Http\Controllers\Api\MobileOtpController;
use App\Http\Controllers\Api\ModerationConfigController;
use App\Http\Controllers\Api\NearbyProfileController;
use App\Http\Controllers\Api\OnboardingLookupController;
use App\Http\Controllers\Api\OnboardingPreferenceAutoDraftController;
use App\Http\Controllers\Internal\LocationHierarchyController;
use App\Http\Controllers\Internal\LocationSearchController;
use App\Http\Controllers\Internal\LocationSuggestionController as InternalLocationSuggestionController;
use App\Http\Controllers\OccupationController;
use App\Http\Controllers\Webhooks\MetaWhatsAppWebhookController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;

/*
| Moderation thresholds for Python NudeNet (no auth; same network / firewall in production).
*/
Route::get('/moderation-config', ModerationConfigController::class);

/*
| Meta WhatsApp Cloud API — webhook (no auth; verify token + optional HMAC).
| Set callback URL to: https://your-domain.com/api/webhooks/whatsapp
*/
Route::get('/webhooks/whatsapp', [MetaWhatsAppWebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp', [MetaWhatsAppWebhookController::class, 'handle']);

Route::post('/whatsapp/register-user', [WhatsAppController::class, 'registerUser']);

Route::get('/location/search', [LocationController::class, 'search']);
Route::get('/location/nearby', [LocationController::class, 'nearby']);
Route::get('/profiles/nearby', [NearbyProfileController::class, 'index']);
Route::post('/location/suggestions', [ApiLocationSuggestionController::class, 'store'])
    ->middleware('auth:sanctum');

Route::get('/internal/location/search', [LocationSearchController::class, 'search']);
Route::get('/internal/location/states', [LocationHierarchyController::class, 'states']);
Route::get('/internal/location/districts', [LocationHierarchyController::class, 'districts']);
Route::get('/internal/location/talukas', [LocationHierarchyController::class, 'talukas']);
Route::get('/internal/location/cities', [LocationHierarchyController::class, 'cities']);
Route::post('/internal/location/suggest', [InternalLocationSuggestionController::class, 'store'])
    ->middleware('auth:sanctum');

/*
| Master education hierarchy (Shaadi.com-style). Public read-only.
*/
Route::get('/master/education', [MasterEducationController::class, 'index']);

/*
| Highest education picker — public read-only degree master (no auth redirect).
*/
Route::get('/education-degrees/search', EducationDegreeSearchController::class)->name('api.education_degrees.search');

Route::get('/occupations/search', [OccupationController::class, 'search'])->name('api.occupations.search');
Route::get('/occupations/category/{occupation_master}', [OccupationController::class, 'category'])->name('api.occupations.category');

Route::prefix('v1')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | AUTH ROUTES (User only)
    |--------------------------------------------------------------------------
    */

    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/auth/mobile-otp/send', [MobileOtpController::class, 'send']);
    Route::post('/auth/mobile-otp/verify', [MobileOtpController::class, 'verify']);
    Route::get('/onboarding/lookups/bootstrap', [OnboardingLookupController::class, 'bootstrap']);
    Route::patch('/account/details', [MobileAccountController::class, 'update'])
        ->middleware('auth:sanctum');
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/onboarding/start', [MobileOnboardingController::class, 'start']);
        Route::get('/onboarding/status', [MobileOnboardingController::class, 'status']);
        Route::get('/onboarding/draft', [MobileOnboardingController::class, 'draft']);
        Route::patch('/onboarding/draft/{step}', [MobileOnboardingController::class, 'saveDraftStep']);
        Route::post('/onboarding/profile/save-step', [MobileOnboardingController::class, 'saveProfileStep']);
        Route::get('/onboarding/activation-checklist', [MobileOnboardingController::class, 'activationChecklist']);
        Route::get('/onboarding/lookups/religions', [OnboardingLookupController::class, 'religions']);
        Route::get('/onboarding/lookups/castes', [OnboardingLookupController::class, 'castes']);
        Route::get('/onboarding/lookups/sub-castes', [OnboardingLookupController::class, 'subCastes']);
        Route::get('/onboarding/lookups/locations', [OnboardingLookupController::class, 'locations']);
        Route::post('/onboarding/location-suggestions', [OnboardingLookupController::class, 'storeLocationSuggestion']);
        Route::get('/onboarding/lookups/education', [OnboardingLookupController::class, 'education']);
        Route::post('/onboarding/education-suggestions', [OnboardingLookupController::class, 'storeEducationSuggestion']);
        Route::get('/onboarding/lookups/working-with', [OnboardingLookupController::class, 'workingWith']);
        Route::get('/onboarding/lookups/occupations', [OnboardingLookupController::class, 'occupations']);
        Route::post('/onboarding/occupation-suggestions', [OnboardingLookupController::class, 'storeOccupationSuggestion']);
        Route::get('/onboarding/lookups/income-options', [OnboardingLookupController::class, 'incomeOptions']);
        Route::get('/onboarding/lookups/diet', [OnboardingLookupController::class, 'diet']);
        Route::get('/onboarding/lookups/smoking', [OnboardingLookupController::class, 'smoking']);
        Route::get('/onboarding/lookups/drinking', [OnboardingLookupController::class, 'drinking']);
        Route::get('/onboarding/preferences/auto-draft/preview', [OnboardingPreferenceAutoDraftController::class, 'preview']);
        Route::post('/onboarding/preferences/auto-draft', [OnboardingPreferenceAutoDraftController::class, 'store']);
        Route::get('/onboarding/preferences/auto-draft/status', [OnboardingPreferenceAutoDraftController::class, 'status']);
    });

    /*
    |--------------------------------------------------------------------------
    | HEALTH CHECK
    |--------------------------------------------------------------------------
    */

    Route::get('/ping', function () {
        return response()->json([
            'status' => 'api alive',
        ]);
    });

    /*
    | Read-only mobile lookup options.
    */
    Route::get('/genders', [GenderLookupController::class, 'index']);

    require __DIR__.'/api/member.php';
    require __DIR__.'/api/admin.php';
});
