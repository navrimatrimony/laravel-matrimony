<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('mobile-verify', [\App\Http\Controllers\Auth\MobileOtpController::class, 'show'])->name('mobile.verify');
    Route::get('mobile-verify/skip', [\App\Http\Controllers\Auth\MobileOtpController::class, 'skip'])->name('mobile.verify.skip');
    Route::post('mobile-verify/send', [\App\Http\Controllers\Auth\MobileOtpController::class, 'sendOtp'])->name('mobile.verify.send');
    Route::post('mobile-verify/verify', [\App\Http\Controllers\Auth\MobileOtpController::class, 'verifyOtp'])->name('mobile.verify.submit');

    /*
    | Email verification has ONE authority: the OTP engine behind
    | /settings/email. This route name survives only because Laravel's own
    | `verified` middleware and anything else that means "go verify your email"
    | resolves it — it now leads to that one page instead of a second flow.
    */
    Route::get('verify-email', fn () => redirect()->route('user.settings.email'))
        ->name('verification.notice');

    /*
    | The signed link is NOT a way to change an email — it only marks the
    | address already on the account verified. It stays because registration
    | still mints these links (Registered → SendEmailVerificationNotification,
    | reached from Api\AuthController::register, which takes an email), so
    | removing it would dead-end mail already sitting in inboxes. There is
    | deliberately no page that mints a new one on demand.
    */
    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
