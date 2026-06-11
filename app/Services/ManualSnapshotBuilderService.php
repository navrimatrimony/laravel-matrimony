<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5 SSOT: Reusable builder for full manual profile snapshot.
 * Used by ProfileWizardController (section=full) and legacy redirects.
 * MutationService remains the single apply path.
 */
class ManualSnapshotBuilderService
{
    /**
     * Build full SSOT snapshot (core + contacts + children
     * + addresses + property text + horoscope + preferences + extended_narrative).
     */
    public function buildFullManualSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        if (Schema::hasColumn('matrimony_profiles', 'highest_education')) {
            app(EducationService::class)->mergeMultiselectEducationIntoRequest($request);
        }
        if (Schema::hasColumn('matrimony_profiles', 'occupation_master_id')) {
            app(OccupationService::class)->mergeOccupationIntoRequest($request);
        }
        $this->resolveMasterLookupIds($request);
        $core = [
            'full_name' => $request->input('full_name'),
            'gender_id' => $request->input('gender_id') ? (int) $request->input('gender_id') : $profile->gender_id,
            'date_of_birth' => $request->input('date_of_birth') ?: null,
            'birth_time' => $request->filled('birth_time') ? trim($request->input('birth_time')) : null,
            'religion_id' => $request->input('religion_id') ? (int) $request->input('religion_id') : null,
            'caste_id' => $request->input('caste_id') ? (int) $request->input('caste_id') : null,
            'sub_caste_id' => $request->input('sub_caste_id') ? (int) $request->input('sub_caste_id') : null,
            'mother_tongue_id' => $request->input('mother_tongue_id') ? (int) $request->input('mother_tongue_id') : null,
            'marital_status_id' => $request->input('marital_status_id') ? (int) $request->input('marital_status_id') : null,
            'has_children' => $request->filled('has_children') ? ($request->input('has_children') === '1' || $request->input('has_children') === 1) : null,
            'height_cm' => $request->has('height_cm') && $request->input('height_cm') !== '' ? (int) $request->input('height_cm') : null,
            'weight_kg' => $request->has('weight_kg') && $request->input('weight_kg') !== '' ? (int) $request->input('weight_kg') : null,
            'complexion_id' => $request->input('complexion_id') ? (int) $request->input('complexion_id') : null,
            'physical_build_id' => $request->input('physical_build_id') ? (int) $request->input('physical_build_id') : null,
            'blood_group_id' => $request->input('blood_group_id') ? (int) $request->input('blood_group_id') : null,
            'spectacles_lens' => $request->input('spectacles_lens') ?: null,
            'physical_condition' => $request->input('physical_condition') ?: null,
            'diet_id' => $request->input('diet_id') ? (int) $request->input('diet_id') : null,
            'smoking_status_id' => $request->input('smoking_status_id') ? (int) $request->input('smoking_status_id') : null,
            'drinking_status_id' => $request->input('drinking_status_id') ? (int) $request->input('drinking_status_id') : null,
            'highest_education' => $request->input('highest_education'),
            'highest_education_other' => $request->filled('highest_education_other') ? trim((string) $request->input('highest_education_other')) : null,
            'working_with_type_id' => $workingWithTypeId = $request->filled('working_with_type_id') ? (int) $request->input('working_with_type_id') : null,
            'profession_id' => $this->resolveProfessionIdForWorkingWith($request->input('profession_id'), $workingWithTypeId),
            'occupation_master_id' => $request->filled('occupation_master_id') ? (int) $request->input('occupation_master_id') : null,
            'occupation_custom_id' => $request->filled('occupation_custom_id') ? (int) $request->input('occupation_custom_id') : null,
            'occupation_type' => $request->input('occupation_type') ?: null,
            'occupation_title' => $request->input('occupation_title'),
            'company_name' => $request->input('company_name'),
            'annual_income' => $request->has('annual_income') && $request->input('annual_income') !== '' ? (float) $request->input('annual_income') : null,
            'income_range_id' => $request->filled('income_range_id') ? (int) $request->input('income_range_id') : null,
            'income_private' => $request->boolean('income_private'),
            'income_currency_id' => $request->input('income_currency_id') ? (int) $request->input('income_currency_id') : (\App\Models\MasterIncomeCurrency::where('code', 'INR')->value('id')),
            'family_income' => $request->has('family_income') && $request->input('family_income') !== '' ? (float) $request->input('family_income') : null,
        ];
        $core = array_merge($core, $this->buildIncomeEngineCoreForSnapshot($request, 'income'));
        $core = array_merge($core, $this->buildIncomeEngineCoreForSnapshot($request, 'family_income'));
        $core['father_name'] = $request->input('father_name');
        $core['father_occupation'] = $request->input('father_occupation');
        if (Schema::hasColumn('matrimony_profiles', 'father_occupation_master_id')) {
            app(\App\Services\OccupationService::class)->mergeParentOccupationTextIntoRequest($request);
            $core['father_occupation'] = $request->input('father_occupation');
            $core['father_occupation_master_id'] = $request->filled('father_occupation_master_id') ? (int) $request->input('father_occupation_master_id') : null;
            $core['father_occupation_custom_id'] = $request->filled('father_occupation_custom_id') ? (int) $request->input('father_occupation_custom_id') : null;
            $core['mother_occupation_master_id'] = $request->filled('mother_occupation_master_id') ? (int) $request->input('mother_occupation_master_id') : null;
            $core['mother_occupation_custom_id'] = $request->filled('mother_occupation_custom_id') ? (int) $request->input('mother_occupation_custom_id') : null;
        }
        $core['father_extra_info'] = $request->filled('father_extra_info') ? trim((string) $request->input('father_extra_info')) : null;
        $core['father_contact_1'] = trim((string) ($request->input('father_contact_1') ?? '')) ?: null;
        $core['father_contact_2'] = trim((string) ($request->input('father_contact_2') ?? '')) ?: null;
        if (Schema::hasColumn('matrimony_profiles', 'father_contact_3')) {
            $core['father_contact_3'] = trim((string) ($request->input('father_contact_3') ?? '')) ?: null;
        }
        $core['mother_name'] = $request->input('mother_name');
        $core['mother_occupation'] = $request->input('mother_occupation');
        $core['mother_extra_info'] = $request->filled('mother_extra_info') ? trim((string) $request->input('mother_extra_info')) : null;
        $core['mother_contact_1'] = trim((string) ($request->input('mother_contact_1') ?? '')) ?: null;
        $core['mother_contact_2'] = trim((string) ($request->input('mother_contact_2') ?? '')) ?: null;
        if (Schema::hasColumn('matrimony_profiles', 'mother_contact_3')) {
            $core['mother_contact_3'] = trim((string) ($request->input('mother_contact_3') ?? '')) ?: null;
        }
        $core['family_type_id'] = $request->input('family_type_id') ? (int) $request->input('family_type_id') : null;
        $core['family_status'] = $request->input('family_status') ?: null;
        $core['family_values'] = $request->input('family_values') ?: null;
        $core['other_relatives_text'] = trim((string) $request->input('other_relatives_text', '')) ?: null;
        $core['property_details'] = $this->propertyDetailsTextFromRequest($request);
        $core['country_id'] = ($v = $this->rawCoreInput($request, 'country_id')) !== null && $v !== '' ? $v : null;
        $core['state_id'] = ($v = $this->rawCoreInput($request, 'state_id')) !== null && $v !== '' ? $v : null;
        $core['district_id'] = ($v = $this->rawCoreInput($request, 'district_id')) !== null && $v !== '' ? $v : null;
        $core['taluka_id'] = ($v = $this->rawCoreInput($request, 'taluka_id')) !== null && $v !== '' ? $v : null;
        $core['location_id'] = ($v = $this->rawCoreInput($request, 'location_id')) !== null && $v !== '' ? $v : null;
        $addrRaw = $this->rawCoreInput($request, 'address_line');
        $core['address_line'] = $addrRaw !== null && trim((string) $addrRaw) !== '' ? trim((string) $addrRaw) : null;
        $core['work_city_id'] = $request->input('work_city_id') ?: null;
        $core['work_state_id'] = $request->input('work_state_id') ?: null;
        $wlt = trim((string) $request->input('work_location_text', ''));
        $core['work_location_text'] = $wlt !== '' ? mb_substr($wlt, 0, 255) : null;
        $core['serious_intent_id'] = $request->input('serious_intent_id') ?: null;
        $core['has_siblings'] = $request->has('has_siblings') ? ($request->input('has_siblings') === '1' || $request->input('has_siblings') === 1) : null;
        $core = array_map(fn ($v) => $v === '' ? null : $v, $core);

