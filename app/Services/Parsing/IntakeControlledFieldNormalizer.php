<?php

namespace App\Services\Parsing;

use App\Models\City;
use App\Services\BiodataParserService;
use App\Services\ControlledOptions\ControlledOptionNormalizer;
use App\Services\EducationService;
use App\Services\Location\LocationNormalizationService;
use App\Services\Location\LocationOpenPlaceSuggestionService;
use App\Services\OccupationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Deterministic intake snapshot normalizer for controlled fields.
 * Non-destructive: preserve source text, only add safe IDs/canonical forms.
 */
class IntakeControlledFieldNormalizer
{
    private ?int $openPlaceSuggestionUserId = null;

    public function __construct(
        private ControlledOptionNormalizer $controlled,
        private IntakeParsedSnapshotSkeleton $skeleton,
        private EducationService $education,
        private OccupationService $occupation,
        private LocationNormalizationService $locationNormalization,
        private LocationOpenPlaceSuggestionService $openPlaceSuggestions,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function normalizeSnapshot(array $snapshot, ?int $suggestedByUserId = null): array
    {
        $prevUserId = $this->openPlaceSuggestionUserId;
        $this->openPlaceSuggestionUserId = ($suggestedByUserId !== null && $suggestedByUserId > 0)
            ? $suggestedByUserId
            : null;
        try {
            $out = $this->skeleton->ensure($snapshot);
            $out = $this->migrateLegacyAiSnapshot($out);
            $out['core'] = $this->normalizeCore(is_array($out['core'] ?? null) ? $out['core'] : []);
            $this->normalizeBirthPlace($out);
            $this->normalizeNativePlace($out);
            $this->normalizeAddressRows($out);
            $out['horoscope'] = $this->normalizeHoroscopeRows(is_array($out['horoscope'] ?? null) ? $out['horoscope'] : []);
            $out['contacts'] = $this->normalizeContacts(is_array($out['contacts'] ?? null) ? $out['contacts'] : []);
            $out['education_history'] = $this->normalizeEducationRows(is_array($out['education_history'] ?? null) ? $out['education_history'] : []);
            $out['career_history'] = $this->normalizeCareerRows(is_array($out['career_history'] ?? null) ? $out['career_history'] : []);
            $this->normalizeWorkLocation($out);
            [$out['relatives'], $out['relatives_sectioned']] = $this->normalizeRelativesRows(is_array($out['relatives'] ?? null) ? $out['relatives'] : []);
            $out['relatives_parents_family'] = $this->flattenSectionedRelatives($out['relatives_sectioned']['paternal'] ?? []);
            $out['relatives_maternal_family'] = $this->flattenSectionedRelatives($out['relatives_sectioned']['maternal'] ?? []);
            $this->fillParentNamesFromRelatives($out);
            $this->reconcilePrimaryContactVersusRelatives($out);

            $matchedCount = 0;
            $unmatchedCritical = [];
            foreach (['gender_id', 'religion_id', 'caste_id', 'sub_caste_id', 'marital_status_id'] as $k) {
                if (! empty($out['core'][$k])) {
                    $matchedCount++;
                } else {
                    $rawKey = str_replace('_id', '', $k);
                    if (trim((string) ($out['core'][$rawKey] ?? '')) !== '') {
                        $unmatchedCritical[] = $k;
                    }
                }
            }
            Log::debug('IntakeControlledFieldNormalizer: snapshot normalized', [
                'matched_controlled_core_ids' => $matchedCount,
                'unmatched_critical' => $unmatchedCritical,
            ]);

            return $out;
        } finally {
            $this->openPlaceSuggestionUserId = $prevUserId;
        }
    }

    /**
     * Re-apply canonical core/contact/horoscope mapping after ParsedJsonSsotNormalizer (AI path).
     * Idempotent; does not re-bucket relatives.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function finalizePostSsotSnapshot(array $snapshot, ?int $suggestedByUserId = null): array
    {
        $prevUserId = $this->openPlaceSuggestionUserId;
        $this->openPlaceSuggestionUserId = ($suggestedByUserId !== null && $suggestedByUserId > 0)
            ? $suggestedByUserId
            : null;
        try {
            $snapshot = $this->migrateLegacyAiSnapshot($snapshot);
            if (! is_array($snapshot['core'] ?? null)) {
                $snapshot['core'] = [];
            }
            $this->normalizeBirthPlace($snapshot);
            $this->normalizeNativePlace($snapshot);
            $this->normalizeAddressRows($snapshot);
            $snapshot['contacts'] = $this->normalizeContacts(is_array($snapshot['contacts'] ?? null) ? $snapshot['contacts'] : []);
            $snapshot['horoscope'] = $this->normalizeHoroscopeRows(is_array($snapshot['horoscope'] ?? null) ? $snapshot['horoscope'] : []);
            $snapshot['career_history'] = $this->normalizeCareerRows(is_array($snapshot['career_history'] ?? null) ? $snapshot['career_history'] : []);
            $this->normalizeWorkLocation($snapshot);
            $this->fillParentNamesFromRelatives($snapshot);
            $this->reconcilePrimaryContactVersusRelatives($snapshot);

            return $snapshot;
        } finally {
            $this->openPlaceSuggestionUserId = $prevUserId;
        }
    }

    /**
     * @param  array{country_id?: int|null, state_id?: int|null, district_id?: int|null, taluka_id?: int|null}  $optionalHierarchy
     */
    private function recordUnresolvedLocationSuggestion(string $rawInput, string $fieldNameForLog, array $optionalHierarchy = []): void
    {
        if ($this->openPlaceSuggestionUserId === null) {
            return;
        }
        if (! Schema::hasTable('location_open_place_suggestions')) {
            return;
        }
        $trimmed = trim($rawInput);
        if ($trimmed === '') {
            return;
        }
        try {
            $this->openPlaceSuggestions->recordOrBumpUsage(
                $trimmed,
                $this->openPlaceSuggestionUserId,
                $optionalHierarchy,
                'none',
                null,
            );
        } catch (\Throwable $e) {
            Log::warning('IntakeControlledFieldNormalizer: open place suggestion record failed', [
                'field' => $fieldNameForLog,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $addr
     * @return array{country_id?: int, state_id?: int, district_id?: int, taluka_id?: int}
     */
    private function addressRowHierarchyHint(array $addr): array
    {
        $out = [];
        foreach (['country_id', 'state_id', 'district_id', 'taluka_id'] as $k) {
            if (! empty($addr[$k]) && is_numeric($addr[$k])) {
                $out[$k] = (int) $addr[$k];
            }
        }

        return $out;
    }

    public function normalizeCore(array $core): array
    {
        $out = $core;
        $community = trim((string) ($out['community'] ?? $out['community_label'] ?? ''));
        if ($community !== '') {
            $out = $this->applyDeterministicCommunitySplit($out, $community);
        }

        $this->resolveCoreField($out, 'gender', 'gender_id');
        $this->resolveCoreField($out, 'religion', 'religion_id');
        $this->resolveCoreField($out, 'caste', 'caste_id');
        $this->resolveCoreField($out, 'sub_caste', 'sub_caste_id');
        $this->resolveCoreField($out, 'marital_status', 'marital_status_id');
        $this->resolveCoreField($out, 'complexion', 'complexion_id');

        $out = $this->normalizeBloodGroup($out);
        $this->resolveCoreField($out, 'blood_group', 'blood_group_id');

        $this->resolveCoreField($out, 'physical_build', 'physical_build_id');
        $this->resolveCoreField($out, 'mother_tongue', 'mother_tongue_id');
        $this->resolveCoreField($out, 'diet', 'diet_id');

        if (empty($out['smoking_status']) && ! empty($out['smoking'])) {
            $out['smoking_status'] = $out['smoking'];
        }
        if (empty($out['drinking_status']) && ! empty($out['drinking'])) {
            $out['drinking_status'] = $out['drinking'];
        }
        $this->resolveCoreField($out, 'smoking_status', 'smoking_status_id');
        $this->resolveCoreField($out, 'drinking_status', 'drinking_status_id');
        $this->resolveCoreField($out, 'family_type', 'family_type_id');
        $this->resolveCoreField($out, 'income_currency', 'income_currency_id');

        if (empty($out['height_cm'])) {
            $heightText = trim((string) ($out['height'] ?? $out['height_text'] ?? ''));
            if ($heightText !== '') {
                $heightCm = $this->parseHeightCmDeterministic($heightText);
                if ($heightCm !== null) {
                    $out['height_cm'] = $heightCm;
                }
            }
        }
        foreach (['father_contact_1', 'father_contact_2', 'father_contact_3', 'mother_contact_1', 'mother_contact_2', 'mother_contact_3'] as $ck) {
            if (! isset($out[$ck]) || $out[$ck] === null || $out[$ck] === '') {
                continue;
            }
            if (! is_string($out[$ck])) {
                continue;
            }
            $d = preg_replace('/\D/u', '', \App\Services\Ocr\OcrNormalize::normalizeDigits($out[$ck]));
            if (strlen($d) >= 10) {
                $d = substr($d, -10);
                if (preg_match('/^[6-9]\d{9}$/', $d)) {
                    $out[$ck] = $d;
                }
            }
        }

        return $out;
    }

    /**
     * Birth place text → {@see LocationNormalizationService} (alias + city→district/state). Sets hierarchy ids or {@code birth_place_text}.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function normalizeBirthPlace(array &$snapshot): void
    {
        if (! is_array($snapshot['core'] ?? null)) {
            $snapshot['core'] = [];
        }
        $core = &$snapshot['core'];
        if (! empty($core['birth_city_id']) && is_numeric($core['birth_city_id'])) {
            return;
        }

        $raw = $this->extractPlaceTextFromCoreOrSnapshot($core, $snapshot['birth_place'] ?? null);
        if ($raw === null || $raw === '') {
            return;
        }

        $res = $this->locationNormalization->normalizeFromText($raw);
        if (($res['confidence'] ?? 0.0) >= 0.80 && $res['matched'] && $res['city_id'] !== null) {
            $cityId = (int) $res['city_id'];
            $core['birth_city_id'] = $cityId;
            $talukaForSnapshot = null;
            if (isset($res['taluka_id']) && $res['taluka_id'] !== null) {
                $talukaForSnapshot = (int) $res['taluka_id'];
            } else {
                $city = City::query()->find($cityId);
                if ($city !== null && $city->parent_id !== null) {
                    $talukaForSnapshot = (int) $city->parent_id;
                }
            }
            if (! is_array($snapshot['birth_place'] ?? null)) {
                $snapshot['birth_place'] = [];
            }
            $snapshot['birth_place']['city_id'] = $cityId;
            $snapshot['birth_place']['taluka_id'] = $talukaForSnapshot;
            $snapshot['birth_place']['district_id'] = $res['district_id'] ?? null;
            $snapshot['birth_place']['state_id'] = $res['state_id'] ?? null;

            return;
        }

        $existingText = isset($core['birth_place_text']) && is_string($core['birth_place_text'])
            ? trim($core['birth_place_text'])
            : '';
        if ($existingText === '') {
            $core['birth_place_text'] = $raw;
        }
        Log::debug('Unknown location input', [
            'field_name' => 'birth_place',
            'raw_input' => $raw,
        ]);
        $this->recordUnresolvedLocationSuggestion($raw, 'birth_place', []);
    }

    /**
     * Native place text → {@see LocationNormalizationService}. Sets native hierarchy ids or preserves text on {@code native_place}.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function normalizeNativePlace(array &$snapshot): void
    {
        if (! is_array($snapshot['core'] ?? null)) {
            $snapshot['core'] = [];
        }
        $core = &$snapshot['core'];
        if (! empty($core['native_city_id']) && is_numeric($core['native_city_id'])) {
            return;
        }

        $raw = null;
        if (isset($snapshot['native_place']) && is_array($snapshot['native_place'])) {
            $raw = $this->extractScalarPlaceText($snapshot['native_place']);
        }
        if (($raw === null || $raw === '') && ! empty($core['native_place']) && is_scalar($core['native_place'])) {
            $raw = trim((string) $core['native_place']);
        }
        if ($raw === null || $raw === '') {
            return;
        }

        $res = $this->locationNormalization->normalizeFromText($raw);
        if (($res['confidence'] ?? 0.0) >= 0.80 && $res['matched'] && $res['city_id'] !== null) {
            $cityId = (int) $res['city_id'];
            $core['native_city_id'] = $cityId;
            $core['native_district_id'] = $res['district_id'] ?? null;
            $core['native_state_id'] = $res['state_id'] ?? null;
            if (isset($res['taluka_id']) && $res['taluka_id'] !== null) {
                $core['native_taluka_id'] = (int) $res['taluka_id'];
            } else {
                $city = City::query()->find($cityId);
                if ($city !== null && $city->parent_id !== null) {
                    $core['native_taluka_id'] = (int) $city->parent_id;
                }
            }
            if (! is_array($snapshot['native_place'] ?? null)) {
                $snapshot['native_place'] = [];
            }
            $snapshot['native_place']['city_id'] = $cityId;
            $snapshot['native_place']['taluka_id'] = $core['native_taluka_id'] ?? null;
            $snapshot['native_place']['district_id'] = $res['district_id'] ?? null;
            $snapshot['native_place']['state_id'] = $res['state_id'] ?? null;

            return;
        }

        if (! is_array($snapshot['native_place'] ?? null)) {
            $snapshot['native_place'] = [];
        }
        $np = &$snapshot['native_place'];
        if (empty($np['raw']) || ! is_string($np['raw']) || trim($np['raw']) === '') {
            $np['raw'] = $raw;
        }
        if (empty($np['address_line']) || ! is_string($np['address_line']) || trim($np['address_line']) === '') {
            $np['address_line'] = $raw;
        }
        Log::debug('Unknown location input', [
            'field_name' => 'native_place',
            'raw_input' => $raw,
        ]);
        $this->recordUnresolvedLocationSuggestion($raw, 'native_place', []);
    }

    /**
     * Intake `addresses[]`: optional place string → city + hierarchy via {@see LocationNormalizationService}.
     * Preserves `address_line` / `raw` when unmatched or low confidence.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function normalizeAddressRows(array &$snapshot): void
    {
        $list = $snapshot['addresses'] ?? null;
        if (! is_array($list) || $list === []) {
            return;
        }
        foreach ($list as $i => $addr) {
            if (! is_array($addr)) {
                continue;
            }
            if (! empty($addr['city_id']) && is_numeric($addr['city_id'])) {
                continue;
            }
            $raw = $this->extractAddressPlaceCandidate($addr);
            if ($raw === null || $raw === '') {
                continue;
            }
            $res = $this->locationNormalization->normalizeFromText($raw);
            if (($res['confidence'] ?? 0.0) >= 0.80 && ($res['matched'] ?? false) && ($res['city_id'] ?? null) !== null) {
                $addr['city_id'] = (int) $res['city_id'];
                $addr['district_id'] = $res['district_id'] ?? null;
                $addr['state_id'] = $res['state_id'] ?? null;
                $addr['country_id'] = $res['country_id'] ?? null;
                if (isset($res['taluka_id']) && $res['taluka_id'] !== null) {
                    $addr['taluka_id'] = (int) $res['taluka_id'];
                }
                $snapshot['addresses'][$i] = $addr;

                continue;
            }
            Log::debug('Unknown location input', [
                'field_name' => 'addresses.'.$i,
                'raw_input' => $raw,
            ]);
            $this->recordUnresolvedLocationSuggestion($raw, 'addresses.'.$i, $this->addressRowHierarchyHint($addr));
        }
    }

    /**
     * Core `work_location_text` (or first career row location) → `work_city_id` / `work_state_id` when alias match is strong.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function normalizeWorkLocation(array &$snapshot): void
    {
        if (! is_array($snapshot['core'] ?? null)) {
            $snapshot['core'] = [];
        }
        $core = &$snapshot['core'];
        if (! empty($core['work_city_id']) && is_numeric($core['work_city_id'])) {
            return;
        }
        $raw = trim((string) ($core['work_location_text'] ?? ''));
        if ($raw === '') {
            $ch = $snapshot['career_history'] ?? [];
            if (is_array($ch) && isset($ch[0]) && is_array($ch[0])) {
                $row0 = $ch[0];
                $raw = trim((string) ($row0['work_location_text'] ?? $row0['location'] ?? ''));
            }
        }
        if ($raw === '') {
            return;
        }
        $res = $this->locationNormalization->normalizeFromText($raw);
        if (($res['confidence'] ?? 0.0) < 0.80 || ! ($res['matched'] ?? false) || ($res['city_id'] ?? null) === null) {
            Log::debug('Unknown location input', [
                'field_name' => 'work_location',
                'raw_input' => $raw,
            ]);
            $this->recordUnresolvedLocationSuggestion($raw, 'work_location', []);

            return;
        }
        $core['work_city_id'] = (int) $res['city_id'];
        $core['work_state_id'] = $res['state_id'] ?? null;
        if (is_array($snapshot['career_history'] ?? null) && isset($snapshot['career_history'][0]) && is_array($snapshot['career_history'][0])) {
            $loc0 = trim((string) ($snapshot['career_history'][0]['work_location_text'] ?? $snapshot['career_history'][0]['location'] ?? ''));
            if ($loc0 === '' || $loc0 === $raw) {
                $snapshot['career_history'][0]['city_id'] = (int) $res['city_id'];
            }
        }
    }

    /**
     * Prefer structured place keys; last resort: short single-line {@code address_line} (no comma).
     *
     * @param  array<string, mixed>  $addr
     */
    private function extractAddressPlaceCandidate(array $addr): ?string
    {
        foreach (['city', 'place', 'location', 'town', 'village_name'] as $k) {
            $v = $addr[$k] ?? null;
            if (is_string($v)) {
                $t = trim($v);
                if ($t !== '') {
                    return $t;
                }
            }
        }
        $v = $addr['village'] ?? null;
        if (is_string($v)) {
            $t = trim($v);
            if ($t !== '') {
                return $t;
            }
        }
        $line = isset($addr['address_line']) && is_string($addr['address_line']) ? trim($addr['address_line']) : '';
        if ($line !== '' && mb_strlen($line, 'UTF-8') <= 48 && mb_strpos($line, ',') === false) {
            return $line;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function extractPlaceTextFromCoreOrSnapshot(array $core, mixed $birthPlaceSlice): ?string
    {
        if (! empty($core['birth_place']) && is_scalar($core['birth_place'])) {
            $t = trim((string) $core['birth_place']);
            if ($t !== '') {
                return $t;
            }
        }
        if (! empty($core['birth_place_text']) && is_scalar($core['birth_place_text'])) {
            $t = trim((string) $core['birth_place_text']);
            if ($t !== '') {
                return $t;
            }
        }
        if (is_array($birthPlaceSlice)) {
            return $this->extractScalarPlaceText($birthPlaceSlice);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $place
     */
    private function extractScalarPlaceText(array $place): ?string
    {
        foreach (['raw', 'address_line', 'label', 'city_text', 'name'] as $k) {
            if (! empty($place[$k]) && is_string($place[$k])) {
                $t = trim($place[$k]);
                if ($t !== '') {
                    return $t;
                }
            }
        }

        return null;
    }

    public function normalizeHoroscopeRows(array $rows): array
    {
        $snapshot = ['horoscope' => $rows];
        $snapshot = $this->controlled->normalizeIntakeHoroscopeSnapshot($snapshot);
        $normalizedRows = is_array($snapshot['horoscope'] ?? null) ? $snapshot['horoscope'] : [];

        foreach ($normalizedRows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            unset($normalizedRows[$i]['blood_group']);
            $bw = $row['birth_weekday'] ?? null;
            if (is_string($bw)) {
                $normalizedRows[$i]['birth_weekday'] = BiodataParserService::sanitizeBirthWeekdayStrict($bw);
            }
            $nv = $normalizedRows[$i]['navras_name'] ?? null;
            if (is_string($nv) && $nv !== '') {
                $normalizedRows[$i]['navras_name'] = BiodataParserService::sanitizeNavrasDisplayText($nv);
            }
            $rv = $normalizedRows[$i]['rashi'] ?? null;
            if (is_string($rv) && $rv !== '') {
                $normalizedRows[$i]['rashi'] = BiodataParserService::sanitizeRashiDisplayText($rv);
            }
            foreach (['nakshatra', 'nadi', 'gan', 'yoni'] as $hk) {
                $hv = $normalizedRows[$i][$hk] ?? null;
                if (is_string($hv) && $hv !== '') {
                    $h = BiodataParserService::stripResidualHtmlTagsFromString($hv);
                    $h = trim(preg_replace('/\s+/u', ' ', $h) ?? '');
                    $h = trim(preg_replace('/[\)\]\'\"]+$/u', '', $h) ?? '');
                    $normalizedRows[$i][$hk] = $h === '' ? null : $h;
                }
            }
        }

        return $normalizedRows;
    }

    public function normalizeContacts(array $rows): array
    {
        if ($this->isAssociativeList($rows)) {
            $vals = array_values($rows);
            $rows = (isset($vals[0]) && is_array($vals[0])) ? $vals : [];
        }
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (empty($row['phone_number']) && ! empty($row['number'])) {
                $rows[$i]['phone_number'] = $row['number'];
            }
            if (empty($rows[$i]['phone_number']) && ! empty($row['phone'])) {
                $rows[$i]['phone_number'] = $row['phone'];
                $rows[$i]['number'] = $row['phone'];
            }
            if (empty($row['relation_type']) && ! empty($row['label'])) {
                $rows[$i]['relation_type'] = $row['label'];
            }
            if (! isset($row['type']) && ! empty($row['is_primary'])) {
                $rows[$i]['type'] = 'primary';
            }
        }

        return $rows;
    }

    /**
     * Keep every relative row (including repeated same-relation members), preserve honorifics,
     * and build a stable sectioned map for maternal/paternal/other.
     *
     * @return array{0: array<int,array<string,mixed>>, 1: array<string,mixed>}
     */
    public function normalizeRelativesRows(array $rows): array
    {
        $normalized = [];
        $sectioned = $this->skeleton->defaults()['relatives_sectioned'] ?? [
            'maternal' => ['ajol' => [], 'mama' => [], 'mavshi' => [], 'other' => []],
            'paternal' => ['kaka' => [], 'chulte' => [], 'atya' => [], 'other' => []],
            'other' => [],
        ];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $relation = trim((string) ($row['relation_type'] ?? $row['relation'] ?? ''));
            if ($relation === '') {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $notes = trim((string) ($row['notes'] ?? $row['raw_note'] ?? ''));
            if ($name !== '') {
                $name = $this->preserveHonorificName($name);
            } elseif ($notes !== '') {
                $candidate = $this->extractHonorificNameFromNotes($notes);
                if ($candidate !== '') {
                    $name = $candidate;
                }
            }

            $entry = $row;
            $entry['relation_type'] = $relation;
            $entry['name'] = $name;
            $entry['notes'] = $notes !== '' ? $notes : null;
            $entry['address_line'] = trim((string) ($row['address_line'] ?? '')) !== '' ? trim((string) $row['address_line']) : null;
            $entry['contact_number'] = trim((string) ($row['contact_number'] ?? '')) !== '' ? trim((string) $row['contact_number']) : null;

            // No dedup: every row survives, including repeated same-relation relatives.
            $normalized[] = $entry;

            $bucket = $this->relativeBucketFor($relation);
            $side = $bucket['side'];
            $key = $bucket['key'];
            $sectionEntry = ($side === 'maternal' && $key === 'ajol')
                ? BiodataParserService::buildAjolSectionedRelativeRow($entry)
                : $entry;
            if ($side === 'maternal') {
                $sectioned['maternal'][$key][] = $sectionEntry;
            } elseif ($side === 'paternal') {
                $sectioned['paternal'][$key][] = $sectionEntry;
            } else {
                $sectioned['other'][] = $sectionEntry;
            }
        }

        return [$normalized, $sectioned];
    }

    /**
     * @param  array<string, array<int, array<string,mixed>>>  $section
     * @return array<int,array<string,mixed>>
     */
    private function flattenSectionedRelatives(array $section): array
    {
        $out = [];
        foreach ($section as $rows) {
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $r) {
                if (is_array($r)) {
                    $out[] = $r;
                }
            }
        }

        return $out;
    }

    public function normalizeEducationRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (empty($row['degree_id']) && ! empty($row['degree'])) {
                $deg = $this->education->findDegreeMatch((string) $row['degree']);
                if ($deg !== null) {
                    $rows[$i]['degree_id'] = (int) $deg->id;
                }
            }
        }

        return $rows;
    }

    public function normalizeCareerRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! empty($row['role']) && empty($row['occupation_title'])) {
                $rows[$i]['occupation_title'] = $row['role'];
            }
            if (! empty($row['job_title']) && empty($row['occupation_title'])) {
                $rows[$i]['occupation_title'] = $row['job_title'];
            }
            if (! empty($row['company']) && empty($row['company_name'])) {
                $rows[$i]['company_name'] = $row['company'];
            }
            if (! empty($row['employer']) && empty($row['company_name'])) {
                $rows[$i]['company_name'] = $row['employer'];
            }
            if (! empty($row['location']) && empty($row['work_location_text'])) {
                $rows[$i]['work_location_text'] = is_string($row['location']) ? $row['location'] : null;
            }
            if (empty($row['employment_type_id']) && ! empty($row['employment_type'])) {
                $m = $this->controlled->findActiveMasterExact('master_employment_types', (string) $row['employment_type']);
                if ($m !== null) {
                    $rows[$i]['employment_type_id'] = (int) $m['id'];
                }
            }
            if (empty($row['occupation_master_id']) && ! empty($row['occupation_title'])) {
                $occ = $this->occupation->findOccupationMasterForIntake((string) $row['occupation_title']);
                if ($occ !== null) {
                    $rows[$i]['occupation_master_id'] = (int) $occ->id;
                }
            }
            if (empty($rows[$i]['city_id']) || ! is_numeric($rows[$i]['city_id'])) {
                $locRaw = trim((string) ($rows[$i]['location'] ?? $rows[$i]['work_location_text'] ?? ''));
                if ($locRaw !== '') {
                    $locRes = $this->locationNormalization->normalizeFromText($locRaw);
                    if (($locRes['confidence'] ?? 0.0) >= 0.80 && ($locRes['matched'] ?? false) && ($locRes['city_id'] ?? null) !== null) {
                        $rows[$i]['city_id'] = (int) $locRes['city_id'];
                    } else {
                        $this->recordUnresolvedLocationSuggestion($locRaw, 'career_history.'.$i, []);
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Map common AI / legacy keys into wizard-aligned core and contacts (additive; removes only migrated aliases).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function migrateLegacyAiSnapshot(array $snapshot): array
    {
        if (! isset($snapshot['core']) || ! is_array($snapshot['core'])) {
            return $snapshot;
        }
        $core = &$snapshot['core'];
        foreach (['full_name', 'father_name', 'mother_name', 'father_occupation', 'mother_occupation', 'father_extra_info', 'address_line', 'primary_contact_number', 'highest_education', 'birth_place', 'other_relatives_text', 'marital_status', 'religion', 'caste', 'sub_caste'] as $k) {
            if (! empty($core[$k]) && is_string($core[$k])) {
                $core[$k] = trim(preg_replace('/\s+/u', ' ', BiodataParserService::stripIntakeHtmlNoise($core[$k])) ?? '');
                if ($core[$k] === '') {
                    $core[$k] = null;
                }
            }
        }
        if (! empty($core['other_relatives_text']) && is_string($core['other_relatives_text'])) {
            $core['other_relatives_text'] = BiodataParserService::pruneOtherRelativesNarrative($core['other_relatives_text']);
        }
        foreach (['father_contact_1', 'father_contact_2', 'father_contact_3', 'mother_contact_1', 'mother_contact_2', 'mother_contact_3'] as $ck) {
            if (! isset($core[$ck]) || $core[$ck] === null || $core[$ck] === '') {
                continue;
            }
            if (! is_string($core[$ck])) {
                continue;
            }
            $d = preg_replace('/\D/u', '', \App\Services\Ocr\OcrNormalize::normalizeDigits($core[$ck]));
            if (strlen($d) >= 10) {
                $d = substr($d, -10);
                if (preg_match('/^[6-9]\d{9}$/', $d)) {
                    $core[$ck] = $d;
                }
            }
        }
        // Canonical: AI/legacy `name` → `full_name`, then drop alias (never leave both).
        if (! empty($core['name'])) {
            if (empty($core['full_name'])) {
                $core['full_name'] = is_string($core['name']) ? trim($core['name']) : $core['name'];
            }
            unset($core['name']);
        } elseif (array_key_exists('name', $core)) {
            unset($core['name']);
        }
        // Canonical: `birth_date` → `date_of_birth`, then drop alias.
        if (! empty($core['birth_date'])) {
            if (empty($core['date_of_birth'])) {
                $core['date_of_birth'] = $core['birth_date'];
            }
            unset($core['birth_date']);
        } elseif (array_key_exists('birth_date', $core)) {
            unset($core['birth_date']);
        }
        if (! empty($core['job'])) {
            $job = trim((string) $core['job']);
            unset($core['job']);
            if ($job !== '') {
                if (empty($core['occupation_title'])) {
                    $core['occupation_title'] = $job;
                }
                $ch = $snapshot['career_history'] ?? [];
                if (! is_array($ch)) {
                    $ch = [];
                }
                if ($ch === []) {
                    $snapshot['career_history'] = [[
                        'occupation_title' => $job,
                        'company_name' => null,
                        'work_location_text' => null,
                        'role' => $job,
                    ]];
                } elseif (isset($ch[0]) && is_array($ch[0])) {
                    if (empty($ch[0]['occupation_title']) && empty($ch[0]['company_name']) && empty($ch[0]['job_title'])) {
                        $ch[0]['occupation_title'] = $job;
                        $ch[0]['role'] = $ch[0]['role'] ?? $job;
                        $snapshot['career_history'] = $ch;
                    }
                }
            }
        }
        $contacts = $snapshot['contacts'] ?? null;
        if ($contacts instanceof \stdClass) {
            $contacts = json_decode(json_encode($contacts, JSON_UNESCAPED_UNICODE), true);
        }
        $contactAddressFromContacts = null;
        if (is_array($contacts) && isset($contacts['address'])) {
            $ca = $contacts['address'];
            if (is_string($ca) && trim($ca) !== '') {
                $contactAddressFromContacts = trim($ca);
            }
        }
        if ($contacts !== null && ! is_array($contacts)) {
            $snapshot['contacts'] = [];
        } elseif (is_array($contacts) && $contacts !== [] && $this->isAssociativeList($contacts)) {
            $vals = array_values($contacts);
            if (isset($vals[0]) && is_array($vals[0])) {
                $snapshot['contacts'] = $vals;
            } else {
                $phoneRaw = trim((string) ($contacts['phone'] ?? $contacts['phone_number'] ?? $contacts['number'] ?? $contacts['mobile'] ?? ''));
                $digits = preg_replace('/\D/', '', $phoneRaw);
                if (strlen($digits) >= 10) {
                    $norm = strlen($digits) > 10 ? substr($digits, -10) : $digits;
                    $snapshot['contacts'] = [[
                        'type' => 'primary',
                        'label' => 'self',
                        'phone' => $norm,
                        'phone_number' => $norm,
                        'number' => $norm,
                        'relation_type' => 'self',
                        'is_primary' => true,
                    ]];
                } else {
                    $snapshot['contacts'] = [];
                }
            }
        }
        foreach (['addresses', 'education_history'] as $listKey) {
            $list = $snapshot[$listKey] ?? null;
            if (is_array($list) && $this->isAssociativeList($list)) {
                $v = array_values($list);
                $snapshot[$listKey] = $v;
            }
        }
        if (! empty($snapshot['addresses']) && is_array($snapshot['addresses'])) {
            foreach ($snapshot['addresses'] as $ai => $addr) {
                if (! is_array($addr)) {
                    continue;
                }
                if (! empty($addr['raw']) && empty($addr['address_line'])) {
                    $snapshot['addresses'][$ai]['address_line'] = is_string($addr['raw']) ? $addr['raw'] : null;
                }
            }
        }
        $addrs = $snapshot['addresses'] ?? [];
        $addrsEmpty = ! is_array($addrs) || $addrs === [] || ! $this->addressListHasAnyLine($addrs);
        if ($addrsEmpty && $contactAddressFromContacts !== null && $contactAddressFromContacts !== '') {
            $snapshot['addresses'] = [[
                'address_line' => $contactAddressFromContacts,
                'raw' => $contactAddressFromContacts,
                'type' => 'current',
            ]];
        }

        return $snapshot;
    }

    /**
     * @param  array<int, mixed>  $addrs
     */
    private function addressListHasAnyLine(array $addrs): bool
    {
        foreach ($addrs as $addr) {
            if (! is_array($addr)) {
                continue;
            }
            foreach (['address_line', 'raw'] as $k) {
                if (! empty($addr[$k]) && is_string($addr[$k]) && trim($addr[$k]) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    private function isAssociativeList(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function reconcilePrimaryContactVersusRelatives(array &$snapshot): void
    {
        $primary = preg_replace('/\D/', '', (string) ($snapshot['core']['primary_contact_number'] ?? ''));
        if (strlen($primary) < 10) {
            return;
        }
        if (strlen($primary) > 10) {
            $primary = substr($primary, -10);
        }
        $relFlip = $this->relativePhoneNumbersFlip($snapshot);
        if (! isset($relFlip[$primary])) {
            return;
        }
        $replacement = null;
        foreach ($snapshot['contacts'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ph = preg_replace('/\D/', '', (string) ($row['phone_number'] ?? $row['number'] ?? $row['phone'] ?? ''));
            if (strlen($ph) > 10) {
                $ph = substr($ph, -10);
            }
            if (strlen($ph) < 10 || isset($relFlip[$ph])) {
                continue;
            }
            $isSelf = (($row['relation_type'] ?? '') === 'self')
                || (($row['label'] ?? '') === 'self')
                || (($row['type'] ?? '') === 'primary');
            if ($isSelf) {
                $replacement = $ph;
                break;
            }
        }
        if ($replacement === null) {
            foreach ($snapshot['contacts'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $ph = preg_replace('/\D/', '', (string) ($row['phone_number'] ?? $row['number'] ?? $row['phone'] ?? ''));
                if (strlen($ph) > 10) {
                    $ph = substr($ph, -10);
                }
                if (strlen($ph) >= 10 && ! isset($relFlip[$ph])) {
                    $replacement = $ph;
                    break;
                }
            }
        }
        if ($replacement !== null) {
            $snapshot['core']['primary_contact_number'] = $replacement;
        } else {
            $snapshot['core']['primary_contact_number'] = null;
        }
    }

    /**
     * Safe fallback: fill father/mother from sectioned relatives when core is empty.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function fillParentNamesFromRelatives(array &$snapshot): void
    {
        $core = &$snapshot['core'];
        if (! is_array($core)) {
            return;
        }
        $fatherNeed = empty($core['father_name']) || ! is_string($core['father_name']) || trim($core['father_name']) === '';
        $motherNeed = empty($core['mother_name']) || ! is_string($core['mother_name']) || trim($core['mother_name']) === '';

        if ($fatherNeed) {
            $name = $this->firstParentNameFromRelativeRows(array_merge(
                $this->rowsMatchingParentHint(is_array($snapshot['relatives'] ?? null) ? $snapshot['relatives'] : [], true),
                is_array($snapshot['relatives_parents_family'] ?? null) ? $snapshot['relatives_parents_family'] : [],
            ));
            if ($name !== null && $name !== '') {
                $core['father_name'] = $name;
            }
        }
        if ($motherNeed) {
            $name = $this->firstParentNameFromRelativeRows(array_merge(
                $this->rowsMatchingParentHint(is_array($snapshot['relatives'] ?? null) ? $snapshot['relatives'] : [], false),
                is_array($snapshot['relatives_maternal_family'] ?? null) ? $snapshot['relatives_maternal_family'] : [],
            ));
            if ($name !== null && $name !== '') {
                $core['mother_name'] = $name;
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $relatives
     * @return array<int, array<string, mixed>>
     */
    private function rowsMatchingParentHint(array $relatives, bool $father): array
    {
        $out = [];
        foreach ($relatives as $r) {
            if (! is_array($r)) {
                continue;
            }
            $rel = trim((string) ($r['relation_type'] ?? $r['relation'] ?? ''));
            $notes = trim((string) ($r['notes'] ?? $r['raw_note'] ?? ''));
            $hay = $rel.' '.$notes;
            if ($father) {
                if (mb_strpos($hay, 'वडील') !== false || mb_strpos($hay, 'वडिल') !== false || mb_strpos($hay, 'पिता') !== false || stripos($hay, 'father') !== false) {
                    $out[] = $r;
                }
            } else {
                if (mb_strpos($hay, 'आई') !== false || mb_strpos($hay, 'माता') !== false || mb_strpos($hay, 'माते') !== false || stripos($hay, 'mother') !== false) {
                    $out[] = $r;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function firstParentNameFromRelativeRows(array $rows): ?string
    {
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $name = trim((string) ($r['name'] ?? ''));
            if ($name === '') {
                $notes = trim((string) ($r['notes'] ?? $r['raw_note'] ?? ''));
                $name = $this->extractHonorificNameFromNotes($notes);
            }
            $name = $this->preserveHonorificName($name);
            if ($name !== '' && mb_strlen($name) >= 2) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, bool>
     */
    private function relativePhoneNumbersFlip(array $snapshot): array
    {
        $phones = [];
        $add = function (?string $p) use (&$phones): void {
            $d = preg_replace('/\D/', '', (string) $p);
            if (strlen($d) >= 10) {
                $phones[substr($d, -10)] = true;
            }
        };
        $core = $snapshot['core'] ?? [];
        if (is_array($core)) {
            foreach (['father_contact_1', 'father_contact_2', 'father_contact_3', 'mother_contact_1', 'mother_contact_2', 'mother_contact_3'] as $ck) {
                $add($core[$ck] ?? null);
            }
        }
        foreach (['relatives', 'relatives_parents_family', 'relatives_maternal_family'] as $key) {
            foreach ($snapshot[$key] ?? [] as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $add($r['contact_number'] ?? null);
                if (! empty($r['notes']) && is_string($r['notes'])) {
                    if (preg_match_all('/\b([6-9]\d{9})\b/u', $r['notes'], $m)) {
                        foreach ($m[1] as $x) {
                            $add($x);
                        }
                    }
                }
                if (! empty($r['raw_note']) && is_string($r['raw_note'])) {
                    if (preg_match_all('/\b([6-9]\d{9})\b/u', $r['raw_note'], $m)) {
                        foreach ($m[1] as $x) {
                            $add($x);
                        }
                    }
                }
            }
        }

        return $phones;
    }

    private function resolveCoreField(array &$core, string $textKey, string $idKey): void
    {
        if (! empty($core[$idKey]) && is_numeric($core[$idKey])) {
            return;
        }
        $raw = trim((string) ($core[$textKey] ?? ''));
        if ($raw === '') {
            return;
        }
        $compressed = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        $trimPunct = trim($compressed, " \t.:;,|");
        $trimPunct = preg_replace('/^[।]+|[।]+$/u', '', $trimPunct) ?? $trimPunct;
        $trimPunct = trim($trimPunct);
        $candidates = array_values(array_unique(array_filter([$raw, $compressed, $trimPunct], fn ($s) => $s !== '')));
        $context = [];
        if ($textKey === 'caste') {
            $rid = $core['religion_id'] ?? null;
            $context['religion_id'] = is_numeric($rid) ? (int) $rid : null;
        }
        if ($textKey === 'sub_caste') {
            $cid = $core['caste_id'] ?? null;
            $context['caste_id'] = is_numeric($cid) ? (int) $cid : null;
        }
        foreach ($candidates as $candidate) {
            $resolved = $this->controlled->resolveControlledCoreValue($textKey, $candidate, $context);
            if (! empty($resolved['matched']) && ! empty($resolved['id'])) {
                $core[$idKey] = (int) $resolved['id'];

                return;
            }
        }
    }

    private function normalizeBloodGroup(array $core): array
    {
        $raw = trim((string) ($core['blood_group'] ?? ''));
        if ($raw === '') {
            return $core;
        }
        $sanitized = BiodataParserService::sanitizeBloodGroupValue($raw);
        if ($sanitized !== null && $sanitized !== '') {
            $core['blood_group'] = $sanitized;

            return $core;
        }

        $t = mb_strtolower($raw, 'UTF-8');
        $t = str_replace(['positive', 'negative', 'plus', 'minus', 've'], ['+', '-', '+', '-', ''], $t);
        $t = preg_replace('/\s+/u', '', $t) ?? $t;
        $map = ['a+' => 'A+', 'a-' => 'A-', 'b+' => 'B+', 'b-' => 'B-', 'ab+' => 'AB+', 'ab-' => 'AB-', 'o+' => 'O+', 'o-' => 'O-'];
        if (isset($map[$t])) {
            $core['blood_group'] = $map[$t];
        }

        return $core;
    }

    private function parseHeightCmDeterministic(string $text): ?float
    {
        if (preg_match('/(\d{1,2})\s*[\'′]\s*(\d{1,2})\s*(?:["″]|in)?/u', $text, $m)) {
            return round((((int) $m[1]) * 12 + (int) $m[2]) * 2.54, 2);
        }
        if (preg_match('/(\d{1,2})\s*(?:ft|feet|foot|फूट)\s*(\d{1,2})\s*(?:in|inch|इंच)?/iu', $text, $m)) {
            return round((((int) $m[1]) * 12 + (int) $m[2]) * 2.54, 2);
        }
        if (preg_match('/^\s*(\d{1,2})\s*-\s*(\d{1,2})\s*$/u', $text, $m)) {
            return round((((int) $m[1]) * 12 + (int) $m[2]) * 2.54, 2);
        }

        return null;
    }

    private function applyDeterministicCommunitySplit(array $core, string $community): array
    {
        $parts = preg_split('/[-|,]+/u', $community) ?: [$community];
        foreach ($parts as $p) {
            $token = trim($p);
            if ($token === '') {
                continue;
            }
            if (empty($core['religion'])) {
                $r = $this->controlled->resolveControlledCoreValue('religion', $token);
                if (! empty($r['matched'])) {
                    $core['religion'] = $token;
                }
            }
            if (empty($core['caste'])) {
                $c = $this->controlled->resolveControlledCoreValue('caste', $token);
                if (! empty($c['matched'])) {
                    $core['caste'] = $token;
                }
            }
            if (empty($core['sub_caste'])) {
                $s = $this->controlled->resolveControlledCoreValue('sub_caste', $token);
                if (! empty($s['matched'])) {
                    $core['sub_caste'] = $token;
                }
            }
        }

        return $core;
    }

    private function preserveHonorificName(string $name): string
    {
        $n = trim($name);
        if ($n === '') {
            return '';
        }
        // Keep prefixes exactly; only collapse accidental OCR multi-spaces.
        $n = preg_replace('/\s+/u', ' ', $n) ?? $n;

        return trim($n);
    }

    private function extractHonorificNameFromNotes(string $notes): string
    {
        if (preg_match('/\b(श्री\.?|सौ\.?|डॉ\.?)\s*[^\n,()]+/u', $notes, $m)) {
            return trim((string) $m[0]);
        }

        return '';
    }

    /**
     * @return array{side:'maternal'|'paternal'|'other', key:string}
     */
    private function relativeBucketFor(string $relationType): array
    {
        $r = mb_strtolower(trim($relationType), 'UTF-8');
        if (str_contains($r, 'मामा') || str_contains($r, 'mama')) {
            return ['side' => 'maternal', 'key' => 'mama'];
        }
        if (str_contains($r, 'मावशी') || str_contains($r, 'mavshi')) {
            return ['side' => 'maternal', 'key' => 'mavshi'];
        }
        if (str_contains($r, 'आजोळ') || str_contains($r, 'ajol') || str_contains($r, 'maternal_address_ajol')) {
            return ['side' => 'maternal', 'key' => 'ajol'];
        }
        if (str_contains($r, 'चुलते') || str_contains($r, 'chulte')) {
            return ['side' => 'paternal', 'key' => 'chulte'];
        }
        if (str_contains($r, 'आत्या') || str_contains($r, 'atya')) {
            return ['side' => 'paternal', 'key' => 'atya'];
        }
        if (str_contains($r, 'काका') || str_contains($r, 'kaka') || str_contains($r, 'paternal')) {
            return ['side' => 'paternal', 'key' => 'kaka'];
        }
        if (str_contains($r, 'maternal')) {
            return ['side' => 'maternal', 'key' => 'other'];
        }
        if (str_contains($r, 'paternal')) {
            return ['side' => 'paternal', 'key' => 'other'];
        }

        return ['side' => 'other', 'key' => 'other'];
    }
}
