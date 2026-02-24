<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MatrimonyProfileController;
use App\Http\Controllers\ProfileWizardController;
use App\Http\Controllers\InterestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AbuseReportController;
use App\Http\Controllers\BlockController;
use App\Http\Controllers\ShortlistController;
use App\Http\Controllers\Admin\DemoProfileController;
use App\Http\Controllers\Admin\AdminCapabilityController;
use App\Http\Controllers\Admin\AdminVerificationTagController;
use App\Http\Controllers\Admin\AdminSeriousIntentController;
use App\Http\Controllers\Admin\AdminProfileTagController;
use App\Http\Controllers\Admin\AdminIntakeController;
use App\Http\Controllers\Admin\LocationSuggestionWebController;
use App\Http\Controllers\Admin\GovernanceDashboardController;
use App\Http\Controllers\Admin\AdminCasteController;
use App\Http\Controllers\Admin\AdminReligionController;
use App\Http\Controllers\Admin\SubCasteAdminController;
use App\Http\Controllers\Internal\Admin\LocationSuggestionAdminController;
use App\Http\Controllers\Internal\Admin\CityAliasAdminController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntakeController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return view('welcome');
});

// Phase-5 Day-20: Temporary test route — remove before production
Route::get('/phase5-test', function () {
    return 'Phase5 test ready';
});

/*
|--------------------------------------------------------------------------
| Dashboard
|--------------------------------------------------------------------------
*/
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth'])
    ->name('dashboard');


/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {

    /*
    | Phase-5: User-side Intake UI (user-access path /intake/...)
    */
    Route::get('/intake/upload', [IntakeController::class, 'uploadForm'])->name('intake.upload');
    Route::post('/intake/upload', [IntakeController::class, 'store'])->name('intake.store');
    Route::get('/intake/preview/{intake}', [IntakeController::class, 'preview'])->name('intake.preview');
    Route::post('/intake/approve/{intake}', [IntakeController::class, 'approve'])->name('intake.approve');
    Route::get('/intake/approval', [IntakeController::class, 'approval'])->name('intake.approval');
    Route::get('/intake/status', [IntakeController::class, 'status'])->name('intake.status');

    /*
    | Matrimony Profile (Phase-5B: wizard-only create/edit — architectural freeze)
    */
    Route::get('/matrimony/profile/wizard', function () {
        return redirect()->route('matrimony.profile.wizard.section', ['section' => 'basic-info']);
    })->name('matrimony.profile.wizard');
    Route::get('/matrimony/profile/wizard/{section}', [ProfileWizardController::class, 'show'])
        ->name('matrimony.profile.wizard.section')
        ->where('section', 'basic-info|personal-family|location|property|horoscope|legal|about-preferences|contacts|photo|full');
    Route::post('/matrimony/profile/wizard/{section}', [ProfileWizardController::class, 'store'])
        ->name('matrimony.profile.wizard.store')
        ->where('section', 'basic-info|personal-family|location|property|horoscope|legal|about-preferences|contacts|photo|full');

    Route::get('/matrimony/profile/edit', function () {
        return redirect()->route('matrimony.profile.wizard.section', ['section' => 'full']);
    })->name('matrimony.profile.edit');

	Route::get('/matrimony/profile/upload-photo', [MatrimonyProfileController::class, 'uploadPhoto'])
    ->name('matrimony.profile.upload-photo');

	Route::post('/matrimony/profile/upload-photo', [MatrimonyProfileController::class, 'storePhoto'])
    ->name('matrimony.profile.store-photo');


    /*
    | Matrimony Profiles (View / Search)
    */
    Route::get('/profiles', [MatrimonyProfileController::class, 'index'])
        ->name('matrimony.profiles.index');

    // 🔒 SSOT: MatrimonyProfile route MUST use matrimony_profile_id
Route::get('/profile/{matrimony_profile_id}', [MatrimonyProfileController::class, 'show'])
    ->name('matrimony.profile.show');




    /*
    | Interests
    */
    // 🔒 SSOT: MatrimonyProfile route param consistency
Route::post('/interests/send/{matrimony_profile_id}', [InterestController::class, 'store'])
    ->name('interests.send');



    Route::get('/interests/sent', [InterestController::class, 'sent'])
        ->name('interests.sent');

    Route::get('/interests/received', [InterestController::class, 'received'])
        ->name('interests.received');

        // 🔴 Interest Accept
Route::post('/interests/{interest}/accept', [App\Http\Controllers\InterestController::class, 'accept'])
->name('interests.accept');

// 🔴 Interest Reject
Route::post('/interests/{interest}/reject', [App\Http\Controllers\InterestController::class, 'reject'])
->name('interests.reject');

// 🔴 Withdraw (Cancel) Interest
Route::post('/interests/{interest}/withdraw', [App\Http\Controllers\InterestController::class, 'withdraw'])
    ->name('interests.withdraw');

    /*
    | Block (SSOT Day-5)
    */
    Route::get('/blocks', [BlockController::class, 'index'])->name('blocks.index');
    Route::post('/blocks/{matrimony_profile_id}', [BlockController::class, 'store'])->name('blocks.store');
    Route::delete('/blocks/{matrimony_profile_id}', [BlockController::class, 'destroy'])->name('blocks.destroy');

    /*
    | Shortlist (SSOT Day-5)
    */
    Route::get('/shortlist', [ShortlistController::class, 'index'])->name('shortlist.index');
    Route::post('/shortlist/{matrimony_profile_id}', [ShortlistController::class, 'store'])->name('shortlist.store');
    Route::delete('/shortlist/{matrimony_profile_id}', [ShortlistController::class, 'destroy'])->name('shortlist.destroy');

    /*
    | Abuse Reports (User action)
    */
    Route::post('/abuse-reports/{matrimony_profile}', [AbuseReportController::class, 'store'])
        ->name('abuse-reports.store');

    /*
    | Notifications (Day-10 — R5)
    */
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::get('/notifications/{id}', [NotificationController::class, 'show'])->name('notifications.show');
    Route::post('/notifications/{id}/mark-read', [NotificationController::class, 'markRead'])->name('notifications.mark-read');
});