        // Phase-5 Point 7: optional photo in full edit — same handling as wizard photo step
        if ($request->hasFile('profile_photo')) {
            Log::info('UPLOAD ENTRY HIT', [
                'controller' => __METHOD__,
                'user_id' => auth()->id() ?? null,
            ]);

            $request->validate(['profile_photo' => ['image', 'max:2048']]);
            $file = $request->file('profile_photo');
            $pending = app(\App\Services\Image\ImageProcessingService::class)
                ->enqueueProfilePhotoProcessing($file, (int) $profile->id);

            // Store a placeholder filename immediately; final file is written by the queue job.
            $core['profile_photo'] = $pending;
            // Moderation columns are applied after MutationService (see ProfilePhotoPendingStateService).
            $request->attributes->set('matrimony_apply_pending_photo_review', true);
        }

        $contacts = [];
        $phone = trim((string) $request->input('primary_contact_number', ''));
        if ($phone !== '') {
            $pref = $request->input('primary_contact_whatsapp', 'whatsapp');
            $pref = in_array($pref, ['whatsapp', 'call', 'message'], true) ? $pref : 'whatsapp';
            $contacts[] = [
                'relation_type' => 'self',
                'contact_name' => 'Primary',
                'phone_number' => $phone,
                'is_primary' => true,
                'is_whatsapp' => $pref === 'whatsapp',
                'contact_preference' => $pref,
            ];
        }

