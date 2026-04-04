<?php

namespace App\Services;

use App\Models\ConflictRecord;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Support\AdminProfileEditResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates admin manual profile edits: locks, conflicts, mutation, locks — post-validation only.
 * MutationService remains write authority.
 */
class AdminProfileEditGovernanceService
{
    /** @var list<string> */
    public const EDITABLE_CORE_FIELDS = [
        'full_name', 'date_of_birth', 'marital_status_id', 'highest_education', 'location',
        'religion_id', 'caste_id', 'sub_caste_id', 'height_cm',
        'complexion_id', 'blood_group_id', 'physical_build_id', 'weight_kg', 'spectacles_lens', 'physical_condition',
    ];

    public function __construct(
        private readonly MutationService $mutationService,
    ) {}

    /**
     * Phase-5: Resolve string lookups to *_id when form sends key (e.g. marital_status => single).
     */
    public function mergeMaritalStatusFromLegacyRequest(Request $request): void
    {
        if ($request->has('marital_status') && ! $request->has('marital_status_id')) {
            $key = $request->input('marital_status') === 'single' ? 'never_married' : $request->input('marital_status');
            $id = \App\Models\MasterMaritalStatus::where('key', $key)->value('id');
            if ($id) {
                $request->merge(['marital_status_id' => $id]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildOriginalCoreSnapshot(MatrimonyProfile $profile): array
    {
        return $profile->only(self::EDITABLE_CORE_FIELDS);
    }

    public function hasCoreFieldChanges(Request $request, array $originalData): bool
    {
        foreach (self::EDITABLE_CORE_FIELDS as $field) {
            if ($request->exists($field)) {
                $newValue = $request->input($field);
                $oldValue = $originalData[$field] ?? null;
                $newValue = is_string($newValue) ? trim($newValue) : $newValue;
                $newValue = $newValue === '' ? null : $newValue;
                $oldValue = is_string($oldValue) ? trim($oldValue) : $oldValue;
                $oldValue = $oldValue === '' ? null : $oldValue;
                if ((string) $newValue !== (string) $oldValue) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Phase-5B: Build admin snapshot (same structure as manual). Only include changed core + optional extended.
     * No DB write. Used with MutationService::applyManualSnapshot(..., 'admin').
     *
     * @param  array<string, mixed>  $coreOverrides
     * @param  array<string, mixed>  $extendedFields
     * @return array<string, mixed>
     */
    public function buildAdminSnapshot(MatrimonyProfile $profile, array $coreOverrides, array $extendedFields = []): array
    {
        $snapshot = [
            'core' => $coreOverrides,
        ];
        if ($extendedFields !== []) {
            $snapshot['extended_fields'] = $extendedFields;
        }

        return $snapshot;
    }

    /**
     * Run after $request->validate(...) succeeds.
     *
     * @param  array<string, mixed>  $originalData  From buildOriginalCoreSnapshot
     */
    public function applyAfterValidation(Request $request, MatrimonyProfile $profile, User $admin, array $originalData): AdminProfileEditResult
    {
        // STEP 1: REQUEST PAYLOAD (SINGLE RUN) - BEFORE any logic
        Log::info('STEP1_REQUEST', [
            'has' => request()->has('caste'),
            'exists' => request()->exists('caste'),
            'value' => request()->input('caste'),
            'all' => request()->all(),
        ]);

        $updateData = [];
        $editedFields = [];

        foreach (self::EDITABLE_CORE_FIELDS as $field) {
            if ($request->exists($field)) {
                $newValue = $request->input($field);
                $oldValue = $originalData[$field] ?? null;

                $newValue = is_string($newValue) ? trim($newValue) : $newValue;
                $newValue = $newValue === '' ? null : $newValue;
                $oldValue = is_string($oldValue) ? trim($oldValue) : $oldValue;
                $oldValue = $oldValue === '' ? null : $oldValue;

                if ($newValue != $oldValue) {
                    $updateData[$field] = $newValue;
                    $editedFields[] = $field;
                }
            }
        }

        $hasExtendedFields = $request->has('extended_fields') && is_array($request->input('extended_fields'));
        $changedExtendedKeys = $hasExtendedFields
            ? ExtendedFieldService::getChangedExtendedFieldKeys($profile, $request->input('extended_fields'))
            : [];

        if (empty($editedFields) && empty($changedExtendedKeys)) {
            return new AdminProfileEditResult(
                status: 'error',
                message: 'No changes detected. Please modify at least one field.',
            );
        }

        if (! empty($editedFields)) {
            ProfileFieldLockService::assertNotLocked($profile, $editedFields, $admin);
        }
        if (! empty($changedExtendedKeys)) {
            ProfileFieldLockService::assertNotLocked($profile, $changedExtendedKeys, $admin);
        }

        if (! empty($editedFields)) {
            $existingAdminEditedFields = $profile->admin_edited_fields ?? [];
            $mergedAdminEditedFields = array_unique(array_merge($existingAdminEditedFields, $editedFields));

            $updateData['edited_by'] = $admin->id;
            $updateData['edited_at'] = now();
            $updateData['edit_reason'] = $request->input('edit_reason');
            $updateData['edited_source'] = 'admin';
            $updateData['admin_edited_fields'] = $mergedAdminEditedFields;

            $identityCriticalFields = [
                'full_name',
                'date_of_birth',
                'gender_id',
                'religion_id',
                'caste_id',
                'sub_caste_id',
                'marital_status_id',
                'primary_contact_number',
            ];
            $editedCritical = array_intersect($editedFields, $identityCriticalFields);
            if ($profile->serious_intent_id !== null && ! empty($editedCritical)) {
                foreach ($editedCritical as $fieldKey) {
                    if (ConflictRecord::where('profile_id', $profile->id)->where('field_name', $fieldKey)->where('resolution_status', 'PENDING')->exists()) {
                        continue;
                    }
                    $oldVal = ($originalData[$fieldKey] ?? $profile->$fieldKey) === '' ? null : (string) ($originalData[$fieldKey] ?? $profile->$fieldKey ?? null);
                    $newVal = isset($updateData[$fieldKey]) ? ($updateData[$fieldKey] === '' ? null : (string) $updateData[$fieldKey]) : null;
                    ConflictRecord::create([
                        'profile_id' => $profile->id,
                        'field_name' => $fieldKey,
                        'field_type' => 'CORE',
                        'old_value' => $oldVal,
                        'new_value' => $newVal,
                        'source' => 'ADMIN',
                        'detected_at' => now(),
                        'resolution_status' => 'PENDING',
                    ]);
                }
                ProfileLifecycleService::syncLifecycleFromPendingConflicts($profile);

                return new AdminProfileEditResult(
                    status: 'warning',
                    message: 'Identity-critical field(s) changed under serious intent. Conflict(s) created; profile not updated. Resolve via Conflict Records.',
                    escalated_to_conflict: true,
                    edited_fields: $editedFields,
                );
            }

            $extendedInput = $hasExtendedFields ? $request->input('extended_fields') : [];
            $snapshot = $this->buildAdminSnapshot($profile, $updateData, is_array($extendedInput) ? $extendedInput : []);
            $this->mutationService->applyManualSnapshot($profile, $snapshot, (int) $admin->id, 'admin');

            $profile->refresh();

            if (in_array('caste_id', $editedFields) || in_array('caste', $editedFields)) {
                $casteValue = $updateData['caste_id'] ?? $updateData['caste'] ?? null;
                if ($casteValue !== null && (is_string($casteValue) ? trim($casteValue) !== '' : true)) {
                    Log::info('B_AFTER_ADD', [
                        'db_caste' => $profile->caste,
                        'pct' => ProfileCompletenessService::percentage($profile),
                    ]);
                } else {
                    Log::info('C_AFTER_REMOVE', [
                        'db_caste' => $profile->caste,
                        'pct' => ProfileCompletenessService::percentage($profile),
                    ]);
                }
            }

            ProfileFieldLockService::applyLocks($profile, $editedFields, 'CORE', $admin);
        } elseif (! empty($changedExtendedKeys)) {
            $snapshot = $this->buildAdminSnapshot($profile, [
                'edited_by' => $admin->id,
                'edited_at' => now(),
                'edit_reason' => $request->input('edit_reason'),
                'edited_source' => 'admin',
            ], $request->input('extended_fields'));
            $this->mutationService->applyManualSnapshot($profile, $snapshot, (int) $admin->id, 'admin');
        }

        AuditLogService::log(
            $admin,
            'profile_edit',
            'MatrimonyProfile',
            $profile->id,
            $request->input('edit_reason'),
            $profile->is_demo ?? false
        );

        if (! empty($changedExtendedKeys)) {
            ProfileFieldLockService::applyLocks($profile, $changedExtendedKeys, 'EXTENDED', $admin);
        }

        $message = ! empty($editedFields)
            ? 'Profile updated successfully. Edited fields: '.implode(', ', $editedFields)
            : 'Profile updated successfully. Extended fields saved.';

        return new AdminProfileEditResult(
            status: 'success',
            message: $message,
            mutated: true,
            edited_fields: $editedFields,
        );
    }
}
