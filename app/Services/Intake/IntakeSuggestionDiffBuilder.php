<?php

namespace App\Services\Intake;

use App\Models\MatrimonyProfile;
use App\Services\ExtendedFieldService;
use App\Services\Parsing\IntakeControlledFieldNormalizer;
use App\Support\ArrayDiffHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds pending_intake_suggestions_json-shaped deltas (core / core_field_suggestions / extended / place blobs)
 * aligned with MutationService::partitionIntakeSnapshotForExistingProfile semantics.
 */
class IntakeSuggestionDiffBuilder
{
    /** @var list<string> */
    private const CORE_KEYS_EXCLUDED = [
        'primary_contact_number', 'primary_contact_number_2', 'primary_contact_number_3',
        'primary_contact_whatsapp', 'primary_contact_whatsapp_2', 'primary_contact_whatsapp_3',
        'father_contact_2', 'father_contact_3', 'mother_contact_2', 'mother_contact_3',
    ];

    public function __construct(
        private IntakeControlledFieldNormalizer $controlledFieldNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $mappedSnapshot  Full intake snapshot after normalize + dictionary
     * @return array<string, mixed>
     */
    public function build(MatrimonyProfile $profile, array $mappedSnapshot, ?int $sourceIntakeId = null): array
    {
        $suggestions = [
            'core' => [],
            'core_field_suggestions' => [],
            'extended' => [],
        ];

        $proposedCore = is_array($mappedSnapshot['core'] ?? null) ? $mappedSnapshot['core'] : [];
        $normalizedProposedCore = $this->controlledFieldNormalizer->normalizeCore($proposedCore);

        foreach (array_keys($proposedCore) as $fieldKey) {
            if (! array_key_exists($fieldKey, $proposedCore)) {
                continue;
            }
            if (in_array($fieldKey, self::CORE_KEYS_EXCLUDED, true)) {
                continue;
            }

            $proposedRaw = $proposedCore[$fieldKey];
            $proposedNorm = $normalizedProposedCore[$fieldKey] ?? $proposedRaw;

            if ($this->isEmptyProposed($fieldKey, $proposedRaw)) {
                continue;
            }

            $current = $this->currentCoreValue($profile, $fieldKey);

            if ($this->isEmptyExisting($fieldKey, $current)) {
                continue;
            }

            if ($this->valuesEqual($fieldKey, $current, $proposedNorm)) {
                continue;
            }

            $suggestions['core'][$fieldKey] = $proposedNorm;
            $suggestions['core_field_suggestions'][] = [
                'field' => $fieldKey,
                'old_value' => $this->scalarToString($current),
                'new_value' => $this->scalarToString($proposedNorm),
                'source_intake_id' => $sourceIntakeId,
            ];
        }

        $currentExtended = ExtendedFieldService::getValuesForProfile($profile);
        $proposedExtended = is_array($mappedSnapshot['extended'] ?? null) ? $mappedSnapshot['extended'] : [];
        foreach ($proposedExtended as $ek => $ev) {
            if (! is_string($ek)) {
                continue;
            }
            if ($ev === null || $ev === '' || (is_string($ev) && trim($ev) === '')) {
                continue;
            }
            $cur = $currentExtended[$ek] ?? null;
            if ($this->isEmptyExisting($ek, $cur)) {
                continue;
            }
            if ($this->extendedValuesEqual($cur, $ev)) {
                continue;
            }
            $suggestions['extended'][$ek] = $ev;
        }

        $this->appendPlaceSuggestions($profile, $mappedSnapshot, $suggestions);

        return $this->pruneEmpty($suggestions);
    }

    private function appendPlaceSuggestions(MatrimonyProfile $profile, array $mapped, array &$suggestions): void
    {
        $hasAnyPlace = static function (array $place): bool {
            foreach (['city_id', 'taluka_id', 'district_id', 'state_id'] as $k) {
                if (isset($place[$k]) && $place[$k] !== null && $place[$k] !== '' && (int) $place[$k] !== 0) {
                    return true;
                }
            }

            return false;
        };

        if (isset($mapped['birth_place']) && is_array($mapped['birth_place']) && $hasAnyPlace($mapped['birth_place'])) {
            if ($this->profileHasExistingBirthPlace($profile)) {
                $suggestions['birth_place'] = $mapped['birth_place'];
            }
        }

        if (isset($mapped['native_place']) && is_array($mapped['native_place']) && $hasAnyPlace($mapped['native_place'])) {
            if ($this->profileHasExistingNativePlace($profile)) {
                $suggestions['native_place'] = $mapped['native_place'];
            }
        }
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

    private function currentCoreValue(MatrimonyProfile $profile, string $fieldKey): mixed
    {
        if ($fieldKey === 'gender_id') {
            return $profile->getAttribute('gender_id');
        }
        if ($fieldKey === 'primary_contact_number') {
            if (! Schema::hasTable('profile_contacts')) {
                return null;
            }

            return DB::table('profile_contacts')
                ->where('profile_id', $profile->id)
                ->where('is_primary', true)
                ->value('phone_number');
        }
        if ($fieldKey === 'location') {
            if (Schema::hasColumn('matrimony_profiles', 'location_id')) {
                return $profile->getAttribute('location_id');
            }

            return null;
        }

        return $profile->getAttribute($fieldKey);
    }

    private function isEmptyExisting(string $fieldKey, mixed $current): bool
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

    private function isEmptyProposed(string $fieldKey, mixed $v): bool
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

    private function valuesEqual(string $fieldKey, mixed $current, mixed $proposed): bool
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

    private function extendedValuesEqual(mixed $current, mixed $proposed): bool
    {
        return ArrayDiffHelper::deepCompare($current, $proposed);
    }

    private function scalarToString(mixed $v): string
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

    /**
     * @param  array<string, mixed>  $suggestions
     * @return array<string, mixed>
     */
    private function pruneEmpty(array $suggestions): array
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
}
