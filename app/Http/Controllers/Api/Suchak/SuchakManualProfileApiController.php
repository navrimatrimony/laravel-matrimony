<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAccessService;
use App\Modules\Suchak\Services\SuchakCandidateDuplicateCheckService;
use App\Modules\Suchak\Services\SuchakConsentService;
use App\Modules\Suchak\Services\SuchakCustomerLifecycleService;
use App\Modules\Suchak\Services\SuchakRepresentationService;
use App\Services\Admin\AdminSettingService;
use App\Services\MutationService;
use App\Support\MobileNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Thin mobile adapter over ManualProfileController store workflow.
 * Mirrors existing validation and service orchestration; no new business rules.
 */
class SuchakManualProfileApiController extends Controller
{
    public function meta(SuchakAccessService $accessService, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        /** @var SuchakAccount|null $account */
        $account = $user->suchakAccount;
        if ($account === null || ! $accessService->canOwnerPrepareCustomers($account, $user)) {
            return response()->json([
                'success' => false,
                'message' => 'Only active Suchak accounts can create a manual candidate profile.',
            ], 403);
        }

        // Resolved for the whole api group by SetApiLocale; still read here
        // because the response echoes it back as `data.locale`.
        $locale = app()->getLocale();

        return response()->json([
            'success' => true,
            'message' => 'Manual profile form options loaded.',
            'data' => [
                'locale' => $locale,
                'genders' => $this->activeGenders()->map(static fn (MasterGender $g): array => [
                    'id' => $g->id,
                    'key' => $g->key,
                    'label' => $g->localizedLabel(),
                ])->values()->all(),
                'registering_for_options' => $this->registeringForOptions(),
                'consent_relation_label' => __('suchak.manual_profile.consent_relation'),
                'consent_relation_hint' => __('suchak.manual_profile.consent_relation_hint'),
            ],
        ]);
    }

    /**
     * Pre-create duplicate check (PO decision 2026-07-22): after wizard step 1
     * the app asks whether this person already has a profile, so the Suchak can
     * jump straight to consent-on-existing instead of re-typing a duplicate.
     * Reporting only — never blocks; the Suchak decides.
     *
     * Each match reports `confidence` (confirmed|high|medium|low), `is_hard_stop`
     * (true for confirmed/high — the app may stop the wizard there) and
     * `owner_type` (mine|other_suchak|platform_member|unrepresented) so the app
     * can pick the right branch. `data.hard_stop` is the any-match roll-up.
     */
    public function duplicateCheck(
        Request $request,
        SuchakAccessService $accessService,
        SuchakCandidateDuplicateCheckService $duplicateCheckService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        /** @var SuchakAccount|null $account */
        $account = $user->suchakAccount;
        if ($account === null || ! $accessService->canOwnerPrepareCustomers($account, $user)) {
            return response()->json([
                'success' => false,
                'message' => 'Only active Suchak accounts can create a manual candidate profile.',
            ], 403);
        }

        $validated = $request->validate([
            'candidate_name' => ['required', 'string', 'max:255'],
            'candidate_mobile' => ['required', 'string', 'max:32'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'candidate_gender' => ['nullable', Rule::exists('master_genders', 'key')->where('is_active', true)],
            // Optional weak signals — only used when no DOB was typed, and they
            // can never raise a match above 'medium' (advisory).
            'location_id' => ['nullable', 'integer'],
            'caste_id' => ['nullable', 'integer'],
        ]);

        $mobile = MobileNumber::normalize((string) $validated['candidate_mobile']);
        if ($mobile === null) {
            return response()->json([
                'success' => false,
                'message' => __('otp.enter_valid_10_digit_mobile'),
                'errors' => ['candidate_mobile' => [__('otp.enter_valid_10_digit_mobile')]],
            ], 422);
        }

        $result = $duplicateCheckService->check(
            $mobile,
            (string) $validated['candidate_name'],
            $validated['date_of_birth'] ?? null,
            $validated['candidate_gender'] ?? null,
            $account,
            [
                'location_id' => $validated['location_id'] ?? null,
                'caste_id' => $validated['caste_id'] ?? null,
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Duplicate check completed.',
            'data' => $result,
        ]);
    }

    public function store(
        Request $request,
        SuchakAccessService $accessService,
        MutationService $mutationService,
        SuchakRepresentationService $representationService,
        SuchakCustomerLifecycleService $customerLifecycleService,
        SuchakConsentService $consentService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        /** @var SuchakAccount|null $account */
        $account = $user->suchakAccount;
        if ($account === null || ! $accessService->canOwnerPrepareCustomers($account, $user)) {
            return response()->json([
                'success' => false,
                'message' => 'Only active Suchak accounts can create a manual candidate profile.',
            ], 403);
        }

        $validated = $request->validate([
            'candidate_name' => ['required', 'string', 'max:255'],
            // Required as of 2026-07-22 (PO decision): every profile needs at least one
            // reachable number — consent delivery depends on it. Was nullable before.
            'candidate_mobile' => ['required', 'string', 'max:32'],
            'candidate_email' => ['nullable', 'email', 'max:255'],
            'candidate_gender' => ['required', Rule::exists('master_genders', 'key')->where('is_active', true)],
            'registering_for' => [
                'required',
                Rule::in(array_keys($this->registeringForOptions())),
            ],
            // CONSENT-FIRST (2026-07-26): this flag no longer means "link them
            // now". It means "ask this existing person for consent to represent
            // them". Nothing is linked until they accept.
            'use_existing_profile' => ['nullable', 'boolean'],
        ]);

        $mobile = MobileNumber::normalize((string) $validated['candidate_mobile']);
        if ($mobile === null) {
            return response()->json([
                'success' => false,
                'message' => __('otp.enter_valid_10_digit_mobile'),
                'errors' => ['candidate_mobile' => [__('otp.enter_valid_10_digit_mobile')]],
            ], 422);
        }

        $existingMember = User::query()
            ->where('mobile', $mobile)
            ->with('matrimonyProfile')
            ->first();

        if ($existingMember !== null) {
            return $this->handleExistingMobileProfile(
                $request,
                $mobile,
                $existingMember,
                $account,
                $representationService,
                $consentService,
            );
        }

        if (! empty($validated['candidate_email'])) {
            Validator::make(
                ['candidate_email' => $validated['candidate_email']],
                ['candidate_email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')]],
            )->validate();
        }

