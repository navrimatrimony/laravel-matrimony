<?php

namespace App\Services\Showcase;

use App\Models\MatrimonyProfile;
use App\Services\DemoProfileDefaultsService;
use App\Services\PartnerPreferencePresetService;
use App\Services\PartnerPreferenceSuggestionService;

/**
 * Builds the `preferences` section for showcase {@see MutationService::applyManualSnapshot} from admin mode.
 */
class ShowcasePartnerPreferenceSnapshotBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function preferencesForShowcase(MatrimonyProfile $showcase, ?MatrimonyProfile $searcher): array
    {
        $mode = ShowcaseSettings::partnerPrefMode();
        if ($searcher === null && ($mode === 'match_searcher' || $mode === 'mixed')) {
            $mode = 'rules_autofill';
        }

        $fallback = DemoProfileDefaultsService::postCreateSnapshotForDemoProfile($showcase->fresh())['preferences'] ?? [];

        return match ($mode) {
            'match_searcher' => $this->matchSearcherPreferences($showcase, $searcher, $fallback),
            'mixed' => $this->mixedPreferences($showcase, $searcher, $fallback),
            default => $this->rulesAutofillPreferences($showcase, $fallback),
        };
    }

    /**
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function rulesAutofillPreferences(MatrimonyProfile $showcase, array $fallback): array
    {
        $s = PartnerPreferenceSuggestionService::suggestForProfile($showcase->fresh());
        $s = PartnerPreferencePresetService::applyPreset('balanced', $s);
        $merged = $fallback;
        foreach ($s as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (is_array($v) && $v === []) {
                continue;
            }
            $merged[$k] = $v;
        }

        return $this->normalizePreferenceShape($merged);
    }

    /**
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function mixedPreferences(MatrimonyProfile $showcase, ?MatrimonyProfile $searcher, array $fallback): array
    {
        $base = $this->rulesAutofillPreferences($showcase, $fallback);
        if ($searcher === null) {
            return $base;
        }
        $overlay = $this->searcherOverlayPreferences($searcher);
        foreach ($overlay as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (is_array($v) && $v === []) {
                continue;
            }
            $base[$k] = $v;
        }

        return $this->normalizePreferenceShape($base);
    }

    /**
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function matchSearcherPreferences(MatrimonyProfile $showcase, ?MatrimonyProfile $searcher, array $fallback): array
    {
        if ($searcher === null) {
            return $this->rulesAutofillPreferences($showcase, $fallback);
        }

        $overlay = $this->searcherOverlayPreferences($searcher);
        $marital = PartnerPreferenceSuggestionService::defaultPreferredMaritalStatusId($showcase->fresh());

        $merged = $fallback;
        $merged['preferred_marital_status_id'] = $marital;
        $merged['preferred_marital_status_ids'] = $marital !== null ? [(int) $marital] : [];
        foreach ($overlay as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (is_array($v) && $v === []) {
                continue;
            }
            $merged[$k] = $v;
        }

        return $this->normalizePreferenceShape($merged);
    }

    /**
     * @return array<string, mixed>
     */
    private function searcherOverlayPreferences(MatrimonyProfile $searcher): array
    {
        $searcher = $searcher->fresh();
        $searcher->loadMissing(['gender']);

        $age = PartnerPreferenceSuggestionService::profileAge($searcher);
        $prefAgeMin = $age !== null ? max(18, $age - 4) : null;
        $prefAgeMax = $age !== null ? min(80, $age + 4) : null;

        $h = is_numeric($searcher->height_cm) ? (int) $searcher->height_cm : null;
        $prefHeightMin = $h !== null ? max(1, $h - 12) : null;
        $prefHeightMax = $h !== null ? $h + 12 : null;

        $loc = PartnerPreferenceSuggestionService::defaultLocationPivotsFromOwnDistrict($searcher);

        $religionIds = $searcher->religion_id ? [(int) $searcher->religion_id] : [];
        $casteIds = $searcher->caste_id ? [(int) $searcher->caste_id] : [];

        $out = [];
        if ($prefAgeMin !== null) {
            $out['preferred_age_min'] = $prefAgeMin;
        }
        if ($prefAgeMax !== null) {
            $out['preferred_age_max'] = $prefAgeMax;
        }
        if ($prefHeightMin !== null) {
            $out['preferred_height_min_cm'] = $prefHeightMin;
        }
        if ($prefHeightMax !== null) {
            $out['preferred_height_max_cm'] = $prefHeightMax;
        }
        if ($religionIds !== []) {
            $out['preferred_religion_ids'] = $religionIds;
        }
        if ($casteIds !== []) {
            $out['preferred_caste_ids'] = $casteIds;
        }
        if (($loc['preferred_country_ids'] ?? []) !== []) {
            $out['preferred_country_ids'] = $loc['preferred_country_ids'];
        }
        if (($loc['preferred_state_ids'] ?? []) !== []) {
            $out['preferred_state_ids'] = $loc['preferred_state_ids'];
        }
        if (($loc['preferred_district_ids'] ?? []) !== []) {
            $out['preferred_district_ids'] = $loc['preferred_district_ids'];
        }
        if (($loc['preferred_taluka_ids'] ?? []) !== []) {
            $out['preferred_taluka_ids'] = $loc['preferred_taluka_ids'];
        }
        if (! empty($searcher->city_id)) {
            $out['preferred_city_id'] = (int) $searcher->city_id;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function normalizePreferenceShape(array $p): array
    {
        $p['preferred_religion_ids'] = array_values(array_unique(array_filter(array_map('intval', $p['preferred_religion_ids'] ?? []))));
        $p['preferred_caste_ids'] = array_values(array_unique(array_filter(array_map('intval', $p['preferred_caste_ids'] ?? []))));
        $p['preferred_country_ids'] = array_values(array_unique(array_filter(array_map('intval', $p['preferred_country_ids'] ?? []))));
        $p['preferred_state_ids'] = array_values(array_unique(array_filter(array_map('intval', $p['preferred_state_ids'] ?? []))));
        $p['preferred_district_ids'] = array_values(array_unique(array_filter(array_map('intval', $p['preferred_district_ids'] ?? []))));
        $p['preferred_taluka_ids'] = array_values(array_unique(array_filter(array_map('intval', $p['preferred_taluka_ids'] ?? []))));
        $p['preferred_master_education_ids'] = array_values(array_unique(array_filter(array_map('intval', $p['preferred_master_education_ids'] ?? []))));
        $p['preferred_working_with_type_ids'] = array_values(array_unique(array_filter(array_map('intval', $p['preferred_working_with_type_ids'] ?? []))));
        $p['preferred_profession_ids'] = array_values(array_unique(array_filter(array_map('intval', $p['preferred_profession_ids'] ?? []))));
        $p['preferred_diet_ids'] = array_values(array_unique(array_filter(array_map('intval', $p['preferred_diet_ids'] ?? []))));
        $p['preferred_marital_status_ids'] = array_values(array_unique(array_filter(array_map('intval', $p['preferred_marital_status_ids'] ?? []))));

        return $p;
    }
}