        $children = [];
        foreach ($request->input('children', []) as $i => $row) {
            $age = $row['age'] ?? $row['child_age'] ?? 0;
            $children[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'child_name' => trim((string) ($row['child_name'] ?? '')),
                'gender' => trim((string) ($row['gender'] ?? $row['child_gender'] ?? '')),
                'age' => $age !== '' && $age !== null ? (int) $age : 0,
                'child_living_with_id' => ! empty($row['child_living_with_id']) ? (int) $row['child_living_with_id'] : null,
                'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : $i,
            ];
        }

        $education_history = [];

        $addresses = array_merge(
            $this->mapTypedAddressRows($request->input('self_addresses'), 'self', 'current'),
            $this->mapTypedAddressRows($request->input('parents_addresses'), 'parents', 'permanent'),
        );
        if ($addresses === []) {
            $addresses = $this->mapLegacyAddressRows($request->input('addresses'));
        }

        $horoscope = [];
        if ($request->has('horoscope')) {
            $h = $request->input('horoscope');
            if (empty($h['id'] ?? null) && $profile->id) {
                $existingId = \App\Models\ProfileHoroscopeData::where('profile_id', $profile->id)->value('id');
                if ($existingId) {
                    $h['id'] = (int) $existingId;
                }
            }
            $horoscope = [[
                'id' => ! empty($h['id']) ? (int) $h['id'] : null,
                'rashi_id' => ! empty($h['rashi_id']) ? (int) $h['rashi_id'] : null,
                'nakshatra_id' => ! empty($h['nakshatra_id']) ? (int) $h['nakshatra_id'] : null,
                'charan' => isset($h['charan']) && $h['charan'] !== '' ? (int) $h['charan'] : null,
                'gan_id' => ! empty($h['gan_id']) ? (int) $h['gan_id'] : null,
                'nadi_id' => ! empty($h['nadi_id']) ? (int) $h['nadi_id'] : null,
                'yoni_id' => ! empty($h['yoni_id']) ? (int) $h['yoni_id'] : null,
                'varna_id' => ! empty($h['varna_id']) ? (int) $h['varna_id'] : null,
                'vashya_id' => ! empty($h['vashya_id']) ? (int) $h['vashya_id'] : null,
                'rashi_lord_id' => ! empty($h['rashi_lord_id']) ? (int) $h['rashi_lord_id'] : null,
                'mangal_dosh_type_id' => ! empty($h['mangal_dosh_type_id']) ? (int) $h['mangal_dosh_type_id'] : null,
                'devak' => trim((string) ($h['devak'] ?? '')),
                'kul' => trim((string) ($h['kuldaivat'] ?? $h['kul'] ?? '')),
                'gotra' => trim((string) ($h['gotra'] ?? '')),
                'navras_name' => trim((string) ($h['navras_name'] ?? '')),
                'birth_weekday' => trim((string) ($h['birth_weekday'] ?? '')),
            ]];
        }

