<?php

use App\Http\Controllers\Api\Suchak\SuchakAgreementLinkApiController;
use App\Http\Controllers\Api\Suchak\SuchakAppConfigApiController;
use App\Http\Controllers\Api\Suchak\SuchakBillingApiController;
use App\Http\Controllers\Api\Suchak\SuchakChatApiController;
use App\Http\Controllers\Api\Suchak\SuchakCollaborationsApiController;
use App\Http\Controllers\Api\Suchak\SuchakCollaborationsMutationsApiController;
use App\Http\Controllers\Api\Suchak\SuchakConsentRequestsApiController;
use App\Http\Controllers\Api\Suchak\SuchakConsentsApiController;
use App\Http\Controllers\Api\Suchak\SuchakCustomerDetailApiController;
use App\Http\Controllers\Api\Suchak\SuchakCustomerPlanApiController;
use App\Http\Controllers\Api\Suchak\SuchakCustomerShareCardApiController;
use App\Http\Controllers\Api\Suchak\SuchakCustomersApiController;
use App\Http\Controllers\Api\Suchak\SuchakDashboardApiController;
use App\Http\Controllers\Api\Suchak\SuchakDeviceTokenApiController;
use App\Http\Controllers\Api\Suchak\SuchakIntakeApiController;
use App\Http\Controllers\Api\Suchak\SuchakLoginApiController;
use App\Http\Controllers\Api\Suchak\SuchakManualProfileApiController;
use App\Http\Controllers\Api\Suchak\SuchakMatchSuggestionsApiController;
use App\Http\Controllers\Api\Suchak\SuchakMeApiController;
use App\Http\Controllers\Api\Suchak\SuchakMeetingsApiController;
use App\Http\Controllers\Api\Suchak\SuchakMeetingsMutationsApiController;
use App\Http\Controllers\Api\Suchak\SuchakNotificationPreferenceApiController;
use App\Http\Controllers\Api\Suchak\SuchakCustomerOpsApiController;
use App\Http\Controllers\Api\Suchak\SuchakPaymentSetupApiController;
use App\Http\Controllers\Api\Suchak\SuchakPaymentIdentityApiController;
use App\Http\Controllers\Api\Suchak\SuchakPaymentRequestOptionsApiController;
use App\Http\Controllers\Api\Suchak\SuchakPaymentRequestsApiController;
use App\Http\Controllers\Api\Suchak\SuchakPaymentsApiController;
use App\Http\Controllers\Api\Suchak\SuchakProfileRequestsApiController;
use App\Http\Controllers\Api\Suchak\SuchakPayuCheckoutApiController;
use App\Http\Controllers\Api\Suchak\SuchakRegisterApiController;
use App\Http\Controllers\Api\Suchak\SuchakRepresentedProfileApiController;
use App\Http\Controllers\Api\Suchak\SuchakSearchApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — Suchak mobile adapters
|--------------------------------------------------------------------------
*/

Route::prefix('suchak')->group(function () {
    Route::get('/app-config', SuchakAppConfigApiController::class);

    Route::post('/login/otp/send', [SuchakLoginApiController::class, 'sendOtp']);
    Route::post('/login/otp/verify', [SuchakLoginApiController::class, 'verifyOtp']);
    Route::post('/login/password', [SuchakLoginApiController::class, 'loginWithPassword']);
    Route::post('/login/google', [SuchakLoginApiController::class, 'loginWithGoogle']);

    Route::post('/register', [SuchakRegisterApiController::class, 'store']);
    Route::post('/register/start', [SuchakRegisterApiController::class, 'startMobile']);
    Route::post('/register/resolve-location', [SuchakRegisterApiController::class, 'resolveLocation']);
});

