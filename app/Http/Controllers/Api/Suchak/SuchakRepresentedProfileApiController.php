<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Api\MatrimonyProfileApiController;
use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAccessService;
use App\Services\MutationService;
use App\Services\Onboarding\MobileOnboardingDraftService;
use App\Services\Onboarding\MobileProfileStepSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Thin mobile adapter: Suchak fills a represented candidate via the same
 * MobileProfileStepSnapshotService + MutationService path as member onboarding,
 * and full profile edit via MatrimonyProfileApiController write helpers.
 * No new profile business rules — representation ownership gate only.
 */
class SuchakRepresentedProfileApiController extends Controller
{
    public function __construct(
        private readonly MobileOnboardingDraftService $draftService,
        private readonly MobileProfileStepSnapshotService $snapshotService,
        private readonly MatrimonyProfileApiController $matrimonyProfileApi,
    ) {}

    /**
     * Full profile prefill for native Suchak edit (member governance payload).
     */
    public function show(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakAccessService $accessService,
    ): JsonResponse {
        $context = $this->authorizedContext($request, $representation, $accessService);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$account, $profile] = $context;

        $response = $this->matrimonyProfileApi->showForProfile($profile, $request->user());
        $payload = $response->getData(true);
        if (! is_array($payload)) {
            $payload = [];
        }
        $payload['data'] = [
            'representation_id' => (int) $representation->id,
            'profile_id' => (int) $profile->id,
            'suchak_account_id' => (int) $account->id,
        ];

        return response()->json($payload, $response->status());
    }

    /**
     * Full profile update for native Suchak edit (same snapshot engine as member PUT).
     */
    public function update(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakAccessService $accessService,
    ): JsonResponse {
        $context = $this->authorizedContext($request, $representation, $accessService);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$account, $profile] = $context;

        /** @var User $actor */
        $actor = $request->user();
        $response = $this->matrimonyProfileApi->updateForProfile($request, $profile, $actor);
        $payload = $response->getData(true);
        if (! is_array($payload)) {
            $payload = [];
        }
        $payload['data'] = [
            'representation_id' => (int) $representation->id,
            'profile_id' => (int) $profile->id,
            'suchak_account_id' => (int) $account->id,
        ];

        return response()->json($payload, $response->status());
    }

    public function saveStep(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakAccessService $accessService,
        MutationService $mutationService,
    ): JsonResponse {
        $context = $this->authorizedContext($request, $representation, $accessService);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$account, $profile, $member] = $context;

        $validated = $request->validate([
            'step' => ['required', 'string', Rule::in(MobileProfileStepSnapshotService::PROFILE_STEPS)],
            'data' => ['required', 'array'],
        ]);

        $step = (string) $validated['step'];
        if ($step === 'profile_for_whom') {
            return response()->json([
                'success' => false,
                'message' => 'profile_for_whom is set at manual profile create. Use basic_info and later steps.',
            ], 422);
        }

        try {
            $data = $this->snapshotService->validatedData($step, $validated['data'], $member);
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $snapshot = $this->snapshotService->buildSnapshot($step, $data, $member, $profile);
        if ($this->snapshotHasWritableData($snapshot)) {
            $mutationService->applyManualSnapshot(
                $profile,
                $snapshot,
                (int) $request->user()->id,
                'manual',
            );
            $profile = $profile->fresh() ?? $profile;
        }

        $draft = $this->draftService->saveStep($member, $step, $data);
        $this->draftService->linkProfile($member, $profile->fresh() ?? $profile);

        return response()->json([
            'success' => true,
            'message' => 'Profile step saved.',
            'data' => [
                'representation_id' => (int) $representation->id,
                'profile_id' => (int) $profile->id,
                'member_id' => (int) $member->id,
                'suchak_account_id' => (int) $account->id,
                'step' => $step,
                'draft_id' => (int) $draft->id,
            ],
        ]);
    }

    public function uploadPhoto(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakAccessService $accessService,
    ): JsonResponse {
        $context = $this->authorizedContext($request, $representation, $accessService);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [, $profile, $member] = $context;

        $request->validate([
            'profile_photo' => 'required|image|max:2048',
        ]);

        if (Schema::hasColumn('users', 'photo_uploads_suspended') && (bool) $member->photo_uploads_suspended) {
            return response()->json([
                'success' => false,
                'message' => 'Photo uploads have been suspended for this candidate account.',
            ], 403);
        }

        $file = $request->file('profile_photo');
        $pending = app(\App\Services\Image\ImageProcessingService::class)
            ->enqueueProfilePhotoProcessing($file, (int) $profile->id);

        $snapshot = [
            'core' => [
                'profile_photo' => $pending,
            ],
        ];
        app(MutationService::class)->applyManualSnapshot(
            $profile,
            $snapshot,
            (int) $request->user()->id,
            'manual',
        );
        app(\App\Services\Image\ProfilePhotoPendingStateService::class)
            ->applyPendingReviewState($profile);

        return response()->json([
            'success' => true,
            'message' => 'Profile photo uploaded. Processing will complete shortly.',
            'data' => [
                'representation_id' => (int) $representation->id,
                'profile_id' => (int) $profile->id,
                'profile_photo' => $pending,
                'status' => 'processing',
            ],
        ]);
    }

    /**
     * @return array{0: SuchakAccount, 1: MatrimonyProfile, 2: User}|JsonResponse
     */
    private function authorizedContext(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakAccessService $accessService,
    ): array|JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        /** @var SuchakAccount|null $account */
        $account = $user->suchakAccount;
        if ($account === null || ! $accessService->canOwnerPrepareCustomers($account, $user)) {
            return response()->json([
                'success' => false,
                'message' => 'Only active Suchak accounts can edit represented candidate profiles.',
            ], 403);
        }

        if ((int) $representation->suchak_account_id !== (int) $account->id) {
            return response()->json([
                'success' => false,
                'message' => 'Representation not found for this Suchak account.',
            ], 404);
        }

        $profile = MatrimonyProfile::query()->find($representation->matrimony_profile_id);
        if (! $profile instanceof MatrimonyProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Matrimony profile not found for this representation.',
            ], 404);
        }

        $member = User::query()->find($profile->user_id);
        if (! $member instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Candidate member account not found.',
            ], 404);
        }

        return [$account, $profile, $member];
    }

    private function snapshotHasWritableData(array $snapshot): bool
    {
        foreach ($snapshot as $value) {
            if (is_array($value) && $value !== []) {
                return true;
            }
            if (! is_array($value) && $value !== null && $value !== '') {
                return true;
            }
        }

        return isset($snapshot['core']) && is_array($snapshot['core']) && $snapshot['core'] !== [];
    }
}
