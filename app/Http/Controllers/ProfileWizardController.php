<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Models\SeriousIntent;
use App\Services\ProfileCompletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase-5B: Section-based profile wizard. MutationService-only save path.
 * Full SSOT coverage: basic-info, personal-family, location, property, horoscope, legal, about-preferences, contacts, photo.
 */
class ProfileWizardController extends Controller
{
    private const SECTIONS = [
        'basic-info',
        'personal-family',
        'siblings',
        'relatives',
        'alliance',
        'location',
        'property',
        'horoscope',
        'legal',
        'about-preferences',
        'contacts',
        'photo',
    ];

    public function index()
    {
        $user = auth()->user();
        $profile = $this->ensureProfile($user);
        if (! $profile) {
            return redirect()->route('login');
        }

        $first = ProfileCompletionService::firstSection();
        $pct = ProfileCompletionService::calculateCompletionPercentage($profile);
        if ($pct >= 100) {
            return redirect()->route('matrimony.profiles.index')->with('info', 'Your profile is complete.');
        }

        return redirect()->route('matrimony.profile.wizard.section', ['section' => $first]);
    }

    /**
     * Show wizard section form.
     */
    public function show(string $section)
    {
        $allowed = array_merge(self::SECTIONS, ['full']);
        if (! in_array($section, $allowed, true)) {
            return redirect()->route('matrimony.profile.wizard')->with('error', 'Invalid section.');
        }

        $user = auth()->user();
        $profile = $this->ensureProfile($user);
        if (! $profile) {
            return redirect()->route('login');
        }

        if (! \App\Services\ProfileLifecycleService::isEditableForManual($profile)) {
            return redirect()->route('matrimony.profile.show', $profile->id)->with('error', 'Profile cannot be edited in its current state.');
        }

        $completionPct = ProfileCompletionService::calculateCompletionPercentage($profile);
        $viewData = $this->getSectionViewData($section, $profile);
        $viewData['profile'] = $profile;
        $viewData['currentSection'] = $section;
        $viewData['sections'] = self::SECTIONS;
        $viewData['completionPct'] = $completionPct;
        $viewData['nextSection'] = ProfileCompletionService::nextSection($section);

        return view('matrimony.profile.wizard.section', $viewData);
    }

