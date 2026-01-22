<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MatrimonyProfileApiController;
use App\Http\Controllers\Api\InterestApiController;

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
    });

});

