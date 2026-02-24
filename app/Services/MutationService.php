<?php

namespace App\Services;

use App\Models\BiodataIntake;
use App\Models\ConflictRecord;
use App\Models\FieldRegistry;
use App\Models\MatrimonyProfile;
use App\Models\ProfileExtendedField;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Phase-5: Safe Mutation Pipeline.
 * Compliant with PHASE-5_MUTATION_EXECUTION_CONTRACT.md.
 * Writes ONLY to profile_change_history (no field_value_history).
 * Duplicate detection before profile creation; FieldRegistry-driven CORE; snapshot key routing.
 */
class MutationService
{
    /** Supported snapshot_schema_version values. Unsupported → throw before transaction. */
    private const SUPPORTED_SNAPSHOT_VERSIONS = [1];

    /** Snapshot key → storage table (contract §5). */
    private const SNAPSHOT_KEY_TO_TABLE = [
        'contacts' => 'profile_contacts',
        'children' => 'profile_children',
        'siblings' => 'profile_siblings',
        'relatives' => 'profile_relatives',
        'alliance_networks' => 'profile_alliance_networks',
        'education_history' => 'profile_education',
        'career_history' => 'profile_career',
        'addresses' => 'profile_addresses',
        'property_summary' => 'profile_property_summary',
        'property_assets' => 'profile_property_assets',
        'horoscope' => 'profile_horoscope_data',
        'legal_cases' => 'profile_legal_cases',
        'extended_narrative' => 'profile_extended_attributes',
    ];

    /** Entity sync order for step 7 (excluding contacts — step 6). */
    private const ENTITY_SYNC_ORDER = [
        'children',
        'siblings',
        'relatives',
        'alliance_networks',
        'education_history',
        'career_history',
        'addresses',
        'property_summary',
        'property_assets',
        'horoscope',
        'legal_cases',
    ];

    /** Snapshot keys that are exactly ONE row per profile_id (upsert by profile_id, not by row id). */
    private const SINGLE_ROW_SNAPSHOT_KEYS = [
        'property_summary',
    ];

    /** States that must NOT be auto-activated (contract §6). */
    private const NO_AUTO_ACTIVATE_STATES = ['suspended', 'archived', 'archived_due_to_marriage'];

    /** Fallback CORE keys when registry has no CORE rows. Phase-5: *_id for master lookups. */
    private const FALLBACK_CORE_KEYS = [
        'full_name', 'gender_id', 'date_of_birth', 'marital_status_id', 'highest_education',
        'location', 'religion_id', 'caste_id', 'sub_caste_id', 'height_cm', 'profile_photo',
        'complexion_id', 'physical_build_id', 'blood_group_id', 'family_type_id', 'income_currency_id',
        'photo_approved', 'photo_rejected_at', 'photo_rejection_reason', 'is_suspended',
    ];

    /** Lifecycle states that block manual edit (PART-5). */
    private const BLOCK_MANUAL_EDIT_STATES = [
        'intake_uploaded',
        'awaiting_user_approval',
        'approved_pending_mutation',
        'conflict_pending',
    ];

    /** When set, writeProfileChangeHistory uses these for source/changed_by (manual mutation). */
    private ?string $historySourceContext = null;

    private ?int $historyChangedByContext = null;

    /** PART-4: Same timestamp for one mutation call (grouping); only written if column exists. */
    private ?int $mutationBatchId = null;

