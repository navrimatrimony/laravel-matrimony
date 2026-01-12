<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

Route::post('/login', [AuthController::class, 'login']);


Route::get('/ping', function () {
    return response()->json([
        'status' => 'api alive'
    ]);
});
