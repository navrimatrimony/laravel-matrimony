<?php

use App\Http\Controllers\AbuseReportController;
use App\Http\Controllers\Api\BiodataIntakeApiController;
use App\Http\Controllers\Api\CasteLookupController;
use App\Http\Controllers\Api\ContactActionApiController;
use App\Http\Controllers\Api\ContactInboxApiController;
use App\Http\Controllers\Api\ExtendedFieldApiController;
use App\Http\Controllers\Api\FieldRegistryApiController;
use App\Http\Controllers\Api\InterestApiController;
use App\Http\Controllers\Api\MatrimonyProfileApiController;
use App\Http\Controllers\Api\MobileBiodataExportApiController;
use App\Http\Controllers\Api\MobileChatApiController;
use App\Http\Controllers\Api\MobileNotificationApiController;
use App\Http\Controllers\Api\MobilePlanApiController;
use App\Http\Controllers\Api\MobileProfilePhotoApiController;
use App\Http\Controllers\Api\MobileProfileListApiController;
use App\Http\Controllers\Api\MobileSettingsApiController;
use App\Http\Controllers\Api\ProfileSetupLookupController;
use App\Http\Controllers\Api\ProfileActionApiController;
use App\Http\Controllers\Api\ProfileFieldLockApiController;
use App\Http\Controllers\Api\ReligionLookupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — member surface (auth:sanctum)
|--------------------------------------------------------------------------
| Loaded inside Route::prefix('v1') from routes/api.php (move-only).
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/matrimony-profile', [MatrimonyProfileApiController::class, 'store']); // CREATE
    Route::get('/matrimony-profile', [MatrimonyProfileApiController::class, 'show']);  // FETCH
    Route::put('/matrimony-profile', [MatrimonyProfileApiController::class, 'update']); // UPDATE
    Route::post('/matrimony-profile/photo', [MatrimonyProfileApiController::class, 'uploadPhoto']); // PHOTO UPLOAD
    Route::get('/matrimony-profile/photos', [MobileProfilePhotoApiController::class, 'index']); // PHOTO GALLERY
    Route::post('/matrimony-profile/photos', [MobileProfilePhotoApiController::class, 'store']); // UPLOAD GALLERY PHOTO
    Route::post('/matrimony-profile/photos/{photo}/primary', [MobileProfilePhotoApiController::class, 'makePrimary']); // SET PRIMARY PHOTO
    Route::delete('/matrimony-profile/photos/{photo}', [MobileProfilePhotoApiController::class, 'destroy']); // DELETE PHOTO
    Route::put('/matrimony-profile/photos/reorder', [MobileProfilePhotoApiController::class, 'reorder']); // REORDER PHOTOS
    Route::get('/matrimony-profile/verification-status', [MobileProfilePhotoApiController::class, 'verificationStatus']); // PROFILE VERIFICATION STATUS
    Route::get('/matrimony-profiles', [MatrimonyProfileApiController::class, 'index']); // LIST ALL PROFILES
    Route::get('/matrimony-profiles/more-sections', [MatrimonyProfileApiController::class, 'moreSections']); // MOBILE MORE MATCHES SECTIONS
    Route::get('/matrimony-profiles/{id}', [MatrimonyProfileApiController::class, 'showById']); // GET PROFILE BY ID
    Route::post('/matrimony-profiles/{id}/contact-reveal', [ContactActionApiController::class, 'reveal']); // REVEAL CONTACT
    Route::post('/matrimony-profiles/{id}/contact-requests', [ContactInboxApiController::class, 'store']); // REQUEST CONTACT
    Route::get('/contact-inbox', [ContactInboxApiController::class, 'index']); // CONTACT REQUEST INBOX
    Route::post('/contact-requests/{id}/approve', [ContactInboxApiController::class, 'approve']); // APPROVE CONTACT REQUEST
    Route::post('/contact-requests/{id}/reject', [ContactInboxApiController::class, 'reject']); // REJECT CONTACT REQUEST
    Route::get('/plans/current', [MobilePlanApiController::class, 'current']); // CURRENT PLAN + CONTACT QUOTA
    Route::get('/plans', [MobilePlanApiController::class, 'index']); // MOBILE PLAN CATALOG
    Route::post('/plans/{plan}/checkout', [MobilePlanApiController::class, 'checkout']); // START WEB CHECKOUT BRIDGE
    Route::get('/biodata/export-options', [MobileBiodataExportApiController::class, 'options']); // BIODATA EXPORT OPTIONS
    Route::post('/biodata/export', [MobileBiodataExportApiController::class, 'export']); // CREATE SIGNED BIODATA EXPORT LINK
    Route::get('/notifications', [MobileNotificationApiController::class, 'index']); // MOBILE NOTIFICATIONS LIST
    Route::get('/notifications/unread-count', [MobileNotificationApiController::class, 'unreadCount']); // MOBILE NOTIFICATIONS COUNT
    Route::post('/notifications/{id}/read', [MobileNotificationApiController::class, 'markRead']); // MARK NOTIFICATION READ
    Route::post('/notifications/read-all', [MobileNotificationApiController::class, 'markAllRead']); // MARK ALL NOTIFICATIONS READ
    Route::get('/chats', [MobileChatApiController::class, 'index']); // MOBILE CHAT INBOX
    Route::get('/chats/unread-count', [MobileChatApiController::class, 'unreadCount']); // MOBILE CHAT UNREAD COUNT
    Route::post('/matrimony-profiles/{id}/chat/start', [MobileChatApiController::class, 'start']); // START MOBILE CHAT
    Route::get('/chats/{conversation}', [MobileChatApiController::class, 'show']); // MOBILE CHAT THREAD
    Route::post('/chats/{conversation}/messages', [MobileChatApiController::class, 'sendText']); // SEND MOBILE CHAT TEXT
    Route::post('/chats/{conversation}/read', [MobileChatApiController::class, 'read']); // MARK MOBILE CHAT READ
    Route::get('/settings', [MobileSettingsApiController::class, 'index']); // MOBILE SETTINGS
    Route::put('/settings/privacy', [MobileSettingsApiController::class, 'updatePrivacy']); // UPDATE PRIVACY SETTINGS
    Route::put('/settings/notifications', [MobileSettingsApiController::class, 'updateNotifications']); // UPDATE NOTIFICATION SETTINGS
    Route::put('/settings/communication', [MobileSettingsApiController::class, 'updateCommunication']); // UPDATE COMMUNICATION SETTINGS
    Route::get('/profile-lists/shortlisted', [MobileProfileListApiController::class, 'shortlisted']); // MY SHORTLIST
    Route::get('/profile-lists/blocked', [MobileProfileListApiController::class, 'blocked']); // BLOCKED PROFILES
    Route::get('/profile-lists/hidden', [MobileProfileListApiController::class, 'hidden']); // HIDDEN PROFILES
    Route::post('/matrimony-profiles/{id}/shortlist', [ProfileActionApiController::class, 'shortlist']); // SHORTLIST PROFILE
    Route::delete('/matrimony-profiles/{id}/shortlist', [ProfileActionApiController::class, 'unshortlist']); // REMOVE SHORTLIST
    Route::post('/matrimony-profiles/{id}/hide', [ProfileActionApiController::class, 'hide']); // HIDE PROFILE FROM LISTS
    Route::delete('/matrimony-profiles/{id}/hide', [ProfileActionApiController::class, 'unhide']); // UNHIDE PROFILE
    Route::post('/matrimony-profiles/{id}/block', [ProfileActionApiController::class, 'block']); // BLOCK PROFILE
    Route::delete('/matrimony-profiles/{id}/block', [ProfileActionApiController::class, 'unblock']); // UNBLOCK PROFILE
    Route::post('/interests', [InterestApiController::class, 'store']); // SEND INTEREST
    Route::get('/interests/sent', [InterestApiController::class, 'sent']); // GET SENT INTERESTS
    Route::get('/interests/received', [InterestApiController::class, 'received']); // GET RECEIVED INTERESTS
    Route::post('/interests/{id}/accept', [InterestApiController::class, 'accept']); // ACCEPT INTEREST
    Route::post('/interests/{id}/reject', [InterestApiController::class, 'reject']); // REJECT INTEREST
    Route::post('/interests/{id}/withdraw', [InterestApiController::class, 'withdraw']); // WITHDRAW INTEREST
    Route::post('/logout', [\App\Http\Controllers\Api\AuthController::class, 'logout']); // LOGOUT

    /*
    | Phase-4: Field Registry (read-only)
    */
    Route::get('/field-registry', [FieldRegistryApiController::class, 'index']); // LIST FIELD REGISTRY

    /*
    | Phase-4: Extended Fields (definition read-only)
    */
    Route::get('/extended-fields', [ExtendedFieldApiController::class, 'index']); // LIST EXTENDED FIELD DEFINITIONS

    /*
    | Phase-4: Field Locks (view-only)
    */
    Route::get('/matrimony-profile/field-locks', [ProfileFieldLockApiController::class, 'index']); // LIST FIELD LOCKS FOR OWN PROFILE

    /*
    | Phase-4: Biodata Intakes (list + show only)
    */
    Route::get('/biodata-intakes', [BiodataIntakeApiController::class, 'index']); // LIST BIODATA INTAKES
    Route::get('/biodata-intakes/{id}', [BiodataIntakeApiController::class, 'show']); // SHOW BIODATA INTAKE

    /*
    | Abuse Reports (User action)
    */
    Route::post('/abuse-reports/{profile}', [AbuseReportController::class, 'store']); // SUBMIT ABUSE REPORT

    /*
    | Religion → Caste → SubCaste lookup (Phase-5)
    */
    Route::get('/religions', [ReligionLookupController::class, 'index']);
    Route::get('/castes', [CasteLookupController::class, 'getCastes']); // GET ?religion_id=
    Route::get('/sub-castes', [CasteLookupController::class, 'getSubCastes']); // GET ?caste_id=&q=
    Route::post('/sub-castes', [CasteLookupController::class, 'createSubCaste']);
    Route::get('/profile/basic-physical-options', [ProfileSetupLookupController::class, 'basicPhysicalOptions']);
    Route::get('/profile/education-career-options', [ProfileSetupLookupController::class, 'educationCareerOptions']);
    Route::get('/profile/marital-lifestyle-options', [ProfileSetupLookupController::class, 'maritalLifestyleOptions']);
    Route::get('/profile/remaining-profile-options', [ProfileSetupLookupController::class, 'remainingProfileOptions']);
    Route::get('/profile/partner-preference-options', [ProfileSetupLookupController::class, 'partnerPreferenceOptions']);
});