        $birth_place = null;
        if ($request->has('birth_city_id')) {
            $birth_place = [
                'city_id' => $request->input('birth_city_id') ? (int) $request->input('birth_city_id') : null,
                'taluka_id' => null,
                'district_id' => null,
                'state_id' => null,
            ];
        }

        $native_place = null;
        if ($request->has('native_city_id') || $request->has('native_state_id')) {
            $native_place = [
                'city_id' => $request->input('native_city_id') ? (int) $request->input('native_city_id') : null,
                'taluka_id' => $request->input('native_taluka_id') ? (int) $request->input('native_taluka_id') : null,
                'district_id' => $request->input('native_district_id') ? (int) $request->input('native_district_id') : null,
                'state_id' => $request->input('native_state_id') ? (int) $request->input('native_state_id') : null,
            ];
        }

        $siblings = [];
        $wizardController = app(\App\Http\Controllers\ProfileWizardController::class);
        foreach ($core['has_siblings'] === false ? [] : $request->input('siblings', []) as $row) {
            $siblings[] = $wizardController->mapSiblingFormRowFromRequest(is_array($row) ? $row : []);
        }

        $relatives = app(\App\Http\Controllers\ProfileWizardController::class)->collectRelativesFromRequest($request);

        $alliance_networks = [];
        foreach ($request->input('alliance_networks', []) as $row) {
            $surname = trim((string) ($row['surname'] ?? ''));
            if ($surname === '') {
                continue;
            }
            $alliance_networks[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'surname' => $surname,
                'city_id' => ! empty($row['city_id']) ? (int) $row['city_id'] : null,
                'taluka_id' => ! empty($row['taluka_id']) ? (int) $row['taluka_id'] : null,
                'district_id' => ! empty($row['district_id']) ? (int) $row['district_id'] : null,
                'state_id' => ! empty($row['state_id']) ? (int) $row['state_id'] : null,
                'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
            ];
        }

        $preferences = [];
        if ($request->has('preferences')) {
            $pr = $request->input('preferences');
            $preferences = [[
                'id' => ! empty($pr['id']) ? (int) $pr['id'] : null,
                'preferred_city' => trim((string) ($pr['preferred_city'] ?? '')),
                'preferred_caste' => trim((string) ($pr['preferred_caste'] ?? '')),
                'preferred_age_min' => isset($pr['preferred_age_min']) && $pr['preferred_age_min'] !== '' ? (int) $pr['preferred_age_min'] : null,
                'preferred_age_max' => isset($pr['preferred_age_max']) && $pr['preferred_age_max'] !== '' ? (int) $pr['preferred_age_max'] : null,
                'preferred_height_min_cm' => isset($pr['preferred_height_min_cm']) && $pr['preferred_height_min_cm'] !== '' ? (int) $pr['preferred_height_min_cm'] : null,
                'preferred_height_max_cm' => isset($pr['preferred_height_max_cm']) && $pr['preferred_height_max_cm'] !== '' ? (int) $pr['preferred_height_max_cm'] : null,
                'preferred_income_min' => isset($pr['preferred_income_min']) && $pr['preferred_income_min'] !== '' ? (float) $pr['preferred_income_min'] : null,
                'preferred_income_max' => isset($pr['preferred_income_max']) && $pr['preferred_income_max'] !== '' ? (float) $pr['preferred_income_max'] : null,
                'preferred_education' => trim((string) ($pr['preferred_education'] ?? '')),
            ]];
        } elseif (\App\Services\PartnerPreferenceSnapshotBuilder::requestHasFlatPartnerPreferenceFields($request)) {
            $preferences = [\App\Services\PartnerPreferenceSnapshotBuilder::validateAndBuildRow($request)];
        }