    /**
     * Phase-5B: Apply manual edit snapshot. Single mutation authority.
     * Reuses conflict detection, field lock (skipped for admin), core apply, entity sync, profile_change_history.
     *
     * @param  array<string, mixed>  $snapshot  Same schema as approval_snapshot_json (core, contacts, children, ...)
     * @param  'manual'|'admin'|'intake'  $mode  Source for history; 'admin' skips field lock check and lifecycle block.
     * @return array{mutation_success: bool, conflict_detected: bool, profile_id: int}
     */
    public function applyManualSnapshot(MatrimonyProfile $profile, array $snapshot, int $actorUserId, string $mode = 'manual'): array
    {
        $state = $profile->lifecycle_state ?? '';
        $isAdmin = $mode === 'admin';
        if (!$isAdmin && in_array($state, self::BLOCK_MANUAL_EDIT_STATES, true)) {
            throw new \RuntimeException('Profile cannot be edited while intake or conflict is pending. Lifecycle: ' . $state);
        }

        $this->historySourceContext = $mode;
        $this->historyChangedByContext = $actorUserId;
        $this->mutationBatchId = now()->timestamp;
        $hadConflicts = false;

        // Manual mode: bypass MatrimonyProfile model observer so it does not run ConflictDetectionService
        // on save() and throw "Governance: conflicting change detected" (observer runs during $profile->save() inside transaction).
        $priorBypass = false;
        if ($mode === 'manual') {
            $priorBypass = MatrimonyProfile::$bypassGovernanceEnforcement;
            MatrimonyProfile::$bypassGovernanceEnforcement = true;
        }

        try {
            DB::transaction(function () use ($profile, $snapshot, $actorUserId, $isAdmin, $mode, &$hadConflicts): void {
                $proposedCore = $snapshot['core'] ?? [];
                $proposedExtended = $snapshot['extended'] ?? [];
                $conflictRecords = [];
                $conflictFieldNames = [];

                // ——— Conflict detection: only when source is not manual (intake uses applyApprovedIntake; here admin runs engine, manual never creates conflicts) ———
                $runConflictEngine = ($mode !== 'manual');

                if ($runConflictEngine) {
                    // ——— PART-3: Manual duplicate safety (ignore same-profile match) ———
                    $identityCriticalKeys = ['full_name', 'date_of_birth', 'primary_contact_number'];
                    $hasIdentityChange = false;
                    foreach ($identityCriticalKeys as $k) {
                        if ($k === 'primary_contact_number') {
                            $contacts = $snapshot['contacts'] ?? [];
                            if (!empty($contacts)) {
                                $hasIdentityChange = true;
                                break;
                            }
                            continue;
                        }
                        if (array_key_exists($k, $proposedCore)) {
                            $hasIdentityChange = true;
                            break;
                        }
                    }
                    if ($hasIdentityChange) {
                        $duplicateResult = app(DuplicateDetectionService::class)->detectFromSnapshot($snapshot, $profile->user_id);
                        if ($duplicateResult->isDuplicate && $duplicateResult->existingProfileId !== null && (int) $duplicateResult->existingProfileId !== (int) $profile->id) {
                            if (!$this->hasPendingConflictForField($profile->id, 'duplicate_detection')) {
                                ConflictRecord::create([
                                    'profile_id' => $profile->id,
                                    'field_name' => 'duplicate_detection',
                                'field_type' => 'CORE',
                                'old_value' => null,
                                'new_value' => $duplicateResult->duplicateType . ':' . $duplicateResult->existingProfileId,
                                'source' => 'USER',
                                'detected_at' => now(),
                                'resolution_status' => 'PENDING',
                                ]);
                                $conflictFieldNames = array_merge($conflictFieldNames, $identityCriticalKeys);
                                $hadConflicts = true;
                            }
                        }
                    }

                    // ——— Conflict detection ———
                    $conflictResult = ConflictDetectionService::detectResult($profile, $proposedCore, $proposedExtended);
                    $conflictRecords = array_merge($conflictRecords, $conflictResult->conflictRecords);
                    $conflictFieldNames = array_merge($conflictFieldNames, array_map(fn (ConflictRecord $r) => $r->field_name, $conflictResult->conflictRecords));

                    // ——— Field lock check (PART-2: skip for admin so admin can override locked fields) ———
                    $coreFieldKeys = $this->getCoreFieldKeysFromRegistry();
                    if (!$isAdmin) {
                        foreach ($coreFieldKeys as $fieldKey) {
                            if (!array_key_exists($fieldKey, $proposedCore)) {
                                continue;
                            }
                            if (ProfileFieldLockService::isLocked($profile, $fieldKey) && !$this->hasPendingConflictForField($profile->id, $fieldKey)) {
                                $conflictRecords[] = ConflictRecord::create([
                                    'profile_id' => $profile->id,
                                    'field_name' => $fieldKey,
                                    'field_type' => 'CORE',
                                    'old_value' => $this->getCurrentCoreValue($profile, $fieldKey),
                                    'new_value' => $this->normalizeValue($proposedCore[$fieldKey]),
                                    'source' => 'USER',
                                    'detected_at' => now(),
                                    'resolution_status' => 'PENDING',
                                ]);
                                $conflictFieldNames[] = $fieldKey;
                            }
                        }
                        $extendedKeys = array_unique(array_merge(
                            array_keys(ExtendedFieldService::getValuesForProfile($profile)),
                            array_keys($proposedExtended)
                        ));
                        foreach ($extendedKeys as $fieldKey) {
                            if (!array_key_exists($fieldKey, $proposedExtended)) {
                                continue;
                            }
                            if (ProfileFieldLockService::isLocked($profile, $fieldKey) && !$this->hasPendingConflictForField($profile->id, $fieldKey)) {
                                $current = ExtendedFieldService::getValuesForProfile($profile)[$fieldKey] ?? null;
                                $conflictRecords[] = ConflictRecord::create([
                                    'profile_id' => $profile->id,
                                    'field_name' => $fieldKey,
                                    'field_type' => 'EXTENDED',
                                    'old_value' => $current === null ? null : (string) $current,
                                    'new_value' => $this->normalizeValue($proposedExtended[$fieldKey]),
                                    'source' => 'USER',
                                    'detected_at' => now(),
                                    'resolution_status' => 'PENDING',
                                ]);
                                $conflictFieldNames[] = $fieldKey;
                            }
                        }
                    }
                }

                $coreFieldKeys = $this->getCoreFieldKeysFromRegistry();

                // ——— CORE field apply ———
                foreach ($coreFieldKeys as $fieldKey) {
                    if (!array_key_exists($fieldKey, $proposedCore)) {
                        continue;
                    }
                    if (in_array($fieldKey, $conflictFieldNames, true)) {
                        continue;
                    }
                    if (!$isAdmin && ProfileFieldLockService::isLocked($profile, $fieldKey)) {
                        \Illuminate\Support\Facades\Log::info('MANUAL EDIT: field locked (skipped)', ['field' => $fieldKey, 'profile_id' => $profile->id]);
                        continue;
                    }
                    $oldVal = $this->getCurrentCoreValue($profile, $fieldKey);
                    $newVal = $this->normalizeValue($proposedCore[$fieldKey]);
                    if ((string) ($oldVal ?? '') === (string) ($newVal ?? '')) {
                        continue;
                    }
                    $this->setProfileAttribute($profile, $fieldKey, $newVal);
                    $this->writeProfileChangeHistory(
                        $profile->id,
                        'matrimony_profile',
                        $profile->id,
                        $fieldKey,
                        $oldVal,
                        $newVal
                    );
                }
                // Manual or Admin: apply any remaining proposedCore keys that are profile table columns (e.g. Phase-5B fields not in registry)
                if (Schema::hasTable($profile->getTable())) {
                    foreach ($proposedCore as $fieldKey => $newVal) {
                        if (in_array($fieldKey, $conflictFieldNames, true)) {
                            continue;
                        }
                        if (in_array($fieldKey, $coreFieldKeys, true)) {
                            continue;
                        }
                        if (!Schema::hasColumn($profile->getTable(), $fieldKey)) {
                            continue;
                        }
                        \Illuminate\Support\Facades\Log::warning('Core column missing in registry', [
                            'field' => $fieldKey,
                            'profile_id' => $profile->id,
                            'source' => $isAdmin ? 'admin' : 'manual',
                        ]);
                        if (!$isAdmin && ProfileFieldLockService::isLocked($profile, $fieldKey)) {
                            \Illuminate\Support\Facades\Log::info('MANUAL EDIT: field locked (skipped)', ['field' => $fieldKey, 'profile_id' => $profile->id]);
                            continue;
                        }
                        $oldVal = $profile->getAttribute($fieldKey);
                        $norm = $this->normalizeValue($newVal);

                        if ($oldVal instanceof \DateTimeInterface) {
                            $oldVal = $oldVal->format('Y-m-d H:i:s');
                        }
                        // If both are arrays (e.g. JSON column like admin_edited_fields)
                        if (is_array($oldVal) || is_array($norm)) {
                            if (json_encode($oldVal ?? []) === json_encode($norm ?? [])) {
                                continue;
                            }
                        } else {
                            if ((string) ($oldVal ?? '') === (string) ($norm ?? '')) {
                                continue;
                            }
                        }
                        $profile->setAttribute($fieldKey, $newVal);
                        $this->writeProfileChangeHistory(
                            $profile->id,
                            'matrimony_profile',
                            $profile->id,
                            $fieldKey,
                            $oldVal,
                            $norm
                        );
                    }
                }
                $profile->save();

                // ——— Birth place / Native place (snapshot keys → profile columns only; do not touch existing location logic) ———
                $tableName = $profile->getTable();
                $placeUpdated = false;
                $hasAnyPlaceValue = static function (array $place): bool {
                    foreach (['city_id', 'taluka_id', 'district_id', 'state_id'] as $k) {
                        if (isset($place[$k]) && $place[$k] !== null && $place[$k] !== '') {
                            return true;
                        }
                    }
                    return false;
                };
                if (isset($snapshot['birth_place']) && is_array($snapshot['birth_place']) && $hasAnyPlaceValue($snapshot['birth_place'])) {
                    $bp = $snapshot['birth_place'];
                    if (Schema::hasColumn($tableName, 'birth_city_id')) {
                        $profile->birth_city_id = isset($bp['city_id']) ? (int) $bp['city_id'] : null;
                    }
                    if (Schema::hasColumn($tableName, 'birth_taluka_id')) {
                        $profile->birth_taluka_id = isset($bp['taluka_id']) ? (int) $bp['taluka_id'] : null;
                    }
                    if (Schema::hasColumn($tableName, 'birth_district_id')) {
                        $profile->birth_district_id = isset($bp['district_id']) ? (int) $bp['district_id'] : null;
                    }
                    if (Schema::hasColumn($tableName, 'birth_state_id')) {
                        $profile->birth_state_id = isset($bp['state_id']) ? (int) $bp['state_id'] : null;
                    }
                    $placeUpdated = true;
                }
                if (isset($snapshot['native_place']) && is_array($snapshot['native_place']) && $hasAnyPlaceValue($snapshot['native_place'])) {
                    $np = $snapshot['native_place'];
                    if (Schema::hasColumn($tableName, 'native_city_id')) {
                        $profile->native_city_id = isset($np['city_id']) ? (int) $np['city_id'] : null;
                    }
                    if (Schema::hasColumn($tableName, 'native_taluka_id')) {
                        $profile->native_taluka_id = isset($np['taluka_id']) ? (int) $np['taluka_id'] : null;
                    }
                    if (Schema::hasColumn($tableName, 'native_district_id')) {
                        $profile->native_district_id = isset($np['district_id']) ? (int) $np['district_id'] : null;
                    }
                    if (Schema::hasColumn($tableName, 'native_state_id')) {
                        $profile->native_state_id = isset($np['state_id']) ? (int) $np['state_id'] : null;
                    }
                    $placeUpdated = true;
                }
                if ($placeUpdated) {
                    $profile->save();
                }

                // ——— Contact sync (only if snapshot has contacts key) ———
                if (array_key_exists('contacts', $snapshot) && is_array($snapshot['contacts'])) {
                    $contactConflict = $this->syncContactsFromSnapshot($profile, $snapshot['contacts'], $runConflictEngine);
                    if ($contactConflict) {
                        $conflictRecords[] = $contactConflict;
                        $conflictFieldNames[] = $contactConflict->field_name;
                    }
                }

                // ——— Entity sync (only keys present in snapshot) ———
                foreach (self::ENTITY_SYNC_ORDER as $snapshotKey) {
                    if (!array_key_exists($snapshotKey, $snapshot) || !is_array($snapshot[$snapshotKey])) {
                        continue;
                    }
                    $table = self::SNAPSHOT_KEY_TO_TABLE[$snapshotKey] ?? null;
                    if ($table === null || !Schema::hasTable($table)) {
                        continue;
                    }
                    if (in_array($snapshotKey, self::SINGLE_ROW_SNAPSHOT_KEYS, true)) {
                        $this->syncSingleRowSection($profile, $table, $snapshot[$snapshotKey]);
                    } else {
                        $this->syncEntityDiff($profile, $table, $snapshot[$snapshotKey]);
                    }
                }

                if (isset($snapshot['preferences'])) {
                    $prefRow = isset($snapshot['preferences'][0]) && is_array($snapshot['preferences'][0])
                        ? $snapshot['preferences'][0]
                        : $snapshot['preferences'];
                    if (is_array($prefRow)) {
                        $this->syncPreferencesFromSnapshot($profile, $prefRow);
                    }
                }

                // ——— Extended narrative (if present): single row per profile — use upsert to avoid duplicate key ———
                if (array_key_exists('extended_narrative', $snapshot) && Schema::hasTable('profile_extended_attributes')) {
                    $extendedNarrative = $snapshot['extended_narrative'];
                    if (is_array($extendedNarrative)) {
                        $row = isset($extendedNarrative[0]) ? $extendedNarrative[0] : $extendedNarrative;
                        $this->syncExtendedAttributesUpsert($profile, $row);
                    }
                }

                // ——— PART-2: Extended fields (key-value) inside same transaction; profile_change_history only ———
                if (array_key_exists('extended_fields', $snapshot) && is_array($snapshot['extended_fields']) && Schema::hasTable('profile_extended_fields')) {
                    $this->applyExtendedFieldsFromSnapshot($profile, $snapshot['extended_fields']);
                }

                // ——— Lifecycle transition (PART-6: only allowed transitions; draft → active when no conflicts and photo present) ———
                $hasConflicts = count($conflictRecords) > 0;
                $hadConflicts = $hadConflicts || $hasConflicts;
                if ($hadConflicts) {
                    \App\Services\ProfileLifecycleService::syncLifecycleFromPendingConflicts($profile);
                } else {
                    $current = $profile->lifecycle_state ?? 'draft';
                    if ($current === 'draft' && !empty($profile->profile_photo) && !in_array($current, self::NO_AUTO_ACTIVATE_STATES, true)) {
                        $this->setLifecycleState($profile, 'active');
                    }
                }
            });
        } finally {
            if ($mode === 'manual') {
                MatrimonyProfile::$bypassGovernanceEnforcement = $priorBypass;
            }
            $this->historySourceContext = null;
            $this->historyChangedByContext = null;
            $this->mutationBatchId = null;
        }

        // Manual mode must NEVER report conflict to caller — user edits are never blocked by governance.
        if ($mode === 'manual') {
            return [
                'mutation_success' => true,
                'conflict_detected' => false,
                'profile_id' => $profile->id,
            ];
        }

        return [
            'mutation_success' => !$hadConflicts,
            'conflict_detected' => $hadConflicts,
            'profile_id' => $profile->id,
        ];
    }