Route::middleware(['auth:sanctum', 'suchak.account'])->prefix('suchak')->group(function () {
    Route::post('/register/otp/resend', [SuchakRegisterApiController::class, 'resendOtp']);
    Route::post('/register/otp/verify', [SuchakRegisterApiController::class, 'verifyOtp']);
    Route::post('/register/identity', [SuchakRegisterApiController::class, 'updateIdentity']);
    Route::post('/register/location', [SuchakRegisterApiController::class, 'updateLocation']);
    Route::post('/register/password', [SuchakRegisterApiController::class, 'setPassword']);
    Route::post('/register/photo', [SuchakRegisterApiController::class, 'storePhoto']);
    Route::post('/register/organization-logo', [SuchakRegisterApiController::class, 'storeOrganizationLogo']);
    Route::post('/register/office-photo', [SuchakRegisterApiController::class, 'storeOfficePhoto']);
    Route::post('/register/documents', [SuchakRegisterApiController::class, 'storeDocument']);
    Route::delete('/register/documents/{document}', [SuchakRegisterApiController::class, 'destroyDocument'])
        ->whereNumber('document');
    Route::get('/register/status', [SuchakRegisterApiController::class, 'status']);

    /*
    | Mobile push (FCM). Tokens are owned by the SuchakAccount, so the same phone
    | can hold both apps without one unregistering the other.
    */
    Route::post('/device-tokens', [SuchakDeviceTokenApiController::class, 'store']); // REGISTER FCM DEVICE TOKEN
    Route::delete('/device-tokens', [SuchakDeviceTokenApiController::class, 'destroy']); // UNREGISTER ON LOGOUT
    // Same server-driven settings payload as the member app; category list is
    // filtered to types the Suchak app can actually render.
    Route::get('/notification-preferences', [SuchakNotificationPreferenceApiController::class, 'show']);
    Route::put('/notification-preferences', [SuchakNotificationPreferenceApiController::class, 'update']);

    Route::get('/me', SuchakMeApiController::class);
    Route::get('/dashboard', SuchakDashboardApiController::class);
    Route::get('/customers', SuchakCustomersApiController::class);
    Route::get('/customers/{representation}', [SuchakCustomerDetailApiController::class, 'show']);
    Route::get('/customers/{representation}/share-card', SuchakCustomerShareCardApiController::class);
    Route::post('/customers/{representation}/consents', [SuchakConsentsApiController::class, 'store']);
    Route::post('/customers/{representation}/consents/renew', [SuchakConsentsApiController::class, 'renew']);
    // The Suchak's own declaration that the candidate agreed in person.
    Route::post('/customers/{representation}/consents/declare', [SuchakConsentsApiController::class, 'declare']);
    Route::get('/customers/{representation}/payment-request-options', SuchakPaymentRequestOptionsApiController::class);
    Route::post('/customers/{representation}/payment-setup', SuchakPaymentSetupApiController::class);
    Route::post('/customers/{representation}/notes', [SuchakCustomerOpsApiController::class, 'storeNote']);
    Route::post('/customers/{representation}/exports', [SuchakCustomerOpsApiController::class, 'exportBiodata']);
    // Read-only feed of THIS Suchak's un-consented claims. Consent-first
    // linking hides a claim everywhere else, so this is the only way back to
    // the resend endpoint below.
    Route::get('/consent-requests', SuchakConsentRequestsApiController::class);
    Route::post('/consents/{consent}/resend', [SuchakConsentsApiController::class, 'resend']);
    Route::post('/consents/{consent}/cancel-pending', [SuchakConsentsApiController::class, 'cancelPending']);
    Route::get('/search', SuchakSearchApiController::class);
    Route::get('/collaborations', SuchakCollaborationsApiController::class);
    Route::post('/collaborations', [SuchakCollaborationsMutationsApiController::class, 'store']);
    Route::post('/collaborations/{collaboration}/accept', [SuchakCollaborationsMutationsApiController::class, 'accept']);
    Route::post('/collaborations/{collaboration}/reject', [SuchakCollaborationsMutationsApiController::class, 'reject']);
    Route::get('/payments', SuchakPaymentsApiController::class);
    Route::get('/payment-identity', [SuchakPaymentIdentityApiController::class, 'show']);
    Route::post('/payment-identity', [SuchakPaymentIdentityApiController::class, 'update']);
    Route::get('/payment-requests', [SuchakPaymentRequestsApiController::class, 'index']);
    Route::post('/payment-requests', [SuchakPaymentRequestsApiController::class, 'store']);
    Route::post('/payment-requests/{paymentRequest}/mark-paid', [SuchakPaymentRequestsApiController::class, 'markPaid']);
    Route::post('/payment-requests/{paymentRequest}/reverse-paid', [SuchakPaymentRequestsApiController::class, 'reversePaid']);
    // Mints (or re-mints) the single-use link the CUSTOMER accepts the price
    // agreement on. Agreement ids come from
    // GET /customers/{representation}/payment-request-options → customer_agreements[].id.
    // Throttled like the public consent/agreement decision routes: re-issuing
    // kills the link already in the customer's hands.
    Route::post('/customer-agreements/{agreement}/acceptance-link', SuchakAgreementLinkApiController::class)
        ->whereNumber('agreement')
        ->middleware('throttle:10,1');
    Route::get('/plans', [SuchakBillingApiController::class, 'plans']);
    Route::get('/billing', [SuchakBillingApiController::class, 'status']);
    Route::post('/plans/{plan}/payu/start', [SuchakPayuCheckoutApiController::class, 'start']);
    // Per-Suchak REUSABLE customer plan presets (management + carousel).
    // Distinct from /plans above, which is the platform subscription catalog.
    Route::get('/customer-plans', [SuchakCustomerPlanApiController::class, 'index']);
    Route::post('/customer-plans', [SuchakCustomerPlanApiController::class, 'store']);
    Route::post('/customer-plans/reorder', [SuchakCustomerPlanApiController::class, 'reorder']);
    Route::put('/customer-plans/{id}', [SuchakCustomerPlanApiController::class, 'update']);
    Route::delete('/customer-plans/{id}', [SuchakCustomerPlanApiController::class, 'destroy']);
    Route::get('/meetings', SuchakMeetingsApiController::class);
    Route::post('/meetings', [SuchakMeetingsMutationsApiController::class, 'schedule']);
    Route::post('/meetings/{visit}/complete', [SuchakMeetingsMutationsApiController::class, 'complete']);
    Route::post('/meetings/{visit}/cancel', [SuchakMeetingsMutationsApiController::class, 'cancel']);
    Route::post('/intakes', [SuchakIntakeApiController::class, 'store']);
    Route::get('/manual-profiles/meta', [SuchakManualProfileApiController::class, 'meta']);
    Route::post('/manual-profiles/duplicate-check', [SuchakManualProfileApiController::class, 'duplicateCheck']);
    Route::post('/manual-profiles', [SuchakManualProfileApiController::class, 'store']);
    Route::get('/nxt/{representation}/profile', [SuchakRepresentedProfileApiController::class, 'show']);
    Route::put('/nxt/{representation}/profile', [SuchakRepresentedProfileApiController::class, 'update']);
    Route::post('/nxt/{representation}/profile/save-step', [SuchakRepresentedProfileApiController::class, 'saveStep']);
    Route::post('/nxt/{representation}/profile/photo', [SuchakRepresentedProfileApiController::class, 'uploadPhoto']);
    Route::get('/nxt/{representation}/consent-contacts', [SuchakRepresentedProfileApiController::class, 'consentContacts']);
    Route::post('/nxt/{representation}/consent-contacts', [SuchakRepresentedProfileApiController::class, 'storeConsentContact']);
    Route::post('/nxt/{representation}/preferences/auto-draft', [SuchakRepresentedProfileApiController::class, 'autoDraftPreferences']);

    // Incoming member→Suchak requests. Until now this pipeline existed only on
    // the website, so a Suchak using the app never learned that a member had
    // approached one of their customers. Same service as the web reply route.
    Route::get('/profile-requests', [SuchakProfileRequestsApiController::class, 'index']);
    Route::get('/profile-requests/{profileRequest}', [SuchakProfileRequestsApiController::class, 'show']);
    Route::post('/profile-requests/{profileRequest}/reply', [SuchakProfileRequestsApiController::class, 'reply']);
    Route::post('/profile-requests/{profileRequest}/forward', [SuchakProfileRequestsApiController::class, 'forward']);
    Route::post('/profile-requests/{profileRequest}/decision', [SuchakProfileRequestsApiController::class, 'decide']);

    // The READ half of that same pipeline. The Suchak's reply already lands in
    // the member↔candidate conversation; without these the member's answer was
    // invisible to the person handling the match. Same chat engine, same
    // payload shape as /chats for members — only the authorization differs
    // (conversation must belong to a request this Suchak owns, with consent).
    // Polling via ?since_id=, no realtime layer.
    Route::get('/chats', [SuchakChatApiController::class, 'index']);
    Route::get('/chats/unread-count', [SuchakChatApiController::class, 'unreadCount']);
    Route::get('/chats/{conversation}', [SuchakChatApiController::class, 'show']);
    Route::post('/chats/{conversation}/messages', [SuchakChatApiController::class, 'send']);
    Route::post('/chats/{conversation}/read', [SuchakChatApiController::class, 'read']);

    // Ranked, masked match suggestions for one represented candidate + the
    // learning log behind them (impression on read, decision on write).
    Route::get('/representations/{representation}/suggestions', [SuchakMatchSuggestionsApiController::class, 'index']);
    Route::post(
        '/representations/{representation}/suggestions/{candidateProfile}/decision',
        [SuchakMatchSuggestionsApiController::class, 'decide'],
    );
});

