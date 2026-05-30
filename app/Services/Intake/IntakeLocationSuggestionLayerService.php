<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\Location;
use App\Services\Location\AddressHierarchySearch;
use App\Services\Location\LocationCompoundAddressParser;
use App\Services\Location\LocationFormatterService;
use App\Services\Location\LocationService;
use App\Services\Location\PlaceIntakeSearchService;
/**
 * Step 4.5 (UI-only): biodata place suggestions — never overwrites user-filled hierarchy values.
 * Shows one confident option when village + taluka + district match. Never treat {@code core.birth_place} as user text.
 *
 * @see IntakeLocationFieldRegistry
 * @see docs/INTAKE-LOCATION-SUGGESTIONS-SSOT.md
 */
class IntakeLocationSuggestionLayerService
{
    private const MAX_SERVER_SEARCH_CHARS = 120;

    private const CONFIDENT_HIERARCHY_SEARCH_LIMIT = 8;

    public function __construct(
        private LocationCompoundAddressParser $compoundAddressParser,
        private AddressHierarchySearch $hierarchySearch,
        private PlaceIntakeSearchService $placeIntakeSearch,
        private LocationFormatterService $locationFormatter,
        private LocationService $locationService,
    ) {}

    /**
     * @return array<int, array{field_key: string, label: string, raw_input: string, options: array<int, array<string, mixed>>}>
     */
    public function unresolvedCandidates(BiodataIntake $intake, int $limit = 7): array
    {
        return $this->unresolvedCandidatesFromSnapshot($this->snapshotForEdit($intake), $limit);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $intakeParsed
     * @return array<int, array{field_key: string, label: string, raw_input: string, options: array<int, array<string, mixed>>}>
     */
    public function unresolvedCandidatesFromSnapshot(
        array $snapshot,
        int $limit = 7,
        array $intakeParsed = [],
        ?\App\Models\MatrimonyProfile $profile = null,
    ): array {
        $out = [];

        $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
        $intakeCore = is_array($intakeParsed['core'] ?? null) ? $intakeParsed['core'] : [];

        $biodataBirth = $this->extractBiodataBirthPlace($snapshot, $intakeCore);
        if ($biodataBirth !== '' && $this->shouldSuggestBirthPlace($biodataBirth, $core)) {
            $out[] = $this->entry('birth_place', 'Birth place', $biodataBirth);
        }

        $biodataNative = $this->extractBiodataNativePlace($snapshot, $intakeCore);
        if ($biodataNative !== '' && $this->shouldSuggestNativePlace($biodataNative, $core, $snapshot)) {
            $out[] = $this->entry('native_place', 'Native place', $biodataNative);
        }

        $biodataWork = $this->extractBiodataWorkPlace($intakeCore);
        if ($biodataWork !== '' && $this->shouldSuggestWorkPlace($biodataWork, $core)) {
            $out[] = $this->entry('work_location', 'Work location', $biodataWork);
        }

        $parentsAddresses = is_array($snapshot['parents_addresses'] ?? null) ? $snapshot['parents_addresses'] : [];
        foreach ($parentsAddresses as $i => $addr) {
            if (! is_array($addr)) {
                continue;
            }
            if (! empty($addr['city_id']) || ! empty($addr['location_id'])) {
                continue;
            }
            $raw = trim((string) ($addr['location_text'] ?? ''));
            if ($raw === '') {
                $raw = $this->addressRowRawText($addr);
            }
            $searchInput = $this->extractPlaceSearchInput($raw);
            if ($searchInput === '') {
                continue;
            }
            $out[] = $this->entry("parents_addresses.{$i}", __('intake.location_suggestion_parents_address', ['n' => $i + 1]), $searchInput);
        }

        $selfRows = app(IntakePreviewSelfAddressRows::class)->rows($profile, $snapshot, $intakeParsed);
        foreach ($selfRows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $biodataRaw = trim((string) ($row['biodata_intake_line'] ?? ''));
            if ($biodataRaw === '' && ! empty($row['from_biodata'])) {
                $biodataRaw = trim((string) ($row['address_line'] ?? ''));
            }
            if ($biodataRaw === '') {
                continue;
            }

            $userCityId = (int) ($row['location_id'] ?? 0);
            $userLine = trim((string) ($row['address_line'] ?? ''));
            if ($userCityId > 0 && ! $this->intakeDiffersFromUserLocation($biodataRaw, $userCityId)) {
                if ($userLine === ''
                    || $this->normalizePlaceCompareKey($biodataRaw) === $this->normalizePlaceCompareKey($userLine)) {
                    continue;
                }
            }

            $searchInput = $this->extractPlaceSearchInput($biodataRaw);
            if ($searchInput === '') {
                continue;
            }

            $typeKey = (string) ($row['address_type_key'] ?? 'current');
            $out[] = $this->entry(
                "self_addresses.{$i}",
                __('intake.location_suggestion_self_address', ['type' => $typeKey]),
                $searchInput
            );
        }

        foreach (['relatives_parents_family' => __('intake.location_suggestion_paternal_relative'), 'relatives_maternal_family' => __('intake.location_suggestion_maternal_relative')] as $sectionKey => $sectionLabel) {
            $rows = is_array($snapshot[$sectionKey] ?? null) ? $snapshot[$sectionKey] : [];
            $parsedRows = is_array($intakeParsed[$sectionKey] ?? null) ? $intakeParsed[$sectionKey] : [];
            foreach ($rows as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $parsedRow = is_array($parsedRows[$i] ?? null) ? $parsedRows[$i] : [];
                $biodataRaw = $this->relativeRowBiodataText($parsedRow);
                if ($biodataRaw === '') {
                    continue;
                }
                $userCityId = (int) ($row['city_id'] ?? $row['location_id'] ?? 0);
                if ($userCityId > 0 && ! $this->intakeDiffersFromUserLocation($biodataRaw, $userCityId)) {
                    continue;
                }
                $userText = trim((string) ($row['address_line'] ?? $row['notes'] ?? ''));
                if ($userCityId < 1 && $userText !== ''
                    && $this->normalizePlaceCompareKey($biodataRaw) === $this->normalizePlaceCompareKey($userText)) {
                    continue;
                }
                $out[] = $this->entry("{$sectionKey}.{$i}", $sectionLabel.' #'.($i + 1), $biodataRaw);
            }
        }

        return array_slice($out, 0, max(1, $limit));
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public function resolveFieldToCity(BiodataIntake $intake, string $fieldKey, int $locationId): array
    {
        $loc = Location::query()->find($locationId);
        if ($loc === null) {
            return ['ok' => false, 'message' => 'Selected place not found.'];
        }

        $this->locationService->ensureAncestorsLoaded($loc);
        $h = $this->locationService->fillHierarchyGaps($loc, $this->locationService->getFullHierarchy($loc));

        $taluka = $h['taluka'] ?? null;
        $district = $h['district'] ?? null;
        $state = $h['state'] ?? null;
        $countryId = $state?->parent_id;

        $talukaId = $taluka ? (int) $taluka->id : ($loc->type === 'taluka' ? (int) $loc->id : null);
        $districtId = $district ? (int) $district->id : ($loc->type === 'district' ? (int) $loc->id : null);
        $stateId = $state ? (int) $state->id : null;

        $snapshot = $this->snapshotForEdit($intake);
        if (! is_array($snapshot['core'] ?? null)) {
            $snapshot['core'] = [];
        }
        $core = &$snapshot['core'];

        if ($fieldKey === 'birth_place') {
            $existingApplied = (int) ($core['birth_city_id'] ?? 0);
            if ($existingApplied === (int) $loc->id && ! empty($core['birth_place_suggestion_applied'])) {
                return $this->resolveSuccessPayload($loc, $talukaId, $districtId, $stateId);
            }
            $core['birth_city_id'] = (int) $loc->id;
            $core['birth_place_suggestion_applied'] = true;
            if (! is_array($snapshot['birth_place'] ?? null)) {
                $snapshot['birth_place'] = [];
            }
            $snapshot['birth_place']['city_id'] = (int) $loc->id;
            $snapshot['birth_place']['taluka_id'] = $talukaId;
            $snapshot['birth_place']['district_id'] = $districtId;
            $snapshot['birth_place']['state_id'] = $stateId;
            $this->recordLocationSuggestionApplied($core, $fieldKey, $loc, $talukaId, $districtId, $stateId);
        } elseif ($fieldKey === 'native_place') {
            if ((int) ($core['native_city_id'] ?? 0) === (int) $loc->id) {
                return $this->resolveSuccessPayload($loc, $talukaId, $districtId, $stateId);
            }
            $core['native_city_id'] = (int) $loc->id;
            $core['native_taluka_id'] = $talukaId;
            $core['native_district_id'] = $districtId;
            $core['native_state_id'] = $stateId;
            if (! is_array($snapshot['native_place'] ?? null)) {
                $snapshot['native_place'] = [];
            }
            $snapshot['native_place']['city_id'] = (int) $loc->id;
            $snapshot['native_place']['taluka_id'] = $talukaId;
            $snapshot['native_place']['district_id'] = $districtId;
            $snapshot['native_place']['state_id'] = $stateId;
            $this->recordLocationSuggestionApplied($core, $fieldKey, $loc, $talukaId, $districtId, $stateId);
        } elseif ($fieldKey === 'work_location') {
            if ((int) ($core['work_city_id'] ?? 0) === (int) $loc->id) {
                return $this->resolveSuccessPayload($loc, $talukaId, $districtId, $stateId);
            }
            $core['work_city_id'] = (int) $loc->id;
            $core['work_state_id'] = $stateId;
            if (is_array($snapshot['career_history'] ?? null) && isset($snapshot['career_history'][0]) && is_array($snapshot['career_history'][0])) {
                $snapshot['career_history'][0]['city_id'] = (int) $loc->id;
            }
            $this->recordLocationSuggestionApplied($core, $fieldKey, $loc, $talukaId, $districtId, $stateId);
        } elseif (str_starts_with($fieldKey, 'parents_addresses.')) {
            $parts = explode('.', $fieldKey);
            $idx = isset($parts[1]) ? (int) $parts[1] : -1;
            if (! is_array($snapshot['parents_addresses'] ?? null) || ! isset($snapshot['parents_addresses'][$idx]) || ! is_array($snapshot['parents_addresses'][$idx])) {
                return ['ok' => false, 'message' => 'Parents address row not found in snapshot.'];
            }
            $existingAddrId = (int) ($snapshot['parents_addresses'][$idx]['city_id'] ?? $snapshot['parents_addresses'][$idx]['location_id'] ?? 0);
            if ($existingAddrId === (int) $loc->id) {
                return $this->resolveSuccessPayload($loc, $talukaId, $districtId, $stateId);
            }
            $snapshot['parents_addresses'][$idx]['city_id'] = (int) $loc->id;
            $snapshot['parents_addresses'][$idx]['location_id'] = (int) $loc->id;
            $snapshot['parents_addresses'][$idx]['taluka_id'] = $talukaId;
            $snapshot['parents_addresses'][$idx]['district_id'] = $districtId;
            $snapshot['parents_addresses'][$idx]['state_id'] = $stateId;
            $snapshot['parents_addresses'][$idx]['country_id'] = $countryId !== null ? (int) $countryId : null;
            $snapshot['parents_addresses'][$idx]['display'] = $this->locationFormatter->formatForLocation($loc);
            $this->recordLocationSuggestionApplied($core, $fieldKey, $loc, $talukaId, $districtId, $stateId);
        } elseif (str_starts_with($fieldKey, 'addresses.')) {
            $parts = explode('.', $fieldKey);
            $idx = isset($parts[1]) ? (int) $parts[1] : -1;
            if (! is_array($snapshot['addresses'] ?? null) || ! isset($snapshot['addresses'][$idx]) || ! is_array($snapshot['addresses'][$idx])) {
                return ['ok' => false, 'message' => 'Address row not found in snapshot.'];
            }
            $existingAddrId = (int) ($snapshot['addresses'][$idx]['city_id'] ?? $snapshot['addresses'][$idx]['location_id'] ?? 0);
            if ($existingAddrId === (int) $loc->id) {
                return $this->resolveSuccessPayload($loc, $talukaId, $districtId, $stateId);
            }
            $snapshot['addresses'][$idx]['city_id'] = (int) $loc->id;
            $snapshot['addresses'][$idx]['location_id'] = (int) $loc->id;
            $snapshot['addresses'][$idx]['taluka_id'] = $talukaId;
            $snapshot['addresses'][$idx]['district_id'] = $districtId;
            $snapshot['addresses'][$idx]['state_id'] = $stateId;
            $snapshot['addresses'][$idx]['country_id'] = $countryId !== null ? (int) $countryId : null;
            $this->recordLocationSuggestionApplied($core, $fieldKey, $loc, $talukaId, $districtId, $stateId);
        } elseif (preg_match('/^self_addresses\.(\d+)$/', $fieldKey, $selfMatch) === 1) {
            $idx = (int) $selfMatch[1];
            if (! is_array($snapshot['self_addresses'] ?? null)) {
                $snapshot['self_addresses'] = [];
            }
            if (! isset($snapshot['self_addresses'][$idx]) || ! is_array($snapshot['self_addresses'][$idx])) {
                $snapshot['self_addresses'][$idx] = [];
            }
            $existingAddrId = (int) ($snapshot['self_addresses'][$idx]['location_id'] ?? $snapshot['self_addresses'][$idx]['city_id'] ?? 0);
            if ($existingAddrId === (int) $loc->id) {
                return $this->resolveSuccessPayload($loc, $talukaId, $districtId, $stateId);
            }
            $snapshot['self_addresses'][$idx]['location_id'] = (int) $loc->id;
            $snapshot['self_addresses'][$idx]['city_id'] = (int) $loc->id;
            $snapshot['self_addresses'][$idx]['taluka_id'] = $talukaId;
            $snapshot['self_addresses'][$idx]['district_id'] = $districtId;
            $snapshot['self_addresses'][$idx]['state_id'] = $stateId;
            $snapshot['self_addresses'][$idx]['country_id'] = $countryId !== null ? (int) $countryId : null;
            $snapshot['self_addresses'][$idx]['display'] = $this->locationFormatter->formatForLocation($loc);
            $this->recordLocationSuggestionApplied($core, $fieldKey, $loc, $talukaId, $districtId, $stateId);
        } elseif (preg_match('/^relatives_(parents_family|maternal_family)\.(\d+)$/', $fieldKey, $relMatch) === 1) {
            $sectionKey = 'relatives_'.$relMatch[1];
            $idx = (int) $relMatch[2];
            if (! is_array($snapshot[$sectionKey] ?? null) || ! isset($snapshot[$sectionKey][$idx]) || ! is_array($snapshot[$sectionKey][$idx])) {
                return ['ok' => false, 'message' => 'Relative row not found in snapshot.'];
            }
            if ((int) ($snapshot[$sectionKey][$idx]['city_id'] ?? 0) === (int) $loc->id) {
                return $this->resolveSuccessPayload($loc, $talukaId, $districtId, $stateId);
            }
            $snapshot[$sectionKey][$idx]['city_id'] = (int) $loc->id;
            $snapshot[$sectionKey][$idx]['taluka_id'] = $talukaId;
            $snapshot[$sectionKey][$idx]['district_id'] = $districtId;
            $snapshot[$sectionKey][$idx]['state_id'] = $stateId;
            $this->recordLocationSuggestionApplied($core, $fieldKey, $loc, $talukaId, $districtId, $stateId);
        } else {
            return ['ok' => false, 'message' => 'Unsupported location field.'];
        }

        $intake->approval_snapshot_json = $snapshot;
        $intake->save();

        return $this->resolveSuccessPayload($loc, $talukaId, $districtId, $stateId);
    }

    /**
     * @return array{ok: true, city_id: int, display_label: string, taluka_id: ?int, district_id: ?int, state_id: ?int}
     */
    private function resolveSuccessPayload(Location $loc, ?int $talukaId, ?int $districtId, ?int $stateId): array
    {
        return [
            'ok' => true,
            'city_id' => (int) $loc->id,
            'display_label' => $this->locationFormatter->formatForLocation($loc),
            'taluka_id' => $talukaId,
            'district_id' => $districtId,
            'state_id' => $stateId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotForEdit(BiodataIntake $intake): array
    {
        $approval = $intake->approval_snapshot_json;
        if (is_array($approval) && $approval !== []) {
            return $approval;
        }
        $parsed = $intake->parsed_json;
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }

        return [];
    }

    private function extractPlaceSearchInput(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/[\p{L}\p{M}0-9][\p{L}\p{M}0-9\s\-–—]+,\s*ता\.?\s*[\p{L}\p{M}\s\-–—]+,\s*जि\.?\s*[\p{L}\p{M}\s\-–—]+/u', $raw, $compound)) {
            return trim((string) $compound[0]);
        }

        if (mb_strlen($raw) <= self::MAX_SERVER_SEARCH_CHARS && ! $this->looksLikeNarrativeAddress($raw)) {
            return $raw;
        }

        if ($this->looksLikeNarrativeAddress($raw)) {
            return '';
        }

        return mb_substr($raw, 0, self::MAX_SERVER_SEARCH_CHARS);
    }

    private function looksLikeNarrativeAddress(string $raw): bool
    {
        if (mb_strlen($raw) > self::MAX_SERVER_SEARCH_CHARS) {
            return true;
        }

        return str_contains($raw, '##')
            || preg_match('/\d{10}/', $raw) === 1
            || substr_count($raw, ':-') >= 2
            || substr_count($raw, "\n") >= 2;
    }

    /**
     * @return array{field_key: string, label: string, raw_input: string, suggested_search: string, options: array<int, array<string, mixed>>, has_confident_match: bool}
     */
    private function entry(string $fieldKey, string $label, string $rawInput): array
    {
        $searchSeed = $this->extractPlaceSearchInput($rawInput);
        $searchQueries = $searchSeed !== ''
            ? $this->compoundAddressParser->searchQueries($searchSeed)
            : [];
        $confident = $this->findConfidentHierarchySuggestion($rawInput);

        return [
            'field_key' => $fieldKey,
            'label' => $label,
            'raw_input' => $rawInput,
            'suggested_search' => $searchQueries[0] ?? ($searchSeed !== '' ? $searchSeed : $rawInput),
            'options' => $confident !== null ? [$confident] : [],
            'has_confident_match' => $confident !== null,
            'dom_anchor' => IntakeLocationFieldRegistry::domAnchor($fieldKey),
        ];
    }

    /**
     * @param  array<string, mixed>  $addr
     */
    private function addressRowRawText(array $addr): string
    {
        return trim((string) ($addr['raw'] ?? $addr['city'] ?? $addr['place'] ?? $addr['location'] ?? $addr['village'] ?? $addr['address_line'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $parsedRow
     */
    private function relativeRowBiodataText(array $parsedRow): string
    {
        return trim((string) ($parsedRow['address_line'] ?? $parsedRow['notes'] ?? $parsedRow['location'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function recordLocationSuggestionApplied(
        array &$core,
        string $fieldKey,
        Location $loc,
        ?int $talukaId,
        ?int $districtId,
        ?int $stateId,
    ): void {
        if (! is_array($core['_location_suggestion_applies'] ?? null)) {
            $core['_location_suggestion_applies'] = [];
        }
        $core['_location_suggestion_applies'][$fieldKey] = [
            'city_id' => (int) $loc->id,
            'display_label' => $this->locationFormatter->formatForLocation($loc),
            'taluka_id' => $talukaId,
            'district_id' => $districtId,
            'state_id' => $stateId,
        ];
    }

    /**
     * One suggestion only when village + taluka + district from biodata resolve to a single hierarchy leaf.
     *
     * @return array<string, mixed>|null
     */
    private function findConfidentHierarchySuggestion(string $raw): ?array
    {
        $searchText = $this->extractPlaceSearchInput($raw);
        if ($searchText === '') {
            return null;
        }

        return $this->placeIntakeSearch->confidentMatch($searchText);
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     * @param  list<array<string, mixed>>  $searchOrder
     * @param  array{village: string, taluka: string, district: string}  $hints
     * @return array<string, mixed>|null
     */
    private function pickBestHierarchyRow(array $matches, array $searchOrder, array $hints): ?array
    {
        $rankById = [];
        foreach ($searchOrder as $index => $row) {
            $id = (int) ($row['city_id'] ?? 0);
            if ($id > 0) {
                $rankById[$id] = $index;
            }
        }

        $best = null;
        $bestScore = -1;
        $bestRank = PHP_INT_MAX;
        $secondScore = -1;

        foreach ($matches as $row) {
            $score = $this->hierarchyMatchScore($row, $hints);
            $rank = $rankById[(int) ($row['city_id'] ?? 0)] ?? PHP_INT_MAX;

            if ($score > $secondScore && ($best === null || $score < $bestScore)) {
                $secondScore = $score;
            }
            if ($score > $bestScore || ($score === $bestScore && $rank < $bestRank)) {
                if ($best !== null && $bestScore > $secondScore) {
                    $secondScore = $bestScore;
                }
                $bestScore = $score;
                $bestRank = $rank;
                $best = $row;
            }
        }

        if ($best === null || $bestScore < 1) {
            return null;
        }

        if ($secondScore === $bestScore) {
            return null;
        }

        $expectedTokens = $this->villageTokenCount((string) ($hints['village'] ?? ''));

        return ($expectedTokens < 2 || $bestScore >= $expectedTokens * 10) ? $best : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{village: string, taluka: string, district: string}  $hints
     */
    private function hierarchyMatchScore(array $row, array $hints): int
    {
        $villageHint = (string) ($hints['village'] ?? '');
        if ($villageHint === '') {
            return 0;
        }

        $score = 0;
        $names = [(string) ($row['city_name'] ?? $row['name'] ?? '')];
        $cityId = (int) ($row['city_id'] ?? 0);
        if ($cityId > 0) {
            $loc = Location::query()->find($cityId);
            if ($loc !== null) {
                $names = array_merge(
                    [$loc->name_mr, $loc->name_en, $loc->name, $loc->localizedName()],
                    $names
                );
            }
        }

        $parts = preg_split('/[\s\-–—]+/u', $villageHint) ?: [];
        foreach ($parts as $part) {
            $token = $this->compactLookupKey((string) $part);
            if ($token === '' || mb_strlen($token) < 2) {
                continue;
            }
            foreach ($names as $name) {
                $compact = $this->compactLookupKey((string) $name);
                if ($compact === '') {
                    continue;
                }
                if (str_contains($compact, $token) || $this->tokenAppearsInCompactName($compact, $token)) {
                    $score += 10;
                    break;
                }
            }
        }

        return $score;
    }

    private function villageTokenCount(string $villageHint): int
    {
        $parts = preg_split('/[\s\-–—]+/u', $villageHint) ?: [];
        $count = 0;
        foreach ($parts as $part) {
            if ($this->compactLookupKey((string) $part) !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array{village: string, taluka: string, district: string}  $hints
     */
    private function locationMatchesHierarchyHints(Location $loc, array $hints): bool
    {
        $this->locationService->ensureAncestorsLoaded($loc);
        $h = $this->locationService->fillHierarchyGaps($loc, $this->locationService->getFullHierarchy($loc));

        $villageHint = (string) ($hints['village'] ?? '');
        $locName = $this->compactLookupKey($loc->localizedName());
        if ($villageHint === '' || ! $this->villageNameMatchesHint($locName, $villageHint)) {
            return false;
        }

        $taluka = $h['taluka'] ?? null;
        $district = $h['district'] ?? null;
        $talukaHint = $this->normalizePlaceCompareKey((string) ($hints['taluka'] ?? ''));
        $districtHint = $this->normalizePlaceCompareKey((string) ($hints['district'] ?? ''));

        if ($talukaHint !== '' && ! $this->adminUnitMatchesHint(
            (string) ($taluka?->localizedName() ?? ''),
            $talukaHint,
            (int) ($taluka?->id ?? 0)
        )) {
            return false;
        }

        if ($districtHint !== '' && ! $this->adminUnitMatchesHint(
            (string) ($district?->localizedName() ?? ''),
            $districtHint,
            (int) ($district?->id ?? 0)
        )) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{village: string, taluka: string, district: string}  $hints
     */
    private function rowMatchesFullHierarchy(array $row, array $hints): bool
    {
        $talukaHint = trim((string) ($hints['taluka'] ?? ''));
        $districtHint = trim((string) ($hints['district'] ?? ''));
        if ($talukaHint !== '' && ! $this->adminUnitMatchesHint(
            (string) ($row['taluka_name'] ?? ''),
            $talukaHint,
            (int) ($row['taluka_id'] ?? 0)
        )) {
            return false;
        }
        if ($districtHint !== '' && ! $this->adminUnitMatchesHint(
            (string) ($row['district_name'] ?? ''),
            $districtHint,
            (int) ($row['district_id'] ?? 0)
        )) {
            return false;
        }

        $villageHint = (string) ($hints['village'] ?? '');
        if ($villageHint === '') {
            return false;
        }

        $cityId = (int) ($row['city_id'] ?? 0);
        if ($cityId > 0) {
            $loc = Location::query()->find($cityId);
            if ($loc !== null) {
                foreach ([$loc->name_mr, $loc->name_en, $loc->name, $loc->localizedName()] as $candidate) {
                    if ($this->villageNameMatchesHint($this->compactLookupKey((string) $candidate), $villageHint)) {
                        return true;
                    }
                }
            }
        }

        $nameCompact = $this->compactLookupKey((string) ($row['city_name'] ?? $row['name'] ?? ''));

        return $this->villageNameMatchesHint($nameCompact, $villageHint);
    }

    private function adminUnitMatchesHint(string $displayName, string $hint, int $locationId = 0): bool
    {
        $hintNorm = $this->normalizePlaceCompareKey($hint);
        if ($hintNorm === '') {
            return true;
        }

        $displayNorm = $this->normalizePlaceCompareKey($displayName);
        if ($this->adminNamesMatch($displayNorm, $hintNorm)) {
            return true;
        }

        if ($locationId < 1) {
            return false;
        }

        $loc = Location::query()->find($locationId);
        if ($loc === null) {
            return false;
        }

        foreach ([$loc->name_mr, $loc->name_en, $loc->name, $loc->localizedName()] as $candidate) {
            $candidateNorm = $this->normalizePlaceCompareKey((string) $candidate);
            if ($candidateNorm !== '' && $this->adminNamesMatch($candidateNorm, $hintNorm)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRowFromLocation(Location $loc): array
    {
        $h = $this->locationService->getFullHierarchy($loc);
        $h = $this->locationService->fillHierarchyGaps($loc, $h);
        $taluka = $h['taluka'] ?? null;
        $district = $h['district'] ?? null;
        $state = $h['state'] ?? ($district?->parent ?? null);

        return [
            'city_id' => (int) $loc->id,
            'id' => (int) $loc->id,
            'name' => $loc->localizedName(),
            'city_name' => $loc->localizedName(),
            'taluka_id' => $taluka ? (int) $taluka->id : 0,
            'taluka_name' => $taluka?->localizedName() ?? '',
            'district_id' => $district ? (int) $district->id : 0,
            'district_name' => $district?->localizedName() ?? '',
            'state_id' => $state instanceof Location ? (int) $state->id : 0,
            'state_name' => $state instanceof Location ? $state->localizedName() : '',
            'display_label' => $this->locationFormatter->formatForLocation($loc),
        ];
    }

    private function adminNamesMatch(string $resolved, string $hint): bool
    {
        if ($resolved === '' || $hint === '') {
            return false;
        }

        return $resolved === $hint
            || str_contains($resolved, $hint)
            || str_contains($hint, $resolved);
    }

    private function compactLookupKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[\s\-–—\.]+/u', '', $value) ?? $value;

        return trim($value);
    }

    private function compactNamesLooselyMatch(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        similar_text($a, $b, $pct);

        return $pct >= 88.0 || str_contains($a, $b) || str_contains($b, $a);
    }

    private function villageNameMatchesHint(string $locNameCompact, string $villageHint): bool
    {
        $hintCompact = $this->compactLookupKey($villageHint);
        if ($hintCompact === '' || $locNameCompact === '') {
            return false;
        }
        if ($this->compactNamesLooselyMatch($locNameCompact, $hintCompact)) {
            return true;
        }

        $parts = preg_split('/[\s\-–—]+/u', $villageHint) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $tk = $this->compactLookupKey((string) $part);
            if ($tk !== '' && mb_strlen($tk) >= 2) {
                $tokens[] = $tk;
            }
        }

        if (count($tokens) < 2) {
            return str_contains($locNameCompact, $hintCompact) || str_contains($hintCompact, $locNameCompact);
        }

        foreach ($tokens as $token) {
            if (str_contains($locNameCompact, $token)) {
                continue;
            }
            if ($this->tokenAppearsInCompactName($locNameCompact, $token)) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function tokenAppearsInCompactName(string $locNameCompact, string $token): bool
    {
        if ($token === '' || $locNameCompact === '') {
            return false;
        }

        $len = mb_strlen($token);
        for ($i = 0; $i <= mb_strlen($locNameCompact) - $len; $i++) {
            $slice = mb_substr($locNameCompact, $i, $len);
            similar_text($slice, $token, $pct);
            if ($pct >= 86.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $intakeCore
     */
    private function extractBirthPlaceRaw(array $core, array $snapshot, array $intakeCore): string
    {
        $intakeCandidates = [
            $intakeCore['birth_place_text'] ?? null,
            $intakeCore['birth_place'] ?? null,
        ];
        if (is_array($snapshot['birth_place'] ?? null)) {
            $bp = $snapshot['birth_place'];
            $intakeCandidates[] = $bp['raw'] ?? $bp['address_line'] ?? $bp['text'] ?? null;
        }

        $coreCandidates = [
            $core['birth_place_text'] ?? null,
            $core['birth_place'] ?? null,
        ];

        $intakeRaw = $this->firstPlaceText($intakeCandidates);
        $coreRaw = $this->firstPlaceText($coreCandidates);

        if ($intakeRaw !== '') {
            if ($coreRaw === '' || $this->normalizePlaceCompareKey($intakeRaw) !== $this->normalizePlaceCompareKey($coreRaw)) {
                return $intakeRaw;
            }
        }

        return $coreRaw !== '' ? $coreRaw : $intakeRaw;
    }

    /**
     * @param  list<mixed>  $candidates
     */
    private function firstPlaceText(array $candidates): string
    {
        foreach ($candidates as $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '' && $text !== '—' && $text !== '-') {
                return $text;
            }
        }

        return '';
    }

    /**
     * Biodata birth text only — never {@code core.birth_place} (parser-filled form field).
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $intakeCore
     */
    private function extractBiodataBirthPlace(array $snapshot, array $intakeCore): string
    {
        $candidates = [
            $intakeCore['birth_place_text'] ?? null,
            $intakeCore['birth_place'] ?? null,
        ];
        if (is_array($snapshot['birth_place'] ?? null)) {
            $bp = $snapshot['birth_place'];
            $candidates[] = $bp['raw'] ?? $bp['address_line'] ?? $bp['text'] ?? null;
        }

        return $this->firstPlaceText($candidates);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $intakeCore
     */
    private function extractBiodataNativePlace(array $snapshot, array $intakeCore): string
    {
        $candidates = [
            $intakeCore['native_place'] ?? null,
            $intakeCore['native_place_text'] ?? null,
        ];
        if (is_array($snapshot['native_place'] ?? null)) {
            $np = $snapshot['native_place'];
            $candidates[] = $np['raw'] ?? $np['address_line'] ?? $np['text'] ?? null;
        }

        return $this->firstPlaceText($candidates);
    }

    /**
     * @param  array<string, mixed>  $intakeCore
     */
    private function extractBiodataWorkPlace(array $intakeCore): string
    {
        return $this->firstPlaceText([
            $intakeCore['work_location_text'] ?? null,
            $intakeCore['work_location'] ?? null,
        ]);
    }

    /**
     * User picked place in location engine ({@code birth_city_id}) — not parser text in {@code birth_place}.
     *
     * @param  array<string, mixed>  $core
     */
    private function shouldSuggestBirthPlace(string $biodataRaw, array $core): bool
    {
        $cityId = $core['birth_city_id'] ?? null;
        if (is_numeric($cityId) && (int) $cityId > 0) {
            return $this->intakeDiffersFromUserLocation($biodataRaw, (int) $cityId);
        }

        $userText = trim((string) ($core['birth_place_text'] ?? ''));
        if ($userText === '') {
            return $biodataRaw !== '';
        }

        return $this->normalizePlaceCompareKey($biodataRaw) !== $this->normalizePlaceCompareKey($userText);
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $snapshot
     */
    private function shouldSuggestNativePlace(string $biodataRaw, array $core, array $snapshot): bool
    {
        $cityId = $core['native_city_id'] ?? null;
        if (is_numeric($cityId) && (int) $cityId > 0) {
            return $this->intakeDiffersFromUserLocation($biodataRaw, (int) $cityId);
        }

        $userText = trim((string) ($core['native_place'] ?? $snapshot['native_place']['raw'] ?? ''));
        if ($userText === '' || ! is_scalar($userText)) {
            return $biodataRaw !== '';
        }

        return $this->normalizePlaceCompareKey($biodataRaw) !== $this->normalizePlaceCompareKey((string) $userText);
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function shouldSuggestWorkPlace(string $biodataRaw, array $core): bool
    {
        $cityId = $core['work_city_id'] ?? null;
        if (is_numeric($cityId) && (int) $cityId > 0) {
            return $this->intakeDiffersFromUserLocation($biodataRaw, (int) $cityId);
        }

        $userText = trim((string) ($core['work_location_text'] ?? ''));

        return $userText === ''
            ? $biodataRaw !== ''
            : $this->normalizePlaceCompareKey($biodataRaw) !== $this->normalizePlaceCompareKey($userText);
    }

    /**
     * Biodata/OCR text differs from what the user already picked in the location engine.
     */
    private function intakeDiffersFromUserLocation(string $raw, mixed $cityId): bool
    {
        if (! is_numeric($cityId) || (int) $cityId < 1) {
            return true;
        }

        $loc = Location::query()->find((int) $cityId);
        if ($loc === null) {
            return true;
        }

        return ! $this->placeTextMatchesResolvedLocation($raw, $loc);
    }

    private function placeTextMatchesResolvedLocation(string $raw, Location $loc): bool
    {
        $rawNorm = $this->normalizePlaceCompareKey($raw);
        if ($rawNorm === '') {
            return true;
        }

        $labels = [
            $this->normalizePlaceCompareKey($loc->localizedName()),
            $this->normalizePlaceCompareKey($this->locationFormatter->formatForLocation($loc)),
        ];

        foreach ($labels as $labelNorm) {
            if ($labelNorm !== '' && ($labelNorm === $rawNorm || str_contains($rawNorm, $labelNorm) || str_contains($labelNorm, $rawNorm))) {
                return true;
            }
        }

        $components = $this->compoundAddressParser->parseComponents($raw);
        foreach (['village', 'taluka', 'district'] as $key) {
            $part = $this->normalizePlaceCompareKey((string) ($components[$key] ?? ''));
            if ($part === '') {
                continue;
            }
            foreach ($labels as $labelNorm) {
                if ($labelNorm !== '' && str_contains($labelNorm, $part)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizePlaceCompareKey(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $text) ?? $text;
        $text = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $text) ?? $text;

        return trim((string) (preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
