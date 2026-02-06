<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MatrimonyProfileApiController;
use App\Http\Controllers\Api\InterestApiController;
use App\Http\Controllers\Api\FieldRegistryApiController;
use App\Http\Controllers\Api\ExtendedFieldApiController;
use App\Http\Controllers\Api\ProfileFieldLockApiController;
use App\Http\Controllers\Api\BiodataIntakeApiController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AbuseReportController;

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

    /*
    |--------------------------------------------------------------------------
    | MATRIMONY PROFILE ROUTES (Biodata only)
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
        Route::post('/logout', [AuthController::class, 'logout']); // LOGOUT
        
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
    });

    /*
    |--------------------------------------------------------------------------
    | ADMIN ROUTES (Admin only)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
        
        /*
        | Profile Moderation
        */
        Route::post('/profiles/{profile}/suspend', [AdminController::class, 'suspendProfile']); // SUSPEND PROFILE
        Route::post('/profiles/{profile}/unsuspend', [AdminController::class, 'unsuspendProfile']); // UNSUSPEND PROFILE
        Route::post('/profiles/{profile}/soft-delete', [AdminController::class, 'softDeleteProfile']); // SOFT DELETE PROFILE
        
        /*
        | Image Moderation
        */
        Route::post('/profiles/{profile}/approve-image', [AdminController::class, 'approveImage']); // APPROVE IMAGE
        Route::post('/profiles/{profile}/reject-image', [AdminController::class, 'rejectImage']); // REJECT IMAGE
        
        /*
        | Abuse Reports
        */
        Route::get('/abuse-reports', [AbuseReportController::class, 'index']); // LIST ABUSE REPORTS
        Route::post('/abuse-reports/{report}/resolve', [AbuseReportController::class, 'resolve']); // RESOLVE ABUSE REPORT
    });

});

