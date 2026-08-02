<?php

use App\Http\Controllers\AbuseReportController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminProfileModerationController;
use App\Http\Controllers\Api\Admin\SuchakAdminTerminalStageApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — admin surface (auth:sanctum + admin)
|--------------------------------------------------------------------------
| Loaded inside Route::prefix('v1') from routes/api.php (move-only).
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {

    Route::prefix('dashboard')->group(function () {
        Route::get('/overview', [AdminDashboardController::class, 'getOverviewStats']);
        Route::get('/activity', [AdminDashboardController::class, 'getUserActivityStats']);
        Route::get('/revenue', [AdminDashboardController::class, 'getRevenueStats']);
        Route::get('/funnel', [AdminDashboardController::class, 'getFunnelStats']);
        Route::get('/timeseries', [AdminDashboardController::class, 'getTimeSeriesData']);
        Route::get('/insights', [AdminDashboardController::class, 'getInsights']);
        Route::post('/insights/action-click', [AdminDashboardController::class, 'postInsightActionClick']);
        Route::post('/insights/feedback', [AdminDashboardController::class, 'postInsightFeedback']);
        Route::get('/risk', [AdminDashboardController::class, 'getRiskAlerts']);
        Route::get('/live', [AdminDashboardController::class, 'getLiveActions']);
    });

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

    /*
    | SUCHAK TERMINAL CLAIMS — the admin's two doors (blueprint D26, 6.2, section 2).
    |
    | CONFIRM. SuchakCollaborationService::confirmStage() has always mapped an
    | admin to ACTOR_ADMIN and its docblock has always said "an admin may
    | confirm in their place" — and that branch was UNREACHABLE. The only route
    | onto it is on the member API and 404s anybody whose own matrimony profile
    | is not one of the engagement's two candidates. Section 2 says the customer
    | is the FAMILY and usually has no login, so for the blueprint's own customer
    | nobody could confirm at all and the helper's tranche could never settle.
    |
    | VOID. Section 6.2 opens with "two Suchaks can hold simultaneously valid
    | representations … on the same candidate", so a rival may claim a marriage
    | on his own engagement. The candidate-level uniqueness that stops the
    | largest sum in the system having two owners made that first UNCONFIRMED
    | claim permanent: the rows are undeletable and no correction path existed.
    | An admin can now set a wrong claim aside, with a stated reason; a claim the
    | family has CONFIRMED is refused, because that confirmation is what turned it
    | into the attribution in the first place.
    */
    Route::post(
        '/suchak-engagements/{collaboration}/stages/confirm',
        [SuchakAdminTerminalStageApiController::class, 'confirm'],
    )->whereNumber('collaboration'); // STAND IN FOR THE CUSTOMER'S CONFIRMATION (D26)
    Route::post(
        '/suchak/marriage-outcomes/{outcome}/void',
        [SuchakAdminTerminalStageApiController::class, 'voidMarriageOutcome'],
    )->whereNumber('outcome'); // SET A WRONG, UNCONFIRMED ATTRIBUTION ASIDE (6.2)
});