        $extended_narrative = [];
        if ($request->has('extended_narrative')) {
            $en = $request->input('extended_narrative');
            $extended_narrative = [[
                'id' => ! empty($en['id']) ? (int) $en['id'] : null,
                'narrative_about_me' => trim((string) ($en['narrative_about_me'] ?? '')),
                'narrative_expectations' => trim((string) ($en['narrative_expectations'] ?? '')),
                'additional_notes' => trim((string) ($en['additional_notes'] ?? '')),
            ]];
        }

        $marriages = [];
        if ($request->has('marriages')) {
            foreach ($request->input('marriages', []) as $row) {
                $marriages[] = [
                    'id' => $row['id'] ?? null,
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
        }

        return [
            'core' => $core,
            'contacts' => $contacts,
            'birth_place' => $birth_place,
            'native_place' => $native_place,
            'children' => $children,
            'siblings' => $siblings,
            'relatives' => $relatives,
            'alliance_networks' => $alliance_networks,
            'education_history' => $education_history,
            'career_history' => [],
            'addresses' => $addresses,
            'horoscope' => $horoscope,
            'preferences' => $preferences,
            'extended_narrative' => $extended_narrative,
            'marriages' => $marriages,
        ];
    }

    /**
     * Read one core scalar from intake-style {@code snapshot[core][key]}, wizard {@code core[key]}, or flat {@code key}.
     * Intake preview and prefixed wizard sections post nested names; flat keys are used for single-section wizard saves.
     *
     * @return mixed|null Null when the key is absent from all three shapes (not the same as an explicit empty string).
     */
    private function rawCoreInput(Request $request, string $key): mixed
    {
        foreach (['snapshot.core.'.$key, 'core.'.$key, $key] as $path) {
            if ($request->exists($path)) {
                return $request->input($path);
            }
        }

        return null;
    }

    private function propertyDetailsTextFromRequest(Request $request): ?string
    {
        $lines = [];
        foreach (['snapshot.core.property_details', 'core.property_details', 'property_details'] as $path) {
            if (! $request->exists($path)) {
                continue;
            }
            $lines = array_merge($lines, $this->propertyTextLines($request->input($path)));
        }

        if ($request->has('property_summary')) {
            $ps = $request->input('property_summary');
            $lines = array_merge($lines, $this->propertyTextLines($this->propertySummaryPayloadToNotes(is_array($ps) ? $ps : [])));
        }

        foreach ($request->input('property_assets', []) as $row) {
            if (is_array($row)) {
                $lines = array_merge($lines, $this->propertyTextLines($this->propertyAssetPayloadToText($row)));
            }
        }

        $merged = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line !== '' && ! in_array($line, $merged, true)) {
                $merged[] = $line;
            }
        }

