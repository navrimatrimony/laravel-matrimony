<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Models\Caste;
use App\Models\Country;
use App\Models\MasterComplexion;
use App\Models\MasterDiet;
use App\Models\MasterDrinkingStatus;
use App\Models\MasterEducation;
use App\Models\MasterMaritalStatus;
use App\Models\MasterPhysicalBuild;
use App\Models\MasterSmokingStatus;
use App\Models\Religion;
use App\Models\State;
use App\Services\AuditLogService;
use App\Services\Showcase\AutoShowcaseSettings;
use App\Services\Showcase\ShowcaseBulkCreateSettings;
use App\Services\Showcase\ShowcaseEligibleCityPopulationService;
use App\Services\Showcase\ShowcaseSettings;
use Illuminate\Http\Request;

class AutoShowcaseSettingsController extends Controller
{
    public function edit()
    {
        $populationService = app(ShowcaseEligibleCityPopulationService::class);

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
        $educations = MasterEducation::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();
        $complexions = MasterComplexion::query()->where('is_active', true)->orderBy('label')->orderBy('id')->get();
        $physicalBuilds = MasterPhysicalBuild::query()->where('is_active', true)->orderBy('label')->orderBy('id')->get();
        $smokingStatuses = MasterSmokingStatus::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $drinkingStatuses = MasterDrinkingStatus::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

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
                '["search_city","district_seat","min_population"]'
            ),
            'minPopulation' => AutoShowcaseSettings::minPopulationThreshold(),
            'perSearchMaxCreate' => AutoShowcaseSettings::perSearchMaxCreate(),
            'dailyUserCap' => AutoShowcaseSettings::dailyUserCap(),
            'bulkLifecycle' => AutoShowcaseSettings::bulkShowcaseLifecycle(),
            'engineLifecycle' => AutoShowcaseSettings::autoEngineShowcaseLifecycle(),
            'religions' => $religions,
            'religionAllowlistSelectedIds' => AutoShowcaseSettings::religionAllowlistIds(),
            'partnerPrefMode' => ShowcaseSettings::partnerPrefMode(),
            'eligibleCityPopulationCount' => $populationService->countEligible(false),
            'eligibleCityPopulationForAiCount' => $populationService->countEligible(true),
            'aiPopulationLockedDistrictCount' => count($populationService->aiLockedDistrictIds()),
            'openAiConfigured' => (string) config('services.openai.key', '') !== '',
            'bulkPolicy' => $bulkPolicy,
            'bulkDistricts' => $bulkDistricts,
            'bulkCountries' => $countries,
            'bulkStates' => $states,
            'bulkCastes' => $castes,
            'bulkMaritalStatuses' => $maritalStatuses,
            'bulkDiets' => $diets,
            'bulkEducations' => $educations,
            'bulkComplexions' => $complexions,
            'bulkPhysicalBuilds' => $physicalBuilds,
            'bulkSmokingStatuses' => $smokingStatuses,
            'bulkDrinkingStatuses' => $drinkingStatuses,
            'bulkNeverFillOptions' => ShowcaseBulkCreateSettings::NEVER_FILL_KEY_OPTIONS,
            'bulkRandomFillOptions' => ShowcaseBulkCreateSettings::RANDOM_FILL_KEY_OPTIONS,
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'auto_showcase_engine_enabled' => 'nullable|in:0,1',
            'auto_showcase_require_low_total' => 'nullable|in:0,1',
            'auto_showcase_min_total_results' => 'required|integer|min:0|max:500',
            'auto_showcase_require_strict_low' => 'nullable|in:0,1',
            'auto_showcase_strict_max' => 'required|integer|min:0|max:100',
            'auto_showcase_strict_field_keys' => 'required|string|max:2000',
            'auto_showcase_residence_fallback' => 'required|string|max:500',
            'auto_showcase_min_population' => 'required|integer|min:0|max:50000000',
            'auto_showcase_per_search_max_create' => 'required|integer|min:0|max:10',
            'auto_showcase_daily_user_cap' => 'required|integer|min:0|max:100',
            'showcase_bulk_create_lifecycle' => 'required|in:draft,active',
            'showcase_auto_engine_lifecycle' => 'required|in:draft,active',
            'auto_showcase_religion_allowlist' => 'nullable|array',
            'auto_showcase_religion_allowlist.*' => 'integer|exists:religions,id',
            'showcase_partner_pref_mode' => 'required|in:match_searcher,rules_autofill,mixed',
            'bulk_religion_ids' => 'nullable|array',
            'bulk_religion_ids.*' => 'integer|exists:religions,id',
            'bulk_caste_ids' => 'nullable|array',
            'bulk_caste_ids.*' => 'integer|exists:castes,id',
            'bulk_country_ids' => 'nullable|array',
            'bulk_country_ids.*' => 'integer|exists:countries,id',
            'bulk_state_ids' => 'nullable|array',
            'bulk_state_ids.*' => 'integer|exists:states,id',
            'bulk_district_ids' => 'nullable|array',
            'bulk_district_ids.*' => 'integer|exists:districts,id',
            'bulk_marital_status_ids' => 'nullable|array',
            'bulk_marital_status_ids.*' => 'integer|exists:master_marital_statuses,id',
            'bulk_diet_ids' => 'nullable|array',
            'bulk_diet_ids.*' => 'integer|exists:master_diets,id',
            'bulk_master_education_ids' => 'nullable|array',
            'bulk_master_education_ids.*' => 'integer|exists:master_education,id',
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
            '["search_city","district_seat","min_population"]'
        ));
        AdminSetting::setValue('auto_showcase_min_population', (string) (int) $request->input('auto_showcase_min_population'));
        AdminSetting::setValue('auto_showcase_per_search_max_create', (string) (int) $request->input('auto_showcase_per_search_max_create'));
        AdminSetting::setValue('auto_showcase_daily_user_cap', (string) (int) $request->input('auto_showcase_daily_user_cap'));
        AdminSetting::setValue('showcase_bulk_create_lifecycle', (string) $request->input('showcase_bulk_create_lifecycle'));
        AdminSetting::setValue('showcase_auto_engine_lifecycle', (string) $request->input('showcase_auto_engine_lifecycle'));
        AdminSetting::setValue('auto_showcase_religion_allowlist', $this->religionAllowlistJsonFromRequest($request));
        AdminSetting::query()->where('key', 'auto_showcase_religion_blocklist')->delete();
        AdminSetting::setValue('showcase_partner_pref_mode', (string) $request->input('showcase_partner_pref_mode'));

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

    public function fillCityPopulation(Request $request, ShowcaseEligibleCityPopulationService $populationService)
    {
        $request->validate([
            'fill_mode' => 'required|in:heuristic,ai',
            'population_fill_limit' => 'required|integer|min:1|max:500',
        ]);
        $limit = (int) $request->input('population_fill_limit');

        if ($request->input('fill_mode') === 'heuristic') {
            $n = $populationService->fillHeuristic($limit);

            return redirect()->route('admin.auto-showcase-settings.edit')
                ->with('success', "Heuristic: updated population for {$n} eligible cit".($n === 1 ? 'y' : 'ies').'.');
        }

        if ((string) config('services.openai.key', '') === '') {
            return redirect()->route('admin.auto-showcase-settings.edit')
                ->with('error', 'OPENAI_API_KEY is not set; AI population fill cannot run.');
        }

        $n = $populationService->fillWithAi($limit);
        if ($n === 0) {
            $stillAll = $populationService->countEligible(false);
            $stillAi = $populationService->countEligible(true);
            $msg = $stillAll > 0 && $stillAi === 0
                ? 'AI skipped: every remaining eligible city is in a district already processed by AI (cost lock). Use heuristic fill, or reset AI district locks below.'
                : 'AI fill did not update any rows (check logs / API response).';

            return redirect()->route('admin.auto-showcase-settings.edit')->with('error', $msg);
        }

        return redirect()->route('admin.auto-showcase-settings.edit')
            ->with('success', "AI: updated population for {$n} eligible cit".($n === 1 ? 'y' : 'ies').'. Locked those districts from repeat AI runs.');
    }

    public function resetAiPopulationDistrictLocks(Request $request)
    {
        AdminSetting::setValue('showcase_ai_population_district_ids_done', '[]');
        AuditLogService::log(
            $request->user(),
            'reset_showcase_ai_population_district_locks',
            'AdminSetting',
            null,
            'showcase_ai_population_district_ids_done cleared',
            false
        );

        return redirect()->route('admin.auto-showcase-settings.edit')
            ->with('success', 'AI district locks cleared — AI population fill can run again in those districts.');
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

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
