<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MatrimonyProfileController;
use App\Http\Controllers\InterestController;

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
})->middleware(['auth', 'verified'])->name('dashboard');

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

    Route::get('/profile/{id}', [MatrimonyProfileController::class, 'show'])
        ->name('matrimony.profile.show');

    /*
    | Interests
    */
    Route::post('/interests/send/{user}', [InterestController::class, 'store'])
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


});
require __DIR__.'/auth.php';