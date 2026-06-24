<?php

namespace App\Services\Onboarding;

use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Models\MobileOnboardingDraft;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class MobileOnboardingDraftService
{
    public const STEPS = [
        'account',
        'profile_for_whom',
        'basic_info',
        'religion_caste',
        'location',
        'education',
        'career',
        'lifestyle',
        'family',
        'photo',
        'activation',
    ];

    public const DRAFT_SAVE_STEPS = [
        'profile_for_whom',
        'basic_info',
        'religion_caste',
        'location',
        'education',
        'career',
        'lifestyle',
        'family',
        'photo',
    ];

    public const PROFILE_FOR_WHOM_VALUES = [
        'self',
        'son',
        'daughter',
        'brother',
        'sister',
        'relative',
        'friend',
    ];

    public function getForUser(User $user): ?MobileOnboardingDraft
    {
        return MobileOnboardingDraft::query()
            ->where('user_id', $user->id)
            ->first();
    }

    public function findOrCreateForUser(User $user): MobileOnboardingDraft
    {
        return DB::transaction(function () use ($user): MobileOnboardingDraft {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $profile = $this->existingProfileForUser($user, true);

            $draft = MobileOnboardingDraft::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $draft) {
                $draft = new MobileOnboardingDraft([
                    'user_id' => $user->id,
                    'current_step' => 'profile_for_whom',
                    'completed_steps' => [],
                    'draft_data' => [],
                    'started_at' => now(),
                ]);
            }

            if ($draft->started_at === null) {
                $draft->started_at = now();
            }

            if ($profile instanceof MatrimonyProfile) {
                $draft->matrimony_profile_id = $profile->id;
            }

            $draft->completed_steps = $this->accountAwareCompletedSteps($user, $draft->completed_steps ?? []);
            if (! $draft->current_step) {
                $draft->current_step = $this->nextStepAfter($draft->last_completed_step) ?? 'profile_for_whom';
            }
            $draft->save();

            return $draft->fresh();
        });
    }

    public function saveStep(User $user, string $step, array $data): MobileOnboardingDraft
    {
        $this->assertDraftStep($step);

        return DB::transaction(function () use ($user, $step, $data): MobileOnboardingDraft {
            $draft = $this->findOrCreateForUser($user);
            $draft = MobileOnboardingDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            $allData = is_array($draft->draft_data) ? $draft->draft_data : [];
            $existingStepData = isset($allData[$step]) && is_array($allData[$step]) ? $allData[$step] : [];
            $mergedStepData = array_replace($existingStepData, $this->normalizeStepData($data));

            $allData[$step] = $this->applyDependentClears($step, $existingStepData, $mergedStepData);

            $draft->draft_data = $allData;
            $draft->completed_steps = $this->stepsWithCompleted($user, $draft->completed_steps ?? [], $step);
            $draft->last_completed_step = $step;
            $draft->current_step = $this->nextStepAfter($step) ?? 'activation';
            $draft->save();

            return $draft->fresh();
        });
    }

    public function markStepComplete(User $user, string $step): MobileOnboardingDraft
    {
        $this->assertKnownStep($step);

        return DB::transaction(function () use ($user, $step): MobileOnboardingDraft {
            $draft = $this->findOrCreateForUser($user);
            $draft = MobileOnboardingDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $draft->completed_steps = $this->stepsWithCompleted($user, $draft->completed_steps ?? [], $step);
            $draft->last_completed_step = $step;
            $draft->current_step = $this->nextStepAfter($step) ?? 'activation';
            $draft->save();

            return $draft->fresh();
        });
    }

    public function setCurrentStep(User $user, string $step): MobileOnboardingDraft
    {
        $this->assertKnownStep($step);
        $draft = $this->findOrCreateForUser($user);
        $draft->forceFill(['current_step' => $step])->save();

        return $draft->fresh();
    }

    public function linkProfile(User $user, MatrimonyProfile $profile): MobileOnboardingDraft
    {
        if ((int) $profile->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'profile' => 'Profile does not belong to this account.',
            ]);
        }

        $draft = $this->findOrCreateForUser($user);
        $draft->forceFill(['matrimony_profile_id' => $profile->id])->save();

        return $draft->fresh();
    }

    public function existingProfileForUser(User $user, bool $lock = false): ?MatrimonyProfile
    {
        $query = MatrimonyProfile::query()->where('user_id', $user->id)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function hasExistingProfile(User $user): bool
    {
        return MatrimonyProfile::query()->where('user_id', $user->id)->exists();
    }

    public function nextStepAfter(?string $step): ?string
    {
        if ($step === null || $step === '') {
            return 'profile_for_whom';
        }

        $index = array_search($step, self::STEPS, true);
        if ($index === false) {
            return 'profile_for_whom';
        }

        return self::STEPS[$index + 1] ?? null;
    }

    public function legacyRegisteringFor(string $profileForWhom): string
    {
        return match ($profileForWhom) {
            'self' => 'self',
            'son', 'daughter' => 'parent_guardian',
            'brother', 'sister' => 'sibling',
            'relative' => 'relative',
            'friend' => 'friend',
            default => 'other',
        };
    }

    public function isNeverMarriedValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_string($value)) {
            return str_replace('-', '_', strtolower(trim($value))) === 'never_married';
        }

        if (is_numeric($value) && Schema::hasTable('master_marital_statuses')) {
            return MasterMaritalStatus::query()
                ->whereKey((int) $value)
                ->where('key', 'never_married')
                ->exists();
        }

        return false;
    }

    public function draftPayload(MobileOnboardingDraft $draft): array
    {
        return [
            'id' => (int) $draft->id,
            'current_step' => $draft->current_step,
            'last_completed_step' => $draft->last_completed_step,
            'completed_steps' => array_values($draft->completed_steps ?? []),
            'data' => $draft->draft_data ?? [],
        ];
    }

    private function assertKnownStep(string $step): void
    {
        if (! in_array($step, self::STEPS, true)) {
            throw ValidationException::withMessages([
                'step' => 'Unsupported onboarding step.',
            ]);
        }
    }

    private function assertDraftStep(string $step): void
    {
        if (! in_array($step, self::DRAFT_SAVE_STEPS, true)) {
            throw ValidationException::withMessages([
                'step' => 'Unsupported onboarding draft step.',
            ]);
        }
    }

    private function stepsWithCompleted(User $user, array $existing, string $step): array
    {
        $steps = $this->accountAwareCompletedSteps($user, $existing);
        if (! in_array($step, $steps, true)) {
            $steps[] = $step;
        }

        return $this->sortSteps($steps);
    }

    private function accountAwareCompletedSteps(User $user, array $existing): array
    {
        $steps = array_values(array_filter(array_map('strval', $existing)));
        if ($user->mobile_verified_at !== null && trim((string) ($user->name ?? '')) !== '' && ! in_array('account', $steps, true)) {
            $steps[] = 'account';
        }

        return $this->sortSteps($steps);
    }

    private function sortSteps(array $steps): array
    {
        $allowed = array_flip(self::STEPS);

        return collect($steps)
            ->filter(fn (string $step): bool => isset($allowed[$step]))
            ->unique()
            ->sortBy(fn (string $step): int => $allowed[$step])
            ->values()
            ->all();
    }

    private function normalizeStepData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            $out[$key] = $value === '' ? null : $value;
        }

        return $out;
    }

    private function applyDependentClears(string $step, array $existing, array $data): array
    {
        if ($step === 'religion_caste') {
            if (array_key_exists('religion_id', $data) && ($existing['religion_id'] ?? null) != $data['religion_id']) {
                $data['caste_id'] = null;
                $data['sub_caste_id'] = null;
                $data['same_caste_expected'] = null;
                $data['same_sub_caste_required'] = null;
                $data['caste_strictness'] = null;
                $data['sub_caste_strictness'] = null;
            } elseif (array_key_exists('caste_id', $data) && ($existing['caste_id'] ?? null) != $data['caste_id']) {
                $data['sub_caste_id'] = null;
                $data['same_sub_caste_required'] = null;
                $data['sub_caste_strictness'] = null;
            }
        }

        if ($step === 'basic_info') {
            $statusValue = $data['marital_status_key'] ?? $data['marital_status'] ?? $data['marital_status_id'] ?? null;
            if ($this->isNeverMarriedValue($statusValue)) {
                $data['has_children'] = false;
                $data['children'] = [];
                $data['children_count'] = null;
                $data['children_living_with'] = null;
                $data['children_living_with_id'] = null;
            }
        }

        if ($step === 'career' && array_key_exists('working_with', $data) && ($existing['working_with'] ?? null) != $data['working_with']) {
            $data['working_as'] = null;
            $data['occupation_master_id'] = null;
            $data['occupation_custom_id'] = null;
            $data['occupation_title'] = null;
        }

        return $data;
    }
}