    /**
     * Phase-5 Day-20: Entry point for intake-driven mutation (alias for applyApprovedIntake).
     * 1) Duplicate detection 2) Conflict detection 3) Field lock 4) Core apply 5) Contact sync
     * 6) Entity sync 7) History write 8) Lifecycle transition 9) intake_locked=true 10) intake_status=applied
     *
     * @return array{mutation_success: bool, conflict_detected: bool, profile_id: int|null}
     */
    public function applyFromIntake(BiodataIntake $intake): array
    {
        return $this->applyApprovedIntake($intake->id);
    }

    /**
     * @return array{mutation_success: bool, conflict_detected: bool, profile_id: int|null}
     */
    public function applyApprovedIntake(int $intakeId): array
    {
        Log::info('MutationService::applyApprovedIntake START', ['intakeId' => $intakeId]);

        $intake = BiodataIntake::find($intakeId);
        if (!$intake) {
            throw new \RuntimeException("BiodataIntake not found: {$intakeId}");
        }
        if (empty($intake->approval_snapshot_json)) {
            throw new \RuntimeException('Intake must have approval_snapshot_json.');
        }
        if ($intake->approved_by_user !== true) {
            throw new \RuntimeException('Intake must be approved (approved_by_user = true).');
        }
        if ($intake->intake_status === 'applied' || $intake->intake_locked === true) {
            return [
                'mutation_success' => true,
                'conflict_detected' => false,
                'profile_id' => $intake->matrimony_profile_id,
                'already_applied' => true,
            ];
        }

        $snapshot = is_array($intake->approval_snapshot_json) ? $intake->approval_snapshot_json : [];
        $version = $intake->snapshot_schema_version ?? $snapshot['snapshot_schema_version'] ?? null;
        $version = $version !== null ? (int) $version : null;
        if ($version === null || !in_array($version, self::SUPPORTED_SNAPSHOT_VERSIONS, true)) {
            throw new \RuntimeException('Unsupported or missing snapshot_schema_version. Supported: [1].');
        }
        Log::info('MutationService::applyApprovedIntake after snapshot version check', [
            'version' => $version,
            'version_type' => gettype($version),
        ]);

        $duplicateDetected = false;
        $hadConflicts = false;
        $profileIdForLog = null;
        $blockedByConflictPending = false;
        $alreadyAppliedInTransaction = false;

        Log::info('MutationService::applyApprovedIntake BEFORE DB::transaction');
        $this->mutationBatchId = now()->timestamp;

        try {
            DB::transaction(function () use ($intake, $snapshot, &$duplicateDetected, &$hadConflicts, &$profileIdForLog, &$blockedByConflictPending, &$alreadyAppliedInTransaction): void {
                $intakeId = $intake->id;
                $intake = BiodataIntake::where('id', $intakeId)->lockForUpdate()->first();
                if (!$intake) {
                    throw new \RuntimeException("BiodataIntake not found (locked): {$intakeId}");
                }
                if ($intake->intake_locked === true) {
                    $profileIdForLog = $intake->matrimony_profile_id;
                    $alreadyAppliedInTransaction = true;
                    return;
                }

                $proposedCore = $snapshot['core'] ?? [];
                $proposedExtended = $snapshot['extended'] ?? [];

                // ——— Step 1: Duplicate detection ———
                $duplicateResult = app(DuplicateDetectionService::class)->detectFromSnapshot(
                    $snapshot,
                    $intake->uploaded_by
                );
                Log::info('MutationService::applyApprovedIntake after duplicate detection (full DuplicateResult)', [
                    'isDuplicate' => $duplicateResult->isDuplicate,
                    'duplicateType' => $duplicateResult->duplicateType,
                    'existingProfileId' => $duplicateResult->existingProfileId,
                    'reason' => $duplicateResult->reason,
                ]);

                if ($duplicateResult->isDuplicate && $duplicateResult->existingProfileId !== null) {
                    $existingProfileId = $duplicateResult->existingProfileId;
                    Log::info('MutationService::applyApprovedIntake DUPLICATE PATH — right before return', [
                        'existingProfileId' => $existingProfileId,
                    ]);
                    $existingProfile = MatrimonyProfile::where('id', $existingProfileId)->lockForUpdate()->first();
                    if ($existingProfile && !$this->hasPendingConflictForField($existingProfile->id, 'duplicate_detection')) {
                        ConflictRecord::create([
                            'profile_id' => $existingProfile->id,
                            'field_name' => 'duplicate_detection',
                            'field_type' => 'CORE',
                            'old_value' => null,
                            'new_value' => $duplicateResult->duplicateType . ':' . $duplicateResult->existingProfileId,
                            'source' => 'SYSTEM',
                            'detected_at' => now(),
                            'resolution_status' => 'PENDING',
                        ]);
                        \App\Services\ProfileLifecycleService::syncLifecycleFromPendingConflicts($existingProfile);
                    }
                    $intake->update([
                        'matrimony_profile_id' => $existingProfileId,
                        'intake_locked' => true,
                    ]);
                    $profileIdForLog = $existingProfileId;
                    $duplicateDetected = true;
                    return;
                }

                Log::info('MutationService::applyApprovedIntake before profile existence step');
            // ——— Step 2: Profile existence ———
            $profile = null;
            $profileCreatedInThisTransaction = false;
            if (!empty($intake->matrimony_profile_id)) {
                $profile = MatrimonyProfile::where('id', $intake->matrimony_profile_id)->lockForUpdate()->first();
                if (!$profile) {
                    throw new \RuntimeException('Intake references non-existent profile.');
                }
                if (($profile->lifecycle_state ?? '') === 'conflict_pending') {
                    $profileIdForLog = $profile->id;
                    $blockedByConflictPending = true;
                    $intake->update(['intake_locked' => true]);
                    return;
                }
            } else {
                $actor = User::find($intake->uploaded_by);
                if (!$actor) {
                    throw new \RuntimeException('Intake uploaded_by user not found.');
                }
                $profile = new MatrimonyProfile();
                $profile->user_id = $intake->uploaded_by;
                $profile->full_name = $proposedCore['full_name'] ?? 'Draft';
                $profile->lifecycle_state = 'draft';
                $profile->save();
                $intake->update(['matrimony_profile_id' => $profile->id]);
                $profileCreatedInThisTransaction = true;
            }

            // ——— Step 3: Field-level conflict detection (ConflictDetectionService owns escalation) ———
            $conflictResult = ConflictDetectionService::detectResult($profile, $proposedCore, $proposedExtended);
            $conflictRecords = $conflictResult->conflictRecords;
            $conflictFieldNames = array_map(fn (ConflictRecord $r) => $r->field_name, $conflictRecords);

            // ——— Step 4: Field lock check ———
            $coreFieldKeys = $this->getCoreFieldKeysFromRegistry();
            foreach ($coreFieldKeys as $fieldKey) {
                if (!array_key_exists($fieldKey, $proposedCore)) {
                    continue;
                }
                if (ProfileFieldLockService::isLocked($profile, $fieldKey) && !$this->hasPendingConflictForField($profile->id, $fieldKey)) {
                    $conflictRecords[] = ConflictRecord::create([
                        'profile_id' => $profile->id,
                        'field_name' => $fieldKey,
                        'field_type' => 'CORE',
                        'old_value' => $this->getCurrentCoreValue($profile, $fieldKey),
                        'new_value' => $this->normalizeValue($proposedCore[$fieldKey]),
                        'source' => 'SYSTEM',
                        'detected_at' => now(),
                        'resolution_status' => 'PENDING',
                    ]);
                    $conflictFieldNames[] = $fieldKey;
                }
            }
            $extendedKeys = array_unique(array_merge(
                array_keys(ExtendedFieldService::getValuesForProfile($profile)),
                array_keys($proposedExtended)
            ));
            foreach ($extendedKeys as $fieldKey) {
                if (!array_key_exists($fieldKey, $proposedExtended)) {
                    continue;
                }
                if (ProfileFieldLockService::isLocked($profile, $fieldKey) && !$this->hasPendingConflictForField($profile->id, $fieldKey)) {
                    $current = ExtendedFieldService::getValuesForProfile($profile)[$fieldKey] ?? null;
                    $conflictRecords[] = ConflictRecord::create([
                        'profile_id' => $profile->id,
                        'field_name' => $fieldKey,
                        'field_type' => 'EXTENDED',
                        'old_value' => $current === null ? null : (string) $current,
                        'new_value' => $this->normalizeValue($proposedExtended[$fieldKey]),
                        'source' => 'SYSTEM',
                        'detected_at' => now(),
                        'resolution_status' => 'PENDING',
                    ]);
                    $conflictFieldNames[] = $fieldKey;
                }
            }

            // ——— Step 5: CORE field apply (FieldRegistry-driven) ———
            foreach ($coreFieldKeys as $fieldKey) {
                if (!array_key_exists($fieldKey, $proposedCore)) {
                    continue;
                }
                if (in_array($fieldKey, $conflictFieldNames, true)) {
                    continue;
                }
                if (ProfileFieldLockService::isLocked($profile, $fieldKey)) {
                    continue;
                }
                $oldVal = $this->getCurrentCoreValue($profile, $fieldKey);
                $newVal = $this->normalizeValue($proposedCore[$fieldKey]);
                $unchanged = (string) ($oldVal ?? '') === (string) ($newVal ?? '');
                if ($unchanged && !$profileCreatedInThisTransaction) {
                    continue;
                }
                $this->setProfileAttribute($profile, $fieldKey, $newVal);
                $this->writeProfileChangeHistory(
                    $profile->id,
                    'matrimony_profile',
                    $profile->id,
                    $fieldKey,
                    $oldVal,
                    $newVal
                );
            }
            $profile->save();

            // ——— Step 6: Contact sync (snapshot key: contacts → profile_contacts) ———
            $contactConflict = $this->syncContactsFromSnapshot($profile, $snapshot['contacts'] ?? []);
            if ($contactConflict) {
                $conflictRecords[] = $contactConflict;
                $conflictFieldNames[] = $contactConflict->field_name;
            }

            // ——— Step 7: Normalized entity sync (snapshot keys → tables) ———
            foreach (self::ENTITY_SYNC_ORDER as $snapshotKey) {
                $table = self::SNAPSHOT_KEY_TO_TABLE[$snapshotKey] ?? null;
                if ($table === null || !Schema::hasTable($table)) {
                    continue;
                }
                $proposed = $snapshot[$snapshotKey] ?? [];
                if (!is_array($proposed)) {
                    continue;
                }
                if (in_array($snapshotKey, self::SINGLE_ROW_SNAPSHOT_KEYS, true)) {
                    $this->syncSingleRowSection($profile, $table, $proposed);
                } else {
                    $this->syncEntityDiff($profile, $table, $proposed);
                }
            }

            if (isset($snapshot['preferences']) && is_array($snapshot['preferences'])) {
                $prefRow = isset($snapshot['preferences'][0]) && is_array($snapshot['preferences'][0])
                    ? $snapshot['preferences'][0]
                    : $snapshot['preferences'];
                if (is_array($prefRow)) {
                    $this->syncPreferencesFromSnapshot($profile, $prefRow);
                }
            }

            // ——— Step 8: Extended narrative (single row per profile — upsert to avoid duplicate key) ———
            $extendedNarrative = $snapshot['extended_narrative'] ?? null;
            if (Schema::hasTable('profile_extended_attributes') && is_array($extendedNarrative)) {
                $row = isset($extendedNarrative[0]) ? $extendedNarrative[0] : $extendedNarrative;
                $this->syncExtendedAttributesUpsert($profile, $row);
            }

            Log::info('MutationService::applyApprovedIntake before lifecycle transition', ['profileId' => $profile->id]);
            // ——— Step 9: Lifecycle transition ———
            $hasConflicts = count($conflictRecords) > 0;
            if ($hasConflicts) {
                \App\Services\ProfileLifecycleService::syncLifecycleFromPendingConflicts($profile);
            } else {
                $current = $profile->lifecycle_state ?? 'active';
                if (!in_array($current, self::NO_AUTO_ACTIVATE_STATES, true)) {
                    // Allow activation without photo when profile was just created from intake (intake-first flow).
                    if (empty($profile->profile_photo) && !$profileCreatedInThisTransaction) {
                        throw new \RuntimeException('Primary photo required before activation.');
                    }
                    $this->setLifecycleState($profile, 'active');
                }
            }

            Log::info('MutationService::applyApprovedIntake before intake finalization', ['hasConflicts' => $hasConflicts]);
            // ——— Step 10: Intake finalization (update only these columns to avoid touching approval_snapshot_json) ———
            $updates = ['matrimony_profile_id' => $profile->id, 'intake_locked' => true];
            if (!$hasConflicts) {
                $updates['intake_status'] = 'applied';
            }
            $intake->update($updates);

            $hadConflicts = $hasConflicts;
            $profileIdForLog = $profile->id;
            });

            Log::info('MutationService::applyApprovedIntake AFTER DB::transaction');
        } catch (\Throwable $e) {
            Log::error('MutationService::applyApprovedIntake EXCEPTION inside/around transaction', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        } finally {
            $this->mutationBatchId = null;
        }

        if ($alreadyAppliedInTransaction ?? false) {
            return [
                'mutation_success' => true,
                'conflict_detected' => false,
                'profile_id' => $profileIdForLog,
                'already_applied' => true,
            ];
        }
        if ($blockedByConflictPending ?? false) {
            return [
                'mutation_success' => false,
                'conflict_detected' => true,
                'profile_id' => $profileIdForLog,
                'blocked' => 'profile_conflict_pending',
            ];
        }

        // ——— Step 12: Mutation log (after commit) ———
        if ($this->mutationLogTableExists() && $profileIdForLog !== null) {
            $status = $duplicateDetected ? 'duplicate' : ($hadConflicts ? 'conflict' : 'applied');
            DB::table('mutation_log')->insert([
                'intake_id' => $intake->id,
                'profile_id' => $profileIdForLog,
                'mutation_status' => $status,
                'conflict_detected' => $hadConflicts || $duplicateDetected,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'mutation_success' => !$duplicateDetected && !$hadConflicts,
            'conflict_detected' => $duplicateDetected || $hadConflicts,
            'profile_id' => $profileIdForLog,
        ];
    }

    /**
     * True if a PENDING conflict already exists for this profile + field (prevents duplicate conflicts).
     */
    private function hasPendingConflictForField(int $profileId, string $fieldName): bool
    {
        return ConflictRecord::where('profile_id', $profileId)
            ->where('field_name', $fieldName)
            ->where('resolution_status', 'PENDING')
            ->exists();
    }

    /**
     * Set lifecycle_state and write ONLY to profile_change_history.
     */
    private function setLifecycleState(MatrimonyProfile $profile, string $targetState): void
    {
        $current = $profile->lifecycle_state ?? 'active';
        if ($current === $targetState) {
            return;
        }
        $this->writeProfileChangeHistory(
            $profile->id,
            'matrimony_profile',
            $profile->id,
            'lifecycle_state',
            $current,
            $targetState
        );
        $profile->lifecycle_state = $targetState;
        $profile->save();
    }

    /**
     * Sync snapshot key 'contacts' to profile_contacts. Only one primary; primary change → conflict.
     * Returns a ConflictRecord if primary change is critical and $createConflictRecord is true, else null.
     * When $createConflictRecord is false (e.g. manual edit), sync is performed but no conflict record is created.
     */
    private function syncContactsFromSnapshot(MatrimonyProfile $profile, array $proposed, bool $createConflictRecord = true): ?ConflictRecord
    {
        if (!Schema::hasTable('profile_contacts')) {
            return null;
        }
        $existing = DB::table('profile_contacts')->where('profile_id', $profile->id)->get();
        $existingPrimary = $existing->firstWhere('is_primary', true);

        // 1) If more than one proposed contact has is_primary = true, keep only the first as primary, others false.
        $primarySeen = false;
        $proposed = array_map(function ($c) use (&$primarySeen) {
            if (!is_array($c)) {
                return $c;
            }
            $isPrimary = !empty($c['is_primary']);
            if ($isPrimary && $primarySeen) {
                $c['is_primary'] = false;
                return $c;
            }
            if ($isPrimary) {
                $primarySeen = true;
            }
            return $c;
        }, $proposed);
        $proposedPrimary = collect($proposed)->firstWhere('is_primary', true);

        $contactConflict = null;
        if ($existingPrimary && $proposedPrimary && isset($proposedPrimary['phone_number'])) {
            $existingPhone = trim((string) ($existingPrimary->phone_number ?? ''));
            $proposedPhone = trim((string) ($proposedPrimary['phone_number'] ?? ''));
            if ($existingPhone !== '' && $proposedPhone !== '' && $existingPhone !== $proposedPhone && $createConflictRecord && !$this->hasPendingConflictForField($profile->id, 'primary_contact_number')) {
                $contactConflict = ConflictRecord::create([
                    'profile_id' => $profile->id,
                    'field_name' => 'primary_contact_number',
                    'field_type' => 'CORE',
                    'old_value' => $existingPhone,
                    'new_value' => $proposedPhone,
                    'source' => 'SYSTEM',
                    'detected_at' => now(),
                    'resolution_status' => 'PENDING',
                ]);
            }
        }

        // 2) Before syncing: clear is_primary on all existing contacts for this profile.
        DB::table('profile_contacts')
            ->where('profile_id', $profile->id)
            ->update(['is_primary' => false]);

        // 3) Exactly one in $proposed has is_primary = true (or none — we do not auto-create).
        $this->syncEntityDiff($profile, 'profile_contacts', $proposed);
        return $contactConflict;
    }

    /**
     * Sync a single entity from snapshot key (e.g. manual profile edit). No other snapshot keys affected.
     */
    public function syncEntityFromSnapshot(MatrimonyProfile $profile, string $snapshotKey, array $proposed): void
    {
        $table = self::SNAPSHOT_KEY_TO_TABLE[$snapshotKey] ?? null;
        if ($table === null || !Schema::hasTable($table)) {
            return;
        }
        $this->syncEntityDiff($profile, $table, $proposed);
    }

    /**
     * Map snapshot row keys to table columns. Phase-5: resolve string lookups to *_id for entity tables.
     */
    private function mapSnapshotRowToTable(string $entityType, array $row): array
    {
        if ($entityType === 'profile_education') {
            $mapped = $row;
            if (array_key_exists('institution', $mapped)) {
                $mapped['university'] = $mapped['institution'];
                unset($mapped['institution']);
            }
            if (array_key_exists('year', $mapped)) {
                $mapped['year_completed'] = $mapped['year'] !== null && $mapped['year'] !== '' ? (int) $mapped['year'] : 0;
                unset($mapped['year']);
            }
            if (!array_key_exists('year_completed', $mapped)) {
                $mapped['year_completed'] = 0;
            }
            return $mapped;
        }
        if ($entityType === 'profile_addresses') {
            $mapped = $row;
            if (array_key_exists('type', $mapped)) {
                $mapped['address_type'] = $mapped['type'];
                unset($mapped['type']);
            }
            if (array_key_exists('raw', $mapped)) {
                unset($mapped['raw']);
            }
            $mapped['village_id'] = $mapped['village_id'] ?? null;
            $mapped = $this->resolveAddressTypeToId($mapped);
            return $mapped;
        }
        if ($entityType === 'profile_contacts') {
            $mapped = $this->resolveContactRelationToId($row);
            return $mapped;
        }
        if ($entityType === 'profile_children') {
            $mapped = $this->resolveChildLivingWithToId($row);
            return $mapped;
        }
        if ($entityType === 'profile_property_assets') {
            $mapped = $this->resolvePropertyAssetLookupsToId($row);
            return $mapped;
        }
        if ($entityType === 'profile_legal_cases') {
            $mapped = $this->resolveLegalCaseTypeToId($row);
            return $mapped;
        }
        if ($entityType === 'profile_horoscope_data') {
            $mapped = $this->resolveHoroscopeLookupsToId($row);
            return $mapped;
        }
        if ($entityType === 'profile_relatives') {
            $mapped = $row;
            $mapped['relation_type'] = trim((string) ($mapped['relation_type'] ?? ''));
            $mapped['name'] = trim((string) ($mapped['name'] ?? ''));
            $mapped['occupation'] = isset($mapped['occupation']) && trim((string) $mapped['occupation']) !== '' ? trim((string) $mapped['occupation']) : null;
            $mapped['city_id'] = ! empty($mapped['city_id']) ? (int) $mapped['city_id'] : null;
            $mapped['state_id'] = ! empty($mapped['state_id']) ? (int) $mapped['state_id'] : null;
            $mapped['contact_number'] = isset($mapped['contact_number']) && trim((string) $mapped['contact_number']) !== '' ? trim((string) $mapped['contact_number']) : null;
            $mapped['notes'] = isset($mapped['notes']) && trim((string) $mapped['notes']) !== '' ? trim((string) $mapped['notes']) : null;
            $mapped['is_primary_contact'] = ! empty($mapped['is_primary_contact']);
            return $mapped;
        }
        if ($entityType === 'profile_alliance_networks') {
            $mapped = $row;
            $mapped['surname'] = trim((string) ($mapped['surname'] ?? ''));
            $mapped['city_id'] = ! empty($mapped['city_id']) ? (int) $mapped['city_id'] : null;
            $mapped['taluka_id'] = ! empty($mapped['taluka_id']) ? (int) $mapped['taluka_id'] : null;
            $mapped['district_id'] = ! empty($mapped['district_id']) ? (int) $mapped['district_id'] : null;
            $mapped['state_id'] = ! empty($mapped['state_id']) ? (int) $mapped['state_id'] : null;
            $mapped['notes'] = isset($mapped['notes']) && trim((string) $mapped['notes']) !== '' ? trim((string) $mapped['notes']) : null;
            return $mapped;
        }
        if ($entityType === 'profile_siblings') {
            $mapped = $row;
            $mapped['gender'] = in_array($mapped['gender'] ?? null, ['male', 'female'], true) ? $mapped['gender'] : null;
            $mapped['marital_status'] = in_array($mapped['marital_status'] ?? null, ['unmarried', 'married'], true) ? $mapped['marital_status'] : null;
            $mapped['occupation'] = isset($mapped['occupation']) && trim((string) $mapped['occupation']) !== '' ? trim((string) $mapped['occupation']) : null;
            $mapped['city_id'] = ! empty($mapped['city_id']) ? (int) $mapped['city_id'] : null;
            $mapped['notes'] = isset($mapped['notes']) && trim((string) $mapped['notes']) !== '' ? trim((string) $mapped['notes']) : null;
            return $mapped;
        }
        return $row;
    }

    private function resolveMasterKey(string $table, ?string $key): ?int
    {
        if ($key === null || trim((string) $key) === '') {
            return null;
        }
        $normalized = str_replace(' ', '_', strtolower(trim($key)));
        $id = DB::table($table)->where('key', $normalized)->value('id');
        return $id !== null ? (int) $id : null;
    }

    private function resolveAddressTypeToId(array $row): array
    {
        $mapped = $row;
        $val = $mapped['address_type_id'] ?? $mapped['address_type'] ?? $mapped['type'] ?? null;
        if ($val !== null && !is_numeric($val)) {
            $mapped['address_type_id'] = $this->resolveMasterKey('master_address_types', $val);
        } elseif (isset($mapped['address_type_id']) && is_numeric($mapped['address_type_id'])) {
            $mapped['address_type_id'] = (int) $mapped['address_type_id'];
        }
        unset($mapped['address_type'], $mapped['type']);
        return $mapped;
    }

    private function resolveContactRelationToId(array $row): array
    {
        $mapped = $row;
        $val = $mapped['contact_relation_id'] ?? $mapped['relation_type'] ?? null;
        if ($val !== null && !is_numeric($val)) {
            $mapped['contact_relation_id'] = $this->resolveMasterKey('master_contact_relations', $val);
        } elseif (isset($mapped['contact_relation_id']) && is_numeric($mapped['contact_relation_id'])) {
            $mapped['contact_relation_id'] = (int) $mapped['contact_relation_id'];
        }
        unset($mapped['relation_type']);
        return $mapped;
    }

    private function resolveChildLivingWithToId(array $row): array
    {
        $mapped = $row;
        if (array_key_exists('child_living_with_id', $mapped) && is_numeric($mapped['child_living_with_id'])) {
            $mapped['child_living_with_id'] = (int) $mapped['child_living_with_id'];
            unset($mapped['lives_with_parent']);
            return $mapped;
        }
        if (array_key_exists('lives_with_parent', $mapped)) {
            $withParent = $mapped['lives_with_parent'];
            $key = ($withParent === true || $withParent === '1' || $withParent === 1) ? 'with_parent' : 'with_other_parent';
            $mapped['child_living_with_id'] = $this->resolveMasterKey('master_child_living_with', $key);
        }
        unset($mapped['lives_with_parent']);
        return $mapped;
    }

    private function resolvePropertyAssetLookupsToId(array $row): array
    {
        $mapped = $row;
        $val = $mapped['asset_type_id'] ?? $mapped['asset_type'] ?? null;
        if ($val !== null && !is_numeric($val)) {
            $mapped['asset_type_id'] = $this->resolveMasterKey('master_asset_types', $val);
        } elseif (isset($mapped['asset_type_id']) && is_numeric($mapped['asset_type_id'])) {
            $mapped['asset_type_id'] = (int) $mapped['asset_type_id'];
        }
        unset($mapped['asset_type']);
        $val = $mapped['ownership_type_id'] ?? $mapped['ownership_type'] ?? null;
        if ($val !== null && !is_numeric($val)) {
            $mapped['ownership_type_id'] = $this->resolveMasterKey('master_ownership_types', $val);
        } elseif (isset($mapped['ownership_type_id']) && is_numeric($mapped['ownership_type_id'])) {
            $mapped['ownership_type_id'] = (int) $mapped['ownership_type_id'];
        }
        unset($mapped['ownership_type']);
        return $mapped;
    }

    private function resolveLegalCaseTypeToId(array $row): array
    {
        $mapped = $row;
        $val = $mapped['legal_case_type_id'] ?? $mapped['case_type'] ?? null;
        if ($val !== null && !is_numeric($val)) {
            $mapped['legal_case_type_id'] = $this->resolveMasterKey('master_legal_case_types', $val);
        } elseif (isset($mapped['legal_case_type_id']) && is_numeric($mapped['legal_case_type_id'])) {
            $mapped['legal_case_type_id'] = (int) $mapped['legal_case_type_id'];
        }
        unset($mapped['case_type']);
        return $mapped;
    }

    private function resolveHoroscopeLookupsToId(array $row): array
    {
        $mapped = $row;
        $pairs = [
            'rashi' => ['rashi_id', 'master_rashis'],
            'nakshatra' => ['nakshatra_id', 'master_nakshatras'],
            'gan' => ['gan_id', 'master_gans'],
            'nadi' => ['nadi_id', 'master_nadis'],
            'mangal_dosh_type' => ['mangal_dosh_type_id', 'master_mangal_dosh_types'],
            'yoni' => ['yoni_id', 'master_yonis'],
        ];
        foreach ($pairs as $strKey => [$idCol, $masterTable]) {
            $val = $mapped[$idCol] ?? $mapped[$strKey] ?? null;
            if ($val !== null && !is_numeric($val)) {
                $mapped[$idCol] = $this->resolveMasterKey($masterTable, $val);
            } elseif (isset($mapped[$idCol]) && is_numeric($mapped[$idCol])) {
                $mapped[$idCol] = (int) $mapped[$idCol];
            }
            unset($mapped[$strKey]);
        }
        return $mapped;
    }

    /**
     * Upsert single row for profile_extended_attributes (one row per profile_id) to avoid duplicate key.
     */
    private function syncExtendedAttributesUpsert(MatrimonyProfile $profile, array $row): void
    {
        if (! is_array($row) || ! Schema::hasTable('profile_extended_attributes')) {
            return;
        }
        $data = [
            'narrative_about_me' => isset($row['narrative_about_me']) ? trim((string) $row['narrative_about_me']) : null,
            'narrative_expectations' => isset($row['narrative_expectations']) ? trim((string) $row['narrative_expectations']) : null,
            'additional_notes' => isset($row['additional_notes']) ? trim((string) $row['additional_notes']) : null,
        ];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $existing = DB::table('profile_extended_attributes')->where('profile_id', $profile->id)->first();
        if ($existing) {
            $changes = [];
            foreach (['narrative_about_me', 'narrative_expectations', 'additional_notes'] as $col) {
                $old = $existing->$col ?? null;
                $new = $data[$col] ?? null;
                if ((string) ($old ?? '') !== (string) ($new ?? '')) {
                    $changes[$col] = ['old' => $old, 'new' => $new];
                }
            }
            if (! empty($changes)) {
                $data['updated_at'] = now();
                DB::table('profile_extended_attributes')->where('profile_id', $profile->id)->update($data);
                foreach ($changes as $fieldName => $vals) {
                    $this->writeProfileChangeHistory($profile->id, 'profile_extended_attributes', (int) $existing->id, $fieldName, $vals['old'], $vals['new']);
                }
            }
        } else {
            $data['profile_id'] = $profile->id;
            $data['created_at'] = now();
            $data['updated_at'] = now();
            DB::table('profile_extended_attributes')->insert($data);
            $this->writeProfileChangeHistory($profile->id, 'profile_extended_attributes', null, 'insert', null, json_encode($data));
        }
    }

    /**
     * Sync preferences from snapshot to profile_preference_criteria + pivot tables.
     * Writes only when tables exist. No reference to profile_preferences.
     */
    private function syncPreferencesFromSnapshot(MatrimonyProfile $profile, array $proposed): void
    {
        if (!is_array($proposed)) {
            return;
        }
        $profileId = $profile->id;

        if (Schema::hasTable('profile_preference_criteria')) {
            $allowed = ['preferred_age_min', 'preferred_age_max', 'preferred_income_min', 'preferred_income_max', 'preferred_education', 'preferred_city_id'];
            $data = [];
            foreach ($allowed as $col) {
                if (array_key_exists($col, $proposed)) {
                    $v = $proposed[$col];
                    $data[$col] = $v === '' || $v === null ? null : $v;
                }
            }
            $existing = DB::table('profile_preference_criteria')->where('profile_id', $profileId)->first();
            $data['updated_at'] = now();
            if ($existing) {
                $changes = [];
                foreach ($data as $col => $newVal) {
                    if (in_array($col, ['created_at', 'updated_at'], true)) {
                        continue;
                    }
                    $oldVal = $existing->$col ?? null;
                    if ((string) ($oldVal ?? '') !== (string) ($newVal ?? '')) {
                        $changes[$col] = ['old' => $oldVal, 'new' => $newVal];
                    }
                }
                if (!empty($changes)) {
                    DB::table('profile_preference_criteria')->where('profile_id', $profileId)->update($data);
                    foreach ($changes as $fieldName => $vals) {
                        $this->writeProfileChangeHistory($profileId, 'profile_preference_criteria', (int) $existing->id, $fieldName, $vals['old'], $vals['new']);
                    }
                }
            } else {
                $data['profile_id'] = $profileId;
                $data['created_at'] = now();
                DB::table('profile_preference_criteria')->insert($data);
                $this->writeProfileChangeHistory($profileId, 'profile_preference_criteria', null, 'insert', null, json_encode($data));
            }
        }

        $pivotConfig = [
            'profile_preferred_religions' => ['preferred_religion_ids', 'religion_id'],
            'profile_preferred_castes' => ['preferred_caste_ids', 'caste_id'],
            'profile_preferred_districts' => ['preferred_district_ids', 'district_id'],
        ];
        foreach ($pivotConfig as $table => [$key, $fkCol]) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $ids = $proposed[$key] ?? [];
            if (!is_array($ids)) {
                $ids = [];
            }
            $ids = array_filter(array_map('intval', $ids));
            $existingIds = DB::table($table)->where('profile_id', $profileId)->pluck($fkCol)->map(function ($id) {
                return (int) $id;
            })->all();
            $toAdd = array_diff($ids, $existingIds);
            $toRemove = array_diff($existingIds, $ids);
            foreach ($toRemove as $removeId) {
                DB::table($table)->where('profile_id', $profileId)->where($fkCol, $removeId)->delete();
                $this->writeProfileChangeHistory($profileId, $table, null, 'delete', (string) $removeId, null);
            }
            foreach ($toAdd as $addId) {
                DB::table($table)->insert([
                    'profile_id' => $profileId,
                    $fkCol => $addId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->writeProfileChangeHistory($profileId, $table, null, 'insert', null, (string) $addId);
            }
        }
    }

    /**
     * Sync a single-row-per-profile section (e.g. profile_property_summary).
     * If row exists → UPDATE; if not → INSERT with profile_id. Ensures exactly one row per profile_id.
     */
    private function syncSingleRowSection(MatrimonyProfile $profile, string $table, array $proposed): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }
        $row = isset($proposed[0]) && is_array($proposed[0]) ? $proposed[0] : $proposed;
        if (!is_array($row)) {
            return;
        }
        $row = $this->mapSnapshotRowToTable($table, $row);
        $allowedColumns = array_flip(Schema::getColumnListing($table));
        $data = [];
        foreach ($row as $col => $value) {
            if ($col === 'id' || $col === 'profile_id') {
                continue;
            }
            if (!isset($allowedColumns[$col])) {
                continue;
            }
            $data[$col] = $value;
        }
        $existing = DB::table($table)->where('profile_id', $profile->id)->first();
        if ($existing) {
            $changes = [];
            foreach ($data as $col => $newVal) {
                $oldVal = $existing->$col ?? null;
                if ((string) ($oldVal ?? '') !== (string) ($newVal ?? '')) {
                    $changes[$col] = ['old' => $oldVal, 'new' => $newVal];
                }
            }
            if (!empty($changes)) {
                $updateData = $data;
                $updateData['updated_at'] = now();
                DB::table($table)->where('profile_id', $profile->id)->update($updateData);
                foreach ($changes as $fieldName => $vals) {
                    $this->writeProfileChangeHistory(
                        $profile->id,
                        $table,
                        (int) $existing->id,
                        $fieldName,
                        $vals['old'],
                        $vals['new']
                    );
                }
            }
        } else {
            $insertData = array_merge(['profile_id' => $profile->id], $data);
            $insertData['created_at'] = now();
            $insertData['updated_at'] = now();
            DB::table($table)->insert($insertData);
            $this->writeProfileChangeHistory($profile->id, $table, null, 'insert', null, json_encode($data));
        }
    }

    private function syncEntityDiff(MatrimonyProfile $profile, string $entityType, array $proposed): void
    {
        if (!Schema::hasTable($entityType)) {
            return;
        }
        $existing = DB::table($entityType)->where('profile_id', $profile->id)->get()->keyBy('id');
        foreach ($proposed as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row = $this->mapSnapshotRowToTable($entityType, $row);
            $id = isset($row['id']) ? (int) $row['id'] : null;
            $existingRow = $id !== null ? $existing->get($id) : null;
            if ($existingRow === null) {
                $insertData = array_merge(['profile_id' => $profile->id], $row);
                unset($insertData['id']);
                $insertData['created_at'] = now();
                $insertData['updated_at'] = now();
                DB::table($entityType)->insert($insertData);
                $this->writeProfileChangeHistory(
                    $profile->id,
                    $entityType,
                    null,
                    'insert',
                    null,
                    json_encode($row)
                );
            } else {
                $changes = [];
                foreach ($row as $col => $newVal) {
                    if (in_array($col, ['id', 'profile_id', 'created_at', 'updated_at'], true)) {
                        continue;
                    }
                    $oldVal = $existingRow->$col ?? null;
                    if ((string) ($oldVal ?? '') !== (string) ($newVal ?? '')) {
                        $changes[$col] = ['old' => $oldVal, 'new' => $newVal];
                    }
                }
                if (!empty($changes)) {
                    $updateData = collect($row)->except(['id', 'profile_id', 'created_at'])->all();
                    $updateData['updated_at'] = now();
                    DB::table($entityType)->where('id', $id)->update($updateData);
                    foreach ($changes as $fieldName => $vals) {
                        $this->writeProfileChangeHistory(
                            $profile->id,
                            $entityType,
                            (int) $id,
                            $fieldName,
                            $vals['old'],
                            $vals['new']
                        );
                    }
                }
            }
        }

        // Log deletion intent for existing rows not present in proposed snapshot (no hard delete).
        $proposedIds = [];
        foreach ($proposed as $row) {
            if (is_array($row) && isset($row['id'])) {
                $proposedIds[(int) $row['id']] = true;
            }
        }
        foreach ($existing as $id => $existingRow) {
            if (isset($proposedIds[$id])) {
                continue;
            }
            $this->writeProfileChangeHistory(
                $profile->id,
                $entityType,
                (int) $id,
                'delete',
                json_encode($existingRow),
                null
            );
        }
    }

    /**
     * PART-2: Apply extended_fields (key-value) inside transaction; write profile_change_history only.
     */
    private function applyExtendedFieldsFromSnapshot(MatrimonyProfile $profile, array $extendedFields): void
    {
        $currentValues = ExtendedFieldService::getValuesForProfile($profile);
        foreach ($extendedFields as $fieldKey => $value) {
            $registry = FieldRegistry::where('field_key', $fieldKey)->where('field_type', 'EXTENDED')->first();
            if (!$registry) {
                throw ValidationException::withMessages([$fieldKey => ['Extended field is not defined in registry.']]);
            }
            if (($registry->is_enabled ?? true) === false) {
                continue;
            }
            $row = ProfileExtendedField::firstOrNew([
                'profile_id' => $profile->id,
                'field_key' => $fieldKey,
            ]);
            if (($registry->is_archived ?? false) && !$row->exists) {
                throw ValidationException::withMessages([$fieldKey => ['This field is archived and cannot be set for new entry.']]);
            }
            if (!ExtendedFieldService::validateValue($registry, $value)) {
                throw ValidationException::withMessages([$fieldKey => ['Invalid value for data type ' . $registry->data_type . '.']]);
            }
            $newValue = ExtendedFieldService::normalizeValueForMutation($registry, $value);
            $newValue = $newValue === '' ? null : $newValue;
            $oldValue = $row->exists ? ($row->field_value ?? null) : ($currentValues[$fieldKey] ?? null);
            $oldValue = $oldValue === '' ? null : $oldValue;
            if ((string) ($oldValue ?? '') === (string) ($newValue ?? '')) {
                continue;
            }
            $row->field_value = $newValue;
            $row->save();
            $this->writeProfileChangeHistory(
                $profile->id,
                'profile_extended_field',
                $row->id,
                $fieldKey,
                $oldValue,
                $newValue
            );
        }
    }

    private function getCoreFieldKeysFromRegistry(): array
    {
        $query = FieldRegistry::where('field_type', 'CORE')
            ->where(function ($q) {
                $q->where('is_archived', false)->orWhereNull('is_archived');
            })
            ->whereNull('replaced_by_field');
        if (Schema::hasColumn((new FieldRegistry)->getTable(), 'is_enabled')) {
            $query->where(function ($q) {
                $q->where('is_enabled', true)->orWhereNull('is_enabled');
            });
        }
        $keys = $query->pluck('field_key')->values()->all();
        return $keys !== [] ? $keys : self::FALLBACK_CORE_KEYS;
    }

    private function getCurrentCoreValue(MatrimonyProfile $profile, string $fieldKey): mixed
    {
        if ($fieldKey === 'gender_id') {
            return $profile->getAttribute('gender_id');
        }
        if ($fieldKey === 'primary_contact_number') {
            return DB::table('profile_contacts')
                ->where('profile_id', $profile->id)
                ->where('is_primary', true)
                ->value('phone_number');
        }
        if ($fieldKey === 'location') {
            return $profile->city_id ?? $profile->state_id ?? $profile->country_id ?? null;
        }
        return $profile->getAttribute($fieldKey);
    }

    private function normalizeValue(mixed $value): mixed
    {
        // If array (e.g. admin_edited_fields JSON column), return as-is
        if (is_array($value)) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        $s = is_string($value) ? trim($value) : (string) $value;

        return $s === '' ? null : $s;
    }

    private function setProfileAttribute(MatrimonyProfile $profile, string $fieldKey, $value): void
    {
        if ($fieldKey === 'location') {
            return;
        }
        $profile->setAttribute($fieldKey, $value);
    }

    /**
     * Mutation path: write ONLY to profile_change_history (no field_value_history).
     * Uses historySourceContext / historyChangedByContext when set (manual mutation).
     */
    private function writeProfileChangeHistory(
        int $profileId,
        string $entityType,
        ?int $entityId,
        string $fieldName,
        $oldValue,
        $newValue
    ): void {
        if (!Schema::hasTable('profile_change_history')) {
            return;
        }
        $source = $this->historySourceContext ?? 'intake';
        $changedBy = $this->historyChangedByContext;
        $data = [
            'profile_id' => $profileId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'field_name' => $fieldName,
            'old_value' => $oldValue === null
                ? null
                : (is_array($oldValue) ? json_encode($oldValue) : (string) $oldValue),
            'new_value' => $newValue === null
                ? null
                : (is_array($newValue) ? json_encode($newValue) : (string) $newValue),
            'changed_by' => $changedBy,
            'source' => $source,
            'changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        // PART-4: Future-ready mutation grouping; only if column exists (no schema change).
        if ($this->mutationBatchId !== null && Schema::hasColumn('profile_change_history', 'mutation_batch_id')) {
            $data['mutation_batch_id'] = $this->mutationBatchId;
        }
        DB::table('profile_change_history')->insert($data);
    }

    private function mutationLogTableExists(): bool
    {
        if (!Schema::hasTable('mutation_log')) {
            return false;
        }
        return true;
    }
}
