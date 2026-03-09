<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Services\IncomeEngineService;
use Illuminate\Http\Request;

/**
 * Phase-5 SSOT: Reusable builder for full manual profile snapshot.
 * Used by ProfileWizardController (section=full) and legacy redirects.
 * MutationService remains the single apply path.
 */
class ManualSnapshotBuilderService
{
    /**
     * Build full SSOT snapshot (core + contacts + children + education_history + career_history
     * + addresses + property_summary + property_assets + horoscope + preferences + extended_narrative).
     */
    public function buildFullManualSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $this->resolveMasterLookupIds($request);
        $core = [
            'full_name' => $request->input('full_name'),
            'gender_id' => $request->input('gender_id') ? (int) $request->input('gender_id') : $profile->gender_id,
            'date_of_birth' => $request->input('date_of_birth') ?: null,
            'birth_time' => $request->filled('birth_time') ? trim($request->input('birth_time')) : null,
            'religion_id' => $request->input('religion_id') ? (int) $request->input('religion_id') : null,
            'caste_id' => $request->input('caste_id') ? (int) $request->input('caste_id') : null,
            'sub_caste_id' => $request->input('sub_caste_id') ? (int) $request->input('sub_caste_id') : null,
            'marital_status_id' => $request->input('marital_status_id') ? (int) $request->input('marital_status_id') : null,
            'has_children' => $request->filled('has_children') ? ($request->input('has_children') === '1' || $request->input('has_children') === 1) : null,
            'height_cm' => $request->has('height_cm') && $request->input('height_cm') !== '' ? (int) $request->input('height_cm') : null,
            'weight_kg' => $request->has('weight_kg') && $request->input('weight_kg') !== '' ? (int) $request->input('weight_kg') : null,
            'complexion_id' => $request->input('complexion_id') ? (int) $request->input('complexion_id') : null,
            'physical_build_id' => $request->input('physical_build_id') ? (int) $request->input('physical_build_id') : null,
            'blood_group_id' => $request->input('blood_group_id') ? (int) $request->input('blood_group_id') : null,
            'spectacles_lens' => $request->input('spectacles_lens') ?: null,
            'physical_condition' => $request->input('physical_condition') ?: null,
            'highest_education' => $request->input('highest_education'),
            'highest_education_other' => $request->filled('highest_education_other') ? trim((string) $request->input('highest_education_other')) : null,
            'specialization' => $request->input('specialization'),
            'college_id' => $request->filled('college_id') ? (int) $request->input('college_id') : null,
            'working_with_type_id' => $workingWithTypeId = $request->filled('working_with_type_id') ? (int) $request->input('working_with_type_id') : null,
            'profession_id' => $this->resolveProfessionIdForWorkingWith($request->input('profession_id'), $workingWithTypeId),
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
        $core['father_contact_1'] = trim((string) ($request->input('father_contact_1') ?? '')) ?: null;
        $core['father_contact_2'] = trim((string) ($request->input('father_contact_2') ?? '')) ?: null;
        $core['father_contact_3'] = trim((string) ($request->input('father_contact_3') ?? '')) ?: null;
        $core['mother_name'] = $request->input('mother_name');
        $core['mother_occupation'] = $request->input('mother_occupation');
        $core['mother_contact_1'] = trim((string) ($request->input('mother_contact_1') ?? '')) ?: null;
        $core['mother_contact_2'] = trim((string) ($request->input('mother_contact_2') ?? '')) ?: null;
        $core['mother_contact_3'] = trim((string) ($request->input('mother_contact_3') ?? '')) ?: null;
        $core['family_type_id'] = $request->input('family_type_id') ? (int) $request->input('family_type_id') : null;
        $core['country_id'] = $request->input('country_id') ?: null;
        $core['state_id'] = $request->input('state_id') ?: null;
        $core['district_id'] = $request->input('district_id') ?: null;
        $core['taluka_id'] = $request->input('taluka_id') ?: null;
        $core['city_id'] = $request->input('city_id') ?: null;
        $core['address_line'] = $request->filled('address_line') ? trim($request->input('address_line')) : null;
        $core['work_city_id'] = $request->input('work_city_id') ?: null;
        $core['work_state_id'] = $request->input('work_state_id') ?: null;
        $core['serious_intent_id'] = $request->input('serious_intent_id') ?: null;
        $core['has_siblings'] = $request->has('has_siblings') ? ($request->input('has_siblings') === '1' || $request->input('has_siblings') === 1) : null;
        $core = array_map(fn ($v) => $v === '' ? null : $v, $core);

        // Phase-5 Point 7: optional photo in full edit — same handling as wizard photo step
        if ($request->hasFile('profile_photo')) {
            $request->validate(['profile_photo' => ['image', 'max:2048']]);
            $file = $request->file('profile_photo');
            $filename = time() . '_' . basename($file->getClientOriginalName());
            $file->move(public_path('uploads/matrimony_photos'), $filename);
            $photoApprovalRequired = \App\Services\AdminSettingService::isPhotoApprovalRequired();
            $core['profile_photo'] = $filename;
            $core['photo_approved'] = ! $photoApprovalRequired;
            $core['photo_rejected_at'] = null;
            $core['photo_rejection_reason'] = null;
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
        foreach ($request->input('education_history', []) as $row) {
            $education_history[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'degree' => trim((string) ($row['degree'] ?? '')),
                'specialization' => trim((string) ($row['specialization'] ?? '')),
                'university' => trim((string) ($row['university'] ?? '')),
                'year_completed' => ! empty($row['year_completed']) ? (int) $row['year_completed'] : 0,
            ];
        }

        $career_history = [];
        foreach ($request->input('career_history', []) as $row) {
            $career_history[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'designation' => trim((string) ($row['designation'] ?? '')),
                'company' => trim((string) ($row['company'] ?? '')),
                'location' => trim((string) ($row['location'] ?? '')) ?: null,
                'start_year' => ! empty($row['start_year']) ? (int) $row['start_year'] : null,
                'end_year' => ! empty($row['end_year']) ? (int) $row['end_year'] : null,
                'is_current' => isset($row['is_current']) && (string) $row['is_current'] === '1',
            ];
        }

        $addresses = [];
        foreach ($request->input('addresses', []) as $row) {
            $addresses[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'address_type_id' => ! empty($row['address_type_id']) ? (int) $row['address_type_id'] : null,
                'village_id' => $row['village_id'] ?? null,
                'taluka' => trim((string) ($row['taluka'] ?? '')),
                'district' => trim((string) ($row['district'] ?? '')),
                'state' => trim((string) ($row['state'] ?? '')),
                'country' => trim((string) ($row['country'] ?? '')),
                'pin_code' => trim((string) ($row['pin_code'] ?? '')),
            ];
        }

        $property_summary = [];
        if ($request->has('property_summary')) {
            $ps = $request->input('property_summary');
            $property_summary = [[
                'id' => ! empty($ps['id']) ? (int) $ps['id'] : null,
                'owns_house' => ! empty($ps['owns_house']),
                'owns_flat' => ! empty($ps['owns_flat']),
                'owns_agriculture' => ! empty($ps['owns_agriculture']),
                'agriculture_type' => isset($ps['agriculture_type']) && trim((string) ($ps['agriculture_type'] ?? '')) !== '' ? trim((string) $ps['agriculture_type']) : null,
                'total_land_acres' => isset($ps['total_land_acres']) && $ps['total_land_acres'] !== '' ? (float) $ps['total_land_acres'] : null,
                'annual_agri_income' => isset($ps['annual_agri_income']) && $ps['annual_agri_income'] !== '' ? (float) $ps['annual_agri_income'] : null,
                'summary_notes' => trim((string) ($ps['summary_notes'] ?? '')),
            ]];
        }

        $property_assets = [];
        foreach ($request->input('property_assets', []) as $row) {
            $property_assets[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'asset_type_id' => ! empty($row['asset_type_id']) ? (int) $row['asset_type_id'] : null,
                'location' => trim((string) ($row['location'] ?? '')),
                'estimated_value' => isset($row['estimated_value']) && $row['estimated_value'] !== '' ? (float) $row['estimated_value'] : null,
                'ownership_type_id' => ! empty($row['ownership_type_id']) ? (int) $row['ownership_type_id'] : null,
                'city_id' => ! empty($row['city_id']) ? (int) $row['city_id'] : null,
                'taluka_id' => ! empty($row['taluka_id']) ? (int) $row['taluka_id'] : null,
                'district_id' => ! empty($row['district_id']) ? (int) $row['district_id'] : null,
                'state_id' => ! empty($row['state_id']) ? (int) $row['state_id'] : null,
            ];
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
                'mangal_dosh_type_id' => ! empty($h['mangal_dosh_type_id']) ? (int) $h['mangal_dosh_type_id'] : null,
                'devak' => trim((string) ($h['devak'] ?? '')),
                'kul' => trim((string) ($h['kul'] ?? '')),
                'gotra' => trim((string) ($h['gotra'] ?? '')),
                'navras_name' => trim((string) ($h['navras_name'] ?? '')),
                'birth_weekday' => trim((string) ($h['birth_weekday'] ?? '')),
            ]];
        }

