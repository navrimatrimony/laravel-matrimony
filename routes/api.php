<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ModerationConfigController;
use App\Http\Controllers\Api\MasterEducationController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Internal\LocationHierarchyController;
use App\Http\Controllers\Internal\LocationSearchController;
use App\Http\Controllers\Internal\LocationSuggestionController;
use Illuminate\Support\Facades\Route;

/*
| Moderation thresholds for Python NudeNet (no auth; same network / firewall in production).
*/
Route::get('/moderation-config', ModerationConfigController::class);

Route::get('/internal/location/search', [LocationSearchController::class, 'search']);
Route::get('/internal/location/states', [LocationHierarchyController::class, 'states']);
Route::get('/internal/location/districts', [LocationHierarchyController::class, 'districts']);
Route::get('/internal/location/talukas', [LocationHierarchyController::class, 'talukas']);
Route::get('/internal/location/cities', [LocationHierarchyController::class, 'cities']);
Route::post('/internal/location/suggest', [LocationSuggestionController::class, 'store']);

/*
| Master education hierarchy (Shaadi.com-style). Public read-only.
*/
Route::get('/master/education', [MasterEducationController::class, 'index']);

Route::prefix('v1')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | AUTH ROUTES (User only)
    |--------------------------------------------------------------------------
    */

    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);

    /*
    |--------------------------------------------------------------------------
    | HEALTH CHECK
    |--------------------------------------------------------------------------
    */

    Route::get('/ping', function () {
        return response()->json([
            'status' => 'api alive'
        ]);
    });

    Route::get('/locations/cities', [LocationController::class, 'cities']);

    require __DIR__.'/api/member.php';
    require __DIR__.'/api/admin.php';
});
