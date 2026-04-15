<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Services\CareerHistoryRowNormalizer;
use App\Services\FieldCatalogService;
use App\Services\PartnerPreferenceNavService;
use App\Services\PartnerPreferenceSnapshotBuilder;
use App\Services\ProfileCompletenessService;
use App\Services\ProfileCompletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
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
        'property',
        'horoscope',
        'about-me',
        'about-preferences',
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
        $profile = $this->ensureProfile($user, request());
        if (! $profile) {
            return redirect()->route('login');
        }

        $minimal = $this->isMinimalWizard();
        $first = $minimal ? FieldCatalogService::getFirstSection(true) : FieldCatalogService::getFirstSection(false);
        // Same metric as profile show + section nav: all catalog sections (not legacy 5×20% buckets).
        $pct = ProfileCompletenessService::detailedPercentage($profile);
        if ($pct >= 100) {
            session()->forget('wizard_minimal');

            return redirect()->route('matrimony.profiles.index')->with('info', __('wizard.profile_complete'));
        }

        return redirect()->route('matrimony.profile.wizard.section', ['section' => $first]);
    }

    /**
     * Show wizard section form.
     */
    public function show(Request $request, string $section)
    {
        // Legacy: personal-family was split into education-career + family-details; redirect old links
        if ($section === 'personal-family') {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'education-career'], 301);
        }
        // Legacy: location tab removed — location is captured within each relevant section.
        if ($section === 'location') {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'basic-info'], 301);
        }
        // Legacy: contacts tab removed — contact is captured within Basic info.
        if ($section === 'contacts') {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'basic-info'], 301);
        }
        // Legacy: marriages tab removed — marital engine lives under Basic info.
        if ($section === 'marriages') {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'basic-info'], 301)
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
        $profile = $this->ensureProfile($user, $request);
        if (! $profile) {
            return redirect()->route('login');
        }

        if (! \App\Services\ProfileLifecycleService::isEditableForManual($profile)) {
            return redirect()->route('matrimony.profile.show', $profile->id)->with('error', __('wizard.profile_not_editable_current_state'));
        }

        $minimal = $this->isMinimalWizard();
        if ($section === 'full') {
            if (! $request->boolean('all')) {
                return redirect()->route('matrimony.profile.wizard.section', ['section' => 'basic-info']);
            }
            session()->forget('wizard_minimal');
            $minimal = false;
            $profile->load(['religion', 'caste', 'subCaste']);
        }
        $sections = $minimal
            ? FieldCatalogService::getSectionKeys(true)
            : array_merge(['full'], FieldCatalogService::getSectionKeys(false));
        $sectionLabels = FieldCatalogService::getSectionsForDisplay($minimal);
        if (! $minimal) {
            $sectionLabels = array_merge(['full' => 'wizard.full_form'], $sectionLabels);
        }
        $nextSection = $minimal ? FieldCatalogService::getNextSection($section, true) : FieldCatalogService::getNextSection($section, false);
        if ($nextSection === null && $minimal) {
            $nextSection = 'full';
        }
        $previousSection = $minimal ? FieldCatalogService::getPreviousSection($section, true) : FieldCatalogService::getPreviousSection($section, false);

        $completionPct = ProfileCompletenessService::detailedPercentage($profile);
        $sectionStatuses = ProfileCompletionService::getSectionStatuses($profile, $sections);
        $viewData = $this->getSectionViewData($section, $profile);
        $viewData['profile'] = $profile;
        $viewData['currentSection'] = $section;
        $viewData['sections'] = $sections;
        $viewData['sectionLabels'] = $sectionLabels;
        $viewData['completionPct'] = $completionPct;
        $viewData['nextSection'] = $nextSection;
        $viewData['previousSection'] = $previousSection;
        $viewData['sectionStatuses'] = $sectionStatuses;
        $viewData['wizardMinimal'] = $minimal;

        if ($section === 'about-preferences') {
            $viewData['partnerPrefSection'] = PartnerPreferenceNavService::resolveActiveSection($request);
            $viewData['partnerPrefNavItems'] = PartnerPreferenceNavService::navItems($profile, $viewData);
        }

        return view('matrimony.profile.wizard.section', $viewData);
    }

    /**
     * Preserve ?pref= when redirecting within Partner Preferences workspace.
     *
     * @return array<string, string>
     */
    private function partnerPrefQuery(Request $request): array
    {
        return PartnerPreferenceNavService::prefQuery($request);
    }

    /**
     * Query params to preserve when redirecting back to a wizard section (GET).
     */
    private function wizardSectionRedirectQuery(Request $request, string $section): array
    {
        $q = [];
        if ($section === 'full') {
            $q['all'] = 1;
        }
        if ($section === 'about-preferences') {
            $q = array_merge($q, $this->partnerPrefQuery($request));
        }
        $q = array_merge($q, $this->wizardAdminProfileIdQuery($request));

        return $q;
    }

    /**
     * Preserve ?profile_id= when an admin is editing a showcase/demo profile (POST body may not repeat query string).
     *
     * @return array<string, string>
     */
    private function wizardAdminProfileIdQuery(Request $request): array
    {
        $user = auth()->user();
        if (! $user || ! method_exists($user, 'isAnyAdmin') || ! $user->isAnyAdmin()) {
            return [];
        }
        $targetId = (int) ($request->input('profile_id') ?? $request->query('profile_id') ?? session('admin_edit_profile_id') ?? 0);
        if ($targetId <= 0) {
            return [];
        }
        $target = MatrimonyProfile::withTrashed()->find($targetId);
        if ($target && ($target->is_demo ?? false)) {
            return ['profile_id' => (string) $target->id];
        }

        return [];
    }

    /**
     * Legacy: Return marriage-fields partial HTML for given status (old dropdown partials).
     * GET ?status=divorced|widowed|separated|married. The MaritalEngine does not use this; it is the single UI for marital+children everywhere (wizard marriages + full).
     */
    public function marriageFields(Request $request)
    {
        $profile = $this->ensureProfile(auth()->user(), $request);
        if (! $profile) {
            return response('', 403);
        }

        $allowed = ['divorced', 'widowed', 'separated', 'married'];
        $status = $request->query('status');
        if (! in_array($status, $allowed, true)) {
            return response('', 400);
        }

        $marriage = \App\Models\ProfileMarriage::where('profile_id', $profile->id)->orderBy('id')->first();
        $view = 'matrimony.profile.wizard.sections.marriage_partials.marriages_'.$status;

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
        // Legacy: marriages section removed — do not accept POST here.
        if ($section === 'marriages') {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('info', __('wizard.marriages_location_removed'));
        }
        // Legacy: location section removed — do not accept POST here.
        if ($section === 'location') {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'basic-info']);
        }
        // Legacy: contacts section removed — do not accept POST here.
        if ($section === 'contacts') {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'basic-info']);
        }

        $allowed = $this->getAllowedSectionKeys();
        if (! in_array($section, $allowed, true)) {
            $minimal = $this->isMinimalWizard();
            $first = $minimal ? FieldCatalogService::getFirstSection(true) : FieldCatalogService::getFirstSection(false);

            return redirect()->route('matrimony.profile.wizard.section', ['section' => $first])
                ->with('error', __('wizard.invalid_section'));
        }

        $user = auth()->user();
        $profile = $this->ensureProfile($user, $request);
        if (! $profile) {
            return redirect()->route('login');
        }

        if (! \App\Services\ProfileLifecycleService::isEditableForManual($profile)) {
            return redirect()->route('matrimony.profile.show', $profile->id)->with('error', __('wizard.profile_not_editable_current_state'));
        }

        // Photo section: no direct upload in wizard; user uses centralized upload engine. Save & Next without file = skip to next.
        if ($section === 'photo' && ! $request->hasFile('profile_photo')) {
            $minimal = $this->isMinimalWizard();
            $next = $minimal ? FieldCatalogService::getNextSection($section, true) : FieldCatalogService::getNextSection($section, false);
            if ($next) {
                return redirect()->route('matrimony.profile.wizard.section', array_merge(['section' => $next], $this->wizardSectionRedirectQuery($request, $next)))
                    ->with('info', 'Use the photo upload engine above to add or change your photo.');
            }

            return redirect()->route('matrimony.profiles.index')->with('info', 'You can add a photo anytime from the photo section.');
        }

        $snapshot = $this->buildSectionSnapshot($section, $request, $profile);
        \Log::info('DEBUG SNAPSHOT', $snapshot ?? []);
        \Log::info('DEBUG SNAPSHOT FULL', $snapshot ?? []);

        if ($snapshot === null) {
            return redirect()->route('matrimony.profile.wizard.section', array_merge(['section' => $section], $this->wizardSectionRedirectQuery($request, $section)))
                ->with('error', 'Invalid section or no data.')
                ->withInput();
        }

        // Alliance free-text "other_relatives_text" is now governed via snapshot CORE.
        if ($section === 'alliance' && \Schema::hasColumn('matrimony_profiles', 'other_relatives_text')) {
            $snapshot['core']['other_relatives_text'] = trim((string) $request->input('other_relatives_text', '')) ?: null;
        }

        try {
            $result = app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');
            if ($request->attributes->get('matrimony_apply_pending_photo_review')) {
                app(\App\Services\Image\ProfilePhotoPendingStateService::class)->applyPendingReviewState($profile);
            }
            \Illuminate\Support\Facades\Log::info('WIZARD RESULT DEBUG', ['result' => $result, 'keys' => array_keys($result)]);
            $hasChildrenNo = isset($snapshot['core']['has_children']) && ($snapshot['core']['has_children'] === false || $snapshot['core']['has_children'] === 0 || $snapshot['core']['has_children'] === '0');
            if (in_array($section, ['basic-info', 'marriages', 'full'], true) && $hasChildrenNo) {
                DB::table('profile_children')->where('profile_id', $profile->id)->delete();
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->route('matrimony.profile.wizard.section', array_merge(['section' => $section], $this->wizardSectionRedirectQuery($request, $section)))
                ->withErrors($e->errors())
                ->withInput();
        } catch (\RuntimeException $e) {
            return redirect()->route('matrimony.profile.wizard.section', array_merge(['section' => $section], $this->wizardSectionRedirectQuery($request, $section)))
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

        if ($section === 'about-preferences') {
            \App\Services\ProfilePartnerCommunityFlagService::syncIntercasteIntentFromRequest((int) $profile->id, $request);
        }

        if ($result['conflict_detected'] ?? false) {
            return redirect()->route('matrimony.profile.wizard.section', array_merge(['section' => $section], $this->wizardSectionRedirectQuery($request, $section)))
                ->with('warning', 'Some changes could not be applied due to conflicts.')
                ->withInput();
        }

        if ($request->boolean('save_only')) {
            return redirect()->route('matrimony.profile.wizard.section', array_merge(['section' => $section], $this->wizardSectionRedirectQuery($request, $section)))
                ->with('success', __('wizard.saved'));
        }

        $minimal = $this->isMinimalWizard();
        $next = $minimal ? FieldCatalogService::getNextSection($section, true) : FieldCatalogService::getNextSection($section, false);
        if ($next) {
            return redirect()->route('matrimony.profile.wizard.section', array_merge(['section' => $next], $this->wizardSectionRedirectQuery($request, $next)))
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
     * Ensure user has a matrimony profile. Create minimal one if not (full_name from registrant only when registering_for = self).
     */
    private function ensureProfile($user, ?Request $request = null): ?MatrimonyProfile
    {
        if (! $user) {
            return null;
        }

        // Admin override: allow using the wizard engine for a specific showcase/demo profile (SSOT).
        if ($request && method_exists($user, 'isAnyAdmin') && $user->isAnyAdmin()) {
            $targetId = (int) ($request->input('profile_id') ?? $request->query('profile_id') ?? 0);
            if ($targetId <= 0) {
                $targetId = (int) (session('admin_edit_profile_id') ?? 0);
            }
            if ($targetId > 0) {
                $target = MatrimonyProfile::withTrashed()->find($targetId);
                if ($target && ($target->is_demo ?? false)) {
                    session(['admin_edit_profile_id' => (int) $target->id]);

                    return $target;
                }
            }
        }

        $profile = $user->matrimonyProfile;
        if ($profile) {
            return $profile;
        }
        $manualActivation = \App\Services\Admin\AdminSettingService::isManualProfileActivationRequired();
        $genderId = null;
        if (! empty($user->gender)) {
            $genderId = \App\Models\MasterGender::where('key', $user->gender)->where('is_active', true)->value('id');
        }
        $profile = MatrimonyProfile::create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
            'full_name' => $user->defaultBootstrapProfileFullName(),
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
                // Basic Information Engine: full_name, gender_id, date_of_birth, birth_time, birth_place, religion_id, caste_id, sub_caste_id, marital_status_id
                $data['talukasByDistrict'] = \App\Models\Taluka::all()->groupBy('district_id')->map(fn ($col) => $col->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->toArray())->toArray();
                $data['districtsByState'] = \App\Models\District::all()->groupBy('state_id')->map(fn ($col) => $col->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values()->toArray())->toArray();
                $data['stateIdToCountryId'] = \App\Models\State::all()->pluck('country_id', 'id')->toArray();
                $data['genders'] = \App\Models\MasterGender::where('is_active', true)->whereIn('key', ['male', 'female'])->orderByRaw("CASE WHEN `key` = 'male' THEN 1 ELSE 2 END")->get();
                // Build full birth place string (city, taluka, district, state) when any ID is set so wizard shows as in intake.
                $data['birthPlaceDisplay'] = $this->buildBirthPlaceDisplay($profile);
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
                $profileEducation = DB::table('profile_education')->where('profile_id', $profile->id)->orderBy('id')->get();
                // Dedupe education rows (same degree/specialization/university/year) so full form does not show duplicate blocks.
                $seen = [];
                $data['profileEducation'] = $profileEducation->filter(function ($r) use (&$seen) {
                    $key = implode('|', [trim((string) ($r->degree ?? '')), trim((string) ($r->specialization ?? '')), trim((string) ($r->university ?? '')), (string) ($r->year_completed ?? '')]);
                    if (isset($seen[$key])) {
                        return false;
                    }
                    $seen[$key] = true;

                    return true;
                })->values();
                $profileCareer = DB::table('profile_career')->where('profile_id', $profile->id)->orderBy('id')->get();
                // When first career row has no location but profile has work_location_text (e.g. from intake), show it in wizard.
                if ($profileCareer->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasColumn('matrimony_profiles', 'work_location_text')) {
                    $first = $profileCareer->first();
                    if (empty($first->location) && ! empty(trim((string) ($profile->work_location_text ?? '')))) {
                        $first->location = $profile->work_location_text;
                    }
                }
                $data['profileCareer'] = $profileCareer;
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
                    $spouse = $s->spouse ? (object) array_merge($s->spouse->toArray(), ['location_display' => $s->spouse->city?->name ?? $s->spouse->address_line ?? '']) : null;
                    $locationDisplay = $s->city?->name ?? '';
                    if ($locationDisplay === '' && ! empty(trim((string) ($s->notes ?? '')))) {
                        $locationDisplay = $s->notes;
                    }
                    $arr = array_merge($s->toArray(), [
                        'relation_type' => $relationType,
                        'location_display' => $locationDisplay,
                        'spouse' => $spouse,
                    ]);
                    // profile_siblings has only notes (no address_line); show notes in Address field for display.
                    if (empty($arr['address_line']) && ! empty(trim((string) ($arr['notes'] ?? '')))) {
                        $arr['address_line'] = $arr['notes'];
                    }
                    // When spouse exists with data, show Married: Yes in wizard even if marital_status was not synced.
                    if ($spouse && (trim((string) ($spouse->name ?? '')) !== '' || trim((string) ($spouse->address_line ?? '')) !== '' || trim((string) ($spouse->occupation_title ?? '')) !== '')) {
                        $arr['marital_status'] = 'married';
                    }

                    return (object) $arr;
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
                    // profile_relatives has no address_line column; we store address in notes — show in Address field for display.
                    if (empty($arr['address_line']) && ! empty(trim((string) ($arr['notes'] ?? '')))) {
                        $arr['address_line'] = $arr['notes'];
                    }

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
                    if (empty($arr['address_line']) && ! empty(trim((string) ($arr['notes'] ?? '')))) {
                        $arr['address_line'] = $arr['notes'];
                    }

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
                $profile->loadMissing(['city', 'nativeCity', 'addresses.village']);
                $data['profileAddresses'] = $profile->addresses;
                $data['workCityName'] = $profile->work_city_id ? \App\Models\City::where('id', $profile->work_city_id)->value('name') : '';
                $data['nativePlaceDisplay'] = $profile->native_city_id ? \App\Models\City::where('id', $profile->native_city_id)->value('name') : '';
                $data['residencePlaceDisplay'] = old('wizard_residence_display', $profile->residenceLocationDisplayLine());
                $data['workPlaceDisplay'] = old('wizard_work_place_display', $data['workCityName'] ?? '');
                $data['nativePlaceTypeaheadDisplay'] = old('wizard_native_place_display', $data['nativePlaceDisplay'] ?? '');
                $data['talukasByDistrict'] = \App\Models\Taluka::all()->groupBy('district_id')->map(fn ($col) => $col->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->toArray())->toArray();
                $data['districtsByState'] = \App\Models\District::all()->groupBy('state_id')->map(fn ($col) => $col->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values()->toArray())->toArray();
                $data['stateIdToCountryId'] = \App\Models\State::all()->pluck('country_id', 'id')->toArray();
                break;
            case 'property':
                $data['profile_property_summary'] = DB::table('profile_property_summary')->where('profile_id', $profile->id)->first();
                $allAssets = DB::table('profile_property_assets')->where('profile_id', $profile->id)->orderBy('id')->get();
                // Exclude fully empty asset rows so we do not show duplicate empty blocks (engine still adds one empty row if none).
                $data['profile_property_assets'] = $allAssets->filter(function ($r) {
                    $hasType = ! empty($r->asset_type_id ?? null);
                    $hasLoc = trim((string) ($r->location ?? '')) !== '';
                    $hasOwn = ! empty($r->ownership_type_id ?? null);

                    return $hasType || $hasLoc || $hasOwn;
                })->values();
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
                $preferredCountryIds = Schema::hasTable('profile_preferred_countries')
                    ? DB::table('profile_preferred_countries')->where('profile_id', $profile->id)->pluck('country_id')->all()
                    : [];
                $preferredStateIds = Schema::hasTable('profile_preferred_states')
                    ? DB::table('profile_preferred_states')->where('profile_id', $profile->id)->pluck('state_id')->all()
                    : [];
                $preferredTalukaIds = Schema::hasTable('profile_preferred_talukas')
                    ? DB::table('profile_preferred_talukas')->where('profile_id', $profile->id)->pluck('taluka_id')->all()
                    : [];
                $preferredMasterEducationIds = Schema::hasTable('profile_preferred_master_education')
                    ? DB::table('profile_preferred_master_education')->where('profile_id', $profile->id)->pluck('master_education_id')->all()
                    : [];
                $preferredWorkingWithTypeIds = Schema::hasTable('profile_preferred_working_with_types')
                    ? DB::table('profile_preferred_working_with_types')->where('profile_id', $profile->id)->pluck('working_with_type_id')->all()
                    : [];
                $preferredProfessionIds = Schema::hasTable('profile_preferred_professions')
                    ? DB::table('profile_preferred_professions')->where('profile_id', $profile->id)->pluck('profession_id')->all()
                    : [];
                $preferredDietIds = Schema::hasTable('profile_preferred_diets')
                    ? DB::table('profile_preferred_diets')->where('profile_id', $profile->id)->pluck('diet_id')->all()
                    : [];
                $preferredMaritalStatusIdsFromDb = Schema::hasTable('profile_preferred_marital_statuses')
                    ? DB::table('profile_preferred_marital_statuses')->where('profile_id', $profile->id)->pluck('marital_status_id')->all()
                    : [];

                $suggestions = \App\Services\PartnerPreferenceSuggestionService::suggestForProfile($profile);

                $wasCompletelyEmpty = ! $criteria && empty($preferredReligionIds) && empty($preferredCasteIds) && empty($preferredDistrictIds)
                    && empty($preferredCountryIds) && empty($preferredStateIds) && empty($preferredTalukaIds)
                    && empty($preferredMasterEducationIds) && empty($preferredWorkingWithTypeIds) && empty($preferredProfessionIds)
                    && empty($preferredDietIds) && empty($preferredMaritalStatusIdsFromDb)
                    && ($criteria?->preferred_marital_status_id ?? null) === null;

                $merged = \App\Services\PartnerPreferenceSuggestionService::mergePartnerPreferencesForDisplay(
                    $profile,
                    $criteria,
                    $preferredReligionIds,
                    $preferredCasteIds,
                    $preferredCountryIds,
                    $preferredStateIds,
                    $preferredDistrictIds,
                    $preferredTalukaIds,
                    $preferredDietIds,
                    $preferredMaritalStatusIdsFromDb
                );
                $criteria = $merged['criteria'];
                $preferredReligionIds = $merged['preferredReligionIds'];
                $preferredCasteIds = $merged['preferredCasteIds'];
                $preferredCountryIds = $merged['preferredCountryIds'];
                $preferredStateIds = $merged['preferredStateIds'];
                $preferredDistrictIds = $merged['preferredDistrictIds'];
                $preferredTalukaIds = $merged['preferredTalukaIds'];
                $preferredDietIds = $merged['preferredDietIds'];
                $preferredMaritalStatusIdsMerged = $merged['preferredMaritalStatusIds'] ?? [];

                $data['preferencePreset'] = $wasCompletelyEmpty ? ($suggestions['preference_preset'] ?? 'balanced') : 'custom';

                $base = $suggestions;
                $base['preferred_income_min'] = null;
                $base['preferred_income_max'] = null;
                if (! empty($base['preferred_city_id'])) {
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
                $data['preferredCountryIds'] = $preferredCountryIds;
                $data['preferredStateIds'] = $preferredStateIds;
                $data['preferredTalukaIds'] = $preferredTalukaIds;
                $data['preferredMasterEducationIds'] = $preferredMasterEducationIds;
                $data['preferredWorkingWithTypeIds'] = $preferredWorkingWithTypeIds;
                $data['preferredProfessionIds'] = $preferredProfessionIds;
                $data['preferredDietIds'] = $preferredDietIds;
                $data['partnerDietOptions'] = \App\Models\MasterDiet::where('is_active', true)->orderBy('sort_order')->orderBy('label')->get();

                $preferredCountryIds = array_values(array_unique(array_map('intval', $preferredCountryIds)));
                $preferredStateIds = array_values(array_unique(array_map('intval', $preferredStateIds)));
                $preferredDistrictIds = array_values(array_unique(array_map('intval', $preferredDistrictIds)));
                $preferredTalukaIds = array_values(array_unique(array_map('intval', $preferredTalukaIds)));

                $data['allCountries'] = \App\Models\Country::query()->orderBy('name')->get();
                $data['partnerLocationInitialStates'] = $preferredStateIds !== [] || $preferredCountryIds !== []
                    ? \App\Models\State::query()
                        ->where(function ($q) use ($preferredCountryIds, $preferredStateIds) {
                            if ($preferredCountryIds !== []) {
                                $q->whereIn('country_id', $preferredCountryIds);
                            }
                            if ($preferredStateIds !== []) {
                                $q->orWhereIn('id', $preferredStateIds);
                            }
                        })
                        ->orderBy('name')
                        ->get()
                    : collect();
                $data['partnerLocationInitialDistricts'] = $preferredDistrictIds !== [] || $preferredStateIds !== []
                    ? \App\Models\District::query()
                        ->where(function ($q) use ($preferredStateIds, $preferredDistrictIds) {
                            if ($preferredStateIds !== []) {
                                $q->whereIn('state_id', $preferredStateIds);
                            }
                            if ($preferredDistrictIds !== []) {
                                $q->orWhereIn('id', $preferredDistrictIds);
                            }
                        })
                        ->orderBy('name')
                        ->get()
                    : collect();
                $data['partnerLocationInitialTalukas'] = $preferredTalukaIds !== [] || $preferredDistrictIds !== []
                    ? \App\Models\Taluka::query()
                        ->where(function ($q) use ($preferredDistrictIds, $preferredTalukaIds) {
                            if ($preferredDistrictIds !== []) {
                                $q->whereIn('district_id', $preferredDistrictIds);
                            }
                            if ($preferredTalukaIds !== []) {
                                $q->orWhereIn('id', $preferredTalukaIds);
                            }
                        })
                        ->orderBy('name')
                        ->get()
                    : collect();
                $data['partnerLocationStateById'] = $data['partnerLocationInitialStates']->mapWithKeys(
                    fn ($s) => [$s->id => ['id' => $s->id, 'name' => $s->name, 'country_id' => $s->country_id]]
                )->all();
                $data['partnerLocationDistrictById'] = $data['partnerLocationInitialDistricts']->mapWithKeys(
                    fn ($d) => [$d->id => ['id' => $d->id, 'name' => $d->name, 'state_id' => $d->state_id]]
                )->all();
                $data['partnerLocationTalukaById'] = $data['partnerLocationInitialTalukas']->mapWithKeys(
                    fn ($t) => [$t->id => ['id' => $t->id, 'name' => $t->name, 'district_id' => $t->district_id]]
                )->all();
                $data['partnerLocationApiBase'] = url('/api/internal/location');

                $partnerProfessions = \App\Models\Profession::where('is_active', true)->with('workingWithType')->orderBy('sort_order')->orderBy('name')->get();
                $data['masterEducationOptions'] = \App\Models\MasterEducation::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
                $data['workingWithTypes'] = \App\Models\WorkingWithType::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
                $data['partnerProfessions'] = $partnerProfessions;
                $data['partnerProfessionsByWorkingWithType'] = $partnerProfessions->groupBy('working_with_type_id')->map(
                    fn ($group) => $group->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'working_with_type_id' => $p->working_with_type_id])->values()->all()
                )->all();
                $data['partnerProfessionById'] = $partnerProfessions->keyBy('id')->map(
                    fn ($p) => ['id' => $p->id, 'name' => $p->name, 'working_with_type_id' => $p->working_with_type_id]
                )->all();

                $data['preferredMaritalStatusIds'] = collect(old('preferred_marital_status_ids', $preferredMaritalStatusIdsMerged))
                    ->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values()->all();
                $data['preferredMaritalStatusId'] = count($data['preferredMaritalStatusIds']) === 1
                    ? $data['preferredMaritalStatusIds'][0]
                    : null;

                $data['neverMarriedMaritalStatusId'] = \App\Models\MasterMaritalStatus::where('key', 'never_married')->where('is_active', true)->value('id');
                $data['partnerProfileWithChildren'] = old(
                    'partner_profile_with_children',
                    $criteria->partner_profile_with_children ?? null
                );

                $data['allReligions'] = \App\Models\Religion::where('is_active', true)->orderBy('label')->get();
                $partnerCastes = \App\Models\Caste::where('is_active', true)->orderBy('label')->get();
                $data['partnerCastesByReligion'] = $partnerCastes->groupBy('religion_id')->map(
                    fn ($group) => $group->map(fn ($c) => ['id' => $c->id, 'label' => $c->display_label])->values()->all()
                )->all();
                $data['partnerCasteById'] = $partnerCastes->keyBy('id')->map(
                    fn ($c) => ['id' => $c->id, 'religion_id' => $c->religion_id, 'label' => $c->display_label]
                )->all();
                $data['marriageTypePreferences'] = \App\Models\MasterMarriageTypePreference::where('is_active', true)->orderBy('sort_order')->get();
                $data['allMaritalStatuses'] = \App\Models\MasterMaritalStatus::where('is_active', true)->orderBy('label')->get();
                $data['interestedInIntercaste'] = \App\Services\ProfilePartnerCommunityFlagService::interestedInIntercaste($profile->id);
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
     * Build birth place display string from profile IDs (city, taluka, district, state) so wizard shows as in intake.
     */
    private function buildBirthPlaceDisplay(MatrimonyProfile $profile): string
    {
        if (! $profile->birth_city_id && ! $profile->birth_taluka_id && ! $profile->birth_district_id && ! $profile->birth_state_id) {
            return trim((string) ($profile->birth_place_text ?? ''));
        }
        $parts = [];
        if ($profile->birth_city_id) {
            $parts[] = \App\Models\City::where('id', $profile->birth_city_id)->value('name') ?? '';
        }
        if ($profile->birth_taluka_id) {
            $parts[] = \App\Models\Taluka::where('id', $profile->birth_taluka_id)->value('name') ?? '';
        }
        if ($profile->birth_district_id) {
            $parts[] = \App\Models\District::where('id', $profile->birth_district_id)->value('name') ?? '';
        }
        if ($profile->birth_state_id) {
            $parts[] = \App\Models\State::where('id', $profile->birth_state_id)->value('name') ?? '';
        }

        return implode(', ', array_filter($parts));
    }

    /**
     * Same snapshot builder as wizard `store()` — used by card onboarding to reuse validation + core shape.
     */
    public function buildSnapshotForSection(Request $request, string $section, MatrimonyProfile $profile): ?array
    {
        return $this->buildSectionSnapshot($section, $request, $profile);
    }

    /**
     * Physical + residence core for manual snapshot (legacy onboarding step 5; wizard physical section may still compose similarly).
     */
    public function buildOnboardingPhysicalAddressSnapshot(Request $request, MatrimonyProfile $profile): array
    {
        $this->validateResidenceCoreForSnapshot($request);
        $physical = $this->buildPhysicalSnapshot($request, $profile);
        $res = $this->residenceCoreFromRequest($request);
        $physical['core'] = array_merge($physical['core'], $res);

        return $physical;
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

        $this->validateResidenceCoreForSnapshot($request);

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

        // Current residence (district/taluka/city + address line) — same fields as location snapshot; rendered on Basic info.
        $core = array_merge($core, $this->residenceCoreFromRequest($request));

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

    /**
     * First non-empty residence value across flat input, core[...], and snapshot[core][...].
     * Important: if request has core[] for any reason but city_id is only posted flat, we must not ignore flat keys.
     */
    private function residenceScalarFromRequest(Request $request, string $key): mixed
    {
        foreach ([
            $request->input($key),
            data_get($request->all(), 'core.'.$key),
            data_get($request->all(), 'snapshot.core.'.$key),
        ] as $v) {
            if ($v !== null && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    /**
     * Residence CORE fields from request (wizard: flat; intake: snapshot[core][...]).
     */
    private function residenceCoreFromRequest(Request $request): array
    {
        $toInt = fn ($v) => $v !== null && $v !== '' ? (int) $v : null;
        $addr = $this->residenceScalarFromRequest($request, 'address_line');
        $addressLine = ($addr !== null && trim((string) $addr) !== '') ? trim((string) $addr) : null;

        return [
            'country_id' => $toInt($this->residenceScalarFromRequest($request, 'country_id')),
            'state_id' => $toInt($this->residenceScalarFromRequest($request, 'state_id')),
            'district_id' => $toInt($this->residenceScalarFromRequest($request, 'district_id')),
            'taluka_id' => $toInt($this->residenceScalarFromRequest($request, 'taluka_id')),
            'city_id' => $toInt($this->residenceScalarFromRequest($request, 'city_id')),
            'address_line' => $addressLine,
        ];
    }

    private function validateResidenceCoreForSnapshot(Request $request): void
    {
        $keys = ['country_id', 'state_id', 'district_id', 'taluka_id', 'city_id', 'address_line'];
        $data = [];
        foreach ($keys as $k) {
            $data[$k] = $this->residenceScalarFromRequest($request, $k);
        }
        Validator::make($data, [
            'country_id' => ['nullable', 'exists:countries,id'],
            'state_id' => ['nullable', 'exists:states,id'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'taluka_id' => ['nullable', 'exists:talukas,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'address_line' => ['nullable', 'string', 'max:255'],
        ])->validate();
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
        if (Schema::hasColumn('matrimony_profiles', 'work_location_text')) {
            $wlt = trim((string) $request->input('work_location_text', ''));
            $core['work_location_text'] = $wlt !== '' ? mb_substr($wlt, 0, 255) : null;
        }
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
            if (! is_array($row)) {
                continue;
            }
            $normalized = CareerHistoryRowNormalizer::fromRequestRowOrNull($row);
            if ($normalized !== null) {
                $career_history[] = $normalized;
            }
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
            if (! is_array($row)) {
                continue;
            }
            $normalized = CareerHistoryRowNormalizer::fromRequestRowOrNull($row);
            if ($normalized !== null) {
                $career_history[] = $normalized;
            }
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
        $this->validateResidenceCoreForSnapshot($request);
        $request->validate([
            'work_city_id' => ['nullable', 'exists:cities,id'],
            'work_state_id' => ['nullable', 'exists:states,id'],
        ]);

        $core = $this->residenceCoreFromRequest($request);
        $core['work_city_id'] = $request->filled('work_city_id') ? (int) $request->input('work_city_id') : null;
        $core['work_state_id'] = $request->filled('work_state_id') ? (int) $request->input('work_state_id') : null;
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
        return [
            'preferences' => PartnerPreferenceSnapshotBuilder::validateAndBuildRow($request),
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
        Log::info('UPLOAD ENTRY HIT', [
            'controller' => __METHOD__,
            'user_id' => auth()->id() ?? null,
        ]);

        $request->validate([
            'profile_photo' => ['required', 'image', 'max:2048'],
        ]);

        $file = $request->file('profile_photo');
        $pending = app(\App\Services\Image\ImageProcessingService::class)
            ->enqueueProfilePhotoProcessing($file, (int) $profile->id);

        $request->attributes->set('matrimony_apply_pending_photo_review', true);

        return [
            'core' => [
                'profile_photo' => $pending,
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
            $key = $i === 0 ? 'primary_contact_number' : 'primary_contact_number_'.($i + 1);
            $whatsappKey = $i === 0 ? 'primary_contact_whatsapp' : 'primary_contact_whatsapp_'.($i + 1);
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
            'marriages.*.marriage_year' => ['nullable', 'integer', 'min:1901', 'max:'.(int) date('Y')],
            'marriages.*.separation_year' => ['nullable', 'integer', 'min:1901', 'max:'.(int) date('Y')],
            'marriages.*.divorce_year' => ['nullable', 'integer', 'min:1901', 'max:'.(int) date('Y')],
            'marriages.*.spouse_death_year' => ['nullable', 'integer', 'min:1901', 'max:'.(int) date('Y')],
        ];
        if ($statusKey && in_array($statusKey, $statusesRequiringChildren, true)) {
            $rules['has_children'] = ['nullable', 'in:0,1'];
        }
        $request->validate($rules);

        if ($statusKey && in_array($statusKey, $statusesRequiringChildren, true)) {
            $hasChildren = $request->input('has_children');
            $hasChildrenYes = $hasChildren === '1' || $hasChildren === 1 || $hasChildren === true;
            if ($hasChildrenYes) {
                $request->validate([
                    'children' => ['nullable', 'array'],
                    'children.*.gender' => ['nullable', 'in:male,female,other,prefer_not_say'],
                    'children.*.age' => ['nullable', 'integer', 'min:1', 'max:120'],
                    'children.*.child_living_with_id' => ['nullable'],
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
                $gender = trim((string) ($row['gender'] ?? ''));
                $ageRaw = $row['age'] ?? null;
                $livingWithIdRaw = $row['child_living_with_id'] ?? null;
                $hasAnyChildValue = $gender !== ''
                    || ($ageRaw !== null && $ageRaw !== '')
                    || ($livingWithIdRaw !== null && $livingWithIdRaw !== '');
                if (! $hasAnyChildValue) {
                    continue;
                }
                $children[] = [
                    'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                    'child_name' => null,
                    'gender' => $gender !== '' ? $gender : null,
                    'age' => $ageRaw !== null && $ageRaw !== '' ? (int) $ageRaw : null,
                    'child_living_with_id' => ! empty($livingWithIdRaw) ? (int) $livingWithIdRaw : null,
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
        $vt = $request->input($prefix.'_value_type');
        $rules = [
            $prefix.'_period' => 'nullable|in:annual,monthly,weekly,daily',
            $prefix.'_value_type' => 'nullable|in:exact,approximate,range,undisclosed',
            $prefix.'_currency_id' => 'nullable|exists:master_income_currencies,id',
            $prefix.'_private' => 'nullable|boolean',
        ];
        if ($prefix === 'family_income') {
            $rules[$prefix.'_currency_id'] = 'nullable|exists:master_income_currencies,id';
        }
        if (in_array($vt, ['exact', 'approximate'], true)) {
            $rules[$prefix.'_amount'] = 'required|numeric|min:0';
        }
        if ($vt === 'range') {
            $rules[$prefix.'_min_amount'] = 'required|numeric|min:0';
            $rules[$prefix.'_max_amount'] = 'required|numeric|min:0|gte:'.$prefix.'_min_amount';
        }

        return $rules;
    }

    /**
     * Build core snapshot keys for one Income Engine (income or family_income).
     */
    private function buildIncomeEngineCore(Request $request, string $prefix, \App\Services\IncomeEngineService $service): array
    {
        $period = $request->input($prefix.'_period') ?: 'annual';
        $valueType = $request->input($prefix.'_value_type');
        $amount = $request->filled($prefix.'_amount') ? (float) $request->input($prefix.'_amount') : null;
        $minAmount = $request->filled($prefix.'_min_amount') ? (float) $request->input($prefix.'_min_amount') : null;
        $maxAmount = $request->filled($prefix.'_max_amount') ? (float) $request->input($prefix.'_max_amount') : null;
        $currencyIdKey = $prefix.'_currency_id';
        $defaultInr = \App\Models\MasterIncomeCurrency::where('code', 'INR')->value('id');
        $currencyId = $request->input($currencyIdKey) ? (int) $request->input($currencyIdKey) : ($prefix === 'income' ? $defaultInr : null);
        if ($prefix === 'family_income' && ! $currencyId) {
            $currencyId = $defaultInr;
        }
        $normalized = $service->normalizeToAnnual($valueType, $period, $amount, $minAmount, $maxAmount);

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
            $out['income_currency_id'] = $currencyId ?: $defaultInr;
        } else {
            $out[$prefix.'_currency_id'] = $currencyId;
            $out[$prefix.'_private'] = $request->boolean($prefix.'_private');
        }

        return $out;
    }
}
