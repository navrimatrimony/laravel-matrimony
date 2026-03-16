<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Services\FieldCatalogService;
use App\Services\ProfileCompletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase-5B: Section-based profile wizard. MutationService-only save path.
 * Full SSOT coverage: basic-info, personal-family, location, property, horoscope, about-preferences, contacts, photo.
 */
class ProfileWizardController extends Controller
{
    /** @deprecated Use FieldCatalogService::getSectionKeys() for canonical list. Kept for allowed list fallback. */
    private const SECTIONS = [
        'basic-info',
        'physical',
        'marriages',
        'education-career',
        'family-details',
        'siblings',
        'relatives',
        'alliance',
        'location',
        'property',
        'horoscope',
        'about-me',
        'about-preferences',
        'contacts',
        'photo',
    ];

    private function isMinimalWizard(): bool
    {
        return (bool) session('wizard_minimal', false);
    }

    private function getAllowedSectionKeys(): array
    {
        $minimal = $this->isMinimalWizard();
        $keys = $minimal ? FieldCatalogService::getSectionKeys(true) : FieldCatalogService::getSectionKeys(false);

        return array_merge($keys, ['full']);
    }

    public function index()
    {
        $user = auth()->user();
        $profile = $this->ensureProfile($user);
        if (! $profile) {
            return redirect()->route('login');
        }

        // Feature removal: Marriages + Location tabs removed. Purge their stored data once per session.
        if (! session()->has('purged_marriages_location')) {
            $this->purgeMarriagesAndLocationData($profile);
            session(['purged_marriages_location' => true]);
        }

        $minimal = $this->isMinimalWizard();
        $first = $minimal ? FieldCatalogService::getFirstSection(true) : FieldCatalogService::getFirstSection(false);
        $pct = ProfileCompletionService::calculateCompletionPercentage($profile);
        if ($pct >= 100) {
            session()->forget('wizard_minimal');
            return redirect()->route('matrimony.profiles.index')->with('info', __('wizard.profile_complete'));
        }

        return redirect()->route('matrimony.profile.wizard.section', ['section' => $first]);
    }

