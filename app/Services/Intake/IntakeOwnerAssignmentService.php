<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\IntakeSourceContext;
use App\Models\User;
use App\Support\MobileNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IntakeOwnerAssignmentService
{
    public function __construct(
        private readonly IntakeSourceContextRecorder $sourceContextRecorder
    ) {}

    public function assignExistingUserToUnclaimedIntake(BiodataIntake $intake, User $ownerUser, User $actor, array $meta = []): BiodataIntake
    {
        if ($ownerUser->isAnyAdmin()) {
            throw ValidationException::withMessages([
                'owner_user_id' => 'Select a non-admin member account.',
            ]);
        }

        return DB::transaction(function () use ($intake, $ownerUser, $actor, $meta): BiodataIntake {
            $lockedIntake = BiodataIntake::query()->lockForUpdate()->findOrFail($intake->id);

            if ($lockedIntake->uploaded_by !== null) {
                throw ValidationException::withMessages([
                    'owner_user_id' => 'Owner already assigned. Reassignment is not available in this phase.',
                ]);
            }

            $lockedIntake->forceFill([
                'uploaded_by' => (int) $ownerUser->id,
            ])->save();

            $assignedAt = now()->toIso8601String();

            $this->sourceContextRecorder->recordForIntake($lockedIntake, [
                'source_type' => IntakeSourceContext::SOURCE_ADMIN_MANUAL,
                'source_surface' => IntakeSourceContext::SURFACE_ADMIN_PANEL,
                'actor_type' => IntakeSourceContext::ACTOR_ADMIN,
                'actor_user_id' => (int) $actor->id,
                'bulk_intake_batch_id' => $meta['bulk_intake_batch_id'] ?? null,
                'bulk_intake_batch_item_id' => $meta['bulk_intake_batch_item_id'] ?? null,
                'idempotency_key' => 'intake-owner-assignment:'.$lockedIntake->id.':'.$ownerUser->id,
                'source_meta_json' => [
                    'action' => 'owner_assigned',
                    'previous_uploaded_by' => null,
                    'new_uploaded_by' => (int) $ownerUser->id,
                    'consent_confirmed' => true,
                    'consent_note' => $this->stringOrNull($meta['consent_note'] ?? null),
                    'assigned_at' => $assignedAt,
                ],
            ]);

            return $lockedIntake->refresh();
        });
    }

    /**
     * @return array{user: User, intake: BiodataIntake}
     */
    public function createMemberAndAssignToUnclaimedIntake(BiodataIntake $intake, User $actor, array $userAttributes, array $meta = []): array
    {
        return DB::transaction(function () use ($intake, $actor, $userAttributes, $meta): array {
            $lockedIntake = BiodataIntake::query()->lockForUpdate()->findOrFail($intake->id);

            if ($lockedIntake->uploaded_by !== null) {
                throw ValidationException::withMessages([
                    'new_mobile' => 'Owner already assigned. Reassignment is not available in this phase.',
                ]);
            }

            $normalizedMobile = MobileNumber::normalize($userAttributes['mobile'] ?? null);
            if ($normalizedMobile === null) {
                throw ValidationException::withMessages([
                    'new_mobile' => __('otp.enter_valid_10_digit_mobile'),
                ]);
            }

            if (User::query()->where('mobile', $normalizedMobile)->exists()) {
                throw ValidationException::withMessages([
                    'new_mobile' => __('auth.mobile_duplicate_register'),
                ]);
            }

            $email = $this->stringOrNull($userAttributes['email'] ?? null);
            if ($email !== null && User::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages([
                    'new_email' => 'The email has already been taken.',
                ]);
            }

            $member = User::create([
                'name' => trim((string) ($userAttributes['name'] ?? '')),
                'email' => $email,
                'mobile' => $normalizedMobile,
                'password' => Hash::make(Str::random(40)),
                'registering_for' => (string) ($userAttributes['registering_for'] ?? ''),
                'referral_code' => User::generateUniqueReferralCode(),
            ]);

            $lockedIntake->forceFill([
                'uploaded_by' => (int) $member->id,
            ])->save();

            $assignedAt = now()->toIso8601String();

            $this->sourceContextRecorder->recordForIntake($lockedIntake, [
                'source_type' => IntakeSourceContext::SOURCE_ADMIN_MANUAL,
                'source_surface' => IntakeSourceContext::SURFACE_ADMIN_PANEL,
                'actor_type' => IntakeSourceContext::ACTOR_ADMIN,
                'actor_user_id' => (int) $actor->id,
                'bulk_intake_batch_id' => $meta['bulk_intake_batch_id'] ?? null,
                'bulk_intake_batch_item_id' => $meta['bulk_intake_batch_item_id'] ?? null,
                'idempotency_key' => 'intake-owner-created:'.$lockedIntake->id.':'.$member->id,
                'source_meta_json' => [
                    'action' => 'owner_user_created_and_assigned',
                    'new_uploaded_by' => (int) $member->id,
                    'consent_confirmed' => true,
                    'consent_note' => $this->stringOrNull($meta['consent_note'] ?? null),
                    'created_user_mobile' => $normalizedMobile,
                    'assigned_at' => $assignedAt,
                ],
            ]);

            return [
                'user' => $member,
                'intake' => $lockedIntake->refresh(),
            ];
        });
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
