<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MatrimonyProfileController;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
	
// Matrimony Profile Routes (Day 8)
Route::get('/matrimony/profile/create', [MatrimonyProfileController::class, 'create'])
    ->name('matrimony.profile.create');

Route::post('/matrimony/profile/store', [MatrimonyProfileController::class, 'store'])
    ->name('matrimony.profile.store');

Route::get('/matrimony/profile/edit', [MatrimonyProfileController::class, 'edit'])
    ->name('matrimony.profile.edit');

Route::post('/matrimony/profile/update', [MatrimonyProfileController::class, 'update'])
    ->name('matrimony.profile.update');

Route::get('/profile/{id}', [\App\Http\Controllers\MatrimonyProfileController::class, 'show'])
    ->middleware('auth');

Route::get('/profiles', [\App\Http\Controllers\MatrimonyProfileController::class, 'index'])
    ->middleware('auth');

});

require __DIR__.'/auth.php';
