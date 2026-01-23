<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MatrimonyProfileController;
use App\Http\Controllers\InterestController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AbuseReportController;

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
Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth'])->name('dashboard');


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
    | Abuse Reports (User action)
    */
    Route::post('/abuse-reports/{matrimony_profile}', [AbuseReportController::class, 'store'])
        ->name('abuse-reports.store');

});

/*
|--------------------------------------------------------------------------
| Admin Routes (Admin only)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    
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
    
    /*
    | Abuse Reports
    */
    Route::get('/abuse-reports', [AbuseReportController::class, 'index'])
        ->name('abuse-reports.index');
    
    Route::post('/abuse-reports/{report}/resolve', [AbuseReportController::class, 'resolve'])
        ->name('abuse-reports.resolve');
});

require __DIR__.'/auth.php';