        $birth_place = null;
        if ($request->has('birth_city_id') || $request->has('birth_state_id')) {
            $birth_place = [
                'city_id' => $request->input('birth_city_id') ? (int) $request->input('birth_city_id') : null,
                'taluka_id' => $request->input('birth_taluka_id') ? (int) $request->input('birth_taluka_id') : null,
                'district_id' => $request->input('birth_district_id') ? (int) $request->input('birth_district_id') : null,
                'state_id' => $request->input('birth_state_id') ? (int) $request->input('birth_state_id') : null,
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
        foreach ($request->input('siblings', []) as $row) {
            $maritalStatus = in_array($row['marital_status'] ?? null, ['unmarried', 'married'], true) ? $row['marital_status'] : null;
            $isMarried = $maritalStatus === 'married' || ! empty($row['is_married']);
            $spouseIn = $row['spouse'] ?? [];
            $siblingRow = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'relation_type' => in_array($row['relation_type'] ?? null, ['brother', 'sister'], true) ? $row['relation_type'] : null,
                'name' => trim((string) ($row['name'] ?? '')) ?: null,
                'gender' => in_array($row['gender'] ?? null, ['male', 'female'], true) ? $row['gender'] : null,
                'marital_status' => $maritalStatus,
                'occupation' => trim((string) ($row['occupation'] ?? '')) ?: null,
                'city_id' => ! empty($row['city_id']) ? (int) $row['city_id'] : null,
                'contact_number' => trim((string) ($row['contact_number'] ?? '')) ?: null,
                'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
                'sort_order' => isset($row['sort_order']) && $row['sort_order'] !== '' ? (int) $row['sort_order'] : 0,
            ];
            if ($isMarried && (array_key_exists('name', $spouseIn) || array_key_exists('occupation_title', $spouseIn) || array_key_exists('contact_number', $spouseIn) || array_key_exists('city_id', $spouseIn))) {
                $siblingRow['spouse'] = [
                    'name' => trim((string) ($spouseIn['name'] ?? '')) ?: null,
                    'occupation_title' => trim((string) ($spouseIn['occupation_title'] ?? '')) ?: null,
                    'contact_number' => trim((string) ($spouseIn['contact_number'] ?? '')) ?: null,
                    'address_line' => trim((string) ($spouseIn['address_line'] ?? '')) ?: null,
                    'city_id' => ! empty($spouseIn['city_id']) ? (int) $spouseIn['city_id'] : null,
                    'taluka_id' => ! empty($spouseIn['taluka_id']) ? (int) $spouseIn['taluka_id'] : null,
                    'district_id' => ! empty($spouseIn['district_id']) ? (int) $spouseIn['district_id'] : null,
                    'state_id' => ! empty($spouseIn['state_id']) ? (int) $spouseIn['state_id'] : null,
                ];
            }
            $siblings[] = $siblingRow;
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
                'preferred_income_min' => isset($pr['preferred_income_min']) && $pr['preferred_income_min'] !== '' ? (float) $pr['preferred_income_min'] : null,
                'preferred_income_max' => isset($pr['preferred_income_max']) && $pr['preferred_income_max'] !== '' ? (float) $pr['preferred_income_max'] : null,
                'preferred_education' => trim((string) ($pr['preferred_education'] ?? '')),
            ]];
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
            'career_history' => $career_history,
            'addresses' => $addresses,
            'property_summary' => $property_summary,
            'property_assets' => $property_assets,
            'horoscope' => $horoscope,
            'legal_cases' => [],
            'preferences' => $preferences,
            'extended_narrative' => $extended_narrative,
            'marriages' => $marriages,
        ];
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
        $period = $request->input($prefix . '_period') ?: 'annual';
        $valueType = $request->input($prefix . '_value_type');
        $amount = $request->filled($prefix . '_amount') ? (float) $request->input($prefix . '_amount') : null;
        $minAmount = $request->filled($prefix . '_min_amount') ? (float) $request->input($prefix . '_min_amount') : null;
        $maxAmount = $request->filled($prefix . '_max_amount') ? (float) $request->input($prefix . '_max_amount') : null;
        $normalized = $service->normalizeToAnnual($valueType, $period, $amount, $minAmount, $maxAmount);
        $defaultInr = \App\Models\MasterIncomeCurrency::where('code', 'INR')->value('id');

        $out = [
            $prefix . '_period' => $period,
            $prefix . '_value_type' => $valueType,
            $prefix . '_amount' => $amount,
            $prefix . '_min_amount' => $minAmount,
            $prefix . '_max_amount' => $maxAmount,
            $prefix . '_normalized_annual_amount' => $normalized,
        ];
        if ($prefix === 'income') {
            $out['income_private'] = $request->boolean('income_private');
            $out['income_currency_id'] = $request->input('income_currency_id') ? (int) $request->input('income_currency_id') : $defaultInr;
        } else {
            $out[$prefix . '_currency_id'] = $request->input($prefix . '_currency_id') ? (int) $request->input($prefix . '_currency_id') : $defaultInr;
            $out[$prefix . '_private'] = $request->boolean($prefix . '_private');
        }
        return $out;
    }
}
