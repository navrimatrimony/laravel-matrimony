<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\IntakeSourceContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