        $genderId = MasterGender::query()
            ->where('key', $validated['candidate_gender'])
            ->where('is_active', true)
            ->value('id');

        try {
            [$member, $profile, $representation] = DB::transaction(function () use (
                $validated,
                $mobile,
                $genderId,
                $request,
                $account,
                $user,
                $mutationService,
                $representationService,
                $customerLifecycleService
            ): array {
                $member = User::query()->create([
                    'name' => $validated['candidate_name'],
                    'email' => ($validated['candidate_email'] ?? null) ?: null,
                    'mobile' => $mobile,
                    'password' => Hash::make(Str::random(40)),
                    'registering_for' => $validated['registering_for'],
                    'referral_code' => User::generateUniqueReferralCode(),
                ]);

                $profile = $mutationService->createDraftProfileForUser($member, [
                    'full_name' => $validated['candidate_name'],
                    'gender_id' => $genderId,
                    'is_suspended' => AdminSettingService::isManualProfileActivationRequired(),
                ]);

                $representation = $representationService->createPendingManualProfile(
                    $account,
                    $user,
                    $profile,
                    $request->ip(),
                    $request->userAgent(),
                );

                $customerLifecycleService->createForRepresentation(
                    $account,
                    $user,
                    $representation,
                    [
                        'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
                        'customer_lifecycle_status' => SuchakCustomerContext::STATUS_CANDIDATE_IDENTIFIED,
                        'payer_name' => $validated['candidate_name'],
                    ],
                    $request->ip(),
                    $request->userAgent(),
                );

                return [$member, $profile, $representation];
            });
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => ['candidate_name' => [$exception->getMessage()]],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Manual profile created for {$member->name}.",
            'data' => [
                'outcome' => 'created',
                'member_id' => $member->id,
                'profile_id' => $profile->id,
                'representation_id' => $representation->id,
                'candidate_name' => $member->name,
            ],
        ], 201);
    }

    /**
     * CONSENT-FIRST LINKING (PO rule 2026-07-26).
     *
     * Nobody's profile may be attached to a Suchak before that person agrees.
     * So this endpoint NEVER links. It creates:
     *   1. the consent request, and
     *   2. the minimum pending record needed to route the decision back — the
     *      SuchakProfileRepresentation row in its CLAIM state
     *      (matched_existing_profile + no valid consent).
     *
     * That claim row is invisible and inert: excluded from every customer feed
     * (scopeExcludingPendingConsentClaims), unreadable and unwritable
     * (suchakMayReadProfile / suchakMayEditProfile), and never counted as an
     * active representation. It becomes a real link only inside
     * SuchakConsentService::recordPublicConsentDecision() on ACCEPTED.
     *
     * No customer context is created here — a person who has not consented is
     * not a customer. It is created at acceptance time (and, as before, lazily
     * by SuchakPaymentSetupApiController), never twice.
     *
     */
    private function handleExistingMobileProfile(
        Request $request,
        string $mobile,
        User $existingMember,
        SuchakAccount $account,
        SuchakRepresentationService $representationService,
        SuchakConsentService $consentService,
    ): JsonResponse {
        /** @var MatrimonyProfile|null $existingProfile */
        $existingProfile = $existingMember->matrimonyProfile;

        if ($existingProfile === null) {
            return response()->json([
                'success' => false,
                'message' => 'This mobile number belongs to an existing account, but no candidate profile is available to link. Use another number or contact admin for duplicate review.',
                'errors' => [
                    'candidate_mobile' => [
                        'This mobile number belongs to an existing account, but no candidate profile is available to link. Use another number or contact admin for duplicate review.',
                    ],
                ],
            ], 422);
        }

        // A person actively represented by ANOTHER Suchak may not be claimed at
        // all — no competing consent request is ever created for them.
        $blocked = $this->otherSuchakRefusal($existingProfile, $account);
        if ($blocked !== null) {
            return $blocked;
        }

        if (! $request->boolean('use_existing_profile')) {
            return response()->json([
                'success' => false,
                'message' => __('suchak.manual_profile.existing_profile_consent_required'),
                'data' => [
                    'outcome' => 'existing_profile_confirmation_required',
                    'mobile_mask' => $this->maskMobile($mobile),
                    'profile_id' => $existingProfile->id,
                    // Confirming does NOT link — it only asks for consent.
                    'requires_consent_first' => true,
                ],
            ], 409);
        }

        try {
            [$representation, $consentResult] = DB::transaction(function () use (
                $account,
                $request,
                $existingProfile,
                $mobile,
                $representationService,
                $consentService
            ): array {
                $actor = $request->user();
                assert($actor instanceof User);

                $representation = $this->existingOrNewMatchedRepresentation(
                    $account,
                    $actor,
                    $existingProfile,
                    $representationService,
                    $request->ip(),
                    $request->userAgent(),
                );

                $consentResult = $this->existingOrNewConsentRequest(
                    $representation,
                    $actor,
                    $mobile,
                    $consentService,
                    $request->ip(),
                    $request->userAgent(),
                );

                return [$representation, $consentResult];
            });
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => ['candidate_mobile' => [$exception->getMessage()]],
            ], 422);
        }

        /** @var SuchakConsent $consent */
        $consent = $consentResult['consent'];
        $forwardMessage = (string) ($consentResult['message'] ?? '');

        return response()->json([
            'success' => true,
            'message' => __('suchak.manual_profile.consent_requested'),
            'data' => [
                // Was 'linked_existing' before consent-first linking. Nothing is
                // linked here any more — the app must show "consent requested".
                'outcome' => 'consent_requested',
                'linked' => false,
                'profile_id' => $existingProfile->id,
                // The pending CLAIM, not a customer. It is not listed, readable
                // or writable; keep it only to resend/track this consent.
                'representation_id' => $representation->id,
                'pending_claim' => true,
                // Consent hand-off (2026-07-26): the app must be able to deep-link
                // straight into the SAME consent sheet the customer detail screen
                // uses, so these mirror SuchakConsentsApiController::store() field
                // for field. No second consent mechanism.
                'consent_id' => (int) $consent->id,
                'consent_status' => $consent->consent_status,
                'consent_method' => $consent->consent_method,
                'consent_url' => $consentResult['consent_url'],
                'forward_message' => $forwardMessage !== '' ? $forwardMessage : null,
                'whatsapp_url' => $forwardMessage !== ''
                    ? $consentService->whatsappShareUrl($consent, $forwardMessage)
                    : null,
                // false when an OPEN consent request already existed: its raw
                // token is unrecoverable by design, so the app must call
                // POST /suchak/consents/{consent}/resend to get a fresh link.
                'consent_link_available' => $consentResult['consent_url'] !== null,
                'consent_reused' => $consentResult['reused'],
            ],
        ]);
    }

    private function existingOrNewMatchedRepresentation(
        SuchakAccount $account,
        User $actor,
        MatrimonyProfile $profile,
        SuchakRepresentationService $representationService,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakProfileRepresentation {
        $existing = SuchakProfileRepresentation::query()
            ->where('suchak_account_id', $account->id)
            ->where('matrimony_profile_id', $profile->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $representationService->createPendingMatchedExistingProfile(
            $account,
            $actor,
            $profile,
            $ipAddress,
            $userAgent,
        );
    }

    /**
     * @return array{consent: SuchakConsent, consent_url: ?string, message: ?string, reused: bool}
     */
    private function existingOrNewConsentRequest(
        SuchakProfileRepresentation $representation,
        User $actor,
        string $mobile,
        SuchakConsentService $consentService,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        $existing = SuchakConsent::query()
            ->where('representation_id', $representation->id)
            ->whereIn('consent_status', SuchakConsent::OPEN_STATUSES)
            ->latest('id')
            ->first();

        if ($existing !== null) {
            // Raw token is hashed at rest — a reused request cannot hand back a
            // URL. The app resends instead of minting a parallel consent.
            return [
                'consent' => $existing,
                'consent_url' => null,
                'message' => null,
                'reused' => true,
            ];
        }

        $result = $consentService->createSuchakRelayedLinkConsent(
            $representation,
            $actor,
            [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'intended_mobile' => $mobile,
            ],
            $ipAddress,
            $userAgent,
        );

        return [
            'consent' => $result['consent'],
            'consent_url' => $result['consent_url'] ?? null,
            'message' => $result['message'] ?? null,
            'reused' => false,
        ];
    }

    /**
     * One customer, one Suchak. If a DIFFERENT Suchak already holds an active,
     * consented representation on this profile, refuse outright — never create a
     * competing claim, never send a rival consent request.
     *
     * "Actively represented" reuses scopeWithValidConsent() and the name is
     * revealed only through scopePubliclyRoutable(), exactly the two predicates
     * SuchakCandidateDuplicateCheckService uses for owner_type / owner_suchak_name.
     * A Suchak who is not publicly discoverable stays anonymous here too.
     */
    private function otherSuchakRefusal(MatrimonyProfile $profile, SuchakAccount $account): ?JsonResponse
    {
        /** @var SuchakProfileRepresentation|null $other */
        $other = SuchakProfileRepresentation::query()
            ->withValidConsent()
            ->where('matrimony_profile_id', $profile->id)
            ->where('suchak_account_id', '!=', $account->id)
            ->with('suchakAccount')
            ->first();

        if ($other === null) {
            return null;
        }

        $isPublic = SuchakProfileRepresentation::query()
            ->publiclyRoutable()
            ->whereKey($other->id)
            ->exists();

        $ownerName = $isPublic
            ? (trim((string) ($other->suchakAccount?->suchak_name ?: '')) ?: null)
            : null;

        $message = $ownerName !== null
            ? __('suchak.manual_profile.represented_by_other_suchak_named', ['suchak' => $ownerName])
            : __('suchak.manual_profile.represented_by_other_suchak');

        return response()->json([
            'success' => false,
            'error_code' => 'represented_by_other_suchak',
            'message' => $message,
            'errors' => ['candidate_mobile' => [$message]],
            'data' => [
                'outcome' => 'represented_by_other_suchak',
                'profile_id' => (int) $profile->id,
                'owner_type' => SuchakCandidateDuplicateCheckService::OWNER_OTHER_SUCHAK,
                'owner_suchak_name' => $ownerName,
                'can_link_existing' => false,
            ],
        ], 409);
    }

    private function maskMobile(string $mobile): string
    {
        return str_repeat('*', max(0, strlen($mobile) - 4)).substr($mobile, -4);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, MasterGender>
     */
    private function activeGenders()
    {
        return MasterGender::query()
            ->where('is_active', true)
            ->whereIn('key', ['male', 'female'])
            ->orderByRaw("CASE WHEN `key` = 'male' THEN 1 ELSE 2 END")
            ->get(['id', 'key', 'label']);
    }

    /**
     * @return array<string, string>
     */
    /**
     * Who the entered mobile belongs to, as their relation to the candidate —
     * this is the person consent will be requested from.
     *
     * Labels come from the existing translation files, which already hold both
     * languages, instead of English being hardcoded here. Only "self" needs its
     * own wording: the shared key reads "Myself", which is right for a member
     * registering themselves and wrong for a Suchak filling in someone else.
     *
     * @return array<string, string>
     */
    private function registeringForOptions(): array
    {
        return [
            'self' => __('suchak.manual_profile.consent_relation_self'),
            'parent_guardian' => __('onboarding.registering_for_parent_guardian'),
            'sibling' => __('onboarding.registering_for_sibling'),
            'relative' => __('onboarding.registering_for_relative'),
            'friend' => __('onboarding.registering_for_friend'),
            'other' => __('onboarding.registering_for_other'),
        ];
    }
}
