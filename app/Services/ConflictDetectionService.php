<?php

namespace App\Services;

use App\Models\ConflictRecord;
use App\Models\FieldRegistry;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Services\Location\LocationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use App\Services\Profile\ProfileTypedSelfAddressService;

/**
 * Phase-3 Day-13 / Phase-5: Conflict detection with Escalation Matrix.
 * Compares what a machine proposes against what the profile already says.
 *
 * It no longer records anything: the owner's rule is that a machine fills what
 * is empty and never touches what is answered, which leaves no disagreement for
 * it to file. Conflicts still exist — they are raised where a HUMAN proposes a
 * competing value (duplicate detection and lock violations in MutationService,
 * source USER) and resolved through ConflictResolutionService.
 * Does NOT mutate profile, change lifecycle, or write history.
 * CORE classification from FieldRegistry; identity-critical vs dynamic from contract.
 *
 * Rule: When profile.lifecycle_state == 'draft', do NOT treat identity-critical field
 * differences as conflict; allow initial identity population. Escalation matrix
 * applies only when lifecycle_state != 'draft'.
 */
class ConflictDetectionService
{
    /** Identity-critical fields: change → conflict; under serious_intent → requires admin. Phase-5: *_id for master lookups. */
    private const IDENTITY_CRITICAL_KEYS = [
        'full_name',
        'date_of_birth',
        'gender_id',
        'religion_id',
        'caste_id',
        'sub_caste_id',
        'marital_status_id',
        'primary_contact_number',
        'serious_intent_id',
    ];

    /** Dynamic fields: value diff does NOT create conflict; apply with history only. */
    private const DYNAMIC_KEYS = [
        'annual_income',
        'family_income',
        'occupation_title',
        'company_name',
        'occupation_master_id',
        'occupation_custom_id',
        'work_city_id',
        'work_state_id',
        'work_location_text',
    ];

    /**
     * Run conflict detection with Escalation Matrix; returns full result (records + requiresAdminResolution).
     *
     * @param  array<string, mixed>  $proposedCore
     * @param  array<string, mixed>  $proposedExtended
     */
    public static function detectResult(
        MatrimonyProfile $profile,
        array $proposedCore = [],
        array $proposedExtended = [],
    ): ConflictDetectionResult {
        $created = [];
        $requiresAdmin = false;
        $seriousIntentId = $profile->serious_intent_id ?? null;
        $seriousIntentActive = $seriousIntentId !== null && $seriousIntentId !== '';

        $coreFieldKeys = self::getCoreFieldKeysFromRegistry();

        foreach ($coreFieldKeys as $fieldKey) {
            if (! array_key_exists($fieldKey, $proposedCore)) {
                continue;
            }
            if (ProfileFieldLockService::isLocked($profile, $fieldKey)) {
                continue;
            }
            $current = self::getCurrentCoreValue($profile, $fieldKey);
            $proposed = self::normalize($proposedCore[$fieldKey]);
            if (! self::valuesDiffer($current, $proposed)) {
                continue;
            }

            if (self::isDynamicField($fieldKey) && ! $seriousIntentActive) {
                continue;
            }

            // Draft: allow full initial population from intake; do not create conflicts for any field.
            if (self::isDraft($profile)) {
                continue;
            }

            // THE RULE (owner, 2026-08-08). A machine fills what is EMPTY and
            // never touches what is ANSWERED. Both halves end here:
            //
            //  - empty field: filling it is a fill, not a disagreement, so the
            //    value goes on through and nothing is recorded;
            //  - answered field: a different reading is not the machine's to
            //    argue with. It is dropped, NOT queued for a human, because
            //    there is no question to put to anyone — the answer on the
            //    profile stands.
            //
            // Which is why nothing below this line writes a record any more.
            // A member was invisible for 37 days over a biodata sheet that read
            // 180.34 where his profile said 168, and there was never a decision
            // for anyone to make about it.
            continue;
        }

        // Extended fields obey the same rule, and had a second defect of their
        // own: this loop walked the union of stored and proposed keys, so a
        // field simply ABSENT from the payload was read as a proposal to empty
        // it. Omission is not a proposal.

        return new ConflictDetectionResult($created, $requiresAdmin);
    }

