<?php

use App\Http\Controllers\AbuseReportController;
use App\Http\Controllers\Api\BiodataIntakeApiController;
use App\Http\Controllers\Api\CasteLookupController;
use App\Http\Controllers\Api\ExtendedFieldApiController;
use App\Http\Controllers\Api\FieldRegistryApiController;
use App\Http\Controllers\Api\InterestApiController;
use App\Http\Controllers\Api\MatrimonyProfileApiController;
use App\Http\Controllers\Api\ProfileFieldLockApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — member surface (auth:sanctum)
|--------------------------------------------------------------------------
| Loaded inside Route::prefix('v1') from routes/api.php (move-only).
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/matrimony-profile', [MatrimonyProfileApiController::class, 'store']); // CREATE
    Route::get('/matrimony-profile', [MatrimonyProfileApiController::class, 'show']);  // FETCH
    Route::put('/matrimony-profile', [MatrimonyProfileApiController::class, 'update']); // UPDATE
    Route::post('/matrimony-profile/photo', [MatrimonyProfileApiController::class, 'uploadPhoto']); // PHOTO UPLOAD
    Route::get('/matrimony-profiles', [MatrimonyProfileApiController::class, 'index']); // LIST ALL PROFILES
    Route::get('/matrimony-profiles/{id}', [MatrimonyProfileApiController::class, 'showById']); // GET PROFILE BY ID
    Route::post('/interests', [InterestApiController::class, 'store']); // SEND INTEREST
    Route::get('/interests/sent', [InterestApiController::class, 'sent']); // GET SENT INTERESTS
    Route::get('/interests/received', [InterestApiController::class, 'received']); // GET RECEIVED INTERESTS
    Route::post('/interests/{id}/accept', [InterestApiController::class, 'accept']); // ACCEPT INTEREST
    Route::post('/interests/{id}/reject', [InterestApiController::class, 'reject']); // REJECT INTEREST
    Route::post('/interests/{id}/withdraw', [InterestApiController::class, 'withdraw']); // WITHDRAW INTEREST
    Route::post('/logout', [\App\Http\Controllers\Api\AuthController::class, 'logout']); // LOGOUT

    /*
    | Phase-4: Field Registry (read-only)
    */
    Route::get('/field-registry', [FieldRegistryApiController::class, 'index']); // LIST FIELD REGISTRY

    /*
    | Phase-4: Extended Fields (definition read-only)
    */
    Route::get('/extended-fields', [ExtendedFieldApiController::class, 'index']); // LIST EXTENDED FIELD DEFINITIONS

    /*
    | Phase-4: Field Locks (view-only)
    */
    Route::get('/matrimony-profile/field-locks', [ProfileFieldLockApiController::class, 'index']); // LIST FIELD LOCKS FOR OWN PROFILE

    /*
    | Phase-4: Biodata Intakes (list + show only)
    */
    Route::get('/biodata-intakes', [BiodataIntakeApiController::class, 'index']); // LIST BIODATA INTAKES
    Route::get('/biodata-intakes/{id}', [BiodataIntakeApiController::class, 'show']); // SHOW BIODATA INTAKE

    /*
    | Abuse Reports (User action)
    */
    Route::post('/abuse-reports/{profile}', [AbuseReportController::class, 'store']); // SUBMIT ABUSE REPORT

    /*
    | Religion → Caste → SubCaste lookup (Phase-5)
    */
    Route::get('/castes', [CasteLookupController::class, 'getCastes']); // GET ?religion_id=
    Route::get('/sub-castes', [CasteLookupController::class, 'getSubCastes']); // GET ?caste_id=&q=
    Route::post('/sub-castes', [CasteLookupController::class, 'createSubCaste']);
});
