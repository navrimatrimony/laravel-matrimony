<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MatrimonyProfileController;
use App\Http\Controllers\InterestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AbuseReportController;
use App\Http\Controllers\BlockController;
use App\Http\Controllers\ShortlistController;
use App\Http\Controllers\Admin\DemoProfileController;
use App\Http\Controllers\DashboardController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return view('welcome');
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
    | Matrimony Profile
    */
    Route::get('/matrimony/profile/create', [MatrimonyProfileController::class, 'create'])
        ->name('matrimony.profile.create');

    Route::post('/matrimony/profile/store', [MatrimonyProfileController::class, 'store'])
        ->name('matrimony.profile.store');

    Route::get('/matrimony/profile/edit', [MatrimonyProfileController::class, 'edit'])
        ->name('matrimony.profile.edit');

    Route::post('/matrimony/profile/update', [MatrimonyProfileController::class, 'update'])
        ->name('matrimony.profile.update');
		
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
        return view('admin.dashboard', compact('totalProfiles', 'activeProfiles', 'suspendedProfiles', 'demoProfiles', 'pendingAbuseReports'));
    })->name('dashboard');

    /*
    | Profile View (Admin - bypasses suspension checks)
    */
    Route::get('/profiles/{id}', [AdminController::class, 'showProfile'])
        ->name('profiles.show');
    
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
    | Image Moderation
    */
    Route::post('/profiles/{profile}/approve-image', [AdminController::class, 'approveImage'])
        ->name('profiles.approve-image');
    
    Route::post('/profiles/{profile}/reject-image', [AdminController::class, 'rejectImage'])
        ->name('profiles.reject-image');

    Route::post('/profiles/{profile}/override-visibility', [AdminController::class, 'overrideVisibility'])
        ->name('profiles.override-visibility');
    
    /*
    | Abuse Reports
    */
    Route::get('/abuse-reports', [AbuseReportController::class, 'index'])
        ->name('abuse-reports.index');
    
    Route::post('/abuse-reports/{report}/resolve', [AbuseReportController::class, 'resolve'])
        ->name('abuse-reports.resolve');

    Route::get('/demo-profile/create', [DemoProfileController::class, 'create'])->name('demo-profile.create');
    Route::post('/demo-profile', [DemoProfileController::class, 'store'])->name('demo-profile.store');
    Route::get('/demo-profile/bulk-create', [DemoProfileController::class, 'bulkCreate'])->name('demo-profile.bulk-create');
    Route::post('/demo-profiles/bulk', [DemoProfileController::class, 'bulkStore'])->name('demo-profile.bulk-store');

    Route::get('/view-back-settings', [AdminController::class, 'viewBackSettings'])->name('view-back-settings.index');
    Route::post('/view-back-settings', [AdminController::class, 'updateViewBackSettings'])->name('view-back-settings.update');

    Route::get('/demo-search-settings', [AdminController::class, 'demoSearchSettings'])->name('demo-search-settings.index');
    Route::post('/demo-search-settings', [AdminController::class, 'updateDemoSearchSettings'])->name('demo-search-settings.update');

    Route::get('/notifications', [AdminController::class, 'userNotificationsIndex'])->name('notifications.index');
    Route::get('/notifications/user', [AdminController::class, 'userNotificationsShow'])->name('notifications.user.show');
});

require __DIR__.'/auth.php';

use Illuminate\Support\Facades\Artisan;

Route::get('/_run_migrations_987654', function () {

    // 🔐 ONE-TIME SECRET TOKEN CHECK
    if (request()->query('key') !== 'MIGRATE_ONLY_ONCE_2026') {
        abort(403, 'Unauthorized');
    }

    // 🚀 SAFE MIGRATION (NO DATA LOSS)
    Artisan::call('migrate', [
        '--force' => true,
    ]);

    return "<pre>MIGRATION COMPLETED SUCCESSFULLY\n\n" . Artisan::output() . "</pre>";
});


