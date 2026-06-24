<?php

namespace App\Services\Api;

use App\Models\MatrimonyProfile;
use App\Models\MobileOnboardingDraft;
use App\Models\User;
use App\Services\Onboarding\ActivationChecklistService;
use App\Services\Onboarding\MobileOnboardingDraftService;

class MobileOnboardingStatusService
{
    public function __construct(
        private readonly MobileOnboardingDraftService $draftService,
        private readonly ActivationChecklistService $checklistService,
        private readonly MobileOtpService $otpService,
    ) {}

    public function build(User $user, ?MobileOnboardingDraft $draft = null): array
    {
        $draft ??= $this->draftService->findOrCreateForUser($user);
        $profile = $this->profileFor($user, $draft);

        $profileSummary = $this->checklistService->profileSummary($profile, $user);
        $items = $this->checklistService->items($user, $profile);
        $nextStep = $this->nextStep($draft, $profile);

        return [
            'success' => true,
            'account' => $this->accountPayload($user),
            'draft' => $this->draftService->draftPayload($draft),
            'profile' => $profileSummary,
            'has_profile' => $profile instanceof MatrimonyProfile,
            'has_existing_profile' => $profile instanceof MatrimonyProfile,
            'profile_status' => $this->checklistService->profileStatus($profile),
            'is_searchable' => $this->checklistService->isSearchable($user, $profile),
            'next_step' => $nextStep,
            'account_state' => $this->otpService->accountStateFor($user),
            'activation_checklist' => $items,
        ];
    }

    public function draftResponse(User $user, ?MobileOnboardingDraft $draft = null): array
    {
        $payload = $this->build($user, $draft);

        return [
            'success' => true,
            'draft' => $payload['draft'],
            'profile' => $payload['profile'],
            'activation_checklist' => $payload['activation_checklist'],
        ];
    }

    public function accountPayload(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'creator_name' => $user->name,
            'mobile' => $user->mobile,
            'mobile_verified_at' => optional($user->mobile_verified_at)?->toISOString(),
            'mobile_verified' => $user->mobile_verified_at !== null,
            'creator_name_present' => trim((string) ($user->name ?? '')) !== '',
            'email' => $user->email,
            'email_present' => trim((string) ($user->email ?? '')) !== '',
            'email_verified_at' => optional($user->email_verified_at)?->toISOString(),
            'email_verified' => $user->email_verified_at !== null,
            'preferred_locale' => $user->preferred_locale,
        ];
    }

    private function profileFor(User $user, MobileOnboardingDraft $draft): ?MatrimonyProfile
    {
        if ($draft->matrimony_profile_id) {
            $profile = MatrimonyProfile::query()->find($draft->matrimony_profile_id);
            if ($profile instanceof MatrimonyProfile && (int) $profile->user_id === (int) $user->id) {
                return $profile;
            }
        }

        return $this->draftService->existingProfileForUser($user);
    }

    private function nextStep(MobileOnboardingDraft $draft, ?MatrimonyProfile $profile): string
    {
        if ($profile instanceof MatrimonyProfile && ! $draft->current_step) {
            return 'activation';
        }

        return $draft->current_step ?: 'profile_for_whom';
    }
}
