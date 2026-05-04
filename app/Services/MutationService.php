<?php

namespace App\Services;

use App\Models\BiodataIntake;
use App\Models\ConflictRecord;
use App\Models\FieldRegistry;
use App\Models\MatrimonyProfile;
use App\Models\ProfileExtendedField;
use App\Models\User;
use App\Services\Core\ConflictPolicy;
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
        'marriages' => 'profile_marriages',
        'siblings' => 'profile_siblings',
        'relatives' => 'profile_relatives',
        'alliance_networks' => 'profile_alliance_networks',
        'education_history' => 'profile_education',
        'career_history' => 'profile_career',
        'addresses' => 'profile_addresses',
        'property_summary' => 'profile_property_summary',
        'property_assets' => 'profile_property_assets',
        'horoscope' => 'profile_horoscope_data',
        'extended_narrative' => 'profile_extended_attributes',
    ];

    /** Entity sync order for step 7 (excluding contacts — step 6). */
    private const ENTITY_SYNC_ORDER = [
        'children',
        'siblings',
        'marriages',
        'relatives',
        'alliance_networks',
        'education_history',
        'career_history',
        'addresses',
        'property_summary',
        'property_assets',
        'horoscope',
    ];

    /** Snapshot keys that are exactly ONE row per profile_id (upsert by profile_id, not by row id). */
    private const SINGLE_ROW_SNAPSHOT_KEYS = [
        'property_summary',
    ];

    /** States that must NOT be auto-activated (contract §6). */
    private const NO_AUTO_ACTIVATE_STATES = ['suspended', 'archived', 'archived_due_to_marriage'];

    /** Fallback CORE keys when registry has no CORE rows. Phase-5: *_id for master lookups. address_line so intake address shows in wizard. */
    private const FALLBACK_CORE_KEYS = [
        'full_name', 'gender_id', 'date_of_birth', 'birth_time', 'marital_status_id', 'has_children', 'has_siblings', 'highest_education',
        'location', 'location_id', 'religion_id', 'caste_id', 'sub_caste_id', 'mother_tongue_id', 'height_cm', 'profile_photo',
        'complexion_id', 'physical_build_id', 'blood_group_id', 'diet_id', 'smoking_status_id', 'drinking_status_id', 'family_type_id', 'income_currency_id',
        'address_line', 'annual_income', 'family_income', 'income_private', 'family_income_private',
        'birth_place_text', 'work_location_text',
        'father_name', 'father_occupation', 'father_occupation_master_id', 'father_occupation_custom_id', 'father_extra_info', 'father_contact_1', 'father_contact_2', 'father_contact_3',
        'mother_name', 'mother_occupation', 'mother_occupation_master_id', 'mother_occupation_custom_id', 'mother_extra_info', 'mother_contact_1', 'mother_contact_2', 'mother_contact_3',
        'other_relatives_text',
        // photo_approved / photo_rejected_at / photo_rejection_reason: NOT snapshot-driven (see ProcessProfilePhoto + ProfilePhotoPendingStateService)
        'is_suspended',
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
        \Log::info('DEBUG APPLY SNAPSHOT KEYS', array_keys($snapshot));
        \Log::info('DEBUG APPLY SNAPSHOT', [
            'keys' => array_keys($snapshot),
            'marriages_value' => $snapshot['marriages'] ?? 'NOT_PRESENT',
        ]);

        $state = $profile->lifecycle_state ?? '';
        $isAdmin = $mode === 'admin';
        if (! $isAdmin && in_array($state, self::BLOCK_MANUAL_EDIT_STATES, true)) {
            throw new \RuntimeException('Profile cannot be edited while intake or conflict is pending. Lifecycle: '.$state);
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
            DB::transaction(function () use ($profile, $snapshot, $isAdmin, $mode, &$hadConflicts): void {
                $proposedCore = $snapshot['core'] ?? [];
                if ($mode === 'manual') {
                    $proposedCore = $this->stripPhotoModerationFromManualCore($proposedCore);
                }
                $proposedExtended = $this->proposedExtendedForManualSnapshotConflictDetection($snapshot);
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
                            if (! empty($contacts)) {
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
                            if (! $this->hasPendingConflictForField($profile->id, 'duplicate_detection')) {
                                ConflictPolicy::create([
                                    'profile_id' => $profile->id,
                                    'field_name' => 'duplicate_detection',
                                    'field_type' => 'CORE',
                                    'old_value' => null,
                                    'new_value' => $duplicateResult->duplicateType.':'.$duplicateResult->existingProfileId,
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
                    if (! $isAdmin) {
                        foreach ($coreFieldKeys as $fieldKey) {
                            if (! array_key_exists($fieldKey, $proposedCore)) {
                                continue;
                            }
                            if (ProfileFieldLockService::isLocked($profile, $fieldKey) && ! $this->hasPendingConflictForField($profile->id, $fieldKey)) {
                                $conflictRecords[] = ConflictPolicy::create([
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
                            if (! array_key_exists($fieldKey, $proposedExtended)) {
                                continue;
                            }
                            if (ProfileFieldLockService::isLocked($profile, $fieldKey) && ! $this->hasPendingConflictForField($profile->id, $fieldKey)) {
                                $current = ExtendedFieldService::getValuesForProfile($profile)[$fieldKey] ?? null;
                                $conflictRecords[] = ConflictPolicy::create([
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
                    if (! array_key_exists($fieldKey, $proposedCore)) {
                        continue;
                    }
                    if (in_array($fieldKey, $conflictFieldNames, true)) {
                        continue;
                    }
                    if (! $isAdmin && ProfileFieldLockService::isLocked($profile, $fieldKey)) {
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
                        if (! Schema::hasColumn($profile->getTable(), $fieldKey)) {
                            continue;
                        }
                        \Illuminate\Support\Facades\Log::warning('Core column missing in registry', [
                            'field' => $fieldKey,
                            'profile_id' => $profile->id,
                            'source' => $isAdmin ? 'admin' : 'manual',
                        ]);
                        if (! $isAdmin && ProfileFieldLockService::isLocked($profile, $fieldKey)) {
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
                        $profile->setAttribute($fieldKey, $norm);
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
                    \Log::info('DEBUG MULTI ROW SECTION', ['section' => $snapshotKey]);
                    if (! array_key_exists($snapshotKey, $snapshot) || ! is_array($snapshot[$snapshotKey])) {
                        continue;
                    }
                    if ($snapshotKey === 'siblings' && Schema::hasTable('profile_siblings')) {
                        $this->syncSiblingsWithSpouses($profile, $snapshot['siblings']);

                        continue;
                    }
                    if ($snapshotKey === 'horoscope' && Schema::hasTable('profile_horoscope_data')) {
                        $this->syncHoroscopeUpsert($profile, $snapshot['horoscope']);

                        continue;
                    }
                    $table = self::SNAPSHOT_KEY_TO_TABLE[$snapshotKey] ?? null;
                    if ($table === null || ! Schema::hasTable($table)) {
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
                    // Showcase profiles stay draft until admin publishes from bulk-create; do not
                    // treat photo upload / wizard edits as "go live" for member search.
                    if ($current === 'draft' && ! empty($profile->profile_photo) && ! in_array($current, self::NO_AUTO_ACTIVATE_STATES, true)) {
                        if (! $profile->isShowcaseProfile()) {
                            $this->setLifecycleState($profile, 'active');
                        }
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
            'mutation_success' => ! $hadConflicts,
            'conflict_detected' => $hadConflicts,
            'profile_id' => $profile->id,
        ];
    }

    /**
     * Proposed extended key-value payload for ConflictDetectionService inside applyManualSnapshot only.
     * KV applies later via `extended_fields`; some callers still pass legacy `extended`. When both arrays are
     * non-empty, array_replace(extended, extended_fields) matches overlapping keys to the applied snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function proposedExtendedForManualSnapshotConflictDetection(array $snapshot): array
    {
        $extended = (isset($snapshot['extended']) && is_array($snapshot['extended'])) ? $snapshot['extended'] : [];
        $extendedFields = (isset($snapshot['extended_fields']) && is_array($snapshot['extended_fields'])) ? $snapshot['extended_fields'] : [];

        if ($extended !== []) {
            return $extendedFields !== [] ? array_replace($extended, $extendedFields) : $extended;
        }

        return $extendedFields;
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
     * Apply intake to profile. Direct path: pass snapshot in memory so we write to DB without save-then-read.
     *
     * @param  array<string, mixed>|null  $snapshot  When provided, use this (direct form→DB); intake row updated at end with this for audit.
     * @param  array{manual_edits?: int, auto_filled?: int}|null  $metrics  Optional when snapshot passed; stored on intake.
     * @return array{mutation_success: bool, conflict_detected: bool, profile_id: int|null}
     */
    public function applyApprovedIntake(int $intakeId, ?array $snapshot = null, ?array $metrics = null): array
    {
        Log::info('MutationService::applyApprovedIntake START', ['intakeId' => $intakeId, 'snapshotInMemory' => $snapshot !== null]);

        $intake = BiodataIntake::find($intakeId);
        if (! $intake) {
            throw new \RuntimeException("BiodataIntake not found: {$intakeId}");
        }
        if ($snapshot === null) {
            if (empty($intake->approval_snapshot_json)) {
                throw new \RuntimeException('Intake must have approval_snapshot_json.');
            }
            if ($intake->approved_by_user !== true) {
                throw new \RuntimeException('Intake must be approved (approved_by_user = true).');
            }
        }
        if ($intake->intake_status === 'applied' || $intake->intake_locked === true) {
            return [
                'mutation_success' => true,
                'conflict_detected' => false,
                'profile_id' => $intake->matrimony_profile_id,
                'already_applied' => true,
            ];
        }

        $snapshotPassedInMemory = $snapshot !== null;
        $snapshot = $snapshot !== null ? $snapshot : (is_array($intake->approval_snapshot_json) ? $intake->approval_snapshot_json : []);
        if (empty($snapshot)) {
            throw new \RuntimeException('Intake must have approval_snapshot_json or snapshot passed.');
        }
        if (! isset($snapshot['snapshot_schema_version'])) {
            $snapshot['snapshot_schema_version'] = 1;
        }
        $version = $intake->snapshot_schema_version ?? $snapshot['snapshot_schema_version'] ?? 1;
        $version = $version !== null ? (int) $version : null;
        if ($version === null || ! in_array($version, self::SUPPORTED_SNAPSHOT_VERSIONS, true)) {
            throw new \RuntimeException('Unsupported or missing snapshot_schema_version. Supported: [1].');
        }
        $snapshotInMemory = $snapshotPassedInMemory;
        Log::info('MutationService::applyApprovedIntake after snapshot version check', [
            'version' => $version,
            'version_type' => gettype($version),
            'snapshotInMemory' => $snapshotInMemory,
        ]);

        $duplicateDetected = false;
        $hadConflicts = false;
        $profileIdForLog = null;
        $blockedByConflictPending = false;
        $alreadyAppliedInTransaction = false;

        Log::info('MutationService::applyApprovedIntake BEFORE DB::transaction');
        $this->mutationBatchId = now()->timestamp;

        try {
            DB::transaction(function () use ($intake, $snapshot, $metrics, $snapshotInMemory, $version, &$duplicateDetected, &$hadConflicts, &$profileIdForLog, &$blockedByConflictPending, &$alreadyAppliedInTransaction): void {
                $intakeId = $intake->id;
                $intake = BiodataIntake::where('id', $intakeId)->lockForUpdate()->first();
                if (! $intake) {
                    throw new \RuntimeException("BiodataIntake not found (locked): {$intakeId}");
                }
                if ($intake->intake_locked === true) {
                    $profileIdForLog = $intake->matrimony_profile_id;
                    $alreadyAppliedInTransaction = true;

                    return;
                }

                // Full approval payload for audit (never strip). Working copy may lose keys we refuse to overwrite.
                $auditSnapshot = json_decode(json_encode($snapshot), true);
                $snapshot = json_decode(json_encode($auditSnapshot), true);

                $proposedCore = $snapshot['core'] ?? [];
                $proposedExtended = $snapshot['extended'] ?? [];
                if (array_key_exists('other_relatives_text', $snapshot) && ! array_key_exists('other_relatives_text', $proposedCore)) {
                    $proposedCore['other_relatives_text'] = $snapshot['other_relatives_text'];
                }
                // Ensure birth_place_text is set when core has only scalar birth_place (so Full profile shows it).
                if ((empty($proposedCore['birth_place_text']) || trim((string) ($proposedCore['birth_place_text'] ?? '')) === '')
                    && ! empty($proposedCore['birth_place']) && is_scalar($proposedCore['birth_place']) && trim((string) $proposedCore['birth_place']) !== '') {
                    $proposedCore['birth_place_text'] = trim((string) $proposedCore['birth_place']);
                }
                // Map income-engine keys to profile columns (intake/full form may send income_amount, nested income.amount, or income_normalized_annual_amount).
                if (! isset($proposedCore['annual_income']) || $proposedCore['annual_income'] === '' || $proposedCore['annual_income'] === null) {
                    if (isset($proposedCore['income_normalized_annual_amount']) && is_numeric($proposedCore['income_normalized_annual_amount'])) {
                        $proposedCore['annual_income'] = (float) $proposedCore['income_normalized_annual_amount'];
                    } elseif (isset($proposedCore['income_amount']) && is_numeric($proposedCore['income_amount'])) {
                        $proposedCore['annual_income'] = (float) $proposedCore['income_amount'];
                    } elseif (isset($proposedCore['income']) && is_array($proposedCore['income']) && isset($proposedCore['income']['amount']) && is_numeric($proposedCore['income']['amount'])) {
                        $proposedCore['annual_income'] = (float) $proposedCore['income']['amount'];
                    } elseif (isset($proposedCore['income']) && is_array($proposedCore['income']) && isset($proposedCore['income']['normalized_annual_amount']) && is_numeric($proposedCore['income']['normalized_annual_amount'])) {
                        $proposedCore['annual_income'] = (float) $proposedCore['income']['normalized_annual_amount'];
                    }
                }
                if (! isset($proposedCore['family_income']) || $proposedCore['family_income'] === '' || $proposedCore['family_income'] === null) {
                    if (isset($proposedCore['family_income_normalized_annual_amount']) && is_numeric($proposedCore['family_income_normalized_annual_amount'])) {
                        $proposedCore['family_income'] = (float) $proposedCore['family_income_normalized_annual_amount'];
                    } elseif (isset($proposedCore['family_income_amount']) && is_numeric($proposedCore['family_income_amount'])) {
                        $proposedCore['family_income'] = (float) $proposedCore['family_income_amount'];
                    } elseif (isset($proposedCore['family_income']) && is_array($proposedCore['family_income']) && isset($proposedCore['family_income']['amount']) && is_numeric($proposedCore['family_income']['amount'])) {
                        $proposedCore['family_income'] = (float) $proposedCore['family_income']['amount'];
                    }
                }
                // Honour nested income.private / family_income.private when present.
                if (isset($proposedCore['income']) && is_array($proposedCore['income']) && array_key_exists('private', $proposedCore['income'])) {
                    $proposedCore['income_private'] = (bool) $proposedCore['income']['private'];
                }
                if (isset($proposedCore['family_income']) && is_array($proposedCore['family_income']) && array_key_exists('private', $proposedCore['family_income'])) {
                    $proposedCore['family_income_private'] = (bool) $proposedCore['family_income']['private'];
                }
                // When intake has an amount, show it in wizard (do not leave income_private true).
                if (isset($proposedCore['annual_income']) && is_numeric($proposedCore['annual_income']) && (float) $proposedCore['annual_income'] > 0) {
                    $proposedCore['income_private'] = false;
                }
                if (isset($proposedCore['family_income']) && is_numeric($proposedCore['family_income']) && (float) $proposedCore['family_income'] > 0) {
                    $proposedCore['family_income_private'] = false;
                }

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

                $profile = null;
                $profileCreatedInThisTransaction = false;

                if ($duplicateResult->isDuplicate && $duplicateResult->existingProfileId !== null) {
                    $existingProfileId = $duplicateResult->existingProfileId;
                    $existingProfile = MatrimonyProfile::where('id', $existingProfileId)->lockForUpdate()->first();
                    // SAME_USER: apply intake to this user's existing profile so wizard shows updated data.
                    if ($existingProfile && $duplicateResult->duplicateType === \App\Services\DuplicateResult::TYPE_SAME_USER) {
                        $intake->update(['matrimony_profile_id' => $existingProfileId]);
                        $profile = $existingProfile;
                        Log::info('MutationService::applyApprovedIntake SAME_USER duplicate — applying to existing profile', ['profileId' => $existingProfileId]);
                    } else {
                        if ($existingProfile && ! $this->hasPendingConflictForField($existingProfile->id, 'duplicate_detection')) {
                            ConflictPolicy::create([
                                'profile_id' => $existingProfile->id,
                                'field_name' => 'duplicate_detection',
                                'field_type' => 'CORE',
                                'old_value' => null,
                                'new_value' => $duplicateResult->duplicateType.':'.$duplicateResult->existingProfileId,
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
                }

                if ($profile === null) {
                    Log::info('MutationService::applyApprovedIntake before profile existence step');
                    // ——— Step 2: Profile existence ———
                    if (! empty($intake->matrimony_profile_id)) {
                        $profile = MatrimonyProfile::where('id', $intake->matrimony_profile_id)->lockForUpdate()->first();
                        if (! $profile) {
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
                        if (! $actor) {
                            throw new \RuntimeException('Intake uploaded_by user not found.');
                        }
                        // Use existing profile for this user so wizard shows the same profile (hasOne); avoid creating a second profile.
                        $profile = MatrimonyProfile::where('user_id', $intake->uploaded_by)->lockForUpdate()->first();
                        if ($profile) {
                            if (($profile->lifecycle_state ?? '') === 'conflict_pending') {
                                $profileIdForLog = $profile->id;
                                $blockedByConflictPending = true;
                                $intake->update(['matrimony_profile_id' => $profile->id, 'intake_locked' => true]);

                                return;
                            }
                            $intake->update(['matrimony_profile_id' => $profile->id]);
                        } else {
                            $profile = new MatrimonyProfile;
                            $profile->user_id = $intake->uploaded_by;
                            $profile->full_name = $proposedCore['full_name'] ?? 'Draft';
                            $profile->lifecycle_state = 'draft';
                            $profile->save();
                            $intake->update(['matrimony_profile_id' => $profile->id]);
                            $profileCreatedInThisTransaction = true;
                        }
                    }
                }

                if (! $profileCreatedInThisTransaction) {
                    $mergeSuggestions = $this->partitionIntakeSnapshotForExistingProfile($profile, $snapshot, $proposedCore, $intakeId);
                    $this->mergePendingIntakeSuggestionsIntoProfile($profile, $mergeSuggestions);
                    $proposedExtended = $snapshot['extended'] ?? [];
                }

                // ——— Canonical controlled core (text → *_id): MUST run before conflict + field-lock so evaluation matches apply ———
                $proposedCore = $this->normalizeIntakeCoreForApply($proposedCore);

                // ——— Step 3: Field-level conflict detection (ConflictDetectionService owns escalation) ———
                $conflictResult = ConflictDetectionService::detectResult($profile, $proposedCore, $proposedExtended);
                $conflictRecords = $conflictResult->conflictRecords;
                $conflictFieldNames = array_map(fn (ConflictRecord $r) => $r->field_name, $conflictRecords);

                // ——— Step 4: Field lock check ———
                $coreFieldKeys = $this->getCoreFieldKeysFromRegistry();
                foreach ($coreFieldKeys as $fieldKey) {
                    if (! array_key_exists($fieldKey, $proposedCore)) {
                        continue;
                    }
                    if (ProfileFieldLockService::isLocked($profile, $fieldKey) && ! $this->hasPendingConflictForField($profile->id, $fieldKey)) {
                        $conflictRecords[] = ConflictPolicy::create([
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
                    if (! array_key_exists($fieldKey, $proposedExtended)) {
                        continue;
                    }
                    if (ProfileFieldLockService::isLocked($profile, $fieldKey) && ! $this->hasPendingConflictForField($profile->id, $fieldKey)) {
                        $current = ExtendedFieldService::getValuesForProfile($profile)[$fieldKey] ?? null;
                        $conflictRecords[] = ConflictPolicy::create([
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
                $intakePseudoContactCoreKeys = [
                    'primary_contact_number', 'primary_contact_number_2', 'primary_contact_number_3',
                    'primary_contact_whatsapp', 'primary_contact_whatsapp_2', 'primary_contact_whatsapp_3',
                    // Parent extra slots: stored as profile_contacts (same relation) during intake, not duplicate core columns.
                    'father_contact_2', 'father_contact_3', 'mother_contact_2', 'mother_contact_3',
                ];
                foreach ($coreFieldKeys as $fieldKey) {
                    if (! array_key_exists($fieldKey, $proposedCore)) {
                        continue;
                    }
                    // Intake: phones are applied only via profile_contacts merge (never as fake core columns / never auto-primary).
                    if (in_array($fieldKey, $intakePseudoContactCoreKeys, true)) {
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
                    // DB columns income_private / family_income_private are NOT NULL; never persist null.
                    if (in_array($fieldKey, ['income_private', 'family_income_private'], true) && $newVal === null) {
                        $newVal = false;
                    }
                    $unchanged = (string) ($oldVal ?? '') === (string) ($newVal ?? '');
                    if ($unchanged && ! $profileCreatedInThisTransaction) {
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

                // ——— Step 5.5: Birth / native place → profile columns (only when snapshot still has place — same gate as merge partition) ———
                // Values: prefer $proposedCore IDs (post-normalizeIntakeCoreForApply); fallback snapshot slice if core has no hierarchy.
                $tableName = $profile->getTable();
                $hasAnyPlaceValue = static function (array $place): bool {
                    foreach (['city_id', 'taluka_id', 'district_id', 'state_id'] as $k) {
                        if (isset($place[$k]) && $place[$k] !== null && $place[$k] !== '') {
                            return true;
                        }
                    }

                    return false;
                };
                $placeUpdated = false;
                if (isset($snapshot['birth_place']) && is_array($snapshot['birth_place']) && $hasAnyPlaceValue($snapshot['birth_place'])) {
                    $birthFromCore = [
                        'city_id' => isset($proposedCore['birth_city_id']) ? (int) $proposedCore['birth_city_id'] : null,
                        'taluka_id' => null,
                        'district_id' => null,
                        'state_id' => null,
                    ];
                    $bp = $hasAnyPlaceValue($birthFromCore) ? $birthFromCore : $snapshot['birth_place'];
                    if (Schema::hasColumn($tableName, 'birth_city_id')) {
                        $profile->birth_city_id = isset($bp['city_id']) ? (int) $bp['city_id'] : null;
                    }
                    $placeUpdated = true;
                }
                if (isset($snapshot['native_place']) && is_array($snapshot['native_place']) && $hasAnyPlaceValue($snapshot['native_place'])) {
                    $nativeFromCore = [
                        'city_id' => isset($proposedCore['native_city_id']) ? (int) $proposedCore['native_city_id'] : null,
                        'taluka_id' => isset($proposedCore['native_taluka_id']) ? (int) $proposedCore['native_taluka_id'] : null,
                        'district_id' => isset($proposedCore['native_district_id']) ? (int) $proposedCore['native_district_id'] : null,
                        'state_id' => isset($proposedCore['native_state_id']) ? (int) $proposedCore['native_state_id'] : null,
                    ];
                    $np = $hasAnyPlaceValue($nativeFromCore) ? $nativeFromCore : $snapshot['native_place'];
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

                // ——— Step 6: Contact sync (intake: strict merge — no primary replace, no row deletes, extras preserved) ———
                $contactConflict = $this->syncContactsFromIntakeApply(
                    $profile,
                    is_array($snapshot['contacts'] ?? null) ? $snapshot['contacts'] : [],
                    is_array($proposedCore) ? $proposedCore : [],
                    is_array($auditSnapshot['siblings'] ?? null) ? $auditSnapshot['siblings'] : []
                );
                if ($contactConflict) {
                    $conflictRecords[] = $contactConflict;
                    $conflictFieldNames[] = $contactConflict->field_name;
                }

                // ——— Step 7: Normalized entity sync (snapshot keys → tables) ———
                foreach (self::ENTITY_SYNC_ORDER as $snapshotKey) {
                    $table = self::SNAPSHOT_KEY_TO_TABLE[$snapshotKey] ?? null;
                    if ($table === null || ! Schema::hasTable($table)) {
                        continue;
                    }
                    $proposed = $snapshot[$snapshotKey] ?? [];
                    if (! is_array($proposed)) {
                        continue;
                    }
                    if ($snapshotKey === 'siblings' && Schema::hasTable('profile_siblings')) {
                        $this->syncSiblingsWithSpouses($profile, $proposed);

                        continue;
                    }
                    if ($snapshotKey === 'horoscope' && Schema::hasTable('profile_horoscope_data')) {
                        // Intake approval snapshot is not normalized in normalizeApprovalSnapshot(); match preview/full normalizeSnapshot horoscope path.
                        $proposed = app(\App\Services\Parsing\IntakeControlledFieldNormalizer::class)
                            ->normalizeHoroscopeRows($proposed);
                        $this->syncHoroscopeUpsert($profile, $proposed);

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

                // ——— Step 8b: Extended KV (profile_extended_fields) — same source as $proposedExtended / partition; skip conflicted fields (like core apply) ———
                if (isset($snapshot['extended']) && is_array($snapshot['extended']) && $snapshot['extended'] !== []
                    && Schema::hasTable('profile_extended_fields')) {
                    $extendedToApply = [];
                    foreach ($snapshot['extended'] as $ek => $ev) {
                        if (in_array((string) $ek, $conflictFieldNames, true)) {
                            continue;
                        }
                        $extendedToApply[(string) $ek] = $ev;
                    }
                    if ($extendedToApply !== []) {
                        $this->applyExtendedFieldsFromSnapshot($profile, $extendedToApply);
                    }
                }

                Log::info('MutationService::applyApprovedIntake before lifecycle transition', ['profileId' => $profile->id]);
                // ——— Step 9: Lifecycle transition ———
                $hasConflicts = count($conflictRecords) > 0;
                if ($hasConflicts) {
                    \App\Services\ProfileLifecycleService::syncLifecycleFromPendingConflicts($profile);
                } else {
                    $current = $profile->lifecycle_state ?? 'active';
                    if (! in_array($current, self::NO_AUTO_ACTIVATE_STATES, true)) {
                        // Intake apply: allow activation without photo so applied data is visible in wizard (new or existing profile).
                        $this->setLifecycleState($profile, 'active');
                    }
                }

                Log::info('MutationService::applyApprovedIntake before intake finalization', ['hasConflicts' => $hasConflicts, 'snapshotInMemory' => $snapshotInMemory]);
                // ——— Step 10: Intake finalization ———
                $updates = ['matrimony_profile_id' => $profile->id, 'intake_locked' => true];
                if (! $hasConflicts) {
                    $updates['intake_status'] = 'applied';
                }
                if ($snapshotInMemory) {
                    $updates['approval_snapshot_json'] = $auditSnapshot;
                    $updates['approved_by_user'] = true;
                    $updates['approved_at'] = now();
                    $updates['snapshot_schema_version'] = $version;
                    if (is_array($metrics)) {
                        if (isset($metrics['manual_edits'])) {
                            $updates['fields_manually_edited_count'] = (int) $metrics['manual_edits'];
                        }
                        if (isset($metrics['auto_filled'])) {
                            $updates['fields_auto_filled_count'] = (int) $metrics['auto_filled'];
                        }
                    }
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
            'mutation_success' => ! $duplicateDetected && ! $hadConflicts,
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
        if (! Schema::hasTable('profile_contacts')) {
            return null;
        }
        $existing = DB::table('profile_contacts')->where('profile_id', $profile->id)->get();
        $existingPrimary = $existing->firstWhere('is_primary', true);

        // 1) Dedupe → self primary must be verified → then at most one is_primary=true in the whole list (legacy).
        $proposed = $this->dedupeProposedProfileContactsByPhoneAndRelation($proposed);
        $proposed = $this->applyVerifiedOnlyPrimaryRulesToProposedContacts($existing, $proposed);
        $primarySeen = false;
        $proposed = array_map(function ($c) use (&$primarySeen) {
            if (! is_array($c)) {
                return $c;
            }
            $isPrimary = ! empty($c['is_primary']);
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
            if ($existingPhone !== '' && $proposedPhone !== '' && $existingPhone !== $proposedPhone && $createConflictRecord && ! $this->hasPendingConflictForField($profile->id, 'primary_contact_number')) {
                $contactConflict = ConflictPolicy::create([
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
     * Intake apply only: append profile_contacts from intake without replacing an existing primary row,
     * without deleting existing contacts, and without marking intake numbers as verified primary.
     * Conflicting primary candidates → ConflictRecord + pending_intake_suggestions_json.contacts.
     *
     * @param  array<int, mixed>  $proposedContacts
     * @param  array<string, mixed>  $auditCore  Canonical core (same as Step 5: post-normalizeIntakeCoreForApply) for primary/extra phone slots
     * @param  array<int, mixed>  $auditSiblings
     */
    private function syncContactsFromIntakeApply(
        MatrimonyProfile $profile,
        array $proposedContacts,
        array $auditCore,
        array $auditSiblings,
    ): ?ConflictRecord {
        if (! Schema::hasTable('profile_contacts')) {
            return null;
        }

        $profile->refresh();
        $profile->loadMissing('user');

        $existing = DB::table('profile_contacts')->where('profile_id', $profile->id)->get();
        $existingPrimary = $existing->first(function ($r) {
            return ! empty($r->is_primary);
        });

        $knownDigits = $this->collectKnownPhoneDigitsForIntakeContactMerge($profile, $existing);
        $suggestionContactRows = [];

        $intakePrimaryDigits = $this->intakePrimaryPhoneCandidateDigits($proposedContacts, $auditCore);
        $existingPrimaryDigits = '';
        if ($existingPrimary !== null) {
            $existingPrimaryDigits = $this->normalizePhoneDigitsForDedupe($existingPrimary->phone_number ?? null);
        }

        $contactConflict = null;
        if ($existingPrimaryDigits !== '' && $intakePrimaryDigits !== '' && $intakePrimaryDigits !== $existingPrimaryDigits) {
            if (! $this->hasPendingConflictForField($profile->id, 'primary_contact_number')) {
                $contactConflict = ConflictPolicy::create([
                    'profile_id' => $profile->id,
                    'field_name' => 'primary_contact_number',
                    'field_type' => 'CORE',
                    'old_value' => $existingPrimaryDigits,
                    'new_value' => $intakePrimaryDigits,
                    'source' => 'SYSTEM',
                    'detected_at' => now(),
                    'resolution_status' => 'PENDING',
                ]);
            }
            foreach ($proposedContacts as $row) {
                if (is_array($row)) {
                    $suggestionContactRows[] = $row;
                }
            }
            $suggestionContactRows[] = [
                'relation_type' => 'self',
                'phone_number' => $intakePrimaryDigits,
                'contact_name' => 'Self',
                'intake_note' => 'proposed_primary_from_intake',
            ];
        } elseif ($existingPrimaryDigits === '' && $intakePrimaryDigits !== '') {
            $suggestionContactRows[] = [
                'relation_type' => 'self',
                'phone_number' => $intakePrimaryDigits,
                'contact_name' => 'Self',
                'intake_note' => 'candidate_primary_pending_verification',
            ];
        }

        $candidates = [];
        foreach ($proposedContacts as $row) {
            if (is_array($row)) {
                $candidates[] = $row;
            }
        }
        $this->appendIntakeCorePhoneCandidates($candidates, $auditCore);
        $this->appendIntakeSiblingExtraPhoneCandidates($candidates, $auditSiblings);

        foreach ($candidates as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $relationKey = $this->inferIntakeContactRelationKey($raw);
            $phoneRaw = $raw['phone_number'] ?? $raw['number'] ?? $raw['phone'] ?? null;
            $digits = $this->normalizePhoneDigitsForDedupe($phoneRaw);
            if ($digits === '') {
                continue;
            }
            if (isset($knownDigits[$digits])) {
                continue;
            }

            $row = $raw;
            $row['phone_number'] = $digits;
            $row['relation_type'] = $relationKey;
            $row['is_primary'] = false;
            if (trim((string) ($row['contact_name'] ?? '')) === '') {
                $row['contact_name'] = $this->defaultContactNameForRelationKey($relationKey);
            }

            $mapped = $this->mapSnapshotRowToTable('profile_contacts', $row);
            $mapped['is_primary'] = false;
            $mapped['phone_number'] = $digits;
            if (($mapped['contact_name'] ?? '') === '') {
                $mapped['contact_name'] = $this->defaultContactNameForRelationKey($relationKey);
            }

            $this->insertIntakeProfileContactRow($profile, $mapped, $knownDigits);
        }

        if ($suggestionContactRows !== []) {
            $this->mergePendingIntakeSuggestionsIntoProfile($profile, ['contacts' => array_values($suggestionContactRows)]);
        }

        return $contactConflict;
    }

    private function normalizePhoneDigitsForDedupe(mixed $phone): string
    {
        $d = preg_replace('/\D/', '', (string) ($phone ?? ''));
        if ($d === '') {
            return '';
        }
        if (strlen($d) > 10) {
            $d = substr($d, -10);
        }
        if (strlen($d) !== 10) {
            return '';
        }

        return $d;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>|\Illuminate\Support\Collection<int, \stdClass>  $existingRows
     * @return array<string, true>
     */
    private function collectKnownPhoneDigitsForIntakeContactMerge(MatrimonyProfile $profile, $existingRows): array
    {
        $set = [];
        foreach ($existingRows as $r) {
            $d = $this->normalizePhoneDigitsForDedupe($r->phone_number ?? null);
            if ($d !== '') {
                $set[$d] = true;
            }
        }
        foreach (['father_contact_1', 'father_contact_2', 'father_contact_3', 'mother_contact_1', 'mother_contact_2', 'mother_contact_3'] as $k) {
            $d = $this->normalizePhoneDigitsForDedupe($profile->getAttribute($k));
            if ($d !== '') {
                $set[$d] = true;
            }
        }

        return $set;
    }

    private function intakePrimaryPhoneCandidateDigits(array $proposedContacts, array $auditCore): string
    {
        $fromCore = $this->normalizePhoneDigitsForDedupe($auditCore['primary_contact_number'] ?? null);
        if ($fromCore !== '') {
            return $fromCore;
        }
        foreach ($proposedContacts as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! empty($row['is_primary']) || strtolower(trim((string) ($row['type'] ?? ''))) === 'primary') {
                $d = $this->normalizePhoneDigitsForDedupe($row['phone_number'] ?? $row['number'] ?? null);
                if ($d !== '') {
                    return $d;
                }
            }
        }
        foreach ($proposedContacts as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($this->inferIntakeContactRelationKey($row) === 'self') {
                $d = $this->normalizePhoneDigitsForDedupe($row['phone_number'] ?? $row['number'] ?? null);
                if ($d !== '') {
                    return $d;
                }
            }
        }

        return '';
    }

    private function inferIntakeContactRelationKey(array $row): string
    {
        $rt = strtolower(trim((string) ($row['relation_type'] ?? '')));
        if (in_array($rt, ['brother', 'sister', 'siblings'], true)) {
            return 'sibling';
        }
        if ($rt !== '') {
            return $rt;
        }
        $lab = strtolower(trim((string) ($row['label'] ?? '')));
        if (in_array($lab, ['self', 'father', 'mother', 'spouse', 'sibling', 'guardian', 'other'], true)) {
            return $lab;
        }
        $typ = strtolower(trim((string) ($row['type'] ?? '')));
        if ($typ === 'primary') {
            return 'self';
        }

        return 'self';
    }

    private function defaultContactNameForRelationKey(string $relationKey): string
    {
        return match ($relationKey) {
            'father' => 'Father',
            'mother' => 'Mother',
            'spouse' => 'Spouse',
            'sibling' => 'Sibling',
            'guardian' => 'Guardian',
            'other' => 'Contact',
            default => 'Self',
        };
    }

    /**
     * @param  array<int, mixed>  $candidates
     * @param  array<string, mixed>  $auditCore
     */
    private function appendIntakeCorePhoneCandidates(array &$candidates, array $auditCore): void
    {
        $primaryRaw = $auditCore['primary_contact_number'] ?? null;
        if ($primaryRaw !== null && trim((string) $primaryRaw) !== '') {
            $candidates[] = [
                'phone_number' => trim((string) $primaryRaw),
                'relation_type' => 'self',
                'contact_name' => 'Self',
            ];
        }
        $extras = [
            ['keys' => ['primary_contact_number_2', 'primary_contact_number_3'], 'relation' => 'self', 'name' => 'Self'],
            ['keys' => ['father_contact_1', 'father_contact_2', 'father_contact_3'], 'relation' => 'father', 'name' => 'Father'],
            ['keys' => ['mother_contact_1', 'mother_contact_2', 'mother_contact_3'], 'relation' => 'mother', 'name' => 'Mother'],
        ];
        foreach ($extras as $group) {
            foreach ($group['keys'] as $key) {
                $raw = $auditCore[$key] ?? null;
                if ($raw === null || trim((string) $raw) === '') {
                    continue;
                }
                $candidates[] = [
                    'phone_number' => trim((string) $raw),
                    'relation_type' => $group['relation'],
                    'contact_name' => $group['name'],
                ];
            }
        }
    }

    /**
     * @param  array<int, mixed>  $candidates
     * @param  array<int, mixed>  $auditSiblings
     */
    private function appendIntakeSiblingExtraPhoneCandidates(array &$candidates, array $auditSiblings): void
    {
        foreach ($auditSiblings as $s) {
            if (! is_array($s)) {
                continue;
            }
            $name = trim((string) ($s['name'] ?? ''));
            $label = $name !== '' ? $name : 'Sibling';
            $nums = [
                $s['contact_number_2'] ?? null,
                $s['contact_number_3'] ?? null,
            ];
            foreach ($nums as $raw) {
                if ($raw === null || trim((string) $raw) === '') {
                    continue;
                }
                $candidates[] = [
                    'phone_number' => trim((string) $raw),
                    'relation_type' => 'sibling',
                    'contact_name' => $label,
                ];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $mapped  Output of mapSnapshotRowToTable for profile_contacts
     * @param  array<string, true>  $knownDigits
     */
    private function insertIntakeProfileContactRow(MatrimonyProfile $profile, array $mapped, array &$knownDigits): void
    {
        $digits = $this->normalizePhoneDigitsForDedupe($mapped['phone_number'] ?? null);
        if ($digits === '' || isset($knownDigits[$digits])) {
            return;
        }

        $mapped['phone_number'] = $digits;
        $mapped['is_primary'] = false;
        $mapped['profile_id'] = $profile->id;
        if (Schema::hasColumn('profile_contacts', 'verified_status')
            && (! array_key_exists('verified_status', $mapped) || $mapped['verified_status'] === null)) {
            $mapped['verified_status'] = false;
        }
        if (! isset($mapped['visibility_rule']) || trim((string) $mapped['visibility_rule']) === '') {
            $mapped['visibility_rule'] = 'unlock_only';
        }

        $allowedColumns = array_fill_keys(Schema::getColumnListing('profile_contacts'), true);
        $insert = array_merge($mapped, [
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        unset($insert['id']);
        $insert = array_intersect_key($insert, $allowedColumns);

        DB::table('profile_contacts')->insert($insert);
        $knownDigits[$digits] = true;

        $this->writeProfileChangeHistory(
            $profile->id,
            'profile_contacts',
            null,
            'insert',
            null,
            json_encode($insert)
        );
    }

    /**
     * Sync a single entity from snapshot key (e.g. manual profile edit). No other snapshot keys affected.
     */
    public function syncEntityFromSnapshot(MatrimonyProfile $profile, string $snapshotKey, array $proposed): void
    {
        $table = self::SNAPSHOT_KEY_TO_TABLE[$snapshotKey] ?? null;
        if ($table === null || ! Schema::hasTable($table)) {
            return;
        }
        $this->syncEntityDiff($profile, $table, $proposed);
    }

    /**
     * Map snapshot row keys to table columns. Phase-5: resolve string lookups to *_id for entity tables.
     */
    private function mapSnapshotRowToTable(string $entityType, array $row): array
    {
        \Log::info('DEBUG MAP CALL', [
            'table' => $entityType,
            'row' => $row,
        ]);
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
            if (! array_key_exists('year_completed', $mapped)) {
                $mapped['year_completed'] = 0;
            }
            // DB: degree NOT NULL
            $degree = trim((string) ($mapped['degree'] ?? ''));
            $mapped['degree'] = $degree !== '' ? $degree : '—';

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
        if ($entityType === 'profile_horoscope_data') {
            $mapped = $this->resolveHoroscopeLookupsToId($row);

            return $mapped;
        }
        if ($entityType === 'profile_relatives') {
            $mapped = $row;
            $mapped['relation_type'] = trim((string) ($mapped['relation_type'] ?? ''));
            $mapped['name'] = trim((string) ($mapped['name'] ?? ''));
            $mapped['occupation'] = isset($mapped['occupation']) && trim((string) $mapped['occupation']) !== '' ? trim((string) $mapped['occupation']) : null;
            $mapped['occupation_master_id'] = ! empty($mapped['occupation_master_id']) ? (int) $mapped['occupation_master_id'] : null;
            $mapped['occupation_custom_id'] = ! empty($mapped['occupation_custom_id']) ? (int) $mapped['occupation_custom_id'] : null;
            $mapped['city_id'] = ! empty($mapped['city_id']) ? (int) $mapped['city_id'] : null;
            $mapped['state_id'] = ! empty($mapped['state_id']) ? (int) $mapped['state_id'] : null;
            $mapped['contact_number'] = isset($mapped['contact_number']) && trim((string) $mapped['contact_number']) !== '' ? trim((string) $mapped['contact_number']) : null;
            $notes = isset($mapped['notes']) && trim((string) $mapped['notes']) !== '' ? trim((string) $mapped['notes']) : null;
            $addr = isset($mapped['address_line']) && trim((string) $mapped['address_line']) !== '' ? trim((string) $mapped['address_line']) : null;
            $addr2 = isset($mapped['address']) && trim((string) $mapped['address']) !== '' ? trim((string) $mapped['address']) : null;
            $addr3 = isset($mapped['Address']) && trim((string) $mapped['Address']) !== '' ? trim((string) $mapped['Address']) : null;
            $parts = array_filter([$notes, $addr, $addr2, $addr3]);
            $mapped['notes'] = $parts !== [] ? implode("\n", array_unique($parts)) : null;
            unset($mapped['address_line'], $mapped['address'], $mapped['Address']);
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
            $mapped['relation_type'] = in_array($mapped['relation_type'] ?? null, ['brother', 'sister'], true) ? $mapped['relation_type'] : null;
            $mapped['name'] = isset($mapped['name']) && trim((string) $mapped['name']) !== '' ? trim((string) $mapped['name']) : null;
            $mapped['gender'] = in_array($mapped['gender'] ?? null, ['male', 'female'], true) ? $mapped['gender'] : null;
            // Normalize marital_status: intake/form may send "Yes"/"No" or is_married; store as married/unmarried.
            $maritalRaw = $mapped['marital_status'] ?? ($mapped['is_married'] ?? null);
            if (in_array($maritalRaw, ['married', 'unmarried'], true)) {
                $mapped['marital_status'] = $maritalRaw;
            } elseif ($maritalRaw === true || $maritalRaw === 1 || (is_string($maritalRaw) && strtolower($maritalRaw) === 'yes')) {
                $mapped['marital_status'] = 'married';
            } elseif ($maritalRaw === false || $maritalRaw === 0 || (is_string($maritalRaw) && strtolower($maritalRaw) === 'no')) {
                $mapped['marital_status'] = 'unmarried';
            } elseif (is_string($maritalRaw) && strtolower(trim($maritalRaw)) === 'married') {
                $mapped['marital_status'] = 'married';
            } else {
                $mapped['marital_status'] = in_array($maritalRaw, ['unmarried', 'married'], true) ? $maritalRaw : null;
            }
            // If spouse has name/address, treat as married so Full profile shows spouse (intake form may not send marital_status=Yes).
            $spouse = isset($mapped['spouse']) && is_array($mapped['spouse']) ? $mapped['spouse'] : [];
            $spouseHasData = trim((string) ($spouse['name'] ?? '')) !== '' || trim((string) ($spouse['address_line'] ?? $spouse['address'] ?? $spouse['additional_info'] ?? '')) !== '';
            if ($spouseHasData && strtolower(trim((string) ($mapped['marital_status'] ?? ''))) !== 'married') {
                $mapped['marital_status'] = 'married';
            }
            $mapped['occupation'] = isset($mapped['occupation']) && trim((string) $mapped['occupation']) !== '' ? trim((string) $mapped['occupation']) : null;
            $mapped['occupation_master_id'] = ! empty($mapped['occupation_master_id']) ? (int) $mapped['occupation_master_id'] : null;
            $mapped['occupation_custom_id'] = ! empty($mapped['occupation_custom_id']) ? (int) $mapped['occupation_custom_id'] : null;
            $mapped['city_id'] = ! empty($mapped['city_id']) ? (int) $mapped['city_id'] : null;
            $mapped['contact_number'] = isset($mapped['contact_number']) && trim((string) $mapped['contact_number']) !== '' ? trim((string) $mapped['contact_number']) : null;
            $mapped['contact_number_2'] = isset($mapped['contact_number_2']) && trim((string) $mapped['contact_number_2']) !== '' ? trim((string) $mapped['contact_number_2']) : null;
            $mapped['contact_number_3'] = isset($mapped['contact_number_3']) && trim((string) $mapped['contact_number_3']) !== '' ? trim((string) $mapped['contact_number_3']) : null;
            // Merge address + additional_info into notes so intake data shows in wizard (profile_siblings has only notes).
            $notesParts = array_filter([
                isset($mapped['notes']) && trim((string) $mapped['notes']) !== '' ? trim((string) $mapped['notes']) : null,
                isset($mapped['address']) && trim((string) $mapped['address']) !== '' ? trim((string) $mapped['address']) : null,
                isset($mapped['address_line']) && trim((string) $mapped['address_line']) !== '' ? trim((string) $mapped['address_line']) : null,
                isset($mapped['Address']) && trim((string) $mapped['Address']) !== '' ? trim((string) $mapped['Address']) : null,
                isset($mapped['additional_info']) && trim((string) $mapped['additional_info']) !== '' ? trim((string) $mapped['additional_info']) : null,
            ]);
            $mapped['notes'] = $notesParts !== [] ? implode("\n", $notesParts) : null;
            $mapped['sort_order'] = isset($mapped['sort_order']) && $mapped['sort_order'] !== '' ? (int) $mapped['sort_order'] : 0;
            unset($mapped['spouse'], $mapped['is_married'], $mapped['address'], $mapped['address_line'], $mapped['Address'], $mapped['additional_info']);
            // profile_siblings has no contact_preference_* columns (those are on profile_contacts); drop so insert doesn't fail.
            foreach (array_keys($mapped) as $k) {
                if (str_starts_with($k, 'contact_preference')) {
                    unset($mapped[$k]);
                }
            }

            return $mapped;
        }
        if ($entityType === 'profile_career') {
            $mapped = $row;
            // DB NOT NULL columns: designation, company, start_year — never send null.
            $mapped['designation'] = trim((string) ($mapped['designation'] ?? ''));
            if ($mapped['designation'] === '') {
                $mapped['designation'] = '—';
            }
            $mapped['company'] = trim((string) ($mapped['company'] ?? $mapped['employer_name'] ?? $mapped['company_name'] ?? ''));
            if ($mapped['company'] === '') {
                $mapped['company'] = '—';
            }
            unset($mapped['employer_name'], $mapped['company_name']);
            $mapped['start_year'] = isset($mapped['start_year']) && (string) $mapped['start_year'] !== '' && is_numeric($mapped['start_year'])
                ? (int) $mapped['start_year']
                : 0;
            $mapped['end_year'] = isset($mapped['end_year']) && (string) $mapped['end_year'] !== '' && is_numeric($mapped['end_year'])
                ? (int) $mapped['end_year']
                : null;
            $cityId = ! empty($mapped['city_id']) && is_numeric($mapped['city_id']) ? (int) $mapped['city_id'] : null;
            if ($cityId !== null && $cityId <= 0) {
                $cityId = null;
            }
            if (Schema::hasColumn('profile_career', 'city_id')) {
                $mapped['city_id'] = $cityId;
            } else {
                unset($mapped['city_id']);
            }
            unset($mapped['taluka_id'], $mapped['district_id'], $mapped['state_id']);
            $loc = trim((string) ($mapped['location'] ?? $mapped['work_location'] ?? ''));
            if ($loc === '' && $cityId !== null) {
                $loc = CareerHistoryRowNormalizer::lineForCityId($cityId) ?? '';
            }
            $mapped['location'] = $loc !== '' ? $loc : null;
            $mapped['is_current'] = ! empty($mapped['is_current']);

            return $mapped;
        }
        if ($entityType === 'profile_marriages') {
            \Log::info('DEBUG MAP ROW', $row);

            return [
                'marital_status_id' => $row['marital_status_id'] ?? null,
                'marriage_year' => $row['marriage_year'] ?? null,
                'separation_year' => $row['separation_year'] ?? null,
                'divorce_year' => $row['divorce_year'] ?? null,
                'spouse_death_year' => $row['spouse_death_year'] ?? null,
                'divorce_status' => $row['divorce_status'] ?? null,
                'remarriage_reason' => $row['remarriage_reason'] ?? null,
                'notes' => $row['notes'] ?? null,
            ];
        }

        return $row;
    }

    private function resolveMasterKey(string $table, ?string $key): ?int
    {
        if ($key === null || trim((string) $key) === '') {
            return null;
        }

        $normalized = str_replace(' ', '_', strtolower(trim($key)));

        $query = DB::table($table)->where('key', $normalized);
        // Active-only resolution when table has is_active column.
        if (Schema::hasTable($table) && Schema::hasColumn($table, 'is_active')) {
            $query->where('is_active', true);
        }

        $id = $query->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Resolve a numeric master ID to an active row (when table has is_active).
     * Returns null when the row does not exist or is inactive.
     */
    private function resolveActiveMasterId(string $table, int|string|null $id): ?int
    {
        if ($id === null || $id === '' || ! is_numeric($id)) {
            return null;
        }

        $id = (int) $id;

        $query = DB::table($table)->where('id', $id);
        if (Schema::hasTable($table) && Schema::hasColumn($table, 'is_active')) {
            $query->where('is_active', true);
        }

        $found = $query->value('id');

        return $found !== null ? (int) $found : null;
    }

    private function resolveAddressTypeToId(array $row): array
    {
        $mapped = $row;
        $val = $mapped['address_type_id'] ?? $mapped['address_type'] ?? $mapped['type'] ?? null;
        $engine = app(\App\Services\ControlledOptions\ControlledOptionEngine::class);
        if ($val !== null && ! is_numeric($val)) {
            $res = $engine->resolveKey('entity.address_type', (string) $val);
            $mapped['address_type_id'] = $res['matched'] ? $res['id'] : null;
        } elseif (isset($mapped['address_type_id']) && is_numeric($mapped['address_type_id'])) {
            $mapped['address_type_id'] = $engine->resolveId('entity.address_type', $mapped['address_type_id']);
        }
        unset($mapped['address_type'], $mapped['type']);

        return $mapped;
    }

    private function resolveContactRelationToId(array $row): array
    {
        $mapped = $row;
        if (trim((string) ($mapped['phone_number'] ?? '')) === '' && isset($mapped['number']) && trim((string) $mapped['number']) !== '') {
            $mapped['phone_number'] = trim((string) $mapped['number']);
        }
        $val = $mapped['contact_relation_id'] ?? $mapped['relation_type'] ?? null;
        $engine = app(\App\Services\ControlledOptions\ControlledOptionEngine::class);
        if ($val !== null && ! is_numeric($val)) {
            $res = $engine->resolveKey('entity.contact_relation', (string) $val);
            $mapped['contact_relation_id'] = $res['matched'] ? $res['id'] : null;
        } elseif (isset($mapped['contact_relation_id']) && is_numeric($mapped['contact_relation_id'])) {
            $mapped['contact_relation_id'] = $engine->resolveId('entity.contact_relation', $mapped['contact_relation_id']);
        }
        unset($mapped['relation_type']);
        // Normalize contact preference: intake/wizard may send is_whatsapp as string 'whatsapp'|'call'|'message'
        $pref = $mapped['contact_preference'] ?? $mapped['is_whatsapp'] ?? null;
        if (in_array($pref, ['whatsapp', 'call', 'message'], true)) {
            $mapped['contact_preference'] = $pref;
            $mapped['is_whatsapp'] = ($pref === 'whatsapp');
        } elseif ($pref === true || $pref === '1' || $pref === 1) {
            $mapped['contact_preference'] = 'whatsapp';
            $mapped['is_whatsapp'] = true;
        }
        // DB column contact_name is NOT NULL; never send null/empty so intake/wizard don't cause 500.
        $name = trim((string) ($mapped['contact_name'] ?? ''));
        if ($name === '') {
            $mapped['contact_name'] = 'Self';
        }

        return $mapped;
    }

    private function resolveChildLivingWithToId(array $row): array
    {
        $mapped = $row;
        $engine = app(\App\Services\ControlledOptions\ControlledOptionEngine::class);
        if (array_key_exists('child_living_with_id', $mapped) && is_numeric($mapped['child_living_with_id'])) {
            $mapped['child_living_with_id'] = $engine->resolveId('entity.child_living_with', $mapped['child_living_with_id']);
            unset($mapped['lives_with_parent']);

            return $mapped;
        }
        if (array_key_exists('lives_with_parent', $mapped)) {
            $withParent = $mapped['lives_with_parent'];
            $key = ($withParent === true || $withParent === '1' || $withParent === 1) ? 'with_parent' : 'with_other_parent';
            $res = $engine->resolveKey('entity.child_living_with', $key);
            $mapped['child_living_with_id'] = $res['matched'] ? $res['id'] : null;
        }
        unset($mapped['lives_with_parent']);

        return $mapped;
    }

    private function resolvePropertyAssetLookupsToId(array $row): array
    {
        $mapped = $row;
        $engine = app(\App\Services\ControlledOptions\ControlledOptionEngine::class);
        $val = $mapped['asset_type_id'] ?? $mapped['asset_type'] ?? null;
        if ($val !== null && ! is_numeric($val)) {
            $res = $engine->resolveKey('entity.asset_type', (string) $val);
            $mapped['asset_type_id'] = $res['matched'] ? $res['id'] : null;
        } elseif (isset($mapped['asset_type_id']) && is_numeric($mapped['asset_type_id'])) {
            $mapped['asset_type_id'] = $engine->resolveId('entity.asset_type', $mapped['asset_type_id']);
        }
        unset($mapped['asset_type']);
        $val = $mapped['ownership_type_id'] ?? $mapped['ownership_type'] ?? null;
        if ($val !== null && ! is_numeric($val)) {
            $res = $engine->resolveKey('entity.ownership_type', (string) $val);
            $mapped['ownership_type_id'] = $res['matched'] ? $res['id'] : null;
        } elseif (isset($mapped['ownership_type_id']) && is_numeric($mapped['ownership_type_id'])) {
            $mapped['ownership_type_id'] = $engine->resolveId('entity.ownership_type', $mapped['ownership_type_id']);
        }
        unset($mapped['ownership_type']);
        $mapped['city_id'] = ! empty($mapped['city_id']) ? (int) $mapped['city_id'] : null;
        $mapped['taluka_id'] = ! empty($mapped['taluka_id']) ? (int) $mapped['taluka_id'] : null;
        $mapped['district_id'] = ! empty($mapped['district_id']) ? (int) $mapped['district_id'] : null;
        $mapped['state_id'] = ! empty($mapped['state_id']) ? (int) $mapped['state_id'] : null;

        return $mapped;
    }

    private function resolveHoroscopeLookupsToId(array $row): array
    {
        $mapped = $row;
        $engine = app(\App\Services\ControlledOptions\ControlledOptionEngine::class);
        $fieldMap = [
            'rashi' => ['col' => 'rashi_id', 'field_key' => 'horoscope.rashi'],
            'nakshatra' => ['col' => 'nakshatra_id', 'field_key' => 'horoscope.nakshatra'],
            'gan' => ['col' => 'gan_id', 'field_key' => 'horoscope.gan'],
            'nadi' => ['col' => 'nadi_id', 'field_key' => 'horoscope.nadi'],
            'mangal_dosh_type' => ['col' => 'mangal_dosh_type_id', 'field_key' => 'horoscope.mangal_dosh_type'],
            'yoni' => ['col' => 'yoni_id', 'field_key' => 'horoscope.yoni'],
        ];
        foreach ($fieldMap as $strKey => $meta) {
            $idCol = $meta['col'];
            $fieldKey = $meta['field_key'];
            $val = $mapped[$idCol] ?? $mapped[$strKey] ?? null;
            if ($val !== null && ! is_numeric($val)) {
                $res = $engine->resolveKey($fieldKey, (string) $val);
                $mapped[$idCol] = $res['matched'] ? $res['id'] : null;
            } elseif (isset($mapped[$idCol]) && is_numeric($mapped[$idCol])) {
                $mapped[$idCol] = $engine->resolveId($fieldKey, $mapped[$idCol]);
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
        if (! is_array($proposed)) {
            return;
        }
        $profileId = $profile->id;

        if (Schema::hasTable('profile_preference_criteria')) {
            $allowed = ['preferred_age_min', 'preferred_age_max', 'preferred_height_min_cm', 'preferred_height_max_cm', 'preferred_income_min', 'preferred_income_max', 'preferred_city_id', 'willing_to_relocate', 'settled_city_preference_id', 'marriage_type_preference_id', 'preferred_marital_status_id', 'partner_profile_with_children', 'preferred_profile_managed_by'];
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
                if (! empty($changes)) {
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
            'profile_preferred_countries' => ['preferred_country_ids', 'country_id'],
            'profile_preferred_states' => ['preferred_state_ids', 'state_id'],
            'profile_preferred_districts' => ['preferred_district_ids', 'district_id'],
            'profile_preferred_talukas' => ['preferred_taluka_ids', 'taluka_id'],
            'profile_preferred_education_degrees' => ['preferred_education_degree_ids', 'education_degree_id'],
            'profile_preferred_occupation_master' => ['preferred_occupation_master_ids', 'occupation_master_id'],
            'profile_preferred_diets' => ['preferred_diet_ids', 'diet_id'],
            'profile_preferred_marital_statuses' => ['preferred_marital_status_ids', 'marital_status_id'],
        ];
        foreach ($pivotConfig as $table => [$key, $fkCol]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $ids = $proposed[$key] ?? [];
            if (! is_array($ids)) {
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
        if (! Schema::hasTable($table)) {
            return;
        }
        $row = isset($proposed[0]) && is_array($proposed[0]) ? $proposed[0] : $proposed;
        if (! is_array($row)) {
            return;
        }
        $row = $this->mapSnapshotRowToTable($table, $row);
        $allowedColumns = array_flip(Schema::getColumnListing($table));
        $data = [];
        foreach ($row as $col => $value) {
            if ($col === 'id' || $col === 'profile_id') {
                continue;
            }
            if (! isset($allowedColumns[$col])) {
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
            if (! empty($changes)) {
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

    /**
     * Day 31 Part 2: Sync siblings + spouse rows. Uses soft deletes; collects sibling ids for spouse upsert.
     */
    private function syncSiblingsWithSpouses(MatrimonyProfile $profile, array $proposed): void
    {
        $table = 'profile_siblings';
        $hasDeletedAt = Schema::hasColumn($table, 'deleted_at');
        $existingQuery = DB::table($table)->where('profile_id', $profile->id);
        if ($hasDeletedAt) {
            $existingQuery->whereNull('deleted_at');
        }
        $existing = $existingQuery->get()->keyBy('id');

        $siblingIds = [];
        foreach ($proposed as $idx => $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = $this->mapSnapshotRowToTable($table, $row);
            $id = isset($mapped['id']) ? (int) $mapped['id'] : null;
            $existingRow = $id !== null ? $existing->get($id) : null;

            $allowedColumns = array_flip(Schema::getColumnListing($table));
            $insertData = array_merge(['profile_id' => $profile->id], $mapped);
            unset($insertData['id']);
            $insertData = array_intersect_key($insertData, $allowedColumns);
            if ($hasDeletedAt) {
                $insertData['deleted_at'] = null;
            }
            $insertData['created_at'] = now();
            $insertData['updated_at'] = now();

            if ($existingRow !== null) {
                $updateData = collect($insertData)->except(['profile_id', 'created_at'])->all();
                $updateData = array_intersect_key($updateData, $allowedColumns);
                DB::table($table)->where('id', $id)->update($updateData);
                $siblingIds[$idx] = $id;
            } else {
                $newId = DB::table($table)->insertGetId($insertData);
                $siblingIds[$idx] = $newId;
                $this->writeProfileChangeHistory($profile->id, $table, null, 'insert', null, json_encode($mapped));
            }
        }

        $proposedIds = array_filter($siblingIds);
        foreach ($existing as $id => $existingRow) {
            if (in_array($id, $proposedIds, true)) {
                continue;
            }
            if ($hasDeletedAt) {
                DB::table($table)->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
            }
            $this->writeProfileChangeHistory($profile->id, $table, (int) $id, 'delete', json_encode($existingRow), null);
        }

        if (! Schema::hasTable('profile_sibling_spouses')) {
            return;
        }
        $spouseTable = 'profile_sibling_spouses';
        $spouseHasDeletedAt = Schema::hasColumn($spouseTable, 'deleted_at');
        foreach ($proposed as $idx => $row) {
            if (! is_array($row)) {
                continue;
            }
            $siblingId = $siblingIds[$idx] ?? null;
            if ($siblingId === null) {
                continue;
            }
            $maritalVal = $row['marital_status'] ?? $row['is_married'] ?? null;
            $isMarried = in_array($maritalVal, ['married'], true)
                || $maritalVal === true || $maritalVal === 1
                || (is_string($maritalVal) && in_array(strtolower(trim($maritalVal)), ['yes', 'married'], true));
            $spouseData = $row['spouse'] ?? [];
            $hasSpouseFields = ! empty($spouseData['name']) || ! empty($spouseData['occupation_title']) || ! empty($spouseData['occupation_master_id']) || ! empty($spouseData['occupation_custom_id']) || ! empty($spouseData['contact_number'])
                || ! empty($spouseData['address_line']) || ! empty($spouseData['address']) || ! empty($spouseData['additional_info']) || ! empty($spouseData['city_id']);

            if ($isMarried && $hasSpouseFields) {
                $spouseAddress = trim((string) ($spouseData['address_line'] ?? $spouseData['address'] ?? $spouseData['additional_info'] ?? ''));
                $spouseRow = [
                    'name' => trim((string) ($spouseData['name'] ?? '')) ?: null,
                    'occupation_title' => trim((string) ($spouseData['occupation_title'] ?? '')) ?: null,
                    'occupation_master_id' => ! empty($spouseData['occupation_master_id']) ? (int) $spouseData['occupation_master_id'] : null,
                    'occupation_custom_id' => ! empty($spouseData['occupation_custom_id']) ? (int) $spouseData['occupation_custom_id'] : null,
                    'contact_number' => trim((string) ($spouseData['contact_number'] ?? '')) ?: null,
                    'address_line' => $spouseAddress !== '' ? $spouseAddress : null,
                    'city_id' => ! empty($spouseData['city_id']) ? (int) $spouseData['city_id'] : null,
                    'taluka_id' => ! empty($spouseData['taluka_id']) ? (int) $spouseData['taluka_id'] : null,
                    'district_id' => ! empty($spouseData['district_id']) ? (int) $spouseData['district_id'] : null,
                    'state_id' => ! empty($spouseData['state_id']) ? (int) $spouseData['state_id'] : null,
                ];
                $existingSpouse = DB::table($spouseTable)->where('profile_sibling_id', $siblingId);
                if ($spouseHasDeletedAt) {
                    $existingSpouse->whereNull('deleted_at');
                }
                $existingSpouse = $existingSpouse->first();
                $spouseRow['profile_sibling_id'] = $siblingId;
                $spouseRow['updated_at'] = now();
                if ($existingSpouse) {
                    DB::table($spouseTable)->where('id', $existingSpouse->id)->update($spouseRow);
                } else {
                    $spouseRow['created_at'] = now();
                    DB::table($spouseTable)->insert($spouseRow);
                }
            } else {
                $toSoftDelete = DB::table($spouseTable)->where('profile_sibling_id', $siblingId);
                if ($spouseHasDeletedAt) {
                    $toSoftDelete->whereNull('deleted_at');
                }
                $toSoftDelete->update(['deleted_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * profile_horoscope_data has unique constraint on profile_id (one row per profile). Upsert by profile_id.
     */
    private function syncHoroscopeUpsert(MatrimonyProfile $profile, array $proposed): void
    {
        $row = is_array($proposed[0] ?? null) ? $proposed[0] : (isset($proposed[0]) && is_object($proposed[0]) ? (array) $proposed[0] : null);
        if ($row === null) {
            return;
        }
        $mapped = $this->mapSnapshotRowToTable('profile_horoscope_data', $row);
        $existing = DB::table('profile_horoscope_data')->where('profile_id', $profile->id)->first();
        $allowedColumns = array_fill_keys(Schema::getColumnListing('profile_horoscope_data'), true);
        $data = array_intersect_key(array_merge($mapped, ['profile_id' => $profile->id]), $allowedColumns);
        unset($data['id']);
        $data['updated_at'] = now();

        if ($existing) {
            $data = collect($data)->except(['created_at'])->all();
            DB::table('profile_horoscope_data')->where('id', $existing->id)->update($data);
            $this->writeProfileChangeHistory($profile->id, 'profile_horoscope_data', (int) $existing->id, 'update', json_encode($existing), json_encode($data));
        } else {
            $data['created_at'] = now();
            DB::table('profile_horoscope_data')->insert($data);
            $this->writeProfileChangeHistory($profile->id, 'profile_horoscope_data', null, 'insert', null, json_encode($data));
        }
    }

    private function syncEntityDiff(MatrimonyProfile $profile, string $entityType, array $proposed): void
    {
        if (! Schema::hasTable($entityType)) {
            return;
        }
        $existing = DB::table($entityType)->where('profile_id', $profile->id)->get()->keyBy('id');
        foreach ($proposed as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row = $this->mapSnapshotRowToTable($entityType, $row);
            $id = isset($row['id']) ? (int) $row['id'] : null;
            $existingRow = $id !== null ? $existing->get($id) : null;
            if ($existingRow === null) {
                // profile_contacts: skip insert when phone_number is empty so we don't insert useless/invalid rows.
                if ($entityType === 'profile_contacts') {
                    $phone = trim((string) ($row['phone_number'] ?? ''));
                    if ($phone === '') {
                        continue;
                    }
                }
                $insertData = array_merge(['profile_id' => $profile->id], $row);
                unset($insertData['id']);
                $insertData['created_at'] = now();
                $insertData['updated_at'] = now();
                // Only insert columns that exist on the table (intake/wizard may send extra keys e.g. address_line on profile_siblings).
                $allowedColumns = array_fill_keys(Schema::getColumnListing($entityType), true);
                $insertData = array_intersect_key($insertData, $allowedColumns);
                \Log::info('DEBUG DB OPERATION', [
                    'table' => $entityType,
                    'payload' => $insertData,
                ]);
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
                if (! empty($changes)) {
                    $updateData = collect($row)->except(['id', 'profile_id', 'created_at'])->all();
                    $updateData['updated_at'] = now();
                    $allowedColumns = array_fill_keys(Schema::getColumnListing($entityType), true);
                    $updateData = array_intersect_key($updateData, $allowedColumns);
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

        // Log deletion intent for existing rows not present in proposed snapshot.
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

        // For profile_relatives and profile_contacts: actually hard-delete rows that user removed from the wizard.
        if (in_array($entityType, ['profile_relatives', 'profile_contacts'], true)) {
            $deleteIds = [];
            foreach ($existing as $id => $existingRow) {
                if (! isset($proposedIds[$id])) {
                    $deleteIds[] = (int) $id;
                }
            }
            if (! empty($deleteIds)) {
                DB::table($entityType)
                    ->where('profile_id', $profile->id)
                    ->whereIn('id', $deleteIds)
                    ->delete();
            }
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
            if (! $registry) {
                throw ValidationException::withMessages([$fieldKey => ['Extended field is not defined in registry.']]);
            }
            if (($registry->is_enabled ?? true) === false) {
                continue;
            }
            $row = ProfileExtendedField::firstOrNew([
                'profile_id' => $profile->id,
                'field_key' => $fieldKey,
            ]);
            if (($registry->is_archived ?? false) && ! $row->exists) {
                throw ValidationException::withMessages([$fieldKey => ['This field is archived and cannot be set for new entry.']]);
            }
            if (! ExtendedFieldService::validateValue($registry, $value)) {
                throw ValidationException::withMessages([$fieldKey => ['Invalid value for data type '.$registry->data_type.'.']]);
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
        $merged = array_values(array_unique(array_merge($keys ?: [], self::FALLBACK_CORE_KEYS)));

        return $merged !== [] ? $merged : self::FALLBACK_CORE_KEYS;
    }

    /**
     * @param  array<string, mixed>  $proposedCore
     * @return array<string, mixed>
     */
    private function stripPhotoModerationFromManualCore(array $proposedCore): array
    {
        foreach (['photo_approved', 'photo_rejected_at', 'photo_rejection_reason'] as $k) {
            unset($proposedCore[$k]);
        }
        if (array_key_exists('profile_photo', $proposedCore) && $proposedCore['profile_photo'] === null) {
            unset($proposedCore['profile_photo']);
        }

        return $proposedCore;
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
            if (Schema::hasColumn($profile->getTable(), 'location_id')) {
                return $profile->getAttribute('location_id');
            }

            return null;
        }

        return $profile->getAttribute($fieldKey);
    }

    /**
     * Normalize intake snapshot core: resolve text (religion, caste, sub_caste, complexion, mother_tongue) to *_id
     * so that wizard dropdowns show the correct selection after apply.
     */
    private function normalizeIntakeCoreForApply(array $core): array
    {
        return app(\App\Services\Parsing\IntakeControlledFieldNormalizer::class)
            ->normalizeCore($core);
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
        if (! Schema::hasTable('profile_change_history')) {
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
        if (! Schema::hasTable('mutation_log')) {
            return false;
        }

        return true;
    }

    /**
     * Wizard / manual snapshot: skip duplicate rows with same 10-digit phone + relation (intake merge already dedupes separately).
     *
     * @param  array<int, mixed>  $proposed
     * @return array<int, mixed>
     */
    private function dedupeProposedProfileContactsByPhoneAndRelation(array $proposed): array
    {
        $out = [];
        $seen = [];
        foreach ($proposed as $row) {
            if (! is_array($row)) {
                $out[] = $row;

                continue;
            }
            $digits = $this->normalizePhoneDigitsForDedupe($row['phone_number'] ?? $row['number'] ?? null);
            if ($digits === '') {
                $out[] = $row;

                continue;
            }
            $relKey = 'rt:'.strtolower(trim((string) ($row['relation_type'] ?? '')));
            if (isset($row['contact_relation_id']) && $row['contact_relation_id'] !== null && $row['contact_relation_id'] !== '') {
                $relKey = 'rid:'.(int) $row['contact_relation_id'];
            }
            $key = $digits.'|'.$relKey;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Effective verification for a proposed contact row when merging wizard snapshot with DB.
     * New rows (no id) are unverified unless snapshot explicitly sets verified_status true.
     *
     * @param  \Illuminate\Support\Collection<int|string, object>  $existingById
     */
    private function effectiveVerifiedStatusForProposedContactRow(array $row, $existingById): bool
    {
        $id = isset($row['id']) ? (int) $row['id'] : null;
        $dbRow = $id !== null ? $existingById->get($id) : null;
        if ($dbRow !== null) {
            $dbVerified = ! empty($dbRow->verified_status);
            if (! array_key_exists('verified_status', $row)) {
                return $dbVerified;
            }

            return $dbVerified || ! empty($row['verified_status']);
        }

        return ! empty($row['verified_status']);
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, object>  $existingById
     * @return array{0: bool, 1: bool} [isSelf, verifiedEffective]
     */
    private function proposedContactRowSelfAndVerified(array $row, $existingById, int $selfRelationId, bool $enforceVerified): array
    {
        $mapped = $this->mapSnapshotRowToTable('profile_contacts', $row);
        $isSelf = (int) ($mapped['contact_relation_id'] ?? 0) === $selfRelationId;
        if (! $enforceVerified) {
            return [$isSelf, true];
        }
        $verified = $this->effectiveVerifiedStatusForProposedContactRow($row, $existingById);

        return [$isSelf, $verified];
    }

    /**
     * Self contacts: never assign is_primary when verified_status is false (wizard / manual snapshot).
     * If no verified self is marked primary after sanitization, keep the current verified primary when that row
     * still appears in the proposal (by id or normalized phone). Unverified primaries are not preserved.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $existing
     * @param  array<int, mixed>  $proposed
     * @return array<int, mixed>
     */
    private function applyVerifiedOnlyPrimaryRulesToProposedContacts($existing, array $proposed): array
    {
        if (! Schema::hasTable('profile_contacts')) {
            return $proposed;
        }
        $selfRelationId = $this->resolveSelfContactRelationId();
        if ($selfRelationId === null) {
            return $proposed;
        }
        $enforceVerified = Schema::hasColumn('profile_contacts', 'verified_status');
        $existingById = $existing->keyBy('id');

        foreach ($proposed as &$c) {
            if (! is_array($c)) {
                continue;
            }
            [$isSelf, $verified] = $this->proposedContactRowSelfAndVerified($c, $existingById, $selfRelationId, $enforceVerified);
            if ($isSelf && $enforceVerified && ! $verified && ! empty($c['is_primary'])) {
                $c['is_primary'] = false;
            }
        }
        unset($c);

        $primarySeen = false;
        foreach ($proposed as &$c) {
            if (! is_array($c) || empty($c['is_primary'])) {
                continue;
            }
            [$isSelf, $verified] = $this->proposedContactRowSelfAndVerified($c, $existingById, $selfRelationId, $enforceVerified);
            if (! $isSelf) {
                continue;
            }
            if ($enforceVerified && ! $verified) {
                $c['is_primary'] = false;

                continue;
            }
            if ($primarySeen) {
                $c['is_primary'] = false;

                continue;
            }
            $primarySeen = true;
        }
        unset($c);

        if ($enforceVerified && ! $primarySeen) {
            $existingPrimary = $existing->firstWhere('is_primary', true);
            if ($existingPrimary
                && (int) ($existingPrimary->contact_relation_id ?? 0) === $selfRelationId
                && ! empty($existingPrimary->verified_status)) {
                $epId = (int) $existingPrimary->id;
                $epDigits = $this->normalizePhoneDigitsForDedupe($existingPrimary->phone_number ?? null);
                $matched = false;
                foreach ($proposed as &$c) {
                    if (! is_array($c)) {
                        continue;
                    }
                    if (isset($c['id']) && (int) $c['id'] === $epId) {
                        $c['is_primary'] = true;
                        $matched = true;
                        break;
                    }
                }
                unset($c);
                if (! $matched && $epDigits !== '') {
                    foreach ($proposed as &$c) {
                        if (! is_array($c)) {
                            continue;
                        }
                        $mapped = $this->mapSnapshotRowToTable('profile_contacts', $c);
                        if ((int) ($mapped['contact_relation_id'] ?? 0) !== $selfRelationId) {
                            continue;
                        }
                        $pd = $this->normalizePhoneDigitsForDedupe($c['phone_number'] ?? $c['number'] ?? null);
                        if ($pd === $epDigits) {
                            $c['is_primary'] = true;
                            break;
                        }
                    }
                    unset($c);
                }
            }
        }

        return $proposed;
    }

    private function resolveSelfContactRelationId(): ?int
    {
        if (! Schema::hasTable('master_contact_relations')) {
            return null;
        }
        $id = DB::table('master_contact_relations')->where('key', 'self')->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function assertActingUserOwnsProfile(MatrimonyProfile $profile, int $actingUserId): void
    {
        if ((int) $profile->user_id !== (int) $actingUserId) {
            throw ValidationException::withMessages([
                'profile' => [__('contact_verify.unauthorized_profile')],
            ]);
        }
    }

    /**
     * Mark a profile_contacts row as verified (self numbers only). Used after OTP check in controller.
     */
    public function markSelfContactVerified(MatrimonyProfile $profile, int $contactId, int $actingUserId): void
    {
        $this->assertActingUserOwnsProfile($profile, $actingUserId);
        if (! Schema::hasTable('profile_contacts') || ! Schema::hasColumn('profile_contacts', 'verified_status')) {
            return;
        }
        $selfId = $this->resolveSelfContactRelationId();
        if ($selfId === null) {
            throw ValidationException::withMessages([
                'contact' => [__('contact_verify.self_relation_missing')],
            ]);
        }
        $row = DB::table('profile_contacts')->where('id', $contactId)->where('profile_id', $profile->id)->first();
        if (! $row) {
            throw ValidationException::withMessages([
                'contact' => [__('contact_verify.contact_not_found')],
            ]);
        }
        if ((int) ($row->contact_relation_id ?? 0) !== $selfId) {
            throw ValidationException::withMessages([
                'contact' => [__('contact_verify.only_self')],
            ]);
        }
        if (! empty($row->verified_status)) {
            return;
        }
        $prevSource = $this->historySourceContext;
        $prevBy = $this->historyChangedByContext;
        $this->historySourceContext = 'manual';
        $this->historyChangedByContext = $actingUserId;
        try {
            DB::table('profile_contacts')->where('id', $contactId)->update([
                'verified_status' => true,
                'updated_at' => now(),
            ]);
            $this->writeProfileChangeHistory($profile->id, 'profile_contacts', $contactId, 'verified_status', '0', '1');
        } finally {
            $this->historySourceContext = $prevSource;
            $this->historyChangedByContext = $prevBy;
        }
    }

    /**
     * Mark any other primary self row as non-primary and set this row primary. Requires verified_status when column exists.
     */
    public function promoteVerifiedSelfContactToPrimary(MatrimonyProfile $profile, int $contactId, int $actingUserId): void
    {
        $this->assertActingUserOwnsProfile($profile, $actingUserId);
        if (! Schema::hasTable('profile_contacts')) {
            throw ValidationException::withMessages([
                'contact' => [__('contact_verify.contacts_unavailable')],
            ]);
        }
        $selfId = $this->resolveSelfContactRelationId();
        if ($selfId === null) {
            throw ValidationException::withMessages([
                'contact' => [__('contact_verify.self_relation_missing')],
            ]);
        }
        $row = DB::table('profile_contacts')->where('id', $contactId)->where('profile_id', $profile->id)->first();
        if (! $row) {
            throw ValidationException::withMessages([
                'contact' => [__('contact_verify.contact_not_found')],
            ]);
        }
        if ((int) ($row->contact_relation_id ?? 0) !== $selfId) {
            throw ValidationException::withMessages([
                'contact' => [__('contact_verify.only_self_primary')],
            ]);
        }
        if (Schema::hasColumn('profile_contacts', 'verified_status') && empty($row->verified_status)) {
            throw ValidationException::withMessages([
                'contact' => [__('contact_verify.must_verify_first')],
            ]);
        }
        if (! empty($row->is_primary)) {
            return;
        }

        $prevSource = $this->historySourceContext;
        $prevBy = $this->historyChangedByContext;
        $this->historySourceContext = 'manual';
        $this->historyChangedByContext = $actingUserId;
        try {
            DB::transaction(function () use ($profile, $contactId): void {
                $others = DB::table('profile_contacts')
                    ->where('profile_id', $profile->id)
                    ->where('id', '!=', $contactId)
                    ->where('is_primary', true)
                    ->get();
                foreach ($others as $er) {
                    DB::table('profile_contacts')->where('id', $er->id)->update([
                        'is_primary' => false,
                        'updated_at' => now(),
                    ]);
                    $this->writeProfileChangeHistory($profile->id, 'profile_contacts', (int) $er->id, 'is_primary', '1', '0');
                }
                DB::table('profile_contacts')->where('id', $contactId)->update([
                    'is_primary' => true,
                    'updated_at' => now(),
                ]);
                $this->writeProfileChangeHistory($profile->id, 'profile_contacts', $contactId, 'is_primary', '0', '1');
            });
        } finally {
            $this->historySourceContext = $prevSource;
            $this->historyChangedByContext = $prevBy;
        }
    }

    /**
     * Keys allowed when user explicitly applies a single core value from pending intake suggestions.
     *
     * @return list<string>
     */
    public function coreFieldKeysAllowedForIntakeSuggestionApply(): array
    {
        return $this->getCoreFieldKeysFromRegistry();
    }

    /**
     * Core keys handled by contact / overflow intake paths — do not field-partition (avoid duplicating suggestion vs syncContactsFromIntakeApply).
     *
     * @var list<string>
     */
    private const INTAKE_CORE_KEYS_EXCLUDED_FROM_FIELD_SUGGESTION_PARTITION = [
        'primary_contact_number', 'primary_contact_number_2', 'primary_contact_number_3',
        'primary_contact_whatsapp', 'primary_contact_whatsapp_2', 'primary_contact_whatsapp_3',
        'father_contact_2', 'father_contact_3', 'mother_contact_2', 'mother_contact_3',
    ];

    /**
     * Intake apply: per-field merge for existing profiles — empty profile + intake value applies;
     * same value → noop; different non-empty → pending suggestion (core + core_field_suggestions), never silent overwrite.
     * Contact-specific keys are excluded here; see syncContactsFromIntakeApply.
     *
     * @param  array<string, mixed>  $snapshot  Working intake snapshot (modified in place).
     * @param  array<string, mixed>  $proposedCore  Core subset used by apply pipeline (modified in place).
     * @return array<string, mixed>
     */
    private function partitionIntakeSnapshotForExistingProfile(MatrimonyProfile $profile, array &$snapshot, array &$proposedCore, ?int $sourceIntakeId = null): array
    {
        $profile->loadMissing('user');

        $suggestions = [
            'core' => [],
            'core_field_suggestions' => [],
            'extended' => [],
            'entities' => [],
        ];

        // Normalize text→*_id etc. so equality checks match what apply will use (e.g. religion string vs religion_id).
        $normalizedProposedCore = $this->normalizeIntakeCoreForApply($proposedCore);

        foreach (array_keys($proposedCore) as $fieldKey) {
            if (! array_key_exists($fieldKey, $proposedCore)) {
                continue;
            }
            if (in_array($fieldKey, self::INTAKE_CORE_KEYS_EXCLUDED_FROM_FIELD_SUGGESTION_PARTITION, true)) {
                continue;
            }

            $proposedRaw = $proposedCore[$fieldKey];
            $proposedNorm = $normalizedProposedCore[$fieldKey] ?? $proposedRaw;

            if ($this->isEmptyIntakeProposedValue($fieldKey, $proposedRaw)) {
                unset($proposedCore[$fieldKey]);
                if (isset($snapshot['core']) && is_array($snapshot['core'])) {
                    unset($snapshot['core'][$fieldKey]);
                }

                continue;
            }

            $current = $this->getCurrentCoreValue($profile, $fieldKey);

            if ($this->isEmptyExistingValueForIntakeMerge($fieldKey, $current)) {
                continue;
            }

            if ($this->valuesEqualForIntakeFieldMerge($fieldKey, $current, $proposedNorm)) {
                unset($proposedCore[$fieldKey]);
                if (isset($snapshot['core']) && is_array($snapshot['core'])) {
                    unset($snapshot['core'][$fieldKey]);
                }

                continue;
            }

            $suggestions['core'][$fieldKey] = $proposedNorm;
            $suggestions['core_field_suggestions'][] = [
                'field' => $fieldKey,
                'old_value' => $this->scalarToIntakeSuggestionString($current),
                'new_value' => $this->scalarToIntakeSuggestionString($proposedNorm),
                'source_intake_id' => $sourceIntakeId,
            ];
            unset($proposedCore[$fieldKey]);
            if (isset($snapshot['core']) && is_array($snapshot['core'])) {
                unset($snapshot['core'][$fieldKey]);
            }
        }

        if (isset($snapshot['extended']) && is_array($snapshot['extended'])) {
            $currentExtended = ExtendedFieldService::getValuesForProfile($profile);
            foreach ($snapshot['extended'] as $ek => $ev) {
                $cur = $currentExtended[$ek] ?? null;
                if ($this->isEmptyExistingValueForIntakeMerge((string) $ek, $cur)) {
                    continue;
                }
                $suggestions['extended'][$ek] = $ev;
                unset($snapshot['extended'][$ek]);
            }
            if ($snapshot['extended'] === []) {
                unset($snapshot['extended']);
            }
        }

        $hasAnyPlace = static function (array $place): bool {
            foreach (['city_id', 'taluka_id', 'district_id', 'state_id'] as $k) {
                if (isset($place[$k]) && $place[$k] !== null && $place[$k] !== '' && (int) $place[$k] !== 0) {
                    return true;
                }
            }

            return false;
        };

        if (isset($snapshot['birth_place']) && is_array($snapshot['birth_place']) && $hasAnyPlace($snapshot['birth_place'])) {
            if ($this->profileHasExistingBirthPlace($profile)) {
                $suggestions['birth_place'] = $snapshot['birth_place'];
                unset($snapshot['birth_place']);
            }
        }

        if (isset($snapshot['native_place']) && is_array($snapshot['native_place']) && $hasAnyPlace($snapshot['native_place'])) {
            if ($this->profileHasExistingNativePlace($profile)) {
                $suggestions['native_place'] = $snapshot['native_place'];
                unset($snapshot['native_place']);
            }
        }

        // Intake contacts: merged in MutationService::syncContactsFromIntakeApply (append-only + suggestions).
        // Do not strip snapshot['contacts'] here — that previously blocked all intake numbers when any DB row existed.

        foreach (self::ENTITY_SYNC_ORDER as $snapshotKey) {
            $table = self::SNAPSHOT_KEY_TO_TABLE[$snapshotKey] ?? null;
            if ($table === null || ! Schema::hasTable($table)) {
                continue;
            }
            if (empty($snapshot[$snapshotKey]) || ! is_array($snapshot[$snapshotKey])) {
                continue;
            }
            if ($this->entityRowCountForProfile($table, (int) $profile->id) > 0) {
                $suggestions['entities'][$snapshotKey] = $snapshot[$snapshotKey];
                unset($snapshot[$snapshotKey]);
            }
        }

        if (isset($snapshot['preferences']) && is_array($snapshot['preferences'])) {
            if (Schema::hasTable('profile_preference_criteria')
                && DB::table('profile_preference_criteria')->where('profile_id', $profile->id)->exists()) {
                $suggestions['preferences'] = $snapshot['preferences'];
                unset($snapshot['preferences']);
            }
        }

        if (isset($snapshot['extended_narrative']) && is_array($snapshot['extended_narrative']) && Schema::hasTable('profile_extended_attributes')) {
            if ($this->profileExtendedNarrativeHasAnyText($profile->id)) {
                $suggestions['extended_narrative'] = $snapshot['extended_narrative'];
                unset($snapshot['extended_narrative']);
            }
        }

        if (array_key_exists('other_relatives_text', $snapshot) && trim((string) $snapshot['other_relatives_text']) !== '') {
            $cur = (string) ($profile->other_relatives_text ?? '');
            if (trim($cur) !== '') {
                $suggestions['other_relatives_text'] = $snapshot['other_relatives_text'];
                unset($snapshot['other_relatives_text']);
            }
        }

        return $this->pruneEmptySuggestionBuckets($suggestions);
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    private function mergePendingIntakeSuggestionsIntoProfile(MatrimonyProfile $profile, array $delta): void
    {
        $delta = $this->pruneEmptySuggestionBuckets($delta);
        if ($delta === []) {
            return;
        }
        $prev = $profile->pending_intake_suggestions_json;
        if (! is_array($prev)) {
            $prev = [];
        }
        $profile->pending_intake_suggestions_json = $this->mergeSuggestionPayloads($prev, $delta);
        $profile->save();
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function mergeSuggestionPayloads(array $a, array $b): array
    {
        $out = $a;
        foreach (['core', 'extended', 'entities'] as $k) {
            if (! empty($b[$k]) && is_array($b[$k])) {
                $out[$k] = array_merge($out[$k] ?? [], $b[$k]);
            }
        }
        foreach (['birth_place', 'native_place', 'contacts', 'preferences', 'extended_narrative', 'other_relatives_text'] as $k) {
            if (array_key_exists($k, $b) && $b[$k] !== null && $b[$k] !== [] && $b[$k] !== '') {
                $out[$k] = $b[$k];
            }
        }
        if (! empty($b['core_field_suggestions']) && is_array($b['core_field_suggestions'])) {
            $out['core_field_suggestions'] = array_values(array_merge($out['core_field_suggestions'] ?? [], $b['core_field_suggestions']));
        }

        return $this->pruneEmptySuggestionBuckets($out);
    }

    /**
     * @param  array<string, mixed>  $suggestions
     * @return array<string, mixed>
     */
    private function pruneEmptySuggestionBuckets(array $suggestions): array
    {
        foreach ($suggestions as $k => $v) {
            if ($v === null || $v === [] || $v === '') {
                unset($suggestions[$k]);
            }
            if (is_array($v) && $v === []) {
                unset($suggestions[$k]);
            }
        }

        return $suggestions;
    }

    private function entityRowCountForProfile(string $table, int $profileId): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }
        $q = DB::table($table)->where('profile_id', $profileId);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        return (int) $q->count();
    }

    private function profileHasExistingBirthPlace(MatrimonyProfile $profile): bool
    {
        if (trim((string) ($profile->birth_place_text ?? '')) !== '') {
            return true;
        }
        foreach (['birth_city_id'] as $col) {
            $v = $profile->getAttribute($col);
            if ($v !== null && $v !== '' && (int) $v !== 0) {
                return true;
            }
        }

        return false;
    }

    private function profileHasExistingNativePlace(MatrimonyProfile $profile): bool
    {
        foreach (['native_city_id', 'native_taluka_id', 'native_district_id', 'native_state_id'] as $col) {
            $v = $profile->getAttribute($col);
            if ($v !== null && $v !== '' && (int) $v !== 0) {
                return true;
            }
        }

        return false;
    }

    private function profileExtendedNarrativeHasAnyText(int $profileId): bool
    {
        $row = DB::table('profile_extended_attributes')->where('profile_id', $profileId)->first();
        if (! $row) {
            return false;
        }
        foreach (['narrative_about_me', 'narrative_expectations', 'additional_notes'] as $col) {
            if (trim((string) ($row->$col ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function isEmptyExistingValueForIntakeMerge(string $fieldKey, mixed $current): bool
    {
        if ($current instanceof \DateTimeInterface) {
            $current = $current->format('Y-m-d');
        }
        if ($current === null || $current === '') {
            return true;
        }
        if (is_string($current) && trim($current) === '') {
            return true;
        }
        if (str_ends_with($fieldKey, '_id') && (int) $current === 0) {
            return true;
        }
        if ($fieldKey === 'location') {
            return (int) ($current ?? 0) === 0;
        }
        if (in_array($fieldKey, ['has_children', 'has_siblings'], true)) {
            return $current === null;
        }

        return false;
    }

    private function isEmptyIntakeProposedValue(string $fieldKey, mixed $v): bool
    {
        if ($v === null || $v === '') {
            return true;
        }
        if (is_string($v) && trim($v) === '') {
            return true;
        }
        if (str_ends_with($fieldKey, '_id') && (string) $v !== '' && is_numeric($v) && (int) $v === 0) {
            return true;
        }

        return false;
    }

    /**
     * Deterministic equality for “same as profile” (Case C noop) after intake normalization.
     */
    private function valuesEqualForIntakeFieldMerge(string $fieldKey, mixed $current, mixed $proposed): bool
    {
        if ($current instanceof \DateTimeInterface) {
            $current = $current->format('Y-m-d');
        }
        if ($proposed instanceof \DateTimeInterface) {
            $proposed = $proposed->format('Y-m-d');
        }
        if (str_ends_with($fieldKey, '_id')) {
            return (int) ($current ?? 0) === (int) ($proposed ?? 0);
        }
        if (in_array($fieldKey, ['annual_income', 'family_income', 'height_cm'], true)) {
            if ($current === null && $proposed === null) {
                return true;
            }
            if (! is_numeric($current) || ! is_numeric($proposed)) {
                return trim((string) ($current ?? '')) === trim((string) ($proposed ?? ''));
            }

            return round((float) $current, 4) === round((float) $proposed, 4);
        }
        if (in_array($fieldKey, ['has_children', 'has_siblings'], true)) {
            return (int) ($current ?? -1) === (int) ($proposed ?? -1);
        }

        return trim((string) ($current ?? '')) === trim((string) ($proposed ?? ''));
    }

    private function scalarToIntakeSuggestionString(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_array($v)) {
            $enc = json_encode($v, JSON_UNESCAPED_UNICODE);

            return is_string($enc) ? $enc : '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }

        return trim((string) $v);
    }
}
