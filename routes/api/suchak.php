<?php

use App\Http\Controllers\Api\Suchak\SuchakAgreementLinkApiController;
use App\Http\Controllers\Api\Suchak\SuchakAppConfigApiController;
use App\Http\Controllers\Api\Suchak\SuchakBillingApiController;
use App\Http\Controllers\Api\Suchak\SuchakChatApiController;
use App\Http\Controllers\Api\Suchak\SuchakCollaborationStagesApiController;
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
use App\Http\Controllers\Api\Suchak\SuchakMarketplaceChallengeApiController;
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
use App\Http\Controllers\Api\Suchak\SuchakTwelveMonthClauseApiController;
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
    /*
    | The marketplace ladder (blueprint 6a) and the engagement's binding to the
    | customer agreement revision (6.1). All three services behind these routes
    | shipped complete, guarded and tested with no controller and no route, so
    | `customer_owner_side` could only ever hold its default and
    | `marketplace_stage` / `customer_agreement_id` could only ever be NULL.
    | The customer's CONFIRM half is not here — the actor is the member, so it
    | sits on the member API (routes/api/member.php).
    */
    Route::post(
        '/collaborations/{collaboration}/customer-agreement',
        [SuchakCollaborationStagesApiController::class, 'linkCustomerAgreement'],
    ); // BIND THE ENGAGEMENT TO THE AGREEMENT REVISION IN FORCE (WRITE-ONCE)
    Route::post('/collaborations/{collaboration}/stages', [SuchakCollaborationStagesApiController::class, 'claimEngagementStage']); // CLAIM AN ENGAGEMENT-OWNED LADDER STAGE
    // The four PRE-ENGAGEMENT stages: they happen before any counterparty
    // exists, so they hang off the customer agreement, not off a collaboration.
    Route::post('/customer-agreements/{agreement}/stages', [SuchakCollaborationStagesApiController::class, 'claimCustomerStage'])
        ->whereNumber('agreement'); // CLAIM A PRE-ENGAGEMENT LADDER STAGE
    /*
    | THE 12-MONTH ANTI-CIRCUMVENTION CLAUSE (D11, D21, phase 3) — the READ.
    |
    | "A marriage within 12 months to a profile the customer VIEWED still owes
    | the success fee, however the later contact happened, and even if the
    | engagement ended." The binding is created by the family's own `viewed` rung
    | (SuchakCollaborationStageEvent::CLAUSE_ANCHOR_STAGE); these two routes are
    | the only way to ASK about it, and a clause nobody can query is a clause
    | nobody can enforce.
    |
    | Keyed on the CUSTOMER CONTEXT, not on the agreement and not on the
    | engagement: D21 makes the clause outlive both, so keying it on either would
    | lose exactly the case the clause exists for. The context id is already
    | published by GET /customers/{representation}/payment-request-options.
    |
    | Read-only. Whether the fee is collected is phase 4.
    */
    Route::get(
        '/customer-contexts/{customerContext}/twelve-month-clause',
        [SuchakTwelveMonthClauseApiController::class, 'index'],
    )->whereNumber('customerContext'); // EVERY BINDING THIS CUSTOMER CARRIES
    Route::get(
        '/customer-contexts/{customerContext}/twelve-month-clause/{candidate}',
        [SuchakTwelveMonthClauseApiController::class, 'show'],
    )->whereNumber(['customerContext', 'candidate']); // IS A SHARE OWED ON THIS PAIR, AND UNTIL WHEN
    /*
    | THE CHALLENGE OBJECT (blueprint D4 / D18, phase 2).
    |
    | "I hold this customer; I will pay X to whoever brings the match." Published
    | BEFORE any helper exists, which is why it needed a table of its own: every
    | candidate owner in the schema names two Suchak accounts NOT NULL from row
    | one. It is the INVITATION — the engagement stays
    | suchak_collaboration_requests + suchak_commission_agreements (6.1).
    |
    | /mine is declared before /{challenge} or the numeric binding would swallow
    | it. Both browse reads are gated on the VERIFIED badge (D18 / A10) in the
    | service, which is stricter than canOperate() on purpose.
    */
    Route::get('/marketplace/challenges', [SuchakMarketplaceChallengeApiController::class, 'index']); // BROWSE (verified only, masked candidates)
    Route::get('/marketplace/challenges/mine', [SuchakMarketplaceChallengeApiController::class, 'mine']); // OWN CHALLENGES — where the withdraw id comes from
    Route::post('/marketplace/challenges', [SuchakMarketplaceChallengeApiController::class, 'store']); // PUBLISH + WRITE published_to_marketplace ON THE LADDER
    Route::post('/marketplace/challenges/{challenge}/withdraw', [SuchakMarketplaceChallengeApiController::class, 'withdraw'])
        ->whereNumber('challenge');
    /*
    | ACCEPT BY PROPOSING (blueprint D7 / D7a / 6.1, phase 2).
    |
    | A helping Suchak cannot press a bare "accept" — there is deliberately no
    | such endpoint. He NAMES one of his own candidates, and that act creates
    | the engagement, which is the existing suchak_collaboration_requests +
    | suchak_commission_agreements pair written in the REVERSED direction
    | (5.2's direction note). No third table, and no second accept verb.
    |
    | The publisher answers on the EXISTING collaboration routes above —
    | /collaborations/{collaboration}/accept and /reject already gate on the
    | target actor, and in this direction the target is him. The GET below is
    | what he reads before doing so; without it that decision is blind.
    |
    | The declared share is NOT a parameter on either route. D4 freezes it in
    | the challenge, and POST /collaborations/{id}/commission (web) is refused
    | outright for a marketplace engagement — reversed, its requester-only rule
    | would have handed the split to the helper.
    */
    Route::post('/marketplace/challenges/{challenge}/proposals', [SuchakMarketplaceChallengeApiController::class, 'propose'])
        ->whereNumber('challenge'); // PROPOSE A NAMED CANDIDATE + WRITE profile_suggested ON THE LADDER
    Route::get('/marketplace/challenges/{challenge}/proposals', [SuchakMarketplaceChallengeApiController::class, 'proposals'])
        ->whereNumber('challenge'); // THE PUBLISHER'S INBOX FOR ONE CHALLENGE — masked candidates
    // D7a: the helper's OWN candidates, searchable, filterable and ranked against this challenge's
    // candidate. The other half of the POST above — that route takes a representation_id, and
    // without this read the only way to find one was to scroll every candidate the Suchak holds.
    // Own candidates, so NOT masked; the badge and the not-your-own-challenge gates still run.
    Route::get('/marketplace/challenges/{challenge}/my-candidates', [SuchakMarketplaceChallengeApiController::class, 'myCandidates'])
        ->whereNumber('challenge');
    // Opening ONE listing is logged and shown to the originating Suchak (D18).
    Route::get('/marketplace/challenges/{challenge}', [SuchakMarketplaceChallengeApiController::class, 'show'])
        ->whereNumber('challenge');
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
    // The HELPING Suchak contests a meeting arranged on his candidate (§7.2
    // stop-loss, D26). Members dispute on the member API
    // (`POST /api/v1/suchak-meetings/{visit}/dispute`) and admins on the admin
    // web surface; all three enter the same `disputeVisit()`, which decides who
    // the actor is. The arranging Suchak is refused there — he is the claimant.
    Route::post('/meetings/{visit}/dispute', [SuchakMeetingsMutationsApiController::class, 'dispute']);
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

