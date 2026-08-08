<?php

namespace App\Services\Api;

use App\Models\MatrimonyProfile;
use App\Models\MobileOnboardingDraft;
use App\Models\User;
use App\Services\Onboarding\ActivationChecklistService;
use App\Services\ProfileFieldConfigurationService;
use App\Services\Onboarding\MobileOnboardingDraftService;
use App\Services\Onboarding\RegistrationPartnerPreferenceService;

class MobileOnboardingStatusService
{
    private const EXISTING_PROFILE_ACCOUNT_SHELL_STEPS = [
        'profile_for_whom',
        'basic_info',
    ];

    private const ACCOUNT_SHELL_COMPLETED_STEPS = [
        'account',
        'profile_for_whom',
    ];

    public function __construct(
        private readonly MobileOnboardingDraftService $draftService,
        private readonly ActivationChecklistService $checklistService,
        private readonly MobileOtpService $otpService,
        private readonly RegistrationPartnerPreferenceService $preferenceService,
    ) {}

    public function build(User $user, ?MobileOnboardingDraft $draft = null): array
    {
        $draft ??= $this->draftService->findOrCreateForUser($user);
        $profile = $this->profileFor($user, $draft);

        $profileSummary = $this->checklistService->profileSummary($profile, $user, $draft);
        $items = $this->checklistService->items($user, $profile, $draft);
        $nextStep = $this->nextStep($draft, $profile);

        // Everything keeping this member out of search, ranked, so the app can
        // name the one to do now, list what follows, and draw a bar — instead of
        // handing over a ten-row checklist and a generic edit page. The already
        // built $items is passed down so the checklist is computed once.
        $blockers = $this->checklistService->blockerQueue($user, $profile, $draft, $items);
        $blockers = array_map(function (array $blocker) use ($profile): array {
            $blocker['waiting_since'] = $this->checklistService->waitingSince($profile, $blocker['key']);

            return $blocker;
        }, $blockers);

        $topBlocker = $blockers[0] ?? null;
        $remainingBlockers = array_slice($blockers, 1);

        return [
            'success' => true,
            'account' => $this->accountPayload($user),
            'draft' => $this->draftService->draftPayload($draft),
            'profile' => $profileSummary,
            'has_profile' => $profile instanceof MatrimonyProfile,
            'has_existing_profile' => $profile instanceof MatrimonyProfile,
            'profile_status' => $this->checklistService->profileStatus($profile),
            'is_searchable' => $this->checklistService->isSearchable($user, $profile),
            'top_blocker' => $topBlocker,
            'remaining_blockers' => $remainingBlockers,
            'activation_progress' => $this->checklistService->activationProgress($user, $profile, $draft, $items),
            // Which fields the profile cannot go live without. The app was
            // labelling one of them "(Optional)" in its own edit form, so a
            // member could reasonably skip it and never learn that skipping it
            // is why nobody can find them. Admins change this set in the field
            // configuration; nothing about it belongs compiled into an app.
            'mandatory_fields' => array_values(array_intersect(
                ProfileFieldConfigurationService::getMandatoryFieldKeys(),
                ProfileFieldConfigurationService::getEnabledFieldKeys()
            )),
            'next_step' => $nextStep,
            'pending_location' => $this->checklistService->pendingLocationPayload($draft),
            'account_state' => $this->otpService->accountStateFor($user),
            'activation_checklist' => $items,
            'preferences' => $this->preferenceService->statusForProfile($profile),
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
        if ($profile instanceof MatrimonyProfile && $this->shouldResumeExistingProfileAtActivation($draft)) {
            return 'activation';
        }

        return $draft->current_step ?: 'profile_for_whom';
    }

    private function shouldResumeExistingProfileAtActivation(MobileOnboardingDraft $draft): bool
    {
        $currentStep = trim((string) ($draft->current_step ?? ''));
        if ($currentStep === '') {
            return true;
        }

        if (! in_array($currentStep, self::EXISTING_PROFILE_ACCOUNT_SHELL_STEPS, true)) {
            return false;
        }

        $completedSteps = array_values(array_filter(
            array_map('strval', $draft->completed_steps ?? []),
            fn (string $step): bool => trim($step) !== ''
        ));

        return empty(array_diff($completedSteps, self::ACCOUNT_SHELL_COMPLETED_STEPS));
    }
}
