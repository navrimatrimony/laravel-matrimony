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

        return response()->json([
            'success' => true,
            'message' => 'Manual profile form options loaded.',
            'data' => [
                'genders' => $this->activeGenders()->map(static fn (MasterGender $g): array => [
                    'id' => $g->id,
                    'key' => $g->key,
                    'label' => $g->label,
                ])->values()->all(),
                'registering_for_options' => $this->registeringForOptions(),
            ],
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
                $validated,
                $mobile,
                $existingMember,
                $account,
                $representationService,
                $customerLifecycleService,
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
     * @param  array<string, mixed>  $validated
     */
    private function handleExistingMobileProfile(
        Request $request,
        array $validated,
        string $mobile,
        User $existingMember,
        SuchakAccount $account,
        SuchakRepresentationService $representationService,
        SuchakCustomerLifecycleService $customerLifecycleService,
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

        if (! $request->boolean('use_existing_profile')) {
            return response()->json([
                'success' => false,
                'message' => 'Existing profile found for this mobile. Confirm to link without creating a duplicate.',
                'data' => [
                    'outcome' => 'existing_profile_confirmation_required',
                    'mobile_mask' => $this->maskMobile($mobile),
                    'profile_id' => $existingProfile->id,
                ],
            ], 409);
        }

        try {
            [$representation] = DB::transaction(function () use (
                $account,
                $request,
                $existingProfile,
                $validated,
                $mobile,
                $representationService,
                $customerLifecycleService,
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

                $consent = $this->existingOrNewConsentRequest(
                    $representation,
                    $actor,
                    $mobile,
                    $consentService,
                    $request->ip(),
                    $request->userAgent(),
                );

                $this->existingOrNewCustomerContext(
                    $account,
                    $actor,
                    $representation,
                    $consent,
                    (string) $validated['candidate_name'],
                    $customerLifecycleService,
                    $request->ip(),
                    $request->userAgent(),
                );

                return [$representation];
            });
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => ['candidate_mobile' => [$exception->getMessage()]],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Existing profile linked. Representation and consent request are ready.',
            'data' => [
                'outcome' => 'linked_existing',
                'profile_id' => $existingProfile->id,
                'representation_id' => $representation->id,
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

    private function existingOrNewConsentRequest(
        SuchakProfileRepresentation $representation,
        User $actor,
        string $mobile,
        SuchakConsentService $consentService,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakConsent {
        $existing = SuchakConsent::query()
            ->where('representation_id', $representation->id)
            ->whereIn('consent_status', SuchakConsent::OPEN_STATUSES)
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
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

        return $result['consent'];
    }

    private function existingOrNewCustomerContext(
        SuchakAccount $account,
        User $actor,
        SuchakProfileRepresentation $representation,
        SuchakConsent $consent,
        string $payerName,
        SuchakCustomerLifecycleService $customerLifecycleService,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakCustomerContext {
        $existing = SuchakCustomerContext::query()
            ->where('representation_id', $representation->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $customerLifecycleService->createForRepresentation(
            $account,
            $actor,
            $representation,
            [
                'source_type' => SuchakCustomerContext::SOURCE_TYPE_EXISTING_PROFILE_MATCH,
                'customer_lifecycle_status' => SuchakCustomerContext::STATUS_CONSENT_PENDING,
                'payer_name' => $payerName,
                'consent_id' => $consent->id,
            ],
            $ipAddress,
            $userAgent,
        );
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
    private function registeringForOptions(): array
    {
        return [
            'self' => 'Candidate self',
            'parent_guardian' => 'Parent / guardian',
            'sibling' => 'Sibling',
            'relative' => 'Relative',
            'friend' => 'Friend',
            'other' => 'Other',
        ];
    }
}
