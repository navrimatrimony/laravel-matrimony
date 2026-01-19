<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MatrimonyProfileApiController;

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
    Route::post('/logout', [AuthController::class, 'logout']); // LOGOUT
});