    /**
     * Would this write REPLACE an answer that is already on the profile?
     *
     * Separate from detection on purpose. Detection answers "does the machine
     * have anything to add", and since the rule above it never has anything to
     * argue, it now writes nothing. This answers a different question, asked by
     * MatrimonyProfile's save guard: is a governed field being overwritten
     * directly, behind MutationService's back? That guard aborts the save, so
     * it must not leave a PENDING row behind for a change that never happened —
     * which is one way profiles ended up frozen with nothing to resolve.
     *
     * @param  array<string, mixed>  $proposedCore
     */
    public static function wouldOverwriteAnsweredField(MatrimonyProfile $profile, array $proposedCore): array
    {
        if (self::isDraft($profile)) {
            return [];
        }

        $seriousIntentId = $profile->serious_intent_id ?? null;
        $seriousIntentActive = $seriousIntentId !== null && $seriousIntentId !== '';

        $overwritten = [];

        foreach (self::getCoreFieldKeysFromRegistry() as $fieldKey) {
            if (! array_key_exists($fieldKey, $proposedCore)) {
                continue;
            }
            // The exemptions below are the ones detection has always applied
            // before it would refuse anything. Losing them here would make this
            // guard stricter than the rule it replaced: a locked field is
            // already refused by the caller with a clearer message, a dynamic
            // field is expected to move on its own, and a field that is already
            // waiting on a human is being handled, not re-refused.
            if (ProfileFieldLockService::isLocked($profile, $fieldKey)) {
                continue;
            }
            if (self::isDynamicField($fieldKey) && ! $seriousIntentActive) {
                continue;
            }
            $current = self::normalize(self::getCurrentCoreValue($profile, $fieldKey));
            if ($current === null) {
                continue;
            }
            if (! self::valuesDiffer($current, self::normalize($proposedCore[$fieldKey]))) {
                continue;
            }
            if (ConflictRecord::where('profile_id', $profile->id)
                ->where('field_name', $fieldKey)
                ->where('resolution_status', 'PENDING')
                ->exists()) {
                continue;
            }

            $overwritten[] = $fieldKey;
        }

        return $overwritten;
    }

    /**
     * Run conflict detection; returns conflict records array (backward compatible).
     * Escalation matrix is applied internally; use detectResult() when requiresAdminResolution is needed.
     *
     * @param  array<string, mixed>  $proposedCore
     * @param  array<string, mixed>  $proposedExtended
     * @return ConflictRecord[]
     */
    public static function detect(
        MatrimonyProfile $profile,
        array $proposedCore = [],
        array $proposedExtended = [],
    ): array {
        return self::detectResult($profile, $proposedCore, $proposedExtended)->conflictRecords;
    }

    /** Fallback CORE keys when registry is empty (schema-bound). Phase-5: *_id for master lookups. */
    private const FALLBACK_CORE_KEYS = [
        'full_name', 'gender_id', 'date_of_birth', 'marital_status_id', 'highest_education',
        'location', 'religion_id', 'caste_id', 'sub_caste_id', 'height_cm', 'profile_photo',
        'complexion_id', 'physical_build_id', 'blood_group_id', 'family_type_id', 'income_currency_id',
    ];

    /**
     * CORE field keys from FieldRegistry (single source). Excludes archived; respects is_enabled when present.
     * Falls back to FALLBACK_CORE_KEYS when registry has no CORE rows.
     *
     * @return array<int, string>
     */
    private static function getCoreFieldKeysFromRegistry(): array
    {
        $query = FieldRegistry::where('field_type', 'CORE')
            ->where(function ($q) {
                $q->where('is_archived', false)->orWhereNull('is_archived');
            })
            ->whereNull('replaced_by_field');
        if (\Illuminate\Support\Facades\Schema::hasColumn((new FieldRegistry)->getTable(), 'is_enabled')) {
            $query->where(function ($q) {
                $q->where('is_enabled', true)->orWhereNull('is_enabled');
            });
        }
        $keys = $query->pluck('field_key')->values()->all();

        return $keys !== [] ? $keys : self::FALLBACK_CORE_KEYS;
    }

    private static function isDraft(MatrimonyProfile $profile): bool
    {
        return ($profile->lifecycle_state ?? '') === 'draft';
    }