/*
|--------------------------------------------------------------------------
| Admin Routes (Admin only)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/dashboard', 302);
    Route::get('/dashboard', function () {
        $totalProfiles = \App\Models\MatrimonyProfile::count();
        $activeProfiles = \App\Models\MatrimonyProfile::where('is_suspended', false)->count();
        $suspendedProfiles = \App\Models\MatrimonyProfile::where('is_suspended', true)->count();
        $demoProfiles = \App\Models\MatrimonyProfile::where('is_demo', true)->count();
        $pendingAbuseReports = \App\Models\AbuseReport::where('status', 'open')->count();
        $totalBiodataIntakes = \App\Models\BiodataIntake::count();
        return view('admin.dashboard', compact('totalProfiles', 'activeProfiles', 'suspendedProfiles', 'demoProfiles', 'pendingAbuseReports', 'totalBiodataIntakes'));
    })->name('dashboard');

    /*
    | Profiles List (Admin)
    */
    Route::get('/profiles', [AdminController::class, 'profilesIndex'])->name('profiles.index');

    Route::post('/profiles/{profile}/tags/assign', [AdminProfileTagController::class, 'assign'])
        ->name('profiles.tags.assign');
    Route::delete('/profiles/{profile}/tags/{tag}/remove', [AdminProfileTagController::class, 'remove'])
        ->name('profiles.tags.remove');

    /*
    | Profile View (Admin - bypasses suspension checks)
    */
    Route::get('/profiles/{id}', [AdminController::class, 'showProfile'])
        ->name('profiles.show');
    
    /*
    | Profile Edit (Admin)
    */
    Route::put('/profiles/{profile}', [AdminController::class, 'updateProfile'])
        ->name('profiles.update');
    
    /*
    | Profile Moderation
    */
    Route::post('/profiles/{profile}/suspend', [AdminController::class, 'suspendProfile'])
        ->name('profiles.suspend');
    
    Route::post('/profiles/{profile}/unsuspend', [AdminController::class, 'unsuspendProfile'])
        ->name('profiles.unsuspend');
    
    Route::post('/profiles/{profile}/soft-delete', [AdminController::class, 'softDeleteProfile'])
        ->name('profiles.soft-delete');

    /*
    | Sub-caste approval (Phase-5)
    */
    Route::get('/sub-castes/pending', [SubCasteAdminController::class, 'pending'])->name('sub-castes.pending');
    Route::post('/sub-castes/{id}/approve', [SubCasteAdminController::class, 'approve'])->name('sub-castes.approve');

    /*
    | Master Data: Religion, Caste, SubCaste (Phase-5)
    */
    Route::prefix('master')->name('master.')->group(function () {
        Route::resource('religions', AdminReligionController::class)->except(['show', 'destroy']);
        Route::post('religions/{religion}/disable', [AdminReligionController::class, 'disable'])->name('religions.disable');
        Route::post('religions/{religion}/enable', [AdminReligionController::class, 'enable'])->name('religions.enable');

        Route::resource('castes', AdminCasteController::class)->except(['show', 'destroy']);
        Route::post('castes/{caste}/disable', [AdminCasteController::class, 'disable'])->name('castes.disable');
        Route::post('castes/{caste}/enable', [AdminCasteController::class, 'enable'])->name('castes.enable');

        Route::resource('sub-castes', SubCasteAdminController::class)->only(['index', 'edit', 'update']);
        Route::post('sub-castes/{subCaste}/merge', [SubCasteAdminController::class, 'merge'])->name('sub-castes.merge');
        Route::post('sub-castes/{subCaste}/disable', [SubCasteAdminController::class, 'disable'])->name('sub-castes.disable');
        Route::post('sub-castes/{subCaste}/enable', [SubCasteAdminController::class, 'enable'])->name('sub-castes.enable');
    });

    /*
    | Image Moderation
    */
    Route::post('/profiles/{profile}/approve-image', [AdminController::class, 'approveImage'])
        ->name('profiles.approve-image');
    
    Route::post('/profiles/{profile}/reject-image', [AdminController::class, 'rejectImage'])
        ->name('profiles.reject-image');

    Route::post('/profiles/{profile}/override-visibility', [AdminController::class, 'overrideVisibility'])
        ->name('profiles.override-visibility');

    Route::post('/profiles/{profile}/lifecycle-state', [AdminController::class, 'updateLifecycleState'])
        ->name('profiles.lifecycle-state');

    /*
    | Day-13: Manual conflict detection (no profile mutation)
    */
    Route::post('/profiles/{profile}/detect-conflicts', [AdminController::class, 'detectConflicts'])
        ->name('profiles.detect-conflicts');

    /*
    | Day-14: OCR mode simulation (governance testing only, no OCR engine)
    */
    Route::get('/ocr-simulation', [AdminController::class, 'ocrSimulation'])
        ->name('ocr-simulation.index');
    Route::post('/ocr-simulation/execute', [AdminController::class, 'ocrSimulationExecute'])
        ->name('ocr-simulation.execute');

    /*
    | Abuse Reports
    */
    Route::get('/abuse-reports', [AbuseReportController::class, 'index'])
        ->name('abuse-reports.index');
    
    Route::post('/abuse-reports/{report}/resolve', [AbuseReportController::class, 'resolve'])
        ->name('abuse-reports.resolve');

    Route::get('/location-suggestions', [LocationSuggestionWebController::class, 'index'])
        ->name('location-suggestions.index');

    Route::get('/governance-dashboard', [GovernanceDashboardController::class, 'index'])
        ->name('governance-dashboard');

    Route::prefix('internal')->group(function () {
        Route::get('/location-suggestions', [LocationSuggestionAdminController::class, 'index']);
        Route::post('/location-suggestions/{id}/approve', [LocationSuggestionAdminController::class, 'approve']);
        Route::post('/location-suggestions/{id}/reject', [LocationSuggestionAdminController::class, 'reject']);
        Route::post('/cities/{cityId}/aliases', [CityAliasAdminController::class, 'store']);
    });

    Route::get('/demo-profile/create', [DemoProfileController::class, 'create'])->name('demo-profile.create');
    Route::post('/demo-profile', [DemoProfileController::class, 'store'])->name('demo-profile.store');
    Route::get('/demo-profile/bulk-create', [DemoProfileController::class, 'bulkCreate'])->name('demo-profile.bulk-create');
    Route::post('/demo-profiles/bulk', [DemoProfileController::class, 'bulkStore'])->name('demo-profile.bulk-store');

    /*
    | Verification Tags
    */
    Route::get('/verification-tags', [AdminVerificationTagController::class, 'index'])->name('verification-tags.index');
    Route::post('/verification-tags', [AdminVerificationTagController::class, 'store'])->name('verification-tags.store');
    Route::put('/verification-tags/{id}', [AdminVerificationTagController::class, 'update'])->name('verification-tags.update');
    Route::delete('/verification-tags/{id}', [AdminVerificationTagController::class, 'destroy'])->name('verification-tags.destroy');
    Route::get('/verification-tags/{id}/restore-confirm', [AdminVerificationTagController::class, 'restoreConfirm'])->name('verification-tags.restore-confirm');
    Route::post('/verification-tags/{id}/restore', [AdminVerificationTagController::class, 'restore'])->name('verification-tags.restore');

    /*
    | Serious Intents
    */
    Route::get('/serious-intents', [AdminSeriousIntentController::class, 'index'])->name('serious-intents.index');
    Route::post('/serious-intents', [AdminSeriousIntentController::class, 'store'])->name('serious-intents.store');
    Route::put('/serious-intents/{id}', [AdminSeriousIntentController::class, 'update'])->name('serious-intents.update');
    Route::delete('/serious-intents/{id}', [AdminSeriousIntentController::class, 'destroy'])->name('serious-intents.destroy');
    Route::get('/serious-intents/{id}/restore-confirm', [AdminSeriousIntentController::class, 'restoreConfirm'])->name('serious-intents.restore-confirm');
    Route::post('/serious-intents/{id}/restore', [AdminSeriousIntentController::class, 'restore'])->name('serious-intents.restore');

    /*
    | Admin Capabilities
    */
    Route::get('/admin-capabilities', [AdminCapabilityController::class, 'index'])->name('admin-capabilities.index');
    Route::post('/admin-capabilities/{admin}/update', [AdminCapabilityController::class, 'update'])->name('admin-capabilities.update');

    Route::get('/view-back-settings', [AdminController::class, 'viewBackSettings'])->name('view-back-settings.index');
    Route::post('/view-back-settings', [AdminController::class, 'updateViewBackSettings'])->name('view-back-settings.update');

    Route::get('/demo-search-settings', [AdminController::class, 'demoSearchSettings'])->name('demo-search-settings.index');
    Route::post('/demo-search-settings', [AdminController::class, 'updateDemoSearchSettings'])->name('demo-search-settings.update');

    Route::get('/profile-field-config', [AdminController::class, 'profileFieldConfigIndex'])->name('profile-field-config.index');
    Route::post('/profile-field-config', [AdminController::class, 'profileFieldConfigUpdate'])->name('profile-field-config.update');

    Route::get('/field-registry', [AdminController::class, 'fieldRegistryIndex'])->name('field-registry.index');

    Route::get('/field-registry/extended', [AdminController::class, 'extendedFieldsIndex'])->name('field-registry.extended.index');
    Route::get('/field-registry/extended/create', [AdminController::class, 'extendedFieldsCreate'])->name('field-registry.extended.create');
    Route::post('/field-registry/extended', [AdminController::class, 'extendedFieldsStore'])->name('field-registry.extended.store');
    Route::post('/field-registry/extended/update-bulk', [AdminController::class, 'extendedFieldsUpdateBulk'])->name('field-registry.extended.update-bulk');
    Route::post('/field-registry/{field}/archive', [AdminController::class, 'archiveFieldRegistry'])->name('field-registry.archive');
    Route::post('/field-registry/{field}/unarchive', [AdminController::class, 'unarchiveFieldRegistry'])->name('field-registry.unarchive');

    Route::get('/notifications', [AdminController::class, 'userNotificationsIndex'])->name('notifications.index');
    Route::get('/notifications/user', [AdminController::class, 'userNotificationsShow'])->name('notifications.user.show');

    /*
    | Phase-4 Day-4: Biodata Intake Sandbox & Attach (admin only)
    */
    Route::get('/biodata-intakes', [AdminIntakeController::class, 'biodataIntakesIndex'])->name('biodata-intakes.index');
    Route::get('/biodata-intakes/{intake}', [AdminIntakeController::class, 'showBiodataIntake'])->name('biodata-intakes.show');
    Route::patch('/biodata-intakes/{intake}/attach', [AdminIntakeController::class, 'attachBiodataIntake'])->name('biodata-intakes.attach');

    /*
    | Conflict Records (Phase-3 Day-4/5 — list, create, resolve)
    */
    Route::get('/conflict-records', [AdminController::class, 'conflictRecordsIndex'])->name('conflict-records.index');
    Route::get('/conflict-records/create', [AdminController::class, 'conflictRecordsCreate'])->name('conflict-records.create');
    Route::get('/conflict-records/{record}', [AdminController::class, 'conflictRecordShow'])->name('conflict-records.show');
    Route::post('/conflict-records', [AdminController::class, 'conflictRecordsStore'])->name('conflict-records.store');
    Route::post('/conflict-records/{record}/approve', [AdminController::class, 'conflictRecordApprove'])->name('conflict-records.approve');
    Route::post('/conflict-records/{record}/reject', [AdminController::class, 'conflictRecordReject'])->name('conflict-records.reject');
    Route::post('/conflict-records/{record}/override', [AdminController::class, 'conflictRecordOverride'])->name('conflict-records.override');
});

require __DIR__.'/auth.php';

// Temporary debug route — Phase-5 Day-12 verification. Remove before production.

use App\Http\Controllers\Api\CasteLookupController;

Route::middleware('auth')->group(function () {
    Route::get('/api/castes/{religionId}', [CasteLookupController::class, 'getCastes'])->where('religionId', '[0-9]+');
    Route::get('/api/subcastes/{casteId}', [CasteLookupController::class, 'getSubCastes'])->where('casteId', '[0-9]+');
    Route::post('/api/sub-castes', [CasteLookupController::class, 'createSubCaste']);
});