    /**
     * Save section via MutationService and redirect to next.
     */
    public function store(Request $request, string $section)
    {
        $allowed = array_merge(self::SECTIONS, ['full']);
        if (! in_array($section, $allowed, true)) {
            return redirect()->route('matrimony.profile.wizard')->with('error', 'Invalid section.');
        }

        $user = auth()->user();
        $profile = $this->ensureProfile($user);
        if (! $profile) {
            return redirect()->route('login');
        }

        if (! \App\Services\ProfileLifecycleService::isEditableForManual($profile)) {
            return redirect()->route('matrimony.profile.show', $profile->id)->with('error', 'Profile cannot be edited in its current state.');
        }

        $snapshot = $this->buildSectionSnapshot($section, $request, $profile);
        if ($snapshot === null) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => $section])
                ->with('error', 'Invalid section or no data.')
                ->withInput();
        }

        try {
            $result = app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');
            \Illuminate\Support\Facades\Log::info('WIZARD RESULT DEBUG', ['result' => $result, 'keys' => array_keys($result)]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => $section])
                ->withErrors($e->errors())
                ->withInput();
        } catch (\RuntimeException $e) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => $section])
                ->withErrors(['lifecycle' => $e->getMessage()])
                ->withInput();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('WIZARD CRITICAL ERROR', [
                'error' => $e->getMessage(),
                'section' => $section,
                'profile_id' => $profile->id,
            ]);
            throw $e;
        }

        if ($result['conflict_detected'] ?? false) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => $section])
                ->with('warning', 'Some changes could not be applied due to conflicts.')
                ->withInput();
        }

        $next = ProfileCompletionService::nextSection($section);
        if ($next) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => $next])
                ->with('success', 'Saved. Continue to next section.');
        }

        return redirect()->route('matrimony.profiles.index')->with('success', 'Profile completed.');
    }

    /**
     * Ensure user has a matrimony profile. Create minimal one if not (full_name from user->name).
     */
    private function ensureProfile($user): ?MatrimonyProfile
    {
        if (! $user) {
            return null;
        }
        $profile = $user->matrimonyProfile;
        if ($profile) {
            return $profile;
        }
        $manualActivation = \App\Services\AdminSettingService::isManualProfileActivationRequired();
        $genderId = null;
        if (! empty($user->gender)) {
            $genderId = \App\Models\MasterGender::where('key', $user->gender)->where('is_active', true)->value('id');
        }
        $profile = MatrimonyProfile::create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
            'full_name' => $user->name ?? 'Draft',
            'gender_id' => $genderId,
            'is_suspended' => $manualActivation,
        ]);

        return $profile;
    }

    private function getSectionViewData(string $section, MatrimonyProfile $profile): array
    {
        $data = [];
        switch ($section) {
            case 'basic-info':
                $data['seriousIntents'] = SeriousIntent::whereNull('deleted_at')->orderBy('name')->get();
                $data['primaryContactPhone'] = DB::table('profile_contacts')->where('profile_id', $profile->id)->where('is_primary', true)->value('phone_number');
                $data['talukasByDistrict'] = \App\Models\Taluka::all()->groupBy('district_id')->map(fn ($col) => $col->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->toArray())->toArray();
                $data['districtsByState'] = \App\Models\District::all()->groupBy('state_id')->map(fn ($col) => $col->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values()->toArray())->toArray();
                $data['stateIdToCountryId'] = \App\Models\State::all()->pluck('country_id', 'id')->toArray();
                $data['genders'] = \App\Models\MasterGender::where('is_active', true)->get();
                $data['maritalStatuses'] = \App\Models\MasterMaritalStatus::where('is_active', true)->get();
                $data['complexions'] = \App\Models\MasterComplexion::where('is_active', true)->get();
                $data['physicalBuilds'] = \App\Models\MasterPhysicalBuild::where('is_active', true)->get();
                $data['bloodGroups'] = \App\Models\MasterBloodGroup::where('is_active', true)->get();
                $data['birthPlaceDisplay'] = $profile->birth_city_id ? \App\Models\City::where('id', $profile->birth_city_id)->value('name') : '';
                break;
            case 'personal-family':
                $data['profileChildren'] = DB::table('profile_children')->where('profile_id', $profile->id)->orderBy('id')->get();
                $data['profileEducation'] = DB::table('profile_education')->where('profile_id', $profile->id)->orderBy('id')->get();
                $data['profileCareer'] = DB::table('profile_career')->where('profile_id', $profile->id)->orderBy('id')->get();
                $data['currencies'] = \App\Models\MasterIncomeCurrency::where('is_active', true)->get();
                $data['familyTypes'] = \App\Models\MasterFamilyType::where('is_active', true)->get();
                break;
            case 'siblings':
                $data['profileSiblings'] = DB::table('profile_siblings')->where('profile_id', $profile->id)->orderBy('id')->get();
                $data['cities'] = \App\Models\City::all();
                break;
            case 'relatives':
                $data['profileRelatives'] = DB::table('profile_relatives')->where('profile_id', $profile->id)->orderBy('id')->get();
                $data['relationTypes'] = ['Uncle', 'Aunt', 'Cousin', 'Brother', 'Sister', 'Father', 'Mother', 'Grandfather', 'Grandmother', 'Other'];
                $data['cities'] = \App\Models\City::all();
                $data['states'] = \App\Models\State::all();
                break;
            case 'alliance':
                $rows = DB::table('profile_alliance_networks')->where('profile_id', $profile->id)->orderBy('id')->get();
                $cityIds = $rows->pluck('city_id')->filter()->unique()->values()->all();
                $cityNames = $cityIds ? \App\Models\City::whereIn('id', $cityIds)->pluck('name', 'id')->toArray() : [];
                $data['profileAllianceNetworks'] = $rows->map(function ($row) use ($cityNames) {
                    $arr = (array) $row;
                    $arr['location_display'] = ! empty($row->city_id) ? ($cityNames[$row->city_id] ?? '') : '';

                    return (object) $arr;
                })->all();
                break;
            case 'location':
                $data['countries'] = \App\Models\Country::all();
                $data['states'] = \App\Models\State::all();
                $data['districts'] = \App\Models\District::all();
                $data['talukas'] = \App\Models\Taluka::all();
                $data['cities'] = \App\Models\City::all();
                $profile->load('addresses.village');
                $data['profileAddresses'] = $profile->addresses;
                $data['workCityName'] = $profile->work_city_id ? \App\Models\City::where('id', $profile->work_city_id)->value('name') : '';
                $data['nativePlaceDisplay'] = $profile->native_city_id ? \App\Models\City::where('id', $profile->native_city_id)->value('name') : '';
                $data['talukasByDistrict'] = \App\Models\Taluka::all()->groupBy('district_id')->map(fn ($col) => $col->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->toArray())->toArray();
                $data['districtsByState'] = \App\Models\District::all()->groupBy('state_id')->map(fn ($col) => $col->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values()->toArray())->toArray();
                $data['stateIdToCountryId'] = \App\Models\State::all()->pluck('country_id', 'id')->toArray();
                break;
            case 'property':
                $data['profile_property_summary'] = DB::table('profile_property_summary')->where('profile_id', $profile->id)->first();
                $data['profile_property_assets'] = DB::table('profile_property_assets')->where('profile_id', $profile->id)->orderBy('id')->get();
                $data['assetTypes'] = \App\Models\MasterAssetType::where('is_active', true)->get();
                $data['ownershipTypes'] = \App\Models\MasterOwnershipType::where('is_active', true)->get();
                $data['profile_property_assets'] = $data['profile_property_assets'] ?? collect();
                $data['assetTypes'] = $data['assetTypes'] ?? collect();
                $data['ownershipTypes'] = $data['ownershipTypes'] ?? collect();
                break;
            case 'horoscope':
                $data['profile_horoscope_data'] = DB::table('profile_horoscope_data')->where('profile_id', $profile->id)->first();
                $data['rashis'] = \App\Models\MasterRashi::where('is_active', true)->get();
                $data['nakshatras'] = \App\Models\MasterNakshatra::where('is_active', true)->get();
                $data['gans'] = \App\Models\MasterGan::where('is_active', true)->get();
                $data['nadis'] = \App\Models\MasterNadi::where('is_active', true)->get();
                $data['yonis'] = \App\Models\MasterYoni::where('is_active', true)->get();
                $data['mangalDoshTypes'] = \App\Models\MasterMangalDoshType::where('is_active', true)->get();
                $data['rashis'] = $data['rashis'] ?? collect();
                $data['nakshatras'] = $data['nakshatras'] ?? collect();
                $data['gans'] = $data['gans'] ?? collect();
                $data['nadis'] = $data['nadis'] ?? collect();
                $data['yonis'] = $data['yonis'] ?? collect();
                $data['mangalDoshTypes'] = $data['mangalDoshTypes'] ?? collect();
                break;
            case 'legal':
                $data['profile_legal_cases'] = DB::table('profile_legal_cases')->where('profile_id', $profile->id)->orderBy('id')->get();
                $data['legalCaseTypes'] = \App\Models\MasterLegalCaseType::where('is_active', true)->get();
                $data['profile_legal_cases'] = $data['profile_legal_cases'] ?? collect();
                $data['legalCaseTypes'] = $data['legalCaseTypes'] ?? collect();
                break;
            case 'contacts':
                $data['profile_contacts'] = DB::table('profile_contacts')->where('profile_id', $profile->id)->orderBy('id')->get();
                $data['contactRelations'] = \App\Models\MasterContactRelation::where('is_active', true)->get();
                $data['profile_contacts'] = $data['profile_contacts'] ?? collect();
                $data['contactRelations'] = $data['contactRelations'] ?? collect();
                break;
            case 'about-preferences':
                $data['preferences'] = DB::table('profile_preferences')->where('profile_id', $profile->id)->first();
                $data['extendedAttrs'] = DB::table('profile_extended_attributes')->where('profile_id', $profile->id)->first();
                break;
            case 'photo':
                break;
            case 'full':
                $data = array_merge(
                    $this->getSectionViewData('basic-info', $profile),
                    $this->getSectionViewData('personal-family', $profile),
                    $this->getSectionViewData('siblings', $profile),
                    $this->getSectionViewData('relatives', $profile),
                    $this->getSectionViewData('alliance', $profile),
                    $this->getSectionViewData('location', $profile),
                    $this->getSectionViewData('property', $profile),
                    $this->getSectionViewData('horoscope', $profile),
                    $this->getSectionViewData('legal', $profile),
                    $this->getSectionViewData('contacts', $profile),
                    $this->getSectionViewData('about-preferences', $profile)
                );
                break;
        }

        return $data;
    }

    /**
     * Build partial snapshot for the given section (MutationService applies only present keys).
     */
    private function buildSectionSnapshot(string $section, Request $request, MatrimonyProfile $profile): ?array
    {
        switch ($section) {
            case 'basic-info':
                return $this->buildBasicInfoSnapshot($request, $profile);
            case 'personal-family':
                return $this->buildPersonalFamilySnapshot($request, $profile);
            case 'siblings':
                return $this->buildSiblingsSnapshot($request, $profile);
            case 'relatives':
                return $this->buildRelativesSnapshot($request, $profile);
            case 'alliance':
                return $this->buildAllianceSnapshot($request, $profile);
            case 'location':
                return $this->buildLocationSnapshot($request, $profile);
            case 'property':
                return $this->buildPropertySnapshot($request, $profile);
            case 'horoscope':
                return $this->buildHoroscopeSnapshot($request, $profile);
            case 'legal':
                return $this->buildLegalSnapshot($request, $profile);
            case 'contacts':
                return $this->buildContactsSnapshot($request, $profile);
            case 'about-preferences':
                return $this->buildAboutPreferencesSnapshot($request, $profile);
            case 'photo':
                return $this->buildPhotoSnapshot($request, $profile);
            case 'full':
                return app(\App\Services\ManualSnapshotBuilderService::class)->buildFullManualSnapshot($request, $profile);
        }

        return null;
    }

    private function buildBasicInfoSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $this->resolveMasterLookupIds($request, ['gender' => 'gender_id', 'marital_status' => 'marital_status_id', 'complexion' => 'complexion_id', 'physical_build' => 'physical_build_id', 'blood_group' => 'blood_group_id']);
        $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'gender_id' => ['required', Rule::exists('master_genders', 'id')],
            'date_of_birth' => ['nullable', 'date'],
            'birth_time' => ['nullable', 'string', 'max:20'],
            'religion_id' => ['nullable', 'exists:religions,id'],
