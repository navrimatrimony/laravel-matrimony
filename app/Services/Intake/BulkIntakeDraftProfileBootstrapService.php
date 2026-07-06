<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use App\Models\IntakeSourceContext;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Admin\AdminSettingService;
use App\Services\MutationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BulkIntakeDraftProfileBootstrapService
{
    public function __construct(
        private readonly BulkIntakeReadinessService $readinessService,
        private readonly BulkIntakeBatchService $batchService,
        private readonly IntakeSourceContextRecorder $sourceContextRecorder,
        private readonly MutationService $mutationService
    ) {}

    /**
     * @return array{profile: MatrimonyProfile, intake: BiodataIntake}
     */
    public function bootstrapForItem(BulkIntakeBatchItem $item, User $actor): array
    {
        return DB::transaction(function () use ($item, $actor): array {
            $lockedItem = BulkIntakeBatchItem::query()
                ->lockForUpdate()
                ->with('batch')
                ->findOrFail($item->id);

            if ($lockedItem->biodata_intake_id === null) {
                throw $this->notReady(['missing_linked_intake']);
            }

            $lockedIntake = BiodataIntake::query()
                ->lockForUpdate()
                ->with([
                    'uploadedByUser:id,name,email,mobile,is_admin,admin_role,registering_for',
                    'uploadedByUser.matrimonyProfile:id,user_id',
                ])
                ->findOrFail((int) $lockedItem->biodata_intake_id);

            $lockedItem->setRelation('biodataIntake', $lockedIntake);
            $readiness = $this->readinessService->readinessForItem($lockedItem);
            if ($readiness['status'] !== 'ready_for_profile_review') {
                throw $this->notReady($readiness['reason_codes']);
            }

            $owner = $lockedIntake->uploadedByUser;
            if (! $owner instanceof User || $owner->isAnyAdmin()) {
                throw $this->notReady(['owner_unassigned']);
            }

            User::query()
                ->whereKey($owner->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingProfile = MatrimonyProfile::query()
                ->where('user_id', $owner->id)
                ->lockForUpdate()
                ->first();
            if ($existingProfile instanceof MatrimonyProfile) {
                throw $this->notReady(['already_has_profile']);
            }

            $profile = $this->mutationService->createDraftProfileForUser($owner, [
                'is_suspended' => AdminSettingService::isManualProfileActivationRequired(),
            ]);

            if ($lockedIntake->matrimony_profile_id !== null && (int) $lockedIntake->matrimony_profile_id !== (int) $profile->id) {
                throw $this->notReady(['already_has_profile']);
            }

            $lockedIntake->forceFill([
                'matrimony_profile_id' => (int) $profile->id,
            ])->save();

            $bootstrappedAt = now()->toIso8601String();
            $meta = is_array($lockedItem->item_meta_json) ? $lockedItem->item_meta_json : [];
            $lockedItem->forceFill([
                'item_status' => BulkIntakeBatchItem::STATUS_PROFILE_DRAFT_CREATED,
                'item_meta_json' => array_merge($meta, [
                    'draft_profile_bootstrapped_at' => $bootstrappedAt,
                    'draft_profile_bootstrapped_by_user_id' => (int) $actor->id,
                    'draft_profile_id' => (int) $profile->id,
                ]),
            ])->save();

            $this->sourceContextRecorder->recordForIntake($lockedIntake, [
                'source_type' => IntakeSourceContext::SOURCE_ADMIN_MANUAL,
                'source_surface' => IntakeSourceContext::SURFACE_ADMIN_PANEL,
                'actor_type' => IntakeSourceContext::ACTOR_ADMIN,
                'actor_user_id' => (int) $actor->id,
                'bulk_intake_batch_id' => $lockedItem->bulk_intake_batch_id,
                'bulk_intake_batch_item_id' => $lockedItem->id,
                'idempotency_key' => 'bulk-draft-profile-bootstrap:'.$lockedItem->id.':'.$profile->id,
                'source_meta_json' => [
                    'action' => 'draft_profile_bootstrapped',
                    'profile_id' => (int) $profile->id,
                    'no_parsed_fields_applied' => true,
                    'bootstrapped_at' => $bootstrappedAt,
                ],
            ]);

            if ($lockedItem->batch !== null) {
                $this->batchService->refreshCounters($lockedItem->batch);
            }

            return [
                'profile' => $profile->refresh(),
                'intake' => $lockedIntake->refresh(),
            ];
        });
    }

    /**
     * @param  list<string>  $reasonCodes
     */
    private function notReady(array $reasonCodes): ValidationException
    {
        $reasonText = $reasonCodes === [] ? 'not_ready' : implode(', ', $reasonCodes);

        return ValidationException::withMessages([
            'bootstrap_confirmed' => 'This bulk item is not ready for draft profile bootstrap: '.$reasonText.'.',
        ]);
    }
}
