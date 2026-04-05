<?php

use App\Http\Controllers\AbuseReportController;
use App\Http\Controllers\BlockController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ContactInboxController;
use App\Http\Controllers\ContactRequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntakeController;
use App\Http\Controllers\InterestController;
use App\Http\Controllers\Internal\CurrentLocationController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\MatrimonyProfileController;
use App\Http\Controllers\MatrimonyVerificationEmailController;
use App\Http\Controllers\MediationInboxController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileContactActionController;
use App\Http\Controllers\ProfileContactVerificationController;
use App\Http\Controllers\ProfileHideController;
use App\Http\Controllers\ProfilePhotoReportController;
use App\Http\Controllers\ProfileVerificationController;
use App\Http\Controllers\ProfileWizardController;
use App\Http\Controllers\ShortlistController;
use App\Http\Controllers\UserSettingsController;
use App\Http\Controllers\WhoViewedController;
use App\Models\BiodataIntake;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web — member surface (authenticated end users)
|--------------------------------------------------------------------------
*/

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
    Route::get('/intake', [IntakeController::class, 'index'])->name('intake.index');
    Route::get('/intake/upload', [IntakeController::class, 'uploadForm'])->name('intake.upload');
    Route::post('/intake/upload', [IntakeController::class, 'store'])->name('intake.store');
    Route::get('/intake/preview/{intake}', [IntakeController::class, 'preview'])->name('intake.preview');
    Route::get('/intake/biodata-original/{intake}', [IntakeController::class, 'biodataOriginalImage'])
        ->name('intake.biodata-original');
    Route::get('/intake/manual-prepared/{intake}', [IntakeController::class, 'manualPreparedImage'])
        ->name('intake.manual-prepared-image');
    Route::post('/intake/manual-crop/{intake}', [IntakeController::class, 'saveManualOcrPrepared'])
        ->name('intake.manual-crop-save');
    Route::post('/intake/manual-crop-clear/{intake}', [IntakeController::class, 'clearManualOcrPrepared'])
        ->name('intake.manual-crop-clear');
    Route::get('/intake/debug/ocr-artifact/{intake}', [IntakeController::class, 'debugOcrArtifact'])
        ->name('intake.debug.ocr-artifact');
    Route::post('/intake/reparse/{intake}', [IntakeController::class, 'reparse'])->name('intake.reparse');
    Route::post('/intake/re-extract/{intake}', [IntakeController::class, 'reExtract'])->name('intake.re-extract');
    Route::post('/intake/approve/{intake}', [IntakeController::class, 'approve'])->name('intake.approve');
    Route::get('/intake/approval', [IntakeController::class, 'approval'])->name('intake.approval');
    Route::get('/intake/status/{intake}', [IntakeController::class, 'status'])
        ->name('intake.status');
    Route::post('/intake/apply-suggestion/{intake}', [IntakeController::class, 'applyPendingIntakeSuggestion'])
        ->name('intake.apply-suggestion');
    Route::post('/intake/reject-suggestion/{intake}', [IntakeController::class, 'rejectPendingIntakeSuggestion'])
        ->name('intake.reject-suggestion');

    Route::get('/api/intake-status/{intake}', function (BiodataIntake $intake) {
        return response()->json([
            'parse_status' => $intake->parse_status,
            'approved_by_user' => (bool) $intake->approved_by_user,
            'intake_status' => $intake->intake_status,
        ]);
    });

    /*
    |--------------------------------------------------------------------------
    | User Settings
    |--------------------------------------------------------------------------
    */
    Route::get('/settings', [UserSettingsController::class, 'index'])
        ->name('user.settings.index');

    Route::get('/settings/privacy', [UserSettingsController::class, 'privacy'])
        ->name('user.settings.privacy');
    Route::post('/settings/privacy', [UserSettingsController::class, 'updatePrivacy'])
        ->name('user.settings.privacy.update');

    Route::get('/settings/communication', [UserSettingsController::class, 'communication'])
        ->name('user.settings.communication');
    Route::post('/settings/communication', [UserSettingsController::class, 'updateCommunication'])
        ->name('user.settings.communication.update');

    Route::get('/settings/security', [UserSettingsController::class, 'security'])
        ->name('user.settings.security');

    /*
    | Matrimony Profile (Phase-5B: wizard is the only create path; create/store disallowed — Point 5)
    */
    Route::get('/matrimony/profile/create', function () {
        return redirect()->route('matrimony.onboarding.show', ['step' => 2]);
    })->name('matrimony.profile.create');

    Route::post('/matrimony/profile/store', function () {
        return redirect()->route('matrimony.onboarding.show', ['step' => 2])
            ->with('info', 'Please use the profile wizard or onboarding to create or update your profile.');
    })->name('matrimony.profile.store');

    Route::get('/matrimony/onboarding/complete', [OnboardingController::class, 'complete'])
        ->name('matrimony.onboarding.complete');
    Route::get('/matrimony/onboarding/{step}', [OnboardingController::class, 'show'])
        ->name('matrimony.onboarding.show')
        ->where('step', '[2-7]');
    Route::post('/matrimony/onboarding/{step}', [OnboardingController::class, 'store'])
        ->name('matrimony.onboarding.store')
        ->where('step', '[2-7]');

    Route::get('/matrimony/profile/wizard', [ProfileWizardController::class, 'index'])
        ->name('matrimony.profile.wizard');
    Route::get('/matrimony/profile/wizard/{section}', [ProfileWizardController::class, 'show'])
        ->name('matrimony.profile.wizard.section')
        ->where('section', 'basic-info|physical|marriages|location|personal-family|education-career|family-details|siblings|relatives|alliance|property|horoscope|about-me|about-preferences|contacts|photo|full');
    Route::post('/matrimony/profile/wizard/{section}', [ProfileWizardController::class, 'store'])
        ->name('matrimony.profile.wizard.store')
        ->where('section', 'basic-info|physical|marriages|location|personal-family|education-career|family-details|siblings|relatives|alliance|property|horoscope|about-me|about-preferences|contacts|photo|full');

    Route::post('/matrimony/profile/contacts/{contact}/send-otp', [ProfileContactVerificationController::class, 'sendOtp'])
        ->middleware(['throttle:6,1'])
        ->whereNumber('contact')
        ->name('matrimony.profile.contacts.send-otp');
    Route::post('/matrimony/profile/contacts/{contact}/verify-otp', [ProfileContactVerificationController::class, 'verifyOtp'])
        ->middleware(['throttle:12,1'])
        ->whereNumber('contact')
        ->name('matrimony.profile.contacts.verify-otp');
    Route::post('/matrimony/profile/contacts/{contact}/promote-primary', [ProfileContactVerificationController::class, 'promotePrimary'])
        ->middleware(['throttle:12,1'])
        ->whereNumber('contact')
        ->name('matrimony.profile.contacts.promote-primary');

    /** GPS → canonical location suggestion (MutationService-only saves; no direct profile writes here). */
    Route::post('/matrimony/internal/location/resolve-current', [CurrentLocationController::class, 'resolve'])
        ->middleware(['throttle:location-gps'])
        ->name('matrimony.internal.location.resolve-current');

    Route::get('/matrimony/profile/wizard/marriage-fields', [ProfileWizardController::class, 'marriageFields'])
        ->name('matrimony.profile.wizard.marriage-fields');

    Route::get('/matrimony/profile/edit', [MatrimonyProfileController::class, 'edit'])
        ->name('matrimony.profile.edit');

    Route::get('/matrimony/profile/edit-full', [MatrimonyProfileController::class, 'editFull'])
        ->name('matrimony.profile.edit-full');

    Route::post('/matrimony/profile/update-full', [MatrimonyProfileController::class, 'updateFull'])
        ->name('matrimony.profile.update-full');

    Route::get('/matrimony/profile/upload-photo', [MatrimonyProfileController::class, 'uploadPhoto'])
        ->name('matrimony.profile.upload-photo');

    Route::post('/matrimony/profile/upload-photo', [MatrimonyProfileController::class, 'storePhoto'])
        ->name('matrimony.profile.store-photo');

    // User profile photo manager (gallery) — same-page actions.
    Route::post('/matrimony/profile/photos/{photo}/make-primary', [MatrimonyProfileController::class, 'makePrimary'])
        ->name('matrimony.profile.photos.make-primary');

    Route::post('/matrimony/profile/photos/reorder', [MatrimonyProfileController::class, 'reorderPhotos'])
        ->name('matrimony.profile.photos.reorder');

    Route::delete('/matrimony/profile/photos/{photo}', [MatrimonyProfileController::class, 'destroy'])
        ->name('matrimony.profile.photos.destroy');

    /*
    | Matrimony Profiles (View / Search)
    */
    Route::get('/matches', [MatchController::class, 'myMatches'])->name('matches.index');
    Route::get('/profiles/{matrimony_profile_id}/matches', [MatchController::class, 'show'])->name('matches.show');

    Route::get('/profiles', [MatrimonyProfileController::class, 'index'])
        ->name('matrimony.profiles.index');

    // 🔒 SSOT: MatrimonyProfile route MUST use matrimony_profile_id
    Route::get('/profile/{matrimony_profile_id}', [MatrimonyProfileController::class, 'show'])
        ->name('matrimony.profile.show');

    Route::get('/matrimony/verification/email', [MatrimonyVerificationEmailController::class, 'show'])
        ->name('matrimony.verification.email');
    Route::post('/matrimony/verification/email', [MatrimonyVerificationEmailController::class, 'sendVerificationLink'])
        ->middleware('throttle:6,1')
        ->name('matrimony.verification.email.send');
    Route::get('/matrimony/profile/{matrimony_profile_id}/verification/kyc', [ProfileVerificationController::class, 'showKyc'])
        ->name('matrimony.verification.kyc');
    Route::post('/matrimony/profile/{matrimony_profile_id}/verification/kyc', [ProfileVerificationController::class, 'storeKyc'])
        ->middleware('throttle:10,1')
        ->name('matrimony.profile.verification.kyc.store');

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
    | Day-33: Who viewed my profile
    */
    Route::get('/who-viewed-me', [WhoViewedController::class, 'index'])->name('who-viewed.index');

    /*
    | Day-32: Contact requests (Request Contact flow)
    */
    Route::get('/contact-inbox', [ContactInboxController::class, 'index'])->name('contact-inbox.index');
    Route::post('/contact-requests/{matrimony_profile}', [ContactRequestController::class, 'store'])->name('contact-requests.store');
    Route::post('/contact-requests/{contact_request}/cancel', [ContactRequestController::class, 'cancel'])->name('contact-requests.cancel');
    Route::post('/contact-requests/{contact_request}/approve', [ContactInboxController::class, 'approve'])->name('contact-requests.approve');
    Route::post('/contact-requests/{contact_request}/reject', [ContactInboxController::class, 'reject'])->name('contact-requests.reject');
    Route::post('/contact-grants/{contact_grant}/revoke', [ContactInboxController::class, 'revoke'])->name('contact-grants.revoke');

    Route::get('/mediation-inbox', [MediationInboxController::class, 'index'])->name('mediation-inbox.index');
    Route::post('/mediation-requests/{mediation_request}/respond', [MediationInboxController::class, 'respond'])
        ->middleware('throttle:30,1')
        ->name('mediation-requests.respond');

    Route::post('/matrimony/profile/{matrimony_profile}/contact-reveal', [ProfileContactActionController::class, 'revealContact'])
        ->middleware('throttle:30,1')
        ->name('matrimony.profile.contact-reveal');
    Route::post('/matrimony/profile/{matrimony_profile}/mediator-request', [ProfileContactActionController::class, 'mediatorRequest'])
        ->middleware('throttle:15,1')
        ->name('matrimony.profile.mediator-request');

    /*
    | Chat (governed messaging)
    */
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/{conversation}', [ChatController::class, 'show'])->name('chat.show');
    Route::post('/chat/start/{matrimony_profile}', [ChatController::class, 'start'])->name('chat.start');
    Route::post('/chat/{conversation}/messages/text', [ChatController::class, 'sendText'])->name('chat.messages.text');
    Route::post('/chat/{conversation}/messages/image', [ChatController::class, 'sendImage'])->name('chat.messages.image');
    Route::post('/chat/{conversation}/read', [ChatController::class, 'read'])->name('chat.read');
    Route::get('/chat/messages/{message}/image', [ChatController::class, 'image'])->name('chat.messages.image.show');

    /*
    | Abuse Reports (User action)
    */
    Route::post('/abuse-reports/{matrimony_profile}', [AbuseReportController::class, 'store'])
        ->name('abuse-reports.store');

    Route::post('/hidden-profiles/{matrimony_profile_id}', [ProfileHideController::class, 'store'])
        ->name('hidden-profiles.store');

    Route::post('/profile-photo-reports/{matrimony_profile_id}', [ProfilePhotoReportController::class, 'store'])
        ->name('profile-photo-reports.store');

    /*
    | Notifications (Day-10 — R5)
    */
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::get('/notifications/{id}', [NotificationController::class, 'show'])->name('notifications.show');
    Route::post('/notifications/{id}/mark-read', [NotificationController::class, 'markRead'])->name('notifications.mark-read');
});