'caste_id' => ['nullable', 'exists:castes,id'],
'sub_caste_id' => ['nullable', 'exists:sub_castes,id'],
            'marital_status_id' => ['required', Rule::exists('master_marital_statuses', 'id')],
            'height_cm' => ['nullable', 'integer', 'min:50', 'max:250'],
            'serious_intent_id' => ['nullable', Rule::exists('serious_intents', 'id')->whereNull('deleted_at')],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'complexion_id' => ['nullable', Rule::exists('master_complexions', 'id')],
            'physical_build_id' => ['nullable', Rule::exists('master_physical_builds', 'id')],
            'blood_group_id' => ['nullable', Rule::exists('master_blood_groups', 'id')],
        ]);

        $core = [
            'full_name' => $request->input('full_name'),
            'gender_id' => $request->input('gender_id') ? (int) $request->input('gender_id') : null,
            'date_of_birth' => $request->input('date_of_birth') ?: null,
            'birth_time' => $request->filled('birth_time') ? trim($request->input('birth_time')) : null,
            'religion_id' => $request->input('religion_id') ? (int) $request->input('religion_id') : null,
            'caste_id' => $request->input('caste_id') ? (int) $request->input('caste_id') : null,
            'sub_caste_id' => $request->input('sub_caste_id') ? (int) $request->input('sub_caste_id') : null,
            'marital_status_id' => $request->input('marital_status_id') ? (int) $request->input('marital_status_id') : null,
            'height_cm' => $request->filled('height_cm') ? (int) $request->input('height_cm') : null,
            'serious_intent_id' => $request->input('serious_intent_id') ?: null,
            'weight_kg' => $request->filled('weight_kg') ? (float) $request->input('weight_kg') : null,
            'complexion_id' => $request->input('complexion_id') ? (int) $request->input('complexion_id') : null,
            'physical_build_id' => $request->input('physical_build_id') ? (int) $request->input('physical_build_id') : null,
            'blood_group_id' => $request->input('blood_group_id') ? (int) $request->input('blood_group_id') : null,
        ];
        $core = array_map(fn ($v) => $v === '' ? null : $v, $core);

        $contacts = [];
        $phone = trim((string) $request->input('primary_contact_number', ''));
        if ($phone !== '') {
            $contacts[] = ['relation_type' => 'self', 'contact_name' => 'Primary', 'phone_number' => $phone, 'is_primary' => true];
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

        return [
            'core' => $core,
            'contacts' => $contacts,
            'birth_place' => $birth_place,
            'children' => [],
            'education_history' => [],
            'career_history' => [],
            'addresses' => [],
            'property_summary' => [],
            'property_assets' => [],
            'horoscope' => [],
            'legal_cases' => [],
            'preferences' => [],
            'extended_narrative' => [],
        ];
    }

    private function buildPersonalFamilySnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $this->resolveMasterLookupIds($request, [
            'income_currency' => 'income_currency_id',
            'family_type' => 'family_type_id',
        ]);
        $core = [
            'highest_education' => $request->input('highest_education') ?: null,
            'specialization' => $request->input('specialization') ?: null,
            'occupation_title' => $request->input('occupation_title') ?: null,
            'company_name' => $request->input('company_name') ?: null,
            'annual_income' => $request->filled('annual_income') ? (float) $request->input('annual_income') : null,
            'family_income' => $request->filled('family_income') ? (float) $request->input('family_income') : null,
            'income_currency_id' => $request->input('income_currency_id') ? (int) $request->input('income_currency_id') : (\App\Models\MasterIncomeCurrency::where('code', 'INR')->value('id')),
            'father_name' => $request->input('father_name') ?: null,
            'father_occupation' => $request->input('father_occupation') ?: null,
            'mother_name' => $request->input('mother_name') ?: null,
            'mother_occupation' => $request->input('mother_occupation') ?: null,
            'brothers_count' => $request->filled('brothers_count') ? (int) $request->input('brothers_count') : null,
            'sisters_count' => $request->filled('sisters_count') ? (int) $request->input('sisters_count') : null,
            'family_type_id' => $request->input('family_type_id') ? (int) $request->input('family_type_id') : null,
        ];
        if ($request->filled('highest_education')) {
            $core['highest_education'] = $request->input('highest_education');
        }
        $core = array_map(fn ($v) => $v === '' ? null : $v, $core);

        $children = [];
        foreach ($request->input('children', []) as $row) {
            $children[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'child_name' => trim((string) ($row['child_name'] ?? '')),
                'gender' => trim((string) ($row['child_gender'] ?? '')),
                'age' => ! empty($row['child_age']) ? (int) $row['child_age'] : 0,
                'lives_with_parent' => ($row['lives_with_parent'] ?? '') === '1',
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
                'start_year' => ! empty($row['start_year']) ? (int) $row['start_year'] : null,
                'end_year' => ! empty($row['end_year']) ? (int) $row['end_year'] : null,
            ];
        }

        $snapshot = ['core' => $core];
        if ($request->has('children')) {
            $snapshot['children'] = $children;
        }
        if ($request->has('education_history')) {
            $snapshot['education_history'] = $education_history;
        }
        if ($request->has('career_history')) {
            $snapshot['career_history'] = $career_history;
        }

        return $snapshot;
    }

    private function buildSiblingsSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $siblings = [];
        foreach ($request->input('siblings', []) as $row) {
            $siblings[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'gender' => in_array($row['gender'] ?? null, ['male', 'female'], true) ? $row['gender'] : null,
                'marital_status' => in_array($row['marital_status'] ?? null, ['unmarried', 'married'], true) ? $row['marital_status'] : null,
                'occupation' => trim((string) ($row['occupation'] ?? '')) ?: null,
                'city_id' => ! empty($row['city_id']) ? (int) $row['city_id'] : null,
                'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
            ];
        }

        return [
            'core' => [],
            'siblings' => $siblings,
        ];
    }

    private function buildRelativesSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $relatives = [];
        foreach ($request->input('relatives', []) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $relationType = trim((string) ($row['relation_type'] ?? ''));
            if ($name === '' && $relationType === '') {
                continue;
            }
            $relatives[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'relation_type' => $relationType ?: '',
                'name' => $name ?: '',
                'occupation' => trim((string) ($row['occupation'] ?? '')) ?: null,
                'city_id' => ! empty($row['city_id']) ? (int) $row['city_id'] : null,
                'state_id' => ! empty($row['state_id']) ? (int) $row['state_id'] : null,
                'contact_number' => trim((string) ($row['contact_number'] ?? '')) ?: null,
                'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
                'is_primary_contact' => ! empty($row['is_primary_contact']),
            ];
        }

        return [
            'core' => [],
            'relatives' => $relatives,
        ];
    }

    private function buildAllianceSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $alliance = [];
        foreach ($request->input('alliance_networks', []) as $row) {
            $surname = trim((string) ($row['surname'] ?? ''));
            if ($surname === '') {
                continue;
            }
            $alliance[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'surname' => $surname,
                'city_id' => ! empty($row['city_id']) ? (int) $row['city_id'] : null,
                'taluka_id' => ! empty($row['taluka_id']) ? (int) $row['taluka_id'] : null,
                'district_id' => ! empty($row['district_id']) ? (int) $row['district_id'] : null,
                'state_id' => ! empty($row['state_id']) ? (int) $row['state_id'] : null,
                'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
            ];
        }

        return [
            'core' => [],
            'alliance_networks' => $alliance,
        ];
    }

    private function buildLocationSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $request->validate([
            'country_id' => ['nullable', 'exists:countries,id'],
            'state_id' => ['nullable', 'exists:states,id'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'taluka_id' => ['nullable', 'exists:talukas,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'address_line' => ['nullable', 'string', 'max:255'],
        ]);

        $core = [
            'country_id' => $request->input('country_id') ?: null,
            'state_id' => $request->input('state_id') ?: null,
            'district_id' => $request->input('district_id') ?: null,
            'taluka_id' => $request->input('taluka_id') ?: null,
            'city_id' => $request->input('city_id') ?: null,
            'address_line' => $request->filled('address_line') ? trim($request->input('address_line')) : null,
            'work_city_id' => $request->input('work_city_id') ?: null,
            'work_state_id' => $request->input('work_state_id') ?: null,
        ];
        $core = array_map(fn ($v) => $v === '' ? null : $v, $core);

        $addresses = [];
        foreach ($request->input('addresses', []) as $row) {
            $addresses[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'address_type' => trim((string) ($row['address_type'] ?? 'current')),
                'village_id' => ! empty($row['village_id']) ? (int) $row['village_id'] : null,
                'taluka' => trim((string) ($row['taluka'] ?? '')),
                'district' => trim((string) ($row['district'] ?? '')),
                'state' => trim((string) ($row['state'] ?? '')),
                'country' => trim((string) ($row['country'] ?? '')),
                'pin_code' => trim((string) ($row['pin_code'] ?? '')),
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

        return [
            'core' => $core,
            'addresses' => $addresses,
            'native_place' => $native_place,
        ];
    }

    private function buildAboutPreferencesSnapshot(Request $request, MatrimonyProfile $profile): array
    {
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

        $snapshot = [];
        if ($preferences !== []) {
            $snapshot['preferences'] = $preferences;
        }
        if ($extended_narrative !== []) {
            $snapshot['extended_narrative'] = $extended_narrative;
        }
        if ($snapshot === []) {
            $snapshot = ['core' => []];
        }

        return $snapshot;
    }

    private function buildPhotoSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $request->validate([
            'profile_photo' => ['required', 'image', 'max:2048'],
        ]);

        $file = $request->file('profile_photo');
        $filename = time() . '_' . basename($file->getClientOriginalName());
        $file->move(public_path('uploads/matrimony_photos'), $filename);

        $photoApprovalRequired = \App\Services\AdminSettingService::isPhotoApprovalRequired();
        $photoApproved = ! $photoApprovalRequired;

        return [
            'core' => [
                'profile_photo' => $filename,
                'photo_approved' => $photoApproved,
                'photo_rejected_at' => null,
                'photo_rejection_reason' => null,
            ],
        ];
    }

    private function buildPropertySnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $property_summary = [];
        if ($request->has('property_summary')) {
            $request->validate([
                'property_summary.agriculture_type' => ['nullable', 'string', 'max:50'],
            ]);
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
            ];
        }

        return [
            'core' => [],
            'property_summary' => $property_summary,
            'property_assets' => $property_assets,
        ];
    }

    private function buildHoroscopeSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $horoscope = [];
        if ($request->has('horoscope')) {
            $h = $request->input('horoscope');
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
            ]];
        }

        return [
            'core' => [],
            'horoscope' => $horoscope,
        ];
    }

    private function buildLegalSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $legal_cases = [];
        foreach ($request->input('legal_cases', []) as $row) {
            $legal_cases[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'legal_case_type_id' => ! empty($row['legal_case_type_id']) ? (int) $row['legal_case_type_id'] : null,
                'court_name' => trim((string) ($row['court_name'] ?? '')),
                'case_number' => trim((string) ($row['case_number'] ?? '')),
                'case_stage' => trim((string) ($row['case_stage'] ?? '')),
                'next_hearing_date' => ! empty($row['next_hearing_date']) ? $row['next_hearing_date'] : null,
                'active_status' => ! empty($row['active_status']),
                'notes' => trim((string) ($row['notes'] ?? '')),
            ];
        }

        return [
            'core' => [],
            'legal_cases' => $legal_cases,
        ];
    }

    private function buildContactsSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $contacts = [];
        $phone = trim((string) $request->input('primary_contact_number', ''));
        if ($phone !== '') {
            $contacts[] = ['relation_type' => 'self', 'contact_name' => 'Primary', 'phone_number' => $phone, 'is_primary' => true];
        }
        foreach ($request->input('contacts', []) as $row) {
            $contactName = trim((string) ($row['contact_name'] ?? ''));
            $phoneNumber = trim((string) ($row['phone_number'] ?? ''));
            if ($contactName !== '' || $phoneNumber !== '') {
                $contacts[] = [
                    'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                    'relation_type' => trim((string) ($row['relation_type'] ?? '')),
                    'contact_name' => $contactName,
                    'phone_number' => $phoneNumber,
                    'is_primary' => ! empty($row['is_primary']),
                ];
            }
        }

        return [
            'core' => [],
            'contacts' => $contacts,
        ];
    }

    /** Phase-5: Resolve string lookup inputs to *_id when form sends key/code instead of id. */
    private function resolveMasterLookupIds(Request $request, array $map): void
    {
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
}