    /**
     * Show wizard section form.
     */
    public function show(string $section)
    {
        // Legacy: personal-family was split into education-career + family-details; redirect old links
        if ($section === 'personal-family') {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'education-career'], 301);
        }
        // Legacy: marriages/location tabs removed; purge and redirect
        if ($section === 'marriages' || $section === 'location') {
            $profile = $this->ensureProfile(auth()->user());
            if ($profile) {
                $this->purgeMarriagesAndLocationData($profile);
            }
            return redirect()->route('matrimony.profile.wizard.section', ['section' => FieldCatalogService::getFirstSection($this->isMinimalWizard() ? true : false)], 301)
                ->with('info', __('wizard.marriages_location_removed'));
        }

        $allowed = $this->getAllowedSectionKeys();
        if (! in_array($section, $allowed, true)) {
            $minimal = $this->isMinimalWizard();
            $first = $minimal ? FieldCatalogService::getFirstSection(true) : FieldCatalogService::getFirstSection(false);
            return redirect()->route('matrimony.profile.wizard.section', ['section' => $first])
                ->with('error', $minimal ? __('wizard.complete_short_onboarding_first') : __('wizard.invalid_section'));
        }

        $user = auth()->user();
        $profile = $this->ensureProfile($user);
        if (! $profile) {
            return redirect()->route('login');
        }

        if (! \App\Services\ProfileLifecycleService::isEditableForManual($profile)) {
            return redirect()->route('matrimony.profile.show', $profile->id)->with('error', __('wizard.profile_not_editable_current_state'));
        }

        $minimal = $this->isMinimalWizard();
        if ($section === 'full') {
            session()->forget('wizard_minimal');
            $minimal = false;
        }
        $sections = $minimal ? FieldCatalogService::getSectionKeys(true) : FieldCatalogService::getSectionKeys(false);
        $nextSection = $minimal ? FieldCatalogService::getNextSection($section, true) : FieldCatalogService::getNextSection($section, false);
        if ($nextSection === null && $minimal) {
            $nextSection = 'full';
        }
        $previousSection = $minimal ? FieldCatalogService::getPreviousSection($section, true) : FieldCatalogService::getPreviousSection($section, false);

        $completionPct = ProfileCompletionService::calculateCompletionPercentage($profile);
        $sectionStatuses = ProfileCompletionService::getSectionStatuses($profile, $sections);
        $viewData = $this->getSectionViewData($section, $profile);
        $viewData['profile'] = $profile;
        $viewData['currentSection'] = $section;
        $viewData['sections'] = $sections;
        $viewData['sectionLabels'] = FieldCatalogService::getSectionsForDisplay($minimal);
        $viewData['completionPct'] = $completionPct;
        $viewData['nextSection'] = $nextSection;
        $viewData['previousSection'] = $previousSection;
        $viewData['sectionStatuses'] = $sectionStatuses;
        $viewData['wizardMinimal'] = $minimal;

        return view('matrimony.profile.wizard.section', $viewData);
    }

    /**
     * Legacy: Return marriage-fields partial HTML for given status (old dropdown partials).
     * GET ?status=divorced|widowed|separated|married. The MaritalEngine does not use this; it is the single UI for marital+children everywhere (wizard marriages + full).
     */
    public function marriageFields(Request $request)
    {
        $profile = $this->ensureProfile(auth()->user());
        if (! $profile) {
            return response('', 403);
        }

        $allowed = ['divorced', 'widowed', 'separated', 'married'];
        $status = $request->query('status');
        if (! in_array($status, $allowed, true)) {
            return response('', 400);
        }

        $marriage = \App\Models\ProfileMarriage::where('profile_id', $profile->id)->orderBy('id')->first();
        $view = 'matrimony.profile.wizard.sections.marriage_partials.marriages_' . $status;

        return response()->view($view, ['marriage' => $marriage], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * Save section via MutationService and redirect to next.
     */
    public function store(Request $request, string $section)
    {
        \Log::info('DEBUG SECTION PARAM', ['section' => $section]);

        // Legacy: redirect POST for personal-family to education-career (section no longer in nav)
        if ($section === 'personal-family') {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'education-career'])
                ->with('info', 'This section is now split into Education & Career and Family details.');
        }
        // Legacy: marriages/location tabs removed; purge and redirect
        if ($section === 'marriages' || $section === 'location') {
            $profile = $this->ensureProfile(auth()->user());
            if ($profile) {
                $this->purgeMarriagesAndLocationData($profile);
            }
            return redirect()->route('matrimony.profile.wizard.section', ['section' => FieldCatalogService::getFirstSection($this->isMinimalWizard() ? true : false)])
                ->with('success', 'Removed marriages and location data.');
        }

        $allowed = $this->getAllowedSectionKeys();
        if (! in_array($section, $allowed, true)) {
            $minimal = $this->isMinimalWizard();
            $first = $minimal ? FieldCatalogService::getFirstSection(true) : FieldCatalogService::getFirstSection(false);
            return redirect()->route('matrimony.profile.wizard.section', ['section' => $first])
                ->with('error', __('wizard.invalid_section'));
        }

        $user = auth()->user();
        $profile = $this->ensureProfile($user);
        if (! $profile) {
            return redirect()->route('login');
        }

        if (! \App\Services\ProfileLifecycleService::isEditableForManual($profile)) {
            return redirect()->route('matrimony.profile.show', $profile->id)->with('error', __('wizard.profile_not_editable_current_state'));
        }

        if ($section === 'location') {
            $minimal = $this->isMinimalWizard();
            $next = $minimal ? FieldCatalogService::getNextSection($section, true) : FieldCatalogService::getNextSection($section, false);
            if ($next) {
                return redirect()->route('matrimony.profile.wizard.section', ['section' => $next])
                    ->with('success', __('wizard.saved_continue_next'));
            }
            return redirect()->route('matrimony.profiles.index')->with('success', 'Profile updated.');
        }

        // Photo section: no direct upload in wizard; user uses centralized upload engine. Save & Next without file = skip to next.
        if ($section === 'photo' && ! $request->hasFile('profile_photo')) {
            $minimal = $this->isMinimalWizard();
            $next = $minimal ? FieldCatalogService::getNextSection($section, true) : FieldCatalogService::getNextSection($section, false);
            if ($next) {
                return redirect()->route('matrimony.profile.wizard.section', ['section' => $next])
                    ->with('info', 'Use the photo upload engine above to add or change your photo.');
            }
            return redirect()->route('matrimony.profiles.index')->with('info', 'You can add a photo anytime from the photo section.');
        }

        $snapshot = $this->buildSectionSnapshot($section, $request, $profile);
        \Log::info('DEBUG SNAPSHOT', $snapshot ?? []);
        \Log::info('DEBUG SNAPSHOT FULL', $snapshot ?? []);

        if ($snapshot === null) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => $section])
                ->with('error', 'Invalid section or no data.')
                ->withInput();
        }

        // Alliance free-text "other_relatives_text" is now governed via snapshot CORE.
        if ($section === 'alliance' && \Schema::hasColumn('matrimony_profiles', 'other_relatives_text')) {
            $snapshot['core']['other_relatives_text'] = trim((string) $request->input('other_relatives_text', '')) ?: null;
        }

        try {
            $result = app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');
            \Illuminate\Support\Facades\Log::info('WIZARD RESULT DEBUG', ['result' => $result, 'keys' => array_keys($result)]);
            $hasChildrenNo = isset($snapshot['core']['has_children']) && ($snapshot['core']['has_children'] === false || $snapshot['core']['has_children'] === 0 || $snapshot['core']['has_children'] === '0');
            if (($section === 'marriages' || $section === 'full') && $hasChildrenNo) {
                DB::table('profile_children')->where('profile_id', $profile->id)->delete();
            }
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

        if ($request->boolean('save_only')) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => $section])
                ->with('success', __('wizard.saved'));
        }

        $minimal = $this->isMinimalWizard();
        $next = $minimal ? FieldCatalogService::getNextSection($section, true) : FieldCatalogService::getNextSection($section, false);
        if ($next) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => $next])
                ->with('success', __('wizard.saved_continue_next'));
        }
        if ($minimal) {
            session()->forget('wizard_minimal');
            return redirect()->route('matrimony.profiles.index')
                ->with('success', 'Profile saved. You can complete the rest of your profile anytime from your profile page.');
        }

        return redirect()->route('matrimony.profiles.index')->with('success', __('wizard.profile_completed'));
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

    /**
     * Purge Marriages + Location data for this profile (feature removed).
     * No schema changes; only clears existing rows/columns for the current profile.
     */
    private function purgeMarriagesAndLocationData(MatrimonyProfile $profile): void
    {
        // Marriages + children (entity tables)
        if (\Schema::hasTable('profile_marriages')) {
            DB::table('profile_marriages')->where('profile_id', $profile->id)->delete();
        }
        if (\Schema::hasTable('profile_children')) {
            DB::table('profile_children')->where('profile_id', $profile->id)->delete();
        }

        // Core marital fields
        $coreUpdates = [];
        if (\Schema::hasColumn('matrimony_profiles', 'marital_status_id')) {
            $coreUpdates['marital_status_id'] = null;
        }
        if (\Schema::hasColumn('matrimony_profiles', 'has_children')) {
            $coreUpdates['has_children'] = null;
        }

        // Location hierarchy + address_line + work/native place
        foreach (['country_id', 'state_id', 'district_id', 'taluka_id', 'city_id', 'address_line', 'work_city_id', 'work_state_id', 'native_city_id', 'native_taluka_id', 'native_district_id', 'native_state_id'] as $col) {
            if (\Schema::hasColumn('matrimony_profiles', $col)) {
                $coreUpdates[$col] = null;
            }
        }
        if ($coreUpdates !== []) {
            DB::table('matrimony_profiles')->where('id', $profile->id)->update($coreUpdates);
        }

        // Normalized address rows
        if (\Schema::hasTable('profile_addresses')) {
            DB::table('profile_addresses')->where('profile_id', $profile->id)->delete();
        }
    }

    private function getSectionViewData(string $section, MatrimonyProfile $profile): array
    {
        $data = [];
        switch ($section) {
            case 'basic-info':
                // Basic Information Engine: full_name, gender_id, date_of_birth, birth_time, birth_place, religion_id, caste_id, sub_caste_id, marital_status_id
                $data['talukasByDistrict'] = \App\Models\Taluka::all()->groupBy('district_id')->map(fn ($col) => $col->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->toArray())->toArray();
                $data['districtsByState'] = \App\Models\District::all()->groupBy('state_id')->map(fn ($col) => $col->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values()->toArray())->toArray();
                $data['stateIdToCountryId'] = \App\Models\State::all()->pluck('country_id', 'id')->toArray();
                $data['genders'] = \App\Models\MasterGender::where('is_active', true)->whereIn('key', ['male', 'female'])->orderByRaw("CASE WHEN `key` = 'male' THEN 1 ELSE 2 END")->get();
                $data['birthPlaceDisplay'] = $profile->birth_city_id ? \App\Models\City::where('id', $profile->birth_city_id)->value('name') : '';
                $data['religions'] = \App\Models\Religion::where('is_active', true)->orderBy('label')->get(['id', 'label']);
                $data['motherTongues'] = \App\Models\MasterMotherTongue::where('is_active', true)->orderBy('sort_order')->orderBy('label')->get(['id', 'key', 'label']);
                $maritalKeys = ['never_married', 'divorced', 'annulled', 'separated', 'widowed'];
                $data['maritalStatuses'] = \App\Models\MasterMaritalStatus::where('is_active', true)
                    ->whereIn('key', $maritalKeys)
                    ->get()
                    ->sortBy(fn ($s) => array_search($s->key, $maritalKeys, true) !== false ? array_search($s->key, $maritalKeys, true) : 999)
                    ->values();
                if ($data['maritalStatuses']->isEmpty()) {
                    $data['maritalStatuses'] = \App\Models\MasterMaritalStatus::where('is_active', true)->get();
                }
                $data['profileMarriages'] = \App\Models\ProfileMarriage::where('profile_id', $profile->id)->orderBy('id')->get();
                $data['profileChildren'] = \Illuminate\Support\Facades\DB::table('profile_children')
                    ->where('profile_id', $profile->id)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get();
                $livingKeys = ['with_parent', 'with_other_parent', 'joint', 'other'];
                $data['childLivingWithOptions'] = \App\Models\MasterChildLivingWith::where('is_active', true)
                    ->whereIn('key', $livingKeys)
                    ->get()
                    ->sortBy(fn ($o) => array_search($o->key, $livingKeys, true) !== false ? array_search($o->key, $livingKeys, true) : 999)
                    ->values();
                if (($data['childLivingWithOptions'] ?? collect())->isEmpty()) {
                    $data['childLivingWithOptions'] = \App\Models\MasterChildLivingWith::where('is_active', true)->get();
                }
                break;
            case 'physical':
                $data['complexions'] = \App\Models\MasterComplexion::where('is_active', true)->orderBy('id')->get();
                $data['bloodGroups'] = \App\Models\MasterBloodGroup::where('is_active', true)->orderBy('id')->get();
                $data['physicalBuilds'] = \App\Models\MasterPhysicalBuild::where('is_active', true)->orderBy('id')->get();
                $data['diets'] = \App\Models\MasterDiet::where('is_active', true)->orderBy('sort_order')->get();
                $data['smokingStatuses'] = \App\Models\MasterSmokingStatus::where('is_active', true)->orderBy('sort_order')->get();
                $data['drinkingStatuses'] = \App\Models\MasterDrinkingStatus::where('is_active', true)->orderBy('sort_order')->get();
                break;
            case 'marriages':
                $data['profileMarriages'] = \App\Models\ProfileMarriage::where('profile_id', $profile->id)->orderBy('id')->get();
                $maritalKeys = ['never_married', 'divorced', 'annulled', 'separated', 'widowed'];
                $data['maritalStatuses'] = \App\Models\MasterMaritalStatus::where('is_active', true)
                    ->whereIn('key', $maritalKeys)
                    ->get()
                    ->sortBy(fn ($s) => array_search($s->key, $maritalKeys, true) !== false ? array_search($s->key, $maritalKeys, true) : 999)
                    ->values();
                if ($data['maritalStatuses']->isEmpty()) {
                    $data['maritalStatuses'] = \App\Models\MasterMaritalStatus::where('is_active', true)->get();
                }
                $data['profileChildren'] = \Illuminate\Support\Facades\DB::table('profile_children')
                    ->where('profile_id', $profile->id)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get();
                $livingKeys = ['with_parent', 'with_other_parent', 'joint', 'other'];
                $data['childLivingWithOptions'] = \App\Models\MasterChildLivingWith::where('is_active', true)
                    ->whereIn('key', $livingKeys)
                    ->get()
                    ->sortBy(fn ($o) => array_search($o->key, $livingKeys, true) !== false ? array_search($o->key, $livingKeys, true) : 999)
                    ->values();
                if ($data['childLivingWithOptions']->isEmpty()) {
                    $data['childLivingWithOptions'] = \App\Models\MasterChildLivingWith::where('is_active', true)->get();
                }
                break;
            case 'education-career':
                $data['profileEducation'] = DB::table('profile_education')->where('profile_id', $profile->id)->orderBy('id')->get();
                $data['profileCareer'] = DB::table('profile_career')->where('profile_id', $profile->id)->orderBy('id')->get();
                $data['currencies'] = \App\Models\MasterIncomeCurrency::where('is_active', true)->get();
                break;
            case 'family-details':
                $data['currencies'] = \App\Models\MasterIncomeCurrency::where('is_active', true)->get();
                $data['familyTypes'] = \App\Models\MasterFamilyType::where('is_active', true)->get();
                $data['physicalBuilds'] = \App\Models\MasterPhysicalBuild::where('is_active', true)->get();
                break;
            case 'personal-family':
                $data['profileChildren'] = DB::table('profile_children')->where('profile_id', $profile->id)->orderBy('id')->get();
                $data['profileEducation'] = DB::table('profile_education')->where('profile_id', $profile->id)->orderBy('id')->get();
                $data['profileCareer'] = DB::table('profile_career')->where('profile_id', $profile->id)->orderBy('id')->get();
                $data['currencies'] = \App\Models\MasterIncomeCurrency::where('is_active', true)->get();
                $data['familyTypes'] = \App\Models\MasterFamilyType::where('is_active', true)->get();
                $data['physicalBuilds'] = \App\Models\MasterPhysicalBuild::where('is_active', true)->get();
                break;
            case 'siblings':
                $siblings = \App\Models\ProfileSibling::where('profile_id', $profile->id)
                    ->with(['spouse', 'city'])
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get();
                $data['profileSiblings'] = $siblings->map(function ($s) {
                    $relationType = $s->relation_type ?? ($s->gender === 'male' ? 'brother' : ($s->gender === 'female' ? 'sister' : null));
                    $spouse = $s->spouse ? (object) array_merge($s->spouse->toArray(), ['location_display' => $s->spouse->city?->name ?? '']) : null;
                    return (object) array_merge($s->toArray(), [
                        'relation_type' => $relationType,
                        'location_display' => $s->city?->name ?? '',
                        'spouse' => $spouse,
                    ]);
                });
                // For "Siblings?" Yes/No: when No, sibling form is hidden
                $data['hasSiblings'] = $profile->has_siblings;
                break;
            case 'relatives':
                $relRows = DB::table('profile_relatives')->where('profile_id', $profile->id)->orderBy('id')->get();
                $cityIds = $relRows->pluck('city_id')->filter()->unique()->values()->all();
                $cityNames = $cityIds ? \App\Models\City::whereIn('id', $cityIds)->pluck('name', 'id')->toArray() : [];
                $mapRow = function ($row) use ($cityNames) {
                    $arr = (array) $row;
                    $arr['location_display'] = ! empty($row->city_id) ? ($cityNames[$row->city_id] ?? '') : '';
                    return (object) $arr;
                };
                $parentsFamilyTypes = ['native_place', 'paternal_grandfather', 'paternal_grandmother', 'paternal_uncle', 'wife_paternal_uncle', 'paternal_aunt', 'husband_paternal_aunt', 'Cousin'];
                $maternalFamilyTypes = ['maternal_address_ajol', 'maternal_grandfather', 'maternal_grandmother', 'maternal_uncle', 'wife_maternal_uncle', 'maternal_aunt', 'husband_maternal_aunt', 'maternal_cousin'];
                $parents = $relRows
                    ->filter(fn ($r) => in_array($r->relation_type ?? '', $parentsFamilyTypes, true))
                    ->map($mapRow);
                // Avoid showing visually duplicated rows (same relation, name, location, contact, notes).
                $data['profileRelativesParentsFamily'] = $parents
                    ->unique(function ($row) {
                        return implode('|', [
                            $row->relation_type ?? '',
                            $row->name ?? '',
                            $row->city_id ?? '',
                            $row->state_id ?? '',
                            $row->contact_number ?? '',
                            $row->notes ?? '',
                        ]);
                    })
                    ->values();
                $data['profileRelativesMaternalFamily'] = $relRows->filter(fn ($r) => in_array($r->relation_type ?? '', $maternalFamilyTypes, true))->map($mapRow)->values();
                $data['relationTypesParentsFamily'] = [
                    ['value' => 'native_place', 'label' => 'Native Place'],
                    ['value' => 'paternal_grandfather', 'label' => 'Paternal Grandfather'],
                    ['value' => 'paternal_grandmother', 'label' => 'Paternal Grandmother'],
                    ['value' => 'paternal_uncle', 'label' => 'Paternal Uncle (chulte)'],
                    ['value' => 'wife_paternal_uncle', 'label' => 'Wife of Paternal Uncle (chulti)'],
                    ['value' => 'paternal_aunt', 'label' => 'Paternal Aunt (atya)'],
                    ['value' => 'husband_paternal_aunt', 'label' => 'Husband of Paternal Aunt'],
                    ['value' => 'Cousin', 'label' => 'Cousin'],
                ];
                $data['relationTypesMaternalFamily'] = [
                    ['value' => 'maternal_address_ajol', 'label' => 'Maternal address (Ajol)'],
                    ['value' => 'maternal_grandfather', 'label' => 'Maternal Grandfather'],
                    ['value' => 'maternal_grandmother', 'label' => 'Maternal Grandmother'],
                    ['value' => 'maternal_uncle', 'label' => 'Maternal Uncle (mama)'],
                    ['value' => 'wife_maternal_uncle', 'label' => 'Maternal Uncle\'s wife (mami)'],
                    ['value' => 'maternal_aunt', 'label' => 'Maternal Aunt (mavshi)'],
                    ['value' => 'husband_maternal_aunt', 'label' => 'Husband of Maternal Aunt'],
                    ['value' => 'maternal_cousin', 'label' => 'Cousin'],
                ];
                break;
            case 'alliance':
                $data['otherRelativesText'] = $profile->getAttribute('other_relatives_text') ?? '';
                $relRows = DB::table('profile_relatives')->where('profile_id', $profile->id)->orderBy('id')->get();
                $cityIds = $relRows->pluck('city_id')->filter()->unique()->values()->all();
                $cityNames = $cityIds ? \App\Models\City::whereIn('id', $cityIds)->pluck('name', 'id')->toArray() : [];
                $mapRow = function ($row) use ($cityNames) {
                    $arr = (array) $row;
                    $arr['location_display'] = ! empty($row->city_id) ? ($cityNames[$row->city_id] ?? '') : '';

                    return (object) $arr;
                };
                $maternalFamilyTypes = ['maternal_address_ajol', 'maternal_grandfather', 'maternal_grandmother', 'maternal_uncle', 'wife_maternal_uncle', 'maternal_aunt', 'husband_maternal_aunt', 'maternal_cousin'];
                $data['profileRelativesMaternalFamily'] = $relRows->filter(fn ($r) => in_array($r->relation_type ?? '', $maternalFamilyTypes, true))->map($mapRow)->values();
                $data['relationTypesMaternalFamily'] = [
                    ['value' => 'maternal_address_ajol', 'label' => 'Maternal address (Ajol)'],
                    ['value' => 'maternal_grandfather', 'label' => 'Maternal Grandfather'],
                    ['value' => 'maternal_grandmother', 'label' => 'Maternal Grandmother'],
                    ['value' => 'maternal_uncle', 'label' => 'Maternal Uncle (mama)'],
                    ['value' => 'wife_maternal_uncle', 'label' => 'Maternal Uncle\'s wife (mami)'],
                    ['value' => 'maternal_aunt', 'label' => 'Maternal Aunt (mavshi)'],
                    ['value' => 'husband_maternal_aunt', 'label' => 'Husband of Maternal Aunt'],
                    ['value' => 'maternal_cousin', 'label' => 'Cousin'],
                ];
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
                $data['profile'] = $profile;
                break;
            case 'horoscope':
                $data['profile_horoscope_data'] = DB::table('profile_horoscope_data')->where('profile_id', $profile->id)->first();
                $data['rashis'] = \App\Models\MasterRashi::where('is_active', true)->get();
                $data['nakshatras'] = \App\Models\MasterNakshatra::where('is_active', true)->get();
                $data['gans'] = \App\Models\MasterGan::where('is_active', true)->get();
                $data['nadis'] = \App\Models\MasterNadi::where('is_active', true)->get();
                $data['yonis'] = \App\Models\MasterYoni::where('is_active', true)->get();
                $data['mangalDoshTypes'] = \App\Models\MasterMangalDoshType::where('is_active', true)->get();
                $data['mangalStatuses'] = \App\Models\MasterMangalStatus::where('is_active', true)->orderBy('sort_order')->get();
                $data['varnas'] = DB::table('master_varnas')->where('is_active', true)->orderBy('label')->get();
                $data['vashyas'] = DB::table('master_vashyas')->where('is_active', true)->orderBy('label')->get();
                $data['rashiLords'] = DB::table('master_rashi_lords')->where('is_active', true)->orderBy('label')->get();
                $data['rashis'] = $data['rashis'] ?? collect();
                $data['nakshatras'] = $data['nakshatras'] ?? collect();
                $data['gans'] = $data['gans'] ?? collect();
                $data['nadis'] = $data['nadis'] ?? collect();
                $data['yonis'] = $data['yonis'] ?? collect();
                $data['mangalDoshTypes'] = $data['mangalDoshTypes'] ?? collect();
                $hRow = $data['profile_horoscope_data'] ? (array) $data['profile_horoscope_data'] : [];
                $horoscopeRuleService = app(\App\Services\HoroscopeRuleService::class);
                $data['dependencyWarnings'] = $horoscopeRuleService->getValidationWarningsForUI($hRow)['warnings'];
                $data['dependencyExpected'] = [];
                $data['horoscopeRulesJson'] = $horoscopeRuleService->getRulesForFrontend();
                $data['rashiAshtakootaJson'] = $horoscopeRuleService->getRashiAshtakootaForFrontend();
                // Compute birth weekday from profile DOB for default + mismatch warning in UI.
                $birthWeekdayExpected = null;
                if (! empty($profile->date_of_birth)) {
                    try {
                        $dob = $profile->date_of_birth instanceof \Carbon\CarbonInterface
                            ? $profile->date_of_birth
                            : \Carbon\Carbon::parse($profile->date_of_birth);
                        $birthWeekdayExpected = $dob->englishDayOfWeek;
                    } catch (\Throwable $e) {
                        $birthWeekdayExpected = null;
                    }
                }
                $data['birthWeekdayExpected'] = $birthWeekdayExpected;
                break;
            case 'contacts':
                $allContacts = DB::table('profile_contacts')->where('profile_id', $profile->id)->orderBy('id')->get();
                $selfRelationId = DB::table('master_contact_relations')->where('key', 'self')->value('id');
                $selfContacts = collect($allContacts)->where('contact_relation_id', $selfRelationId)->sortByDesc('is_primary')->values()->take(3)->all();
                $data['self_contacts'] = $selfContacts;
                $data['profile_contacts'] = collect($allContacts)->where('contact_relation_id', '!=', $selfRelationId)->values()->all();
                $data['contactRelations'] = \App\Models\MasterContactRelation::where('is_active', true)->get();
                $data['profile_contacts'] = $data['profile_contacts'] ?? collect();
                $data['contactRelations'] = $data['contactRelations'] ?? collect();
                break;
            case 'about-me':
                $data['extendedAttrs'] = DB::table('profile_extended_attributes')->where('profile_id', $profile->id)->first();
                break;
            case 'about-preferences':
                $criteria = DB::table('profile_preference_criteria')->where('profile_id', $profile->id)->first();
                $preferredReligionIds = DB::table('profile_preferred_religions')->where('profile_id', $profile->id)->pluck('religion_id')->all();
                $preferredCasteIds = DB::table('profile_preferred_castes')->where('profile_id', $profile->id)->pluck('caste_id')->all();
                $preferredDistrictIds = DB::table('profile_preferred_districts')->where('profile_id', $profile->id)->pluck('district_id')->all();

                $suggestions = \App\Services\PartnerPreferenceSuggestionService::suggestForProfile($profile);
                if (!$criteria && empty($preferredReligionIds) && empty($preferredCasteIds) && empty($preferredDistrictIds)) {
                    $criteria = (object) [
                        'preferred_age_min' => $suggestions['preferred_age_min'],
                        'preferred_age_max' => $suggestions['preferred_age_max'],
                        'preferred_income_min' => $suggestions['preferred_income_min'],
                        'preferred_income_max' => $suggestions['preferred_income_max'],
                        'preferred_education' => $suggestions['preferred_education'],
                        'preferred_city_id' => $suggestions['preferred_city_id'],
                    ];
                    $preferredReligionIds = $suggestions['preferred_religion_ids'] ?? [];
                    $preferredCasteIds = $suggestions['preferred_caste_ids'] ?? [];
                    $preferredDistrictIds = $suggestions['preferred_district_ids'] ?? [];
                    $data['preferencePreset'] = $suggestions['preference_preset'] ?? 'balanced';
                } else {
                    $data['preferencePreset'] = 'custom';
                }
                $base = $suggestions;
                if (!empty($base['preferred_city_id'])) {
                    $cityName = \App\Models\City::where('id', $base['preferred_city_id'])->value('name');
                    if ($cityName) {
                        $base['preferred_city_name'] = $cityName;
                    }
                }
                $data['preferencePresetDefaults'] = [
                    'traditional' => \App\Services\PartnerPreferencePresetService::applyPreset('traditional', $base),
                    'balanced' => \App\Services\PartnerPreferencePresetService::applyPreset('balanced', $base),
                    'broad' => \App\Services\PartnerPreferencePresetService::applyPreset('broad', $base),
                ];
                $data['preferenceCriteria'] = $criteria;
                $data['preferredReligionIds'] = $preferredReligionIds;
                $data['preferredCasteIds'] = $preferredCasteIds;
                $data['preferredDistrictIds'] = $preferredDistrictIds;

                $data['allReligions'] = \App\Models\Religion::where('is_active', true)->orderBy('label')->get();
                $data['allCastes'] = \App\Models\Caste::where('is_active', true)->orderBy('label')->get();
                $data['allDistricts'] = \App\Models\District::orderBy('name')->get();
                $data['marriageTypePreferences'] = \App\Models\MasterMarriageTypePreference::where('is_active', true)->orderBy('sort_order')->get();
                break;
            case 'photo':
                break;
            case 'full':
                $data = array_merge(
                    $this->getSectionViewData('basic-info', $profile),
                    $this->getSectionViewData('physical', $profile),
                    $this->getSectionViewData('marriages', $profile),
                    $this->getSectionViewData('education-career', $profile),
                    $this->getSectionViewData('family-details', $profile),
                    $this->getSectionViewData('siblings', $profile),
                    $this->getSectionViewData('relatives', $profile),
                    $this->getSectionViewData('alliance', $profile),
                    $this->getSectionViewData('location', $profile),
                    $this->getSectionViewData('property', $profile),
                    $this->getSectionViewData('horoscope', $profile),
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
            case 'physical':
                return $this->buildPhysicalSnapshot($request, $profile);
            case 'marriages':
                return $this->buildMarriagesSnapshot($request);
            case 'children':
                return $this->buildChildrenSnapshot($request);
            case 'education-career':
                return $this->buildEducationCareerSnapshot($request, $profile);
            case 'family-details':
                return $this->buildFamilyDetailsSnapshot($request, $profile);
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
            case 'contacts':
                return $this->buildContactsSnapshot($request, $profile);
            case 'about-me':
                return $this->buildAboutMeSnapshot($request, $profile);
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
        $this->resolveMasterLookupIds($request, ['gender' => 'gender_id']);
        $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'gender_id' => ['required', Rule::exists('master_genders', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'date_of_birth' => ['nullable', 'date'],
            'birth_time' => ['nullable', 'string', 'max:20'],
            'religion_id' => ['nullable', Rule::exists('religions', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'caste_id' => ['nullable', Rule::exists('castes', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'sub_caste_id' => ['nullable', Rule::exists('sub_castes', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'mother_tongue_id' => ['nullable', Rule::exists('master_mother_tongues', 'id')->where(fn ($q) => $q->where('is_active', true))],
        ]);

        $birthTimeValue = $request->filled('birth_time') ? trim($request->input('birth_time')) : null;
        if ($birthTimeValue === '') {
            $birthTimeValue = null;
        }

        $core = [
            'full_name' => $request->input('full_name'),
            'gender_id' => $request->input('gender_id') ? (int) $request->input('gender_id') : null,
            'date_of_birth' => $request->input('date_of_birth') ?: null,
            'birth_time' => $birthTimeValue,
            'religion_id' => $request->input('religion_id') ? (int) $request->input('religion_id') : null,
            'caste_id' => $request->input('caste_id') ? (int) $request->input('caste_id') : null,
            'sub_caste_id' => $request->input('sub_caste_id') ? (int) $request->input('sub_caste_id') : null,
            'mother_tongue_id' => $request->input('mother_tongue_id') ? (int) $request->input('mother_tongue_id') : null,
        ];
        $core = array_map(fn ($v) => $v === '' ? null : $v, $core);

        // Merge full marital engine snapshot (marital_status_id, has_children, marriages, children)
        $marriagesSnapshot = $this->buildMarriagesSnapshot($request);
        $core['marital_status_id'] = $marriagesSnapshot['core']['marital_status_id'] ?? null;
        $core['has_children'] = $marriagesSnapshot['core']['has_children'] ?? null;

        $birth_place = null;
        if ($request->filled('birth_state_id') || $request->filled('birth_city_id')) {
            $birth_place = [
                'city_id' => $request->input('birth_city_id') ? (int) $request->input('birth_city_id') : null,
                'taluka_id' => $request->input('birth_taluka_id') ? (int) $request->input('birth_taluka_id') : null,
                'district_id' => $request->input('birth_district_id') ? (int) $request->input('birth_district_id') : null,
                'state_id' => $request->input('birth_state_id') ? (int) $request->input('birth_state_id') : null,
            ];
        } elseif ($profile->birth_city_id || $profile->birth_state_id) {
            $birth_place = [
                'city_id' => $profile->birth_city_id,
                'taluka_id' => $profile->birth_taluka_id,
                'district_id' => $profile->birth_district_id,
                'state_id' => $profile->birth_state_id,
            ];
        }

        return [
            'core' => $core,
            'birth_place' => $birth_place,
            'marriages' => $marriagesSnapshot['marriages'] ?? [],
            'children' => $marriagesSnapshot['children'] ?? [],
        ];
    }

    private function buildPhysicalSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $request->validate([
            'height_cm' => ['nullable', 'integer', 'min:50', 'max:250'],
            'complexion_id' => ['nullable', Rule::exists('master_complexions', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'blood_group_id' => ['nullable', Rule::exists('master_blood_groups', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'physical_build_id' => ['nullable', Rule::exists('master_physical_builds', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'spectacles_lens' => ['nullable', 'string', 'max:50', Rule::in(['no', 'spectacles', 'contact_lens', 'both'])],
            'physical_condition' => ['nullable', 'string', 'max:50', Rule::in(['none', 'physically_challenged', 'hearing_condition', 'vision_condition', 'other', 'prefer_not_to_say'])],
            'diet_id' => ['nullable', Rule::exists('master_diets', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'smoking_status_id' => ['nullable', Rule::exists('master_smoking_statuses', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'drinking_status_id' => ['nullable', Rule::exists('master_drinking_statuses', 'id')->where(fn ($q) => $q->where('is_active', true))],
        ]);

        $core = [
            'height_cm' => $request->filled('height_cm') ? (int) $request->input('height_cm') : null,
            'complexion_id' => $request->input('complexion_id') ? (int) $request->input('complexion_id') : null,
            'blood_group_id' => $request->input('blood_group_id') ? (int) $request->input('blood_group_id') : null,
            'physical_build_id' => $request->input('physical_build_id') ? (int) $request->input('physical_build_id') : null,
            'spectacles_lens' => $request->input('spectacles_lens') ?: null,
            'physical_condition' => $request->input('physical_condition') ?: null,
            'diet_id' => $request->input('diet_id') ? (int) $request->input('diet_id') : null,
            'smoking_status_id' => $request->input('smoking_status_id') ? (int) $request->input('smoking_status_id') : null,
            'drinking_status_id' => $request->input('drinking_status_id') ? (int) $request->input('drinking_status_id') : null,
        ];
        $core = array_map(fn ($v) => $v === '' ? null : $v, $core);

        return [
            'core' => $core,
            'contacts' => [],
            'children' => [],
            'education_history' => [],
            'career_history' => [],
            'addresses' => [],
            'property_summary' => [],
            'property_assets' => [],
            'horoscope' => [],
            'preferences' => [],
            'extended_narrative' => [],
        ];
    }

    private function buildEducationCareerSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $this->resolveMasterLookupIds($request, ['income_currency' => 'income_currency_id']);
        $incomeEngineRules = $this->incomeEngineValidationRules($request, 'income');
        $request->validate(array_merge([
            'annual_income' => 'nullable|numeric',
            'income_currency_id' => ['nullable', Rule::exists('master_income_currencies', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'working_with_type_id' => ['nullable', Rule::exists('working_with_types', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'profession_id' => ['nullable', Rule::exists('professions', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'income_range_id' => 'nullable|exists:income_ranges,id',
            'college_id' => 'nullable|exists:colleges,id',
            'company_name' => 'nullable|string|max:255',
            'income_private' => 'nullable|boolean',
        ], $incomeEngineRules));
        if ($request->filled('profession_id') && $request->filled('working_with_type_id')) {
            $prof = \App\Models\Profession::find($request->input('profession_id'));
            if ($prof && (string) $prof->working_with_type_id !== (string) $request->input('working_with_type_id')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'profession_id' => ['The selected profession does not belong to the selected Working With.'],
                ]);
            }
        }
        $workingWithTypeId = $request->filled('working_with_type_id') ? (int) $request->input('working_with_type_id') : null;
        $professionId = $request->filled('profession_id') ? (int) $request->input('profession_id') : null;
        if ($professionId && $workingWithTypeId) {
            $prof = \App\Models\Profession::find($professionId);
            if (! $prof || (string) $prof->working_with_type_id !== (string) $workingWithTypeId) {
                $professionId = null;
            }
        }
        $incomeEngineService = app(\App\Services\IncomeEngineService::class);
        $incomeCore = $this->buildIncomeEngineCore($request, 'income', $incomeEngineService);
        $defaultInr = \App\Models\MasterIncomeCurrency::where('code', 'INR')->value('id');
        $workCityId = $request->filled('work_city_id') ? (int) $request->input('work_city_id') : null;
        $workStateId = $request->filled('work_state_id') ? (int) $request->input('work_state_id') : null;
        $core = [
            'highest_education' => $request->input('highest_education') ?: null,
            'highest_education_other' => $request->input('highest_education_other') ? trim((string) $request->input('highest_education_other')) ?: null : null,
            'specialization' => $request->input('specialization') ?: null,
            'college_id' => $request->filled('college_id') ? (int) $request->input('college_id') : null,
            'working_with_type_id' => $workingWithTypeId,
            'profession_id' => $professionId,
            'company_name' => $request->input('company_name') ?: null,
            'annual_income' => $request->filled('annual_income') ? (float) $request->input('annual_income') : null,
            'income_range_id' => $request->filled('income_range_id') ? (int) $request->input('income_range_id') : null,
            'income_private' => $request->boolean('income_private'),
            'income_currency_id' => $request->input('income_currency_id') ? (int) $request->input('income_currency_id') : $defaultInr,
            'work_city_id' => $workCityId,
            'work_state_id' => $workStateId,
        ];
        $core = array_merge($core, $incomeCore);
        $core = array_map(fn ($v) => $v === '' ? null : $v, $core);

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

        return [
            'core' => $core,
            'education_history' => $education_history,
            'career_history' => $career_history,
        ];
    }

    private function buildFamilyDetailsSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $this->resolveMasterLookupIds($request, [
            'family_type' => 'family_type_id',
            'physical_build' => 'physical_build_id',
        ]);
        $familyIncomeEngineRules = $this->incomeEngineValidationRules($request, 'family_income');
        $request->validate(array_merge([
            'family_income' => 'nullable|numeric',
        ], $familyIncomeEngineRules));
        $incomeEngineService = app(\App\Services\IncomeEngineService::class);
        $familyIncomeCore = $this->buildIncomeEngineCore($request, 'family_income', $incomeEngineService);
        $parentsAddressLine = $request->filled('parents_address_line') ? trim((string) $request->input('parents_address_line')) : null;

        $core = [
            'father_name' => $request->input('father_name') ?: null,
            'father_occupation' => $request->input('father_occupation') ?: null,
            'father_extra_info' => $request->filled('father_extra_info') ? trim((string) $request->input('father_extra_info')) : null,
            'father_contact_1' => trim((string) ($request->input('father_contact_1') ?? '')) ?: null,
            'father_contact_2' => trim((string) ($request->input('father_contact_2') ?? '')) ?: null,
            'father_contact_3' => trim((string) ($request->input('father_contact_3') ?? '')) ?: null,
            'mother_name' => $request->input('mother_name') ?: null,
            'mother_occupation' => $request->input('mother_occupation') ?: null,
            'mother_extra_info' => $request->filled('mother_extra_info') ? trim((string) $request->input('mother_extra_info')) : null,
            'mother_contact_1' => trim((string) ($request->input('mother_contact_1') ?? '')) ?: null,
            'mother_contact_2' => trim((string) ($request->input('mother_contact_2') ?? '')) ?: null,
            'mother_contact_3' => trim((string) ($request->input('mother_contact_3') ?? '')) ?: null,
            'family_type_id' => $request->input('family_type_id') ? (int) $request->input('family_type_id') : null,
            'family_status' => $request->input('family_status') ?: null,
            'family_values' => $request->input('family_values') ?: null,
            'family_income' => $request->filled('family_income') ? (float) $request->input('family_income') : null,
            'weight_kg' => $request->filled('weight_kg') ? (float) $request->input('weight_kg') : $profile->weight_kg,
            'physical_build_id' => $request->input('physical_build_id') ? (int) $request->input('physical_build_id') : $profile->physical_build_id,
        ];
        // Parents home address uses the same core address fields; allow editing from Family details too.
        if ($request->has('city_id') || $request->has('state_id') || $request->has('parents_address_line')) {
            $core['country_id'] = $request->input('country_id') ?: null;
            $core['state_id'] = $request->input('state_id') ?: null;
            $core['district_id'] = $request->input('district_id') ?: null;
            $core['taluka_id'] = $request->input('taluka_id') ?: null;
            $core['city_id'] = $request->input('city_id') ?: null;
            $core['address_line'] = $parentsAddressLine !== null ? $parentsAddressLine : ($profile->address_line ?? null);
        }
        $core = array_merge($core, $familyIncomeCore);
        $core = array_map(fn ($v) => $v === '' ? null : $v, $core);

        return [
            'core' => $core,
        ];
    }

    private function buildPersonalFamilySnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $this->resolveMasterLookupIds($request, [
            'income_currency' => 'income_currency_id',
            'family_type' => 'family_type_id',
            'physical_build' => 'physical_build_id',
        ]);
        $incomeEngineRules = $this->incomeEngineValidationRules($request, 'income');
        $familyIncomeEngineRules = $this->incomeEngineValidationRules($request, 'family_income');
        $request->validate(array_merge([
            'annual_income' => 'nullable|numeric',
            'family_income' => 'nullable|numeric',
            'income_currency_id' => 'nullable|exists:master_income_currencies,id',
            'working_with_type_id' => 'nullable|exists:working_with_types,id',
            'profession_id' => 'nullable|exists:professions,id',
            'income_range_id' => 'nullable|exists:income_ranges,id',
            'college_id' => 'nullable|exists:colleges,id',
            'company_name' => 'nullable|string|max:255',
            'income_private' => 'nullable|boolean',
        ], $incomeEngineRules, $familyIncomeEngineRules));
        if ($request->filled('profession_id') && $request->filled('working_with_type_id')) {
            $prof = \App\Models\Profession::find($request->input('profession_id'));
            if ($prof && (string) $prof->working_with_type_id !== (string) $request->input('working_with_type_id')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'profession_id' => ['The selected profession does not belong to the selected Working With.'],
                ]);
            }
        }
        $workingWithTypeId = $request->filled('working_with_type_id') ? (int) $request->input('working_with_type_id') : null;
        $professionId = $request->filled('profession_id') ? (int) $request->input('profession_id') : null;
        if ($professionId && $workingWithTypeId) {
            $prof = \App\Models\Profession::find($professionId);
            if (! $prof || (string) $prof->working_with_type_id !== (string) $workingWithTypeId) {
                $professionId = null;
            }
        }
        $incomeEngineService = app(\App\Services\IncomeEngineService::class);
        $incomeCore = $this->buildIncomeEngineCore($request, 'income', $incomeEngineService);
        $familyIncomeCore = $this->buildIncomeEngineCore($request, 'family_income', $incomeEngineService);

        $core = [
            'highest_education' => $request->input('highest_education') ?: null,
            'highest_education_other' => $request->input('highest_education_other') ? trim((string) $request->input('highest_education_other')) ?: null : null,
            'specialization' => $request->input('specialization') ?: null,
            'college_id' => $request->filled('college_id') ? (int) $request->input('college_id') : null,
            'working_with_type_id' => $workingWithTypeId,
            'profession_id' => $professionId,
            'company_name' => $request->input('company_name') ?: null,
            'annual_income' => $request->filled('annual_income') ? (float) $request->input('annual_income') : null,
            'income_range_id' => $request->filled('income_range_id') ? (int) $request->input('income_range_id') : null,
            'income_private' => $request->boolean('income_private'),
            'family_income' => $request->filled('family_income') ? (float) $request->input('family_income') : null,
            'income_currency_id' => $request->input('income_currency_id') ? (int) $request->input('income_currency_id') : (\App\Models\MasterIncomeCurrency::where('code', 'INR')->value('id')),
        ];
        $core = array_merge($core, $incomeCore, $familyIncomeCore);
        $core['father_name'] = $request->input('father_name') ?: null;
        $core['father_occupation'] = $request->input('father_occupation') ?: null;
        $core['father_contact_1'] = trim((string) ($request->input('father_contact_1') ?? '')) ?: null;
        $core['father_contact_2'] = trim((string) ($request->input('father_contact_2') ?? '')) ?: null;
        $core['father_contact_3'] = trim((string) ($request->input('father_contact_3') ?? '')) ?: null;
        $core['mother_name'] = $request->input('mother_name') ?: null;
        $core['mother_occupation'] = $request->input('mother_occupation') ?: null;
        $core['mother_contact_1'] = trim((string) ($request->input('mother_contact_1') ?? '')) ?: null;
        $core['mother_contact_2'] = trim((string) ($request->input('mother_contact_2') ?? '')) ?: null;
        $core['mother_contact_3'] = trim((string) ($request->input('mother_contact_3') ?? '')) ?: null;
        $core['family_type_id'] = $request->input('family_type_id') ? (int) $request->input('family_type_id') : null;
        $core['family_status'] = $request->input('family_status') ?: null;
        $core['family_values'] = $request->input('family_values') ?: null;
        $core['weight_kg'] = $request->filled('weight_kg') ? (float) $request->input('weight_kg') : $profile->weight_kg;
        $core['physical_build_id'] = $request->input('physical_build_id') ? (int) $request->input('physical_build_id') : $profile->physical_build_id;
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
                'location' => trim((string) ($row['location'] ?? '')) ?: null,
                'start_year' => ! empty($row['start_year']) ? (int) $row['start_year'] : null,
                'end_year' => ! empty($row['end_year']) ? (int) $row['end_year'] : null,
                'is_current' => isset($row['is_current']) && (string) $row['is_current'] === '1',
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
        $hasSiblings = $request->has('has_siblings') ? ($request->input('has_siblings') === '1' || $request->input('has_siblings') === 1) : null;
        $siblings = [];
        if ($hasSiblings === false) {
            // User chose "No" — do not persist sibling rows
        } else {
        foreach ($request->input('siblings', []) as $row) {
            $relationType = in_array($row['relation_type'] ?? null, ['brother', 'sister'], true) ? $row['relation_type'] : null;
            $maritalStatus = in_array($row['marital_status'] ?? null, ['unmarried', 'married'], true) ? $row['marital_status'] : null;
            $isMarried = $maritalStatus === 'married' || ! empty($row['is_married']);
            $spouseIn = $row['spouse'] ?? [];
            $siblingRow = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'relation_type' => $relationType,
                'name' => trim((string) ($row['name'] ?? '')) ?: null,
                'gender' => in_array($row['gender'] ?? null, ['male', 'female'], true) ? $row['gender'] : null,
                'marital_status' => $maritalStatus,
                'occupation' => trim((string) ($row['occupation'] ?? '')) ?: null,
                'city_id' => ! empty($row['city_id']) ? (int) $row['city_id'] : null,
                'contact_number' => trim((string) ($row['contact_number'] ?? '')) ?: null,
                'contact_number_2' => trim((string) ($row['contact_number_2'] ?? '')) ?: null,
                'contact_number_3' => trim((string) ($row['contact_number_3'] ?? '')) ?: null,
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
        }

        $core = [];
        if ($request->has('has_siblings')) {
            $core['has_siblings'] = $hasSiblings;
        }
        return [
            'core' => $core,
            'siblings' => $siblings,
        ];
    }

    private function buildRelativesSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        // Extended family tab: only paternal from form; keep existing maternal from DB
        $paternal = $this->collectRelativesFromRequestSource($request->input('relatives_parents_family', []));
        $maternalFamilyTypes = ['maternal_address_ajol', 'maternal_grandfather', 'maternal_grandmother', 'maternal_uncle', 'wife_maternal_uncle', 'maternal_aunt', 'husband_maternal_aunt', 'maternal_cousin'];
        $maternalFromDb = $this->loadRelativesFromDb($profile->id, $maternalFamilyTypes);
        $relatives = array_merge($paternal, $maternalFromDb);

        return [
            'core' => [],
            'relatives' => $relatives,
        ];
    }

    /**
     * Collect relatives from one request source (parents_family or maternal_family) into snapshot format.
     */
    private function collectRelativesFromRequestSource(array $rows): array
    {
        $relatives = [];
        foreach ($rows as $row) {
            $relationType = trim((string) ($row['relation_type'] ?? ''));
            if ($relationType === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if (in_array($relationType, ['maternal_address_ajol', 'native_place'], true)) {
                $name = '';
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

        return $relatives;
    }

    /**
     * Load existing relatives from DB (by relation_type) in snapshot format.
     */
    private function loadRelativesFromDb(int $profileId, array $relationTypes): array
    {
        $rows = DB::table('profile_relatives')->where('profile_id', $profileId)->whereIn('relation_type', $relationTypes)->orderBy('id')->get();
        return $rows->map(fn ($r) => [
            'id' => $r->id,
            'relation_type' => $r->relation_type ?? '',
            'name' => $r->name ?? '',
            'occupation' => $r->occupation ?? null,
            'city_id' => $r->city_id ?? null,
            'state_id' => $r->state_id ?? null,
            'contact_number' => $r->contact_number ?? null,
            'notes' => $r->notes ?? null,
            'is_primary_contact' => ! empty($r->is_primary_contact),
        ])->values()->all();
    }

    /**
     * Load existing alliance networks from DB (when Relatives tab form does not include the repeater).
     */
    private function loadAllianceNetworksFromDb(int $profileId): array
    {
        $rows = DB::table('profile_alliance_networks')->where('profile_id', $profileId)->orderBy('id')->get();
        return $rows->map(fn ($r) => [
            'id' => $r->id,
            'surname' => $r->surname ?? '',
            'city_id' => $r->city_id ?? null,
            'taluka_id' => $r->taluka_id ?? null,
            'district_id' => $r->district_id ?? null,
            'state_id' => $r->state_id ?? null,
            'notes' => $r->notes ?? null,
        ])->values()->all();
    }

    /**
     * Collect relatives from both engines (parents' family + maternal family).
     * Used by ManualSnapshotBuilderService for full form.
     */
    public function collectRelativesFromRequest(\Illuminate\Http\Request $request): array
    {
        $paternal = $this->collectRelativesFromRequestSource($request->input('relatives_parents_family', []));
        $maternal = $this->collectRelativesFromRequestSource($request->input('relatives_maternal_family', []));

        return array_merge($paternal, $maternal);
    }

    private function buildAllianceSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $allianceInput = $request->input('alliance_networks', []);
        $alliance = [];
        foreach ($allianceInput as $row) {
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
        // Relatives tab form does not include alliance_networks repeater; preserve existing when empty
        if (empty($alliance)) {
            $alliance = $this->loadAllianceNetworksFromDb($profile->id);
        }

        // Relatives tab: maternal from form; keep existing paternal from DB
        $maternal = $this->collectRelativesFromRequestSource($request->input('relatives_maternal_family', []));
        $parentsFamilyTypes = ['native_place', 'paternal_grandfather', 'paternal_grandmother', 'paternal_uncle', 'wife_paternal_uncle', 'paternal_aunt', 'husband_paternal_aunt', 'Cousin'];
        $paternalFromDb = $this->loadRelativesFromDb($profile->id, $parentsFamilyTypes);
        $relatives = array_merge($paternalFromDb, $maternal);

        return [
            'core' => [],
            'alliance_networks' => $alliance,
            'relatives' => $relatives,
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
        $validated = $request->validate([
            'preferred_age_min' => ['nullable', 'integer', 'min:18', 'max:80'],
            'preferred_age_max' => ['nullable', 'integer', 'min:18', 'max:80'],
            'preferred_income_min' => ['nullable', 'numeric', 'min:0'],
            'preferred_income_max' => ['nullable', 'numeric', 'min:0'],
            'preferred_education' => ['nullable', 'string', 'max:255'],
            'preferred_city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'preferred_religion_ids' => ['nullable', 'array'],
            'preferred_religion_ids.*' => ['integer', Rule::exists('religions', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'preferred_caste_ids' => ['nullable', 'array'],
            'preferred_caste_ids.*' => ['integer', Rule::exists('castes', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'preferred_district_ids' => ['nullable', 'array'],
            'preferred_district_ids.*' => ['integer', 'exists:districts,id'],
            'willing_to_relocate' => ['nullable', 'boolean'],
            'settled_city_preference_id' => ['nullable', 'integer', 'exists:cities,id'],
            'settled_preference' => ['nullable', 'array'],
            'settled_preference.city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'marriage_type_preference_id' => ['nullable', 'integer', Rule::exists('master_marriage_type_preferences', 'id')->where(fn ($q) => $q->where('is_active', true))],
        ]);

        $preferredCitiesInput = $request->input('preferred_cities', []);
        $cityIdsFromPreferred = [];
        if (is_array($preferredCitiesInput)) {
            foreach ($preferredCitiesInput as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cid = $row['city_id'] ?? $row['preferred_city_id'] ?? null;
                if ($cid !== null && $cid !== '') {
                    $cityIdsFromPreferred[] = (int) $cid;
                }
            }
        }
        $cityIdsFromPreferred = array_values(array_unique($cityIdsFromPreferred));

        if (
            isset($validated['preferred_age_min'], $validated['preferred_age_max']) &&
            $validated['preferred_age_min'] !== null &&
            $validated['preferred_age_max'] !== null &&
            $validated['preferred_age_min'] > $validated['preferred_age_max']
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'preferred_age_min' => ['Minimum age must be less than or equal to maximum age.'],
            ]);
        }
        if (
            isset($validated['preferred_income_min'], $validated['preferred_income_max']) &&
            $validated['preferred_income_min'] !== null &&
            $validated['preferred_income_max'] !== null &&
            (float) $validated['preferred_income_min'] > (float) $validated['preferred_income_max']
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'preferred_income_min' => ['Minimum income must be less than or equal to maximum income.'],
            ]);
        }

        $preferredCityId = $validated['preferred_city_id'] ?? null;
        if ($preferredCityId === null && !empty($cityIdsFromPreferred)) {
            $preferredCityId = $cityIdsFromPreferred[0];
        }

        $districtIds = $validated['preferred_district_ids'] ?? [];
        if (!empty($cityIdsFromPreferred)) {
            $talukaIds = DB::table('cities')->whereIn('id', $cityIdsFromPreferred)->pluck('taluka_id')->filter()->all();
            if (!empty($talukaIds)) {
                $districtsFromCities = DB::table('talukas')->whereIn('id', $talukaIds)->pluck('district_id')->filter()->map(fn ($id) => (int) $id)->all();
                if (!empty($districtsFromCities)) {
                    $districtIds = array_values(array_unique(array_merge($districtIds, $districtsFromCities)));
                }
            }
        }

        $snapshotPreferences = [
            'preferred_age_min' => $validated['preferred_age_min'] ?? null,
            'preferred_age_max' => $validated['preferred_age_max'] ?? null,
            'preferred_income_min' => $validated['preferred_income_min'] ?? null,
            'preferred_income_max' => $validated['preferred_income_max'] ?? null,
            'preferred_education' => $validated['preferred_education'] ?? null,
            'preferred_city_id' => $preferredCityId,
            'willing_to_relocate' => $request->boolean('willing_to_relocate') ? true : null,
            'settled_city_preference_id' => $validated['settled_city_preference_id'] ?? (isset($validated['settled_preference']['city_id']) ? (int) $validated['settled_preference']['city_id'] : null),
            'marriage_type_preference_id' => $validated['marriage_type_preference_id'] ?? null,
            'preferred_religion_ids' => $validated['preferred_religion_ids'] ?? [],
            'preferred_caste_ids' => $validated['preferred_caste_ids'] ?? [],
            'preferred_district_ids' => $districtIds,
        ];

        return [
            'preferences' => $snapshotPreferences,
        ];
    }

    private function buildAboutMeSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $extended_narrative = [];
        if ($request->has('extended_narrative')) {
            $en = $request->input('extended_narrative');
            $extended_narrative = [[
                'id' => ! empty($en['id']) ? (int) $en['id'] : null,
                'narrative_about_me' => trim((string) ($en['narrative_about_me'] ?? '')),
                'narrative_expectations' => trim((string) ($en['narrative_expectations'] ?? '')),
            ]];
        }

        $snapshot = [];
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
                'ownership_type_id' => ! empty($row['ownership_type_id']) ? (int) $row['ownership_type_id'] : null,
            ];
        }

        $core = [];
        if ($request->has('city_id') || $request->has('address_line') || $request->has('state_id')) {
            $core = [
                'country_id' => $request->input('country_id') ?: null,
                'state_id' => $request->input('state_id') ?: null,
                'district_id' => $request->input('district_id') ?: null,
                'taluka_id' => $request->input('taluka_id') ?: null,
                'city_id' => $request->input('city_id') ?: null,
                'address_line' => $request->filled('address_line') ? trim($request->input('address_line')) : null,
            ];
            $core = array_map(fn ($v) => $v === '' ? null : $v, $core);
        }

        return [
            'core' => $core,
            'property_summary' => $property_summary,
            'property_assets' => $property_assets,
        ];
    }

    private function buildHoroscopeSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $horoscope = [];
        if ($request->has('horoscope')) {
            $h = $request->input('horoscope');
            if (empty($h['id'] ?? null) && $profile->id) {
                $existingId = \App\Models\ProfileHoroscopeData::where('profile_id', $profile->id)->value('id');
                if ($existingId) {
                    $h['id'] = (int) $existingId;
                }
            }
            $payload = [
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
                'mangal_status_id' => ! empty($h['mangal_status_id']) ? (int) $h['mangal_status_id'] : null,
                'devak' => trim((string) ($h['devak'] ?? '')),
                'kul' => trim((string) ($h['kuldaivat'] ?? $h['kul'] ?? '')),
                'gotra' => trim((string) ($h['gotra'] ?? '')),
                'navras_name' => trim((string) ($h['navras_name'] ?? '')),
                'birth_weekday' => trim((string) ($h['birth_weekday'] ?? '')),
            ];
            if ($payload['charan'] !== null && ($payload['charan'] < 1 || $payload['charan'] > 4)) {
                $payload['charan'] = null;
            }
            $ruleService = app(\App\Services\HoroscopeRuleService::class);
            $payload = $ruleService->applyAutofillToPayload($payload);
            $this->validateHoroscopeStructural($payload);
            $horoscope = [[
                'id' => $payload['id'],
                'rashi_id' => $payload['rashi_id'],
                'nakshatra_id' => $payload['nakshatra_id'],
                'charan' => $payload['charan'],
                'gan_id' => $payload['gan_id'],
                'nadi_id' => $payload['nadi_id'],
                'yoni_id' => $payload['yoni_id'],
                'varna_id' => $payload['varna_id'],
                'vashya_id' => $payload['vashya_id'],
                'rashi_lord_id' => $payload['rashi_lord_id'],
                'mangal_dosh_type_id' => $payload['mangal_dosh_type_id'],
                'mangal_status_id' => $payload['mangal_status_id'],
                'devak' => $payload['devak'],
                'kul' => $payload['kul'],
                'gotra' => $payload['gotra'],
                'navras_name' => $payload['navras_name'],
                'birth_weekday' => $payload['birth_weekday'],
            ]];
        }

        return [
            'core' => [],
            'horoscope' => $horoscope,
        ];
    }

    /** Validate horoscope payload for structural issues only (invalid FK, charan). Does not block on dependency mismatch. */
    private function validateHoroscopeStructural(array $payload): void
    {
        $rules = [
            'nakshatra_id' => ['nullable', Rule::exists('master_nakshatras', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'rashi_id' => ['nullable', Rule::exists('master_rashis', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'gan_id' => ['nullable', Rule::exists('master_gans', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'nadi_id' => ['nullable', Rule::exists('master_nadis', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'yoni_id' => ['nullable', Rule::exists('master_yonis', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'mangal_dosh_type_id' => ['nullable', Rule::exists('master_mangal_dosh_types', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'mangal_status_id' => ['nullable', Rule::exists('master_mangal_statuses', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'varna_id' => ['nullable', Rule::exists('master_varnas', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'vashya_id' => ['nullable', Rule::exists('master_vashyas', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'rashi_lord_id' => ['nullable', Rule::exists('master_rashi_lords', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'charan' => ['nullable', 'integer', 'min:1', 'max:4'],
        ];
        $validator = \Illuminate\Support\Facades\Validator::make($payload, $rules);
        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }
    }

    private function buildContactsSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $contacts = [];
        $labels = ['Primary', 'Self 2', 'Self 3'];
        for ($i = 0; $i < 3; $i++) {
            $key = $i === 0 ? 'primary_contact_number' : 'primary_contact_number_' . ($i + 1);
            $whatsappKey = $i === 0 ? 'primary_contact_whatsapp' : 'primary_contact_whatsapp_' . ($i + 1);
            $phone = trim((string) $request->input($key, ''));
            if ($phone !== '') {
                $pref = $request->input($whatsappKey);
                $pref = in_array($pref, ['whatsapp', 'call', 'message'], true) ? $pref : ($pref ? 'whatsapp' : 'call');
                $contacts[] = [
                    'relation_type' => 'self',
                    'contact_name' => $labels[$i],
                    'phone_number' => $phone,
                    'is_primary' => $i === 0,
                    'is_whatsapp' => $pref === 'whatsapp',
                    'contact_preference' => $pref,
                ];
            }
        }
        foreach ($request->input('contacts', []) as $row) {
            $contactName = trim((string) ($row['contact_name'] ?? ''));
            $phoneNumber = trim((string) ($row['phone_number'] ?? ''));
            if ($contactName !== '' || $phoneNumber !== '') {
                $pref = $row['is_whatsapp'] ?? $row['contact_preference'] ?? null;
                $pref = in_array($pref, ['whatsapp', 'call', 'message'], true) ? $pref : ($pref ? 'whatsapp' : 'call');
                $contacts[] = [
                    'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                    'relation_type' => trim((string) ($row['relation_type'] ?? '')),
                    'contact_name' => $contactName,
                    'phone_number' => $phoneNumber,
                    'is_primary' => ! empty($row['is_primary']),
                    'is_whatsapp' => $pref === 'whatsapp',
                    'contact_preference' => $pref,
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
	protected function buildMarriagesSnapshot($request): array
{
        $maritalStatusId = $request->input('marital_status_id');
        $statusKey = \App\Models\MasterMaritalStatus::where('id', $maritalStatusId)->value('key');
        $statusesRequiringChildren = ['divorced', 'separated', 'widowed'];

        $rules = [
            'marital_status_id' => ['required', Rule::exists('master_marital_statuses', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'marriages.*.marriage_year' => ['nullable', 'integer', 'min:1901', 'max:' . (int) date('Y')],
            'marriages.*.separation_year' => ['nullable', 'integer', 'min:1901', 'max:' . (int) date('Y')],
            'marriages.*.divorce_year' => ['nullable', 'integer', 'min:1901', 'max:' . (int) date('Y')],
            'marriages.*.spouse_death_year' => ['nullable', 'integer', 'min:1901', 'max:' . (int) date('Y')],
        ];
        if ($statusKey && in_array($statusKey, $statusesRequiringChildren, true)) {
            $rules['has_children'] = ['required', 'in:0,1'];
        }
        $request->validate($rules);

        if ($statusKey && in_array($statusKey, $statusesRequiringChildren, true)) {
            $hasChildren = $request->input('has_children');
            $hasChildrenYes = $hasChildren === '1' || $hasChildren === 1 || $hasChildren === true;
            if ($hasChildrenYes) {
                $request->validate([
                    'children' => ['required', 'array', 'min:1'],
                    'children.*.gender' => ['required', 'in:male,female,other,prefer_not_say'],
                    'children.*.age' => ['required', 'integer', 'min:1', 'max:120'],
                    'children.*.child_living_with_id' => ['required'],
                ]);
            }
        }

        $marriageRows = $request->input('marriages', []);
        $marriageRow = $marriageRows[0] ?? [];
        $marriageYear = ! empty($marriageRow['marriage_year']) ? (int) $marriageRow['marriage_year'] : null;
        $divorceYear = ! empty($marriageRow['divorce_year']) ? (int) $marriageRow['divorce_year'] : null;
        $separationYear = ! empty($marriageRow['separation_year']) ? (int) $marriageRow['separation_year'] : null;
        $spouseDeathYear = ! empty($marriageRow['spouse_death_year']) ? (int) $marriageRow['spouse_death_year'] : null;
        $currentYear = (int) date('Y');
        if ($marriageYear !== null && $divorceYear !== null && $divorceYear < $marriageYear) {
            throw \Illuminate\Validation\ValidationException::withMessages(['marriages.0.divorce_year' => ['Divorce year must be greater than or equal to marriage year.']]);
        }
        if ($marriageYear !== null && $separationYear !== null && $separationYear < $marriageYear) {
            throw \Illuminate\Validation\ValidationException::withMessages(['marriages.0.separation_year' => ['Separation year must be greater than or equal to marriage year.']]);
        }
        if ($marriageYear !== null && $spouseDeathYear !== null && $spouseDeathYear < $marriageYear) {
            throw \Illuminate\Validation\ValidationException::withMessages(['marriages.0.spouse_death_year' => ['Spouse death year must be greater than or equal to marriage year.']]);
        }
        if ($marriageYear !== null && $marriageYear > $currentYear) {
            throw \Illuminate\Validation\ValidationException::withMessages(['marriages.0.marriage_year' => ['Marriage year cannot be in the future.']]);
        }
        if ($divorceYear !== null && $divorceYear > $currentYear) {
            throw \Illuminate\Validation\ValidationException::withMessages(['marriages.0.divorce_year' => ['Divorce year cannot be in the future.']]);
        }
        if ($separationYear !== null && $separationYear > $currentYear) {
            throw \Illuminate\Validation\ValidationException::withMessages(['marriages.0.separation_year' => ['Separation year cannot be in the future.']]);
        }
        if ($spouseDeathYear !== null && $spouseDeathYear > $currentYear) {
            throw \Illuminate\Validation\ValidationException::withMessages(['marriages.0.spouse_death_year' => ['Spouse death year cannot be in the future.']]);
        }

        $marriageId = ! empty($marriageRow['id']) ? (int) $marriageRow['id'] : null;
        $marriages = [[
            'id' => $marriageId,
            'marriage_year' => $marriageYear,
            'separation_year' => $separationYear,
            'divorce_year' => $divorceYear,
            'spouse_death_year' => $spouseDeathYear,
            'divorce_status' => trim((string) ($marriageRow['divorce_status'] ?? '')) ?: null,
            'remarriage_reason' => trim((string) ($marriageRow['remarriage_reason'] ?? '')) ?: null,
            'notes' => trim((string) ($marriageRow['notes'] ?? '')) ?: null,
        ]];

        $hasChildren = $request->input('has_children');
        $hasChildrenBool = $hasChildren === '1' || $hasChildren === 1 || $hasChildren === true;
        $core = [
            'marital_status_id' => $maritalStatusId ? (int) $maritalStatusId : null,
            'has_children' => $statusKey && in_array($statusKey, $statusesRequiringChildren, true) ? $hasChildrenBool : null,
        ];

        $children = [];
        if ($hasChildrenBool && $statusKey && in_array($statusKey, $statusesRequiringChildren, true)) {
            foreach ($request->input('children', []) as $i => $row) {
                $children[] = [
                    'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                    'child_name' => null,
                    'gender' => trim((string) ($row['gender'] ?? '')),
                    'age' => isset($row['age']) && $row['age'] !== '' ? (int) $row['age'] : null,
                    'child_living_with_id' => ! empty($row['child_living_with_id']) ? (int) $row['child_living_with_id'] : null,
                    'sort_order' => $i,
                ];
            }
        }

        return [
            'core' => $core,
            'marriages' => $marriages,
            'children' => $children,
        ];
    }

    protected function buildChildrenSnapshot(Request $request): array
    {
        $rows = [];

        foreach ($request->input('children', []) as $row) {
            $rows[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'child_name' => trim((string) ($row['child_name'] ?? '')) ?: null,
                'gender' => trim((string) ($row['gender'] ?? '')) ?: null,
                'age' => isset($row['age']) && $row['age'] !== '' ? (int) $row['age'] : null,
                'child_living_with_id' => ! empty($row['child_living_with_id']) ? (int) $row['child_living_with_id'] : null,
            ];
        }

        return [
            'core' => [],
            'children' => $rows,
        ];
    }

    /**
     * Dynamic validation rules for the centralized Income Engine (personal or family).
     */
    private function incomeEngineValidationRules(Request $request, string $prefix): array
    {
        $vt = $request->input($prefix . '_value_type');
        $rules = [
            $prefix . '_period' => 'nullable|in:annual,monthly,weekly,daily',
            $prefix . '_value_type' => 'nullable|in:exact,approximate,range,undisclosed',
            $prefix . '_currency_id' => 'nullable|exists:master_income_currencies,id',
            $prefix . '_private' => 'nullable|boolean',
        ];
        if ($prefix === 'family_income') {
            $rules[$prefix . '_currency_id'] = 'nullable|exists:master_income_currencies,id';
        }
        if (in_array($vt, ['exact', 'approximate'], true)) {
            $rules[$prefix . '_amount'] = 'required|numeric|min:0';
        }
        if ($vt === 'range') {
            $rules[$prefix . '_min_amount'] = 'required|numeric|min:0';
            $rules[$prefix . '_max_amount'] = 'required|numeric|min:0|gte:' . $prefix . '_min_amount';
        }
        return $rules;
    }

    /**
     * Build core snapshot keys for one Income Engine (income or family_income).
     */
    private function buildIncomeEngineCore(Request $request, string $prefix, \App\Services\IncomeEngineService $service): array
    {
        $period = $request->input($prefix . '_period') ?: 'annual';
        $valueType = $request->input($prefix . '_value_type');
        $amount = $request->filled($prefix . '_amount') ? (float) $request->input($prefix . '_amount') : null;
        $minAmount = $request->filled($prefix . '_min_amount') ? (float) $request->input($prefix . '_min_amount') : null;
        $maxAmount = $request->filled($prefix . '_max_amount') ? (float) $request->input($prefix . '_max_amount') : null;
        $currencyIdKey = $prefix . '_currency_id';
        $defaultInr = \App\Models\MasterIncomeCurrency::where('code', 'INR')->value('id');
        $currencyId = $request->input($currencyIdKey) ? (int) $request->input($currencyIdKey) : ($prefix === 'income' ? $defaultInr : null);
        if ($prefix === 'family_income' && ! $currencyId) {
            $currencyId = $defaultInr;
        }
        $normalized = $service->normalizeToAnnual($valueType, $period, $amount, $minAmount, $maxAmount);

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
            $out['income_currency_id'] = $currencyId ?: $defaultInr;
        } else {
            $out[$prefix . '_currency_id'] = $currencyId;
            $out[$prefix . '_private'] = $request->boolean($prefix . '_private');
        }
        return $out;
    }
}
