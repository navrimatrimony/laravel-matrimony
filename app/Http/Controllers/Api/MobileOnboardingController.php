<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Api\MobileOnboardingStatusService;
use App\Services\MutationService;
use App\Services\Onboarding\ActivationChecklistService;
use App\Services\Onboarding\MobileOnboardingDraftService;
use App\Services\Onboarding\MobileProfileStepSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MobileOnboardingController extends Controller
{
    public function __construct(
        private readonly MobileOnboardingDraftService $draftService,
        private readonly MobileOnboardingStatusService $statusService,
        private readonly ActivationChecklistService $checklistService,
        private readonly MobileProfileStepSnapshotService $snapshotService,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'profile_for_whom' => ['required', 'string', Rule::in(MobileOnboardingDraftService::PROFILE_FOR_WHOM_VALUES)],
            'gender_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_genders', 'id')->where('is_active', true)],
            'mother_tongue_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_mother_tongues', 'id')->where('is_active', true)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $profileForWhom = (string) $validated['profile_for_whom'];
        $this->persistRegisteringFor($user, $profileForWhom);

        $draftData = [
            'profile_for_whom' => $profileForWhom,
        ];
        if (array_key_exists('gender_id', $validated)) {
            $draftData['gender_id'] = $validated['gender_id'];
        }
        if (array_key_exists('mother_tongue_id', $validated)) {
            $draftData['mother_tongue_id'] = $validated['mother_tongue_id'];
        }

        $draft = $this->draftService->saveStep($user->fresh(), 'profile_for_whom', $draftData);

        $payload = $this->statusService->build($user->fresh(), $draft);

        return response()->json([
            'success' => true,
            'draft_id' => (int) $draft->id,
            'profile_id' => $draft->matrimony_profile_id !== null ? (int) $draft->matrimony_profile_id : null,
            'has_existing_profile' => (bool) $payload['has_existing_profile'],
            'last_completed_step' => $draft->last_completed_step,
            'current_step' => $draft->current_step,
            'next_step' => $payload['next_step'],
            'account_state' => $payload['account_state'],
            'activation_checklist' => $payload['activation_checklist'],
            'profile_status' => $payload['profile_status'],
            'is_searchable' => $payload['is_searchable'],
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->statusService->build($request->user()));
    }

    public function draft(Request $request): JsonResponse
    {
        return response()->json($this->statusService->draftResponse($request->user()));
    }

    public function saveDraftStep(Request $request, string $step): JsonResponse
    {
        $validated = $request->validate([
            'data' => ['required', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $data = $validated['data'];

        if (in_array($step, MobileProfileStepSnapshotService::PROFILE_STEPS, true)) {
            $data = $this->carryProfileForWhomBasicsIntoBasicInfo($user, $step, $data);
            $data = $this->snapshotService->validatedData($step, $data, $user);
            if ($step === 'profile_for_whom' && isset($data['profile_for_whom'])) {
                $this->persistRegisteringFor($user, (string) $data['profile_for_whom']);
                $user = $user->fresh();
            }
        } elseif ($step === 'photo') {
            $this->validatePhotoDraftData($data);
        } else {
            abort(404);
        }

        $draft = $this->draftService->saveStep($user, $step, $data);
        $payload = $this->statusService->build($user->fresh(), $draft);

        return response()->json([
            'success' => true,
            'draft' => $payload['draft'],
            'last_completed_step' => $draft->last_completed_step,
            'current_step' => $draft->current_step,
            'next_step' => $payload['next_step'],
            'activation_checklist' => $payload['activation_checklist'],
        ]);
    }

    public function saveProfileStep(Request $request, MutationService $mutationService): JsonResponse
    {
        $validated = $request->validate([
            'step' => ['required', 'string', Rule::in(MobileProfileStepSnapshotService::PROFILE_STEPS)],
            'data' => ['required', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $step = (string) $validated['step'];
        $data = $this->carryProfileForWhomBasicsIntoBasicInfo($user, $step, $validated['data']);
        $data = $this->snapshotService->validatedData($step, $data, $user);

        if ($step === 'profile_for_whom') {
            $this->persistRegisteringFor($user, (string) $data['profile_for_whom']);
            $user = $user->fresh();
            $draft = $this->draftService->saveStep($user, $step, $data);
            $payload = $this->statusService->build($user, $draft);

            return response()->json([
                'success' => true,
                'profile' => $payload['profile'],
                'draft' => $payload['draft'],
                'activation_checklist' => $payload['activation_checklist'],
                'next_step' => $payload['next_step'],
            ]);
        }

        $profile = $this->draftService->existingProfileForUser($user);
        if (! $profile instanceof MatrimonyProfile) {
            $profile = $mutationService->createDraftProfileForUser($user);
        }

        $snapshot = $this->snapshotService->buildSnapshot($step, $data, $user, $profile);
        if ($this->snapshotHasWritableData($snapshot)) {
            $mutationService->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');
        }

        $draft = $this->draftService->saveStep($user, $step, $data);
        $this->draftService->linkProfile($user, $profile->fresh());

        $payload = $this->statusService->build($user->fresh(), $draft->fresh());

        return response()->json([
            'success' => true,
            'profile' => $payload['profile'],
            'draft' => $payload['draft'],
            'activation_checklist' => $payload['activation_checklist'],
            'next_step' => $payload['next_step'],
        ]);
    }

    public function activationChecklist(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $draft = $this->draftService->findOrCreateForUser($user);
        $profile = $draft->matrimony_profile_id
            ? MatrimonyProfile::query()->where('user_id', $user->id)->whereKey($draft->matrimony_profile_id)->first()
            : $this->draftService->existingProfileForUser($user);

        return response()->json([
            'success' => true,
            'profile_status' => $this->checklistService->profileStatus($profile),
            'is_searchable' => $this->checklistService->isSearchable($user, $profile),
            'pending_location' => $this->checklistService->pendingLocationPayload($draft),
            'items' => $this->checklistService->items($user, $profile, $draft),
        ]);
    }

    private function persistRegisteringFor(User $user, string $profileForWhom): void
    {
        if (! Schema::hasColumn('users', 'registering_for')) {
            return;
        }

        $user->forceFill([
            'registering_for' => $this->draftService->legacyRegisteringFor($profileForWhom),
        ])->save();
    }

    private function carryProfileForWhomBasicsIntoBasicInfo(User $user, string $step, array $data): array
    {
        if ($step !== 'basic_info') {
            return $data;
        }

        $existing = $data['mother_tongue_id'] ?? null;
        if ($existing !== null && $existing !== '') {
            return $data;
        }

        $draft = $this->draftService->getForUser($user);
        $motherTongueId = $draft instanceof \App\Models\MobileOnboardingDraft
            ? data_get($draft->draft_data, 'profile_for_whom.mother_tongue_id')
            : null;

        if ($motherTongueId === null || $motherTongueId === '') {
            return $data;
        }

        $data['mother_tongue_id'] = $motherTongueId;

        return $data;
    }

    private function snapshotHasWritableData(array $snapshot): bool
    {
        foreach ($snapshot as $value) {
            if (is_array($value) && $value !== []) {
                if (! array_key_exists('core', $snapshot) || $value !== []) {
                    return true;
                }
            }
            if (! is_array($value) && $value !== null && $value !== '') {
                return true;
            }
        }

        return isset($snapshot['core']) && is_array($snapshot['core']) && $snapshot['core'] !== [];
    }

    private function validatePhotoDraftData(array $data): void
    {
        $allowed = array_flip(['photo_uploaded', 'photo_approved', 'photo_status', 'profile_photo_id']);
        foreach (array_keys($data) as $key) {
            if (! is_string($key) || ! isset($allowed[$key])) {
                throw ValidationException::withMessages([
                    (string) $key => 'This field is not supported for the photo draft step.',
                ]);
            }
        }
    }
}
