<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Models\Caste;
use App\Models\Country;
use App\Models\EducationDegree;
use App\Models\MasterComplexion;
use App\Models\MasterDiet;
use App\Models\MasterDrinkingStatus;
use App\Models\MasterMaritalStatus;
use App\Models\MasterPhysicalBuild;
use App\Models\MasterSmokingStatus;
use App\Models\Profession;
use App\Models\Religion;
use App\Models\State;
use App\Services\AuditLogService;
use App\Services\Showcase\AutoShowcaseSettings;
use App\Services\Showcase\ShowcaseAddressEligibility;
use App\Services\Showcase\ShowcaseBulkCreateSettings;
use App\Services\Showcase\ShowcaseSettings;
use App\Support\Location\AddressSchemaEnumOptions;
use App\Support\Validation\AddressHierarchyRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AutoShowcaseSettingsController extends Controller
{
    public function edit()
    {
        $religions = Religion::query()
            ->where(function ($q) {
                $q->where('is_active', true)->orWhereNull('is_active');
            })
            ->orderBy('label_en')
            ->orderBy('label')
            ->orderBy('id')
            ->get();

        $bulkPolicy = ShowcaseBulkCreateSettings::policy();
        $bulkDistricts = ShowcaseBulkCreateSettings::eligibleNonShowcaseDistrictModels();
        $countries = Country::query()->orderBy('name')->get();
        $states = State::query()->orderBy('name')->limit(600)->get();
        $castes = Caste::query()
            ->where(function ($q) {
                $q->where('is_active', true)->orWhereNull('is_active');
            })
            ->with('religion')
            ->orderBy('label_en')
            ->orderBy('label')
            ->orderBy('id')
            ->limit(4000)
            ->get();
        $maritalStatuses = MasterMaritalStatus::query()->where('is_active', true)->orderBy('label')->orderBy('id')->get();
        $diets = MasterDiet::query()->where('is_active', true)->orderBy('sort_order')->orderBy('label')->orderBy('id')->get();
        $educations = EducationDegree::query()
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('code')
            ->orderBy('id')
            ->get();
        $professions = Profession::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(2500)
            ->get();
        $complexions = MasterComplexion::query()->where('is_active', true)->orderBy('label')->orderBy('id')->get();
        $physicalBuilds = MasterPhysicalBuild::query()->where('is_active', true)->orderBy('label')->orderBy('id')->get();
        $smokingStatuses = MasterSmokingStatus::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $drinkingStatuses = MasterDrinkingStatus::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

        $addressTypeOptions = AddressSchemaEnumOptions::addressTypes();
        $addressTagOptions = AddressSchemaEnumOptions::addressTags();

        return view('admin.auto-showcase-settings.edit', [
            'engineEnabled' => AutoShowcaseSettings::engineEnabled(),
            'requireLowTotal' => AutoShowcaseSettings::requireLowTotal(),
            'minTotalResults' => AutoShowcaseSettings::minTotalResults(),
            'requireStrictLow' => AutoShowcaseSettings::requireStrictLow(),
            'strictMax' => AutoShowcaseSettings::strictMax(),
            'strictFieldKeysJson' => (string) AdminSetting::getValue(
                'auto_showcase_strict_field_keys',
                '["religion_id","caste_id","district_id","city_id","date_of_birth","marital_status_id"]'
            ),
            'residenceFallbackJson' => (string) AdminSetting::getValue(
                'auto_showcase_residence_fallback',
                '["search_city","district_seat","tagged_city"]'
            ),
            'perSearchMaxCreate' => AutoShowcaseSettings::perSearchMaxCreate(),
            'dailyUserCap' => AutoShowcaseSettings::dailyUserCap(),
            'bulkLifecycle' => AutoShowcaseSettings::bulkShowcaseLifecycle(),
            'engineLifecycle' => AutoShowcaseSettings::autoEngineShowcaseLifecycle(),
            'religions' => $religions,
            'religionAllowlistSelectedIds' => AutoShowcaseSettings::religionAllowlistIds(),
            'partnerPrefMode' => ShowcaseSettings::partnerPrefMode(),
            'globalEligibleAddressTypes' => ShowcaseAddressEligibility::globalTypes(),
            'globalEligibleAddressTags' => ShowcaseAddressEligibility::globalTags(),
            'bulkPolicy' => $bulkPolicy,
            'bulkDistricts' => $bulkDistricts,
            'bulkCountries' => $countries,
            'bulkStates' => $states,
            'bulkCastes' => $castes,
            'bulkMaritalStatuses' => $maritalStatuses,
            'bulkDiets' => $diets,
            'bulkEducations' => $educations,
            'bulkProfessions' => $professions,
            'bulkComplexions' => $complexions,
            'bulkPhysicalBuilds' => $physicalBuilds,
            'bulkSmokingStatuses' => $smokingStatuses,
            'bulkDrinkingStatuses' => $drinkingStatuses,
            'bulkNeverFillOptions' => ShowcaseBulkCreateSettings::NEVER_FILL_KEY_OPTIONS,
            'bulkRandomFillOptions' => ShowcaseBulkCreateSettings::RANDOM_FILL_KEY_OPTIONS,
            'addressTypeOptions' => $addressTypeOptions,
            'addressTagOptions' => $addressTagOptions,
        ]);
    }

    public function update(Request $request)
    {
        $addressTypeOptions = AddressSchemaEnumOptions::addressTypes();
        $addressTagOptions = AddressSchemaEnumOptions::addressTags();
        $typeIn = Rule::in($addressTypeOptions);
        $tagIn = Rule::in($addressTagOptions);

        $request->validate([
            'auto_showcase_engine_enabled' => 'nullable|in:0,1',
            'auto_showcase_require_low_total' => 'nullable|in:0,1',
            'auto_showcase_min_total_results' => 'required|integer|min:0|max:500',
            'auto_showcase_require_strict_low' => 'nullable|in:0,1',
            'auto_showcase_strict_max' => 'required|integer|min:0|max:100',
            'auto_showcase_strict_field_keys' => 'required|string|max:2000',
            'auto_showcase_residence_fallback' => 'required|string|max:500',
            'auto_showcase_per_search_max_create' => 'required|integer|min:0|max:10',
            'auto_showcase_daily_user_cap' => 'required|integer|min:0|max:100',
            'showcase_bulk_create_lifecycle' => 'required|in:draft,active',
            'showcase_auto_engine_lifecycle' => 'required|in:draft,active',
            'auto_showcase_religion_allowlist' => 'nullable|array',
            'auto_showcase_religion_allowlist.*' => 'integer|exists:master_religions,id',
            'showcase_partner_pref_mode' => 'required|in:match_searcher,rules_autofill,mixed',
            'showcase_eligible_address_types' => 'nullable|array',
            'showcase_eligible_address_types.*' => ['string', 'max:32', $typeIn],
            'showcase_eligible_address_tags' => 'nullable|array',
            'showcase_eligible_address_tags.*' => ['string', 'max:32', $tagIn],
            'bulk_eligible_address_tags' => 'nullable|array',
            'bulk_eligible_address_tags.*' => ['string', 'max:32', $tagIn],
            'bulk_religion_ids' => 'nullable|array',
            'bulk_religion_ids.*' => 'integer|exists:master_religions,id',
            'bulk_caste_ids' => 'nullable|array',
            'bulk_caste_ids.*' => 'integer|exists:master_castes,id',
            'bulk_country_ids' => 'nullable|array',
            'bulk_country_ids.*' => ['integer', AddressHierarchyRules::existsCountryId()],
            'bulk_state_ids' => 'nullable|array',
            'bulk_state_ids.*' => ['integer', AddressHierarchyRules::existsStateId()],
            'bulk_district_ids' => 'nullable|array',
            'bulk_district_ids.*' => ['integer', AddressHierarchyRules::existsDistrictId()],
            'bulk_marital_status_ids' => 'nullable|array',
            'bulk_marital_status_ids.*' => 'integer|exists:master_marital_statuses,id',
            'bulk_diet_ids' => 'nullable|array',
            'bulk_diet_ids.*' => 'integer|exists:master_diets,id',
            'bulk_master_education_ids' => 'nullable|array',
            'bulk_master_education_ids.*' => 'integer|exists:master_education,id',
            'bulk_profession_ids' => 'nullable|array',
            'bulk_profession_ids.*' => 'integer|exists:professions,id',
            'bulk_age_min' => 'nullable|integer|min:18|max:80',
            'bulk_age_max' => 'nullable|integer|min:18|max:80',
            'bulk_height_cm_min' => 'nullable|integer|min:120|max:220',
            'bulk_height_cm_max' => 'nullable|integer|min:120|max:220',
            'bulk_never_fill_keys' => 'nullable|array',
            'bulk_never_fill_keys.*' => 'string|max:64',
            'bulk_random_fill_keys' => 'nullable|array',
            'bulk_random_fill_keys.*' => 'string|max:64',
            'bulk_fixed_spectacles_lens' => 'nullable|string|max:32',
            'bulk_fixed_complexion_ids' => 'nullable|array',
            'bulk_fixed_complexion_ids.*' => 'integer|exists:master_complexions,id',
            'bulk_fixed_physical_build_ids' => 'nullable|array',
            'bulk_fixed_physical_build_ids.*' => 'integer|exists:master_physical_builds,id',
            'bulk_fixed_smoking_status_id' => 'nullable|integer|exists:master_smoking_statuses,id',
            'bulk_fixed_drinking_status_id' => 'nullable|integer|exists:master_drinking_statuses,id',
            'bulk_about_me_templates' => 'nullable|string|max:50000',
            'bulk_expectations_templates' => 'nullable|string|max:50000',
        ]);

        AdminSetting::setValue('auto_showcase_engine_enabled', $request->has('auto_showcase_engine_enabled') ? '1' : '0');
        AdminSetting::setValue('auto_showcase_require_low_total', $request->has('auto_showcase_require_low_total') ? '1' : '0');
        AdminSetting::setValue('auto_showcase_min_total_results', (string) (int) $request->input('auto_showcase_min_total_results'));
        AdminSetting::setValue('auto_showcase_require_strict_low', $request->has('auto_showcase_require_strict_low') ? '1' : '0');
        AdminSetting::setValue('auto_showcase_strict_max', (string) (int) $request->input('auto_showcase_strict_max'));
        AdminSetting::setValue('auto_showcase_strict_field_keys', $this->normalizeJsonSetting(
            (string) $request->input('auto_showcase_strict_field_keys'),
            '["religion_id","caste_id","district_id","city_id","date_of_birth","marital_status_id"]'
        ));
        AdminSetting::setValue('auto_showcase_residence_fallback', $this->normalizeJsonSetting(
            (string) $request->input('auto_showcase_residence_fallback'),
            '["search_city","district_seat","tagged_city"]'
        ));
        AdminSetting::setValue('auto_showcase_per_search_max_create', (string) (int) $request->input('auto_showcase_per_search_max_create'));
        AdminSetting::setValue('auto_showcase_daily_user_cap', (string) (int) $request->input('auto_showcase_daily_user_cap'));
        AdminSetting::setValue('showcase_bulk_create_lifecycle', (string) $request->input('showcase_bulk_create_lifecycle'));
        AdminSetting::setValue('showcase_auto_engine_lifecycle', (string) $request->input('showcase_auto_engine_lifecycle'));
        AdminSetting::setValue('auto_showcase_religion_allowlist', $this->religionAllowlistJsonFromRequest($request));
        AdminSetting::query()->where('key', 'auto_showcase_religion_blocklist')->delete();
        AdminSetting::setValue('showcase_partner_pref_mode', (string) $request->input('showcase_partner_pref_mode'));

        $globalTypes = ShowcaseAddressEligibility::normalizeTypesList($request->input('showcase_eligible_address_types', []))
            ?? ShowcaseAddressEligibility::defaultTypes();
        $globalTags = ShowcaseAddressEligibility::normalizeTagsList($request->input('showcase_eligible_address_tags', []))
            ?? ShowcaseAddressEligibility::defaultTags();
        AdminSetting::setValue(
            ShowcaseAddressEligibility::SETTING_TYPES_KEY,
            json_encode($globalTypes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        AdminSetting::setValue(
            ShowcaseAddressEligibility::SETTING_TAGS_KEY,
            json_encode($globalTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $bulkPolicy = ShowcaseBulkCreateSettings::normalize($this->buildShowcaseBulkCreatePolicyArrayFromRequest($request));
        AdminSetting::setValue(
            ShowcaseBulkCreateSettings::SETTING_KEY,
            json_encode($bulkPolicy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        AuditLogService::log(
            $request->user(),
            'update_auto_showcase_settings',
            'AdminSetting',
            null,
            'auto_showcase_* keys updated',
            false
        );

        return redirect()->route('admin.auto-showcase-settings.edit')
            ->with('success', 'Auto-showcase settings saved.');
    }

    private function religionAllowlistJsonFromRequest(Request $request): string
    {
        $ids = $request->input('auto_showcase_religion_allowlist', []);
        if (! is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildShowcaseBulkCreatePolicyArrayFromRequest(Request $request): array
    {
        return [
            'religion_ids' => $request->input('bulk_religion_ids', []),
            'caste_ids' => $request->input('bulk_caste_ids', []),
            'country_ids' => $request->input('bulk_country_ids', []),
            'state_ids' => $request->input('bulk_state_ids', []),
            'district_ids' => $request->input('bulk_district_ids', []),
            'marital_status_ids' => $request->input('bulk_marital_status_ids', []),
            'diet_ids' => $request->input('bulk_diet_ids', []),
            'master_education_ids' => $request->input('bulk_master_education_ids', []),
            'profession_ids' => $request->input('bulk_profession_ids', []),
            'age_min' => $request->input('bulk_age_min'),
            'age_max' => $request->input('bulk_age_max'),
            'height_cm_min' => $request->input('bulk_height_cm_min'),
            'height_cm_max' => $request->input('bulk_height_cm_max'),
            'never_fill_keys' => $request->input('bulk_never_fill_keys', []),
            'random_fill_keys' => $request->input('bulk_random_fill_keys', []),
            'about_me_templates' => $this->parseBulkTemplateLines((string) $request->input('bulk_about_me_templates', '')),
            'expectations_templates' => $this->parseBulkTemplateLines((string) $request->input('bulk_expectations_templates', '')),
            'fixed_spectacles_lens' => (string) $request->input('bulk_fixed_spectacles_lens', ''),
            'fixed_complexion_ids' => $request->input('bulk_fixed_complexion_ids', []),
            'fixed_physical_build_ids' => $request->input('bulk_fixed_physical_build_ids', []),
            'fixed_smoking_status_id' => $request->input('bulk_fixed_smoking_status_id'),
            'fixed_drinking_status_id' => $request->input('bulk_fixed_drinking_status_id'),
            'eligible_address_types' => [],
            'eligible_address_tags' => $request->input('bulk_eligible_address_tags', []),
        ];
    }

    /**
     * @return list<string>
     */
    private function parseBulkTemplateLines(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            if (mb_strlen($t) > 1200) {
                $t = mb_substr($t, 0, 1200);
            }
            $out[] = $t;
            if (count($out) >= 40) {
                break;
            }
        }

        return $out;
    }

    private function normalizeJsonSetting(string $raw, string $fallback): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $fallback;
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $fallback;
        }
        if (is_array($decoded)) {
            $decoded = array_map(static function ($v) {
                if (is_string($v) && strtolower(trim($v)) === 'min_population') {
                    return 'tagged_city';
                }

                return $v;
            }, $decoded);
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