        return $merged === [] ? null : implode("\n", $merged);
    }

    /**
     * @return list<string>
     */
    private function propertyTextLines(mixed $value): array
    {
        if (is_array($value)) {
            $lines = [];
            foreach ($value as $item) {
                $lines = array_merge($lines, $this->propertyTextLines($item));
            }

            return $lines;
        }
        if ($value === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\R+/u', trim((string) $value)) ?: []
        ), static fn (string $line): bool => $line !== ''));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function propertyAssetPayloadToText(array $row): ?string
    {
        $parts = [];
        foreach (['asset_type_label', 'asset_type', 'asset_type_key', 'location', 'ownership_type_label', 'ownership_type', 'ownership_type_key'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        if (($row['estimated_value'] ?? null) !== null && (string) $row['estimated_value'] !== '') {
            $parts[] = 'Estimated value: '.trim((string) $row['estimated_value']);
        }
        foreach (['notes', 'additional_information'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        $parts = array_values(array_unique($parts));

        return $parts === [] ? null : implode(' - ', $parts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapTypedAddressRows(mixed $input, string $scope, string $defaultType): array
    {
        if (! is_array($input)) {
            return [];
        }

        $rows = [];
        foreach ($input as $row) {
            if (! is_array($row)) {
                continue;
            }

            $addressLine = trim((string) ($row['address_line'] ?? ''));
            $locationId = isset($row['location_id']) && $row['location_id'] !== ''
                ? (int) $row['location_id']
                : null;
            if ($addressLine === '' && ! $locationId) {
                continue;
            }

            $type = trim((string) ($row['address_type_key'] ?? $row['address_type'] ?? $defaultType));
            $rows[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'address_scope' => $scope,
                'address_type' => $type !== '' ? $type : $defaultType,
                'address_line' => $addressLine !== '' ? mb_substr($addressLine, 0, 255) : null,
                'location_id' => $locationId,
                'location_input' => null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapLegacyAddressRows(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $rows = [];
        foreach ($input as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'address_scope' => trim((string) ($row['address_scope'] ?? 'self')) ?: 'self',
                'address_type_id' => ! empty($row['address_type_id']) ? (int) $row['address_type_id'] : null,
                'address_line' => trim((string) ($row['address_line'] ?? '')) ?: null,
                'location_id' => ! empty($row['location_id']) ? (int) $row['location_id'] : null,
                'village_id' => $row['village_id'] ?? null,
                'taluka' => trim((string) ($row['taluka'] ?? '')),
                'district' => trim((string) ($row['district'] ?? '')),
                'state' => trim((string) ($row['state'] ?? '')),
                'country' => trim((string) ($row['country'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * Resolve string lookup inputs to *_id when form sends key/code instead of id.
     */
    public function resolveMasterLookupIds(Request $request, ?array $map = null): void
    {
        $map = $map ?? [
            'gender' => 'gender_id',
            'marital_status' => 'marital_status_id',
            'complexion' => 'complexion_id',
            'physical_build' => 'physical_build_id',
            'blood_group' => 'blood_group_id',
            'family_type' => 'family_type_id',
            'income_currency' => 'income_currency_id',
        ];
        foreach ($map as $stringKey => $idKey) {
            if ($request->has($stringKey) && ! $request->has($idKey)) {
                $val = $request->input($stringKey);
                if ($val === null || $val === '') {
                    continue;
                }
                $id = null;
                if ($stringKey === 'gender') {
                    $id = \App\Models\MasterGender::where('key', $val)->value('id');
                } elseif ($stringKey === 'marital_status') {
                    $key = $val === 'single' ? 'never_married' : $val;
                    $id = \App\Models\MasterMaritalStatus::where('key', $key)->value('id');
                } elseif ($stringKey === 'income_currency') {
                    $id = \App\Models\MasterIncomeCurrency::where('code', $val)->value('id');
                } elseif ($stringKey === 'family_type') {
                    $id = \App\Models\MasterFamilyType::where('key', $val)->value('id');
                } elseif ($stringKey === 'complexion') {
                    $id = \App\Models\MasterComplexion::where('key', $val)->value('id');
                } elseif ($stringKey === 'physical_build') {
                    $id = \App\Models\MasterPhysicalBuild::where('key', $val)->value('id');
                } elseif ($stringKey === 'blood_group') {
                    $id = \App\Models\MasterBloodGroup::where('key', $val)->value('id');
                }
                if ($id !== null) {
                    $request->merge([$idKey => $id]);
                }
            }
        }
    }

    /**
     * Career dependency: profession_id must belong to working_with_type_id; otherwise clear.
     */
    private function resolveProfessionIdForWorkingWith(mixed $professionId, ?int $workingWithTypeId): ?int
    {
        $pid = $professionId !== null && $professionId !== '' ? (int) $professionId : null;
        if (! $pid || ! $workingWithTypeId) {
            return $pid;
        }
        $prof = \App\Models\Profession::find($pid);

        return $prof && (string) $prof->working_with_type_id === (string) $workingWithTypeId ? $pid : null;
    }

    /**
     * Build income engine core keys for full manual snapshot (income or family_income).
     */
    private function buildIncomeEngineCoreForSnapshot(Request $request, string $prefix): array
    {
        $service = app(IncomeEngineService::class);
        $period = $request->input($prefix.'_period') ?: 'annual';
        $valueType = $request->input($prefix.'_value_type');
        $amount = $request->filled($prefix.'_amount') ? (float) $request->input($prefix.'_amount') : null;
        $minAmount = $request->filled($prefix.'_min_amount') ? (float) $request->input($prefix.'_min_amount') : null;
        $maxAmount = $request->filled($prefix.'_max_amount') ? (float) $request->input($prefix.'_max_amount') : null;
        $normalized = $service->normalizeToAnnual($valueType, $period, $amount, $minAmount, $maxAmount);
        $defaultInr = \App\Models\MasterIncomeCurrency::where('code', 'INR')->value('id');

        $out = [
            $prefix.'_period' => $period,
            $prefix.'_value_type' => $valueType,
            $prefix.'_amount' => $amount,
            $prefix.'_min_amount' => $minAmount,
            $prefix.'_max_amount' => $maxAmount,
            $prefix.'_normalized_annual_amount' => $normalized,
        ];
        if ($prefix === 'income') {
            $out['income_private'] = $request->boolean('income_private');
            $out['income_currency_id'] = $request->input('income_currency_id') ? (int) $request->input('income_currency_id') : $defaultInr;
        } else {
            $out[$prefix.'_currency_id'] = $request->input($prefix.'_currency_id') ? (int) $request->input($prefix.'_currency_id') : $defaultInr;
            $out[$prefix.'_private'] = $request->boolean($prefix.'_private');
        }

        return $out;
    }

    private function propertySummaryPayloadToNotes(array $payload): ?string
    {
        $lines = [];
        if (! empty($payload['owns_house'])) {
            $lines[] = 'Owns house: Yes';
        }
        if (! empty($payload['owns_flat'])) {
            $lines[] = 'Owns flat: Yes';
        }
        if (! empty($payload['owns_agriculture'])) {
            $lines[] = 'Owns agriculture: Yes';
        }
        if (trim((string) ($payload['agriculture_type'] ?? '')) !== '') {
            $lines[] = 'Agriculture type: '.trim((string) $payload['agriculture_type']);
        }
        if (($payload['total_land_acres'] ?? null) !== null && (string) $payload['total_land_acres'] !== '') {
            $lines[] = 'Total land (acres): '.trim((string) $payload['total_land_acres']);
        }
        if (($payload['annual_agri_income'] ?? null) !== null && (string) $payload['annual_agri_income'] !== '') {
            $lines[] = 'Annual agriculture income: '.trim((string) $payload['annual_agri_income']);
        }
        if (trim((string) ($payload['summary_notes'] ?? '')) !== '') {
            $lines[] = trim((string) $payload['summary_notes']);
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     * @return list<array<string, mixed>>
     */
    private function attachPropertyNotesToAssets(array $assets, ?string $notes): array
    {
        $notes = trim((string) $notes);
        if ($notes === '') {
            return $assets;
        }

        foreach ($assets as $idx => $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $hasData = ! empty($asset['id'])
                || ! empty($asset['asset_type_id'])
                || trim((string) ($asset['location'] ?? '')) !== ''
                || ! empty($asset['ownership_type_id'])
                || ($asset['estimated_value'] ?? null) !== null
                || trim((string) ($asset['additional_information'] ?? '')) !== '';
            if (! $hasData) {
                continue;
            }
            $assets[$idx]['notes'] = $notes;

            return $assets;
        }

        $assets[] = [
            'id' => null,
            'asset_type_id' => null,
            'location' => '',
            'estimated_value' => null,
            'ownership_type_id' => null,
            'additional_information' => null,
            'city_id' => null,
            'taluka_id' => null,
            'district_id' => null,
            'state_id' => null,
            'notes' => $notes,
        ];

        return $assets;
    }
}