    private static function isIdentityCriticalField(string $fieldKey): bool
    {
        return in_array($fieldKey, self::IDENTITY_CRITICAL_KEYS, true);
    }

    private static function isDynamicField(string $fieldKey): bool
    {
        return in_array($fieldKey, self::DYNAMIC_KEYS, true);
    }

    private static function getCurrentCoreValue(MatrimonyProfile $profile, string $fieldKey): mixed
    {
        if ($fieldKey === 'gender_id') {
            return $profile->getAttribute('gender_id');
        }
        if ($fieldKey === 'primary_contact_number') {
            return \Illuminate\Support\Facades\DB::table('profile_contacts')
                ->where('profile_id', $profile->id)
                ->where('is_primary', true)
                ->value('phone_number');
        }
        if ($fieldKey === 'location' || $fieldKey === 'location_id') {
            if (\Illuminate\Support\Facades\Schema::hasColumn($profile->getTable(), 'location_id')) {
                return $profile->getAttribute('location_id');
            }

            return $profile->exists
                ? ProfileCanonicalResidenceService::locationLeafId((int) $profile->id)
                : null;
        }
        if ($fieldKey === 'address_line') {
            if (\Illuminate\Support\Facades\Schema::hasColumn($profile->getTable(), 'address_line')) {
                return $profile->getAttribute('address_line');
            }

            return $profile->exists
                ? ProfileCanonicalResidenceService::addressLineRaw((int) $profile->id)
                : null;
        }
        if ($fieldKey === 'work_city_id') {
            if (\Illuminate\Support\Facades\Schema::hasColumn($profile->getTable(), 'work_city_id')) {
                return $profile->getAttribute('work_city_id');
            }

            return $profile->exists
                ? ProfileTypedSelfAddressService::locationLeafIdForSelfType((int) $profile->id, 'work')
                : null;
        }
        if ($fieldKey === 'work_state_id') {
            if (\Illuminate\Support\Facades\Schema::hasColumn($profile->getTable(), 'work_state_id')) {
                return $profile->getAttribute('work_state_id');
            }
            $leaf = $profile->exists
                ? ProfileTypedSelfAddressService::locationLeafIdForSelfType((int) $profile->id, 'work')
                : null;
            if ($leaf === null || ! \Illuminate\Support\Facades\Schema::hasTable(Location::geoTable())) {
                return null;
            }
            $row = Location::query()->find($leaf);
            if ($row === null) {
                return null;
            }
            $state = app(LocationService::class)->getAncestorByType($row, 'state');

            return $state?->id;
        }
        if ($fieldKey === 'work_location_text') {
            if (\Illuminate\Support\Facades\Schema::hasColumn($profile->getTable(), 'work_location_text')) {
                return $profile->getAttribute('work_location_text');
            }

            return $profile->exists
                ? ProfileTypedSelfAddressService::addressLineForSelfType((int) $profile->id, 'work')
                : null;
        }
        if ($fieldKey === 'occupation_title') {
            if (\Illuminate\Support\Facades\Schema::hasColumn($profile->getTable(), 'occupation_title')) {
                return $profile->getAttribute('occupation_title');
            }
            $profile->loadMissing(['occupationMaster', 'occupationCustom']);
            $t = trim((string) ($profile->occupationMaster?->name ?? $profile->occupationCustom?->raw_name ?? ''));

            return $t !== '' ? $t : null;
        }
        if ($fieldKey === 'profession_id') {
            if (\Illuminate\Support\Facades\Schema::hasColumn($profile->getTable(), 'profession_id')) {
                return $profile->getAttribute('profession_id');
            }

            return $profile->resolvedProfession()?->id;
        }
        if ($fieldKey === 'working_with_type_id') {
            if (\Illuminate\Support\Facades\Schema::hasColumn($profile->getTable(), 'working_with_type_id')) {
                return $profile->getAttribute('working_with_type_id');
            }

            return $profile->resolvedWorkingWithType()?->id;
        }

        return $profile->getAttribute($fieldKey);
    }

    private static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = is_string($value) ? trim($value) : (string) $value;

        return $s === '' ? null : $s;
    }

    private static function valuesDiffer(?string $a, ?string $b): bool
    {
        return (string) ($a ?? '') !== (string) ($b ?? '');
    }
}
