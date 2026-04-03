<?php

use App\Http\Controllers\AbuseReportController;
use App\Http\Controllers\Admin\AdminProfileModerationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — admin surface (auth:sanctum + admin)
|--------------------------------------------------------------------------
| Loaded inside Route::prefix('v1') from routes/api.php (move-only).
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {

    /*
    | Profile Moderation
    */
        Route::post('/profiles/{profile}/suspend', [AdminProfileModerationController::class, 'suspendProfile']); // SUSPEND PROFILE
        Route::post('/profiles/{profile}/unsuspend', [AdminProfileModerationController::class, 'unsuspendProfile']); // UNSUSPEND PROFILE
        Route::post('/profiles/{profile}/soft-delete', [AdminProfileModerationController::class, 'softDeleteProfile']); // SOFT DELETE PROFILE
        
        /*
        | Image Moderation
        */
        Route::post('/profiles/{profile}/approve-image', [AdminProfileModerationController::class, 'approveImage']); // APPROVE IMAGE
        Route::post('/profiles/{profile}/reject-image', [AdminProfileModerationController::class, 'rejectImage']); // REJECT IMAGE

    /*
    | Abuse Reports
    */
    Route::get('/abuse-reports', [AbuseReportController::class, 'index']); // LIST ABUSE REPORTS
    Route::post('/abuse-reports/{report}/resolve', [AbuseReportController::class, 'resolve']); // RESOLVE ABUSE REPORT
});
