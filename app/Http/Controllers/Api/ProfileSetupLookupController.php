<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caste;
use App\Models\EducationDegree;
use App\Models\MasterBloodGroup;
use App\Models\MasterComplexion;
use App\Models\MasterDiet;
use App\Models\MasterDrinkingStatus;
use App\Models\MasterFamilyType;
use App\Models\MasterGan;
use App\Models\MasterIncomeCurrency;
use App\Models\MasterMangalDoshType;
use App\Models\MasterMarriageTypePreference;
use App\Models\MasterNadi;
use App\Models\MasterNakshatra;
use App\Models\MasterRashi;
use App\Models\MasterYoni;
use App\Models\MasterMaritalStatus;
use App\Models\MasterMotherTongue;
use App\Models\MasterPhysicalBuild;
use App\Models\MasterSmokingStatus;
use App\Models\OccupationCategory;
use App\Models\OccupationCustom;
use App\Models\OccupationMaster;
use App\Models\Religion;
use App\Services\HoroscopeRuleService;
use App\Support\LocalizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProfileSetupLookupController extends Controller
{
    /** @see \App\Support\MaritalDependencyRules::ALL_STATUS_KEYS — canonical source. */
    private const MARITAL_STATUS_KEYS = \App\Support\MaritalDependencyRules::ALL_STATUS_KEYS;

    private const SPECTACLES_KEYS = ['no', 'spectacles', 'contact_lens', 'both'];

    private const PHYSICAL_CONDITION_KEYS = [
        'none',
        'physically_challenged',
        'hearing_condition',
        'vision_condition',
        'other',
        'prefer_not_to_say',
    ];

    private const PARTNER_PROFILE_WITH_CHILDREN_KEYS = ['no', 'yes_if_live_separate', 'yes'];

    private const PREFERRED_PROFILE_MANAGED_BY_KEYS = ['', 'self', 'parent_guardian', 'sibling', 'relative', 'friend', 'other'];

    /**
     * GET /api/v1/profile/basic-physical-options
     * Read-only mobile options for Phase 1A profile setup fields.
     */
    public function basicPhysicalOptions(): JsonResponse
    {
        return response()->json([
            'mother_tongues' => $this->masterOptions(MasterMotherTongue::class, 'master_mother_tongues', ['sort_order', 'label', 'id']),
            'complexions' => $this->masterOptions(MasterComplexion::class, 'master_complexions', ['id']),
            'blood_groups' => $this->masterOptions(MasterBloodGroup::class, 'master_blood_groups', ['id']),
            'physical_builds' => $this->masterOptions(MasterPhysicalBuild::class, 'master_physical_builds', ['id']),
            'spectacles_lens' => $this->enumOptions('components.physical.spectacles_options', self::SPECTACLES_KEYS),
            'physical_conditions' => $this->enumOptions('components.physical.condition_options', self::PHYSICAL_CONDITION_KEYS),
        ]);
    }

    /**
     * GET /api/v1/profile/education-career-options
     * Read-only mobile options for APK Edit All Education + Career fields.
     */
    public function educationCareerOptions(Request $request): JsonResponse
    {
        return response()->json([
            'education_degrees' => $this->educationDegreeOptions(),
            'occupation_categories' => $this->occupationCategoryOptions(),
            'occupations' => $this->occupationOptions(),
            'custom_occupations' => $this->customOccupationOptions((int) $request->user()->id),
            'currencies' => $this->incomeCurrencyOptions(),
        ]);
    }

    /**
     * GET /api/v1/profile/marital-lifestyle-options
     * Read-only mobile options for APK Edit All Marital + Lifestyle fields.
     */
    public function maritalLifestyleOptions(): JsonResponse
    {
        return response()->json([
            'marital_statuses' => $this->maritalStatusOptions(),
            'child_living_with' => $this->tableOptions('master_child_living_with', ['id']),
            'diets' => $this->masterOptions(MasterDiet::class, 'master_diets', ['sort_order', 'id']),
            'smoking_statuses' => $this->masterOptions(MasterSmokingStatus::class, 'master_smoking_statuses', ['sort_order', 'id']),
            'drinking_statuses' => $this->masterOptions(MasterDrinkingStatus::class, 'master_drinking_statuses', ['sort_order', 'id']),
        ]);
    }

    /**
     * GET /api/v1/profile/remaining-profile-options
     * Read-only mobile options for APK Edit All family + horoscope fields.
     */
    public function remainingProfileOptions(Request $request, HoroscopeRuleService $horoscopeRuleService): JsonResponse
    {
        return response()->json([
            'family_types' => $this->masterOptions(MasterFamilyType::class, 'master_family_types', ['id']),
            'family_statuses' => $this->translationOptions('components.family.status_options'),
            'family_values' => $this->translationOptions('components.family.values_options'),
            'occupation_categories' => $this->occupationCategoryOptions(),
            'occupations' => $this->occupationOptions(),
            'custom_occupations' => $this->customOccupationOptions((int) $request->user()->id),
            'currencies' => $this->incomeCurrencyOptions(),
            'rashis' => $this->masterOptions(MasterRashi::class, 'master_rashis', ['id']),
            'nakshatras' => $this->masterOptions(MasterNakshatra::class, 'master_nakshatras', ['id']),
            'gans' => $this->masterOptions(MasterGan::class, 'master_gans', ['id']),
            'nadis' => $this->masterOptions(MasterNadi::class, 'master_nadis', ['id']),
            'yonis' => $this->masterOptions(MasterYoni::class, 'master_yonis', ['id']),
            'mangal_dosh_types' => $this->masterOptions(MasterMangalDoshType::class, 'master_mangal_dosh_types', ['id']),
            'varnas' => $this->tableOptions('master_varnas', ['label', 'id']),
            'vashyas' => $this->tableOptions('master_vashyas', ['label', 'id']),
            'rashi_lords' => $this->tableOptions('master_rashi_lords', ['label', 'id']),
            'birth_weekdays' => $this->birthWeekdayOptions(),
            'horoscope_rules' => $horoscopeRuleService->getRulesForFrontend(),
            'rashi_ashtakoota' => $horoscopeRuleService->getRashiAshtakootaForFrontend(),
        ]);
    }

    /**
     * GET /api/v1/profile/partner-preference-options
     * Read-only mobile options for APK Edit All simple Partner Preferences fields.
     */
    public function partnerPreferenceOptions(): JsonResponse
    {
        return response()->json([
            'marriage_type_preferences' => $this->masterOptions(MasterMarriageTypePreference::class, 'master_marriage_type_preferences', ['sort_order', 'id']),
            'marital_statuses' => $this->masterOptions(MasterMaritalStatus::class, 'master_marital_statuses', ['label', 'id']),
            'diets' => $this->masterOptions(MasterDiet::class, 'master_diets', ['sort_order', 'id']),
            'mother_tongues' => $this->masterOptions(MasterMotherTongue::class, 'master_mother_tongues', ['sort_order', 'label', 'id']),
            'religions' => $this->religionOptions(),
            'castes' => $this->casteOptions(),
            'education_degrees' => $this->educationDegreeOptions(),
            'occupation_categories' => $this->occupationCategoryOptions(),
            'occupations' => $this->occupationOptions(),
            'partner_profile_with_children' => $this->partnerProfileWithChildrenOptions(),
            'preferred_profile_managed_by' => $this->preferredProfileManagedByOptions(),
        ]);
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array<int, array<string, mixed>>
     */
    private function masterOptions(string $modelClass, string $table, array $orderColumns = ['id']): array
    {
        $hasLabelEn = Schema::hasColumn($table, 'label_en');
        $hasLabelMr = Schema::hasColumn($table, 'label_mr');
        $columns = ['id', 'key', 'label'];
        if ($hasLabelEn) {
            $columns[] = 'label_en';
        }
        if ($hasLabelMr) {
            $columns[] = 'label_mr';
        }

        $query = $modelClass::query()->where('is_active', true);
        $this->applyOptionOrdering($query, $table, $orderColumns);

        return $this->mapMasterOptionRows($query->get($columns), $hasLabelEn, $hasLabelMr);
    }

    private function maritalStatusOptions(): array
    {
        $hasLabelEn = Schema::hasColumn('master_marital_statuses', 'label_en');
        $hasLabelMr = Schema::hasColumn('master_marital_statuses', 'label_mr');
        $columns = ['id', 'key', 'label'];
        if ($hasLabelEn) {
            $columns[] = 'label_en';
        }
        if ($hasLabelMr) {
            $columns[] = 'label_mr';
        }

        $rows = MasterMaritalStatus::query()
            ->where('is_active', true)
            ->whereIn('key', self::MARITAL_STATUS_KEYS)
            ->get($columns);

        if ($rows->isEmpty()) {
            $query = MasterMaritalStatus::query()->where('is_active', true);
            $this->applyOptionOrdering($query, 'master_marital_statuses', ['id']);
            $rows = $query->get($columns);
        } else {
            $rows = $rows
                ->sortBy(fn (MasterMaritalStatus $row): int => array_search($row->key, self::MARITAL_STATUS_KEYS, true) ?: 0)
                ->values();
        }

        return $this->mapMasterOptionRows($rows, $hasLabelEn, $hasLabelMr);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function religionOptions(): array
    {
        if (! Schema::hasTable('master_religions')) {
            return [];
        }

        $hasLabelEn = Schema::hasColumn('master_religions', 'label_en');
        $hasLabelMr = Schema::hasColumn('master_religions', 'label_mr');
        $columns = ['id', 'key', 'label'];
        if ($hasLabelEn) {
            $columns[] = 'label_en';
        }
        if ($hasLabelMr) {
            $columns[] = 'label_mr';
        }

        return Religion::query()
            ->where('is_active', true)
            ->orderBy('label')
            ->orderBy('id')
            ->get($columns)
            ->map(fn (Religion $religion): array => [
                'id' => (int) $religion->id,
                'key' => $religion->key,
                'label' => $religion->label,
                'label_en' => $hasLabelEn ? ($religion->label_en ?: $religion->label) : $religion->label,
                'label_mr' => $hasLabelMr ? LocalizedText::value($religion, 'label_mr') : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function casteOptions(): array
    {
        if (! Schema::hasTable('master_castes')) {
            return [];
        }

        $hasLabelEn = Schema::hasColumn('master_castes', 'label_en');
        $hasLabelMr = Schema::hasColumn('master_castes', 'label_mr');
        $columns = ['id', 'religion_id', 'key', 'label'];
        if ($hasLabelEn) {
            $columns[] = 'label_en';
        }
        if ($hasLabelMr) {
            $columns[] = 'label_mr';
        }

        return Caste::query()
            ->where('is_active', true)
            ->orderBy('label')
            ->orderBy('id')
            ->get($columns)
            ->map(fn (Caste $caste): array => [
                'id' => (int) $caste->id,
                'religion_id' => $caste->religion_id ? (int) $caste->religion_id : null,
                'key' => $caste->key,
                'label' => $caste->label,
                'label_en' => $hasLabelEn ? ($caste->label_en ?: $caste->label) : $caste->label,
                'label_mr' => $hasLabelMr ? LocalizedText::value($caste, 'label_mr') : null,
            ])
            ->values()
            ->all();
    }

    private function applyOptionOrdering($query, string $table, array $orderColumns): void
    {
        foreach ($orderColumns as $column) {
            if ($column === 'id' || Schema::hasColumn($table, $column)) {
                $query->orderBy($column);
            }
        }
    }

    private function mapMasterOptionRows($rows, bool $hasLabelEn, bool $hasLabelMr): array
    {
        return $rows
            ->map(fn (Model $row): array => [
                'id' => (int) $row->getAttribute('id'),
                'key' => $row->getAttribute('key'),
                'label' => $row->getAttribute('label'),
                'label_en' => $hasLabelEn
                    ? ($row->getAttribute('label_en') ?: $row->getAttribute('label'))
                    : $row->getAttribute('label'),
                'label_mr' => $hasLabelMr ? LocalizedText::value($row, 'label_mr') : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tableOptions(string $table, array $orderColumns = ['id']): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $hasKey = Schema::hasColumn($table, 'key');
        $hasLabel = Schema::hasColumn($table, 'label');
        $hasLabelEn = Schema::hasColumn($table, 'label_en');
        $hasLabelMr = Schema::hasColumn($table, 'label_mr');
        $columns = ['id'];
        if ($hasKey) {
            $columns[] = 'key';
        }
        if ($hasLabel) {
            $columns[] = 'label';
        }
        if ($hasLabelEn) {
            $columns[] = 'label_en';
        }
        if ($hasLabelMr) {
            $columns[] = 'label_mr';
        }

        $query = DB::table($table);
        if (Schema::hasColumn($table, 'is_active')) {
            $query->where('is_active', true);
        }
        $this->applyOptionOrdering($query, $table, $orderColumns);

        return $query
            ->get($columns)
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'key' => $hasKey ? ($row->key ?? null) : null,
                'label' => $hasLabel ? ($row->label ?? null) : null,
                'label_en' => $hasLabelEn ? (($row->label_en ?? null) ?: ($row->label ?? null)) : ($row->label ?? null),
                'label_mr' => $hasLabelMr ? LocalizedText::value($row, 'label_mr') : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function incomeCurrencyOptions(): array
    {
        if (! Schema::hasTable('master_income_currencies')) {
            return [];
        }

        return MasterIncomeCurrency::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->orderBy('id')
            ->get(['id', 'code', 'symbol', 'is_default'])
            ->map(function (MasterIncomeCurrency $currency): array {
                $symbol = $currency->displaySymbol();
                $label = trim($symbol.' '.($currency->code ?? ''));

                return [
                    'id' => (int) $currency->id,
                    'key' => $currency->code,
                    'code' => $currency->code,
                    'symbol' => $symbol,
                    'label' => $label !== '' ? $label : $currency->code,
                    'label_en' => $label !== '' ? $label : $currency->code,
                    'label_mr' => null,
                    'is_default' => (bool) $currency->is_default,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, array<string, string|null>>
     */
    private function enumOptions(string $translationKey, array $keys): array
    {
        $english = Lang::get($translationKey, [], 'en');
        $marathi = Lang::get($translationKey, [], 'mr');
        $english = is_array($english) ? $english : [];
        $marathi = is_array($marathi) ? $marathi : [];

        return collect($keys)
            ->map(fn (string $key): array => [
                'key' => $key,
                'label' => $english[$key] ?? Str::headline(str_replace('_', ' ', $key)),
                'label_en' => $english[$key] ?? Str::headline(str_replace('_', ' ', $key)),
                'label_mr' => $marathi[$key] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function translationOptions(string $translationKey): array
    {
        $english = Lang::get($translationKey, [], 'en');
        $marathi = Lang::get($translationKey, [], 'mr');
        $english = is_array($english) ? $english : [];
        $marathi = is_array($marathi) ? $marathi : [];

        return collect($english)
            ->map(fn (string $label, string $key): array => [
                'key' => $key,
                'label' => $label,
                'label_en' => $label,
                'label_mr' => $marathi[$key] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function birthWeekdayOptions(): array
    {
        $english = Lang::get('components.horoscope.weekdays', [], 'en');
        $marathi = Lang::get('components.horoscope.weekdays', [], 'mr');
        $english = is_array($english) ? $english : [];
        $marathi = is_array($marathi) ? $marathi : [];

        return collect($english)
            ->map(fn (string $label, string $key): array => [
                'key' => Str::ucfirst($key),
                'label' => $label,
                'label_en' => $label,
                'label_mr' => $marathi[$key] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function partnerProfileWithChildrenOptions(): array
    {
        $labels = [
            'no' => 'wizard.partner_children_no',
            'yes_if_live_separate' => 'wizard.partner_children_yes_if_live_separate',
            'yes' => 'wizard.partner_children_yes',
        ];

        return collect(self::PARTNER_PROFILE_WITH_CHILDREN_KEYS)
            ->map(fn (string $key): array => $this->translatedKeyOption($key, $labels[$key]))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function preferredProfileManagedByOptions(): array
    {
        $labels = [
            '' => 'wizard.partner_pref_managed_by_any',
            'self' => 'onboarding.registering_for_self',
            'parent_guardian' => 'onboarding.registering_for_parent_guardian',
            'sibling' => 'onboarding.registering_for_sibling',
            'relative' => 'onboarding.registering_for_relative',
            'friend' => 'onboarding.registering_for_friend',
            'other' => 'onboarding.registering_for_other',
        ];

        return collect(self::PREFERRED_PROFILE_MANAGED_BY_KEYS)
            ->map(fn (string $key): array => $this->translatedKeyOption($key, $labels[$key]))
            ->values()
            ->all();
    }

    /**
     * @return array<string, string|null>
     */
    private function translatedKeyOption(string $key, string $translationKey): array
    {
        $labelEn = __($translationKey, [], 'en');
        $labelMr = __($translationKey, [], 'mr');

        return [
            'key' => $key,
            'label' => $labelEn,
            'label_en' => $labelEn,
            'label_mr' => $labelMr !== $translationKey ? $labelMr : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function educationDegreeOptions(): array
    {
        if (! Schema::hasTable('master_education')) {
            return [];
        }

        return EducationDegree::query()
            ->with('category')
            ->when(
                Schema::hasColumn('master_education_categories', 'is_active'),
                fn ($query) => $query->whereHas('category', fn ($category) => $category->where('is_active', true))
            )
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->map(fn (EducationDegree $degree): array => [
                'id' => (int) $degree->id,
                'code' => $degree->code,
                'label' => $degree->code,
                'label_en' => $degree->code,
                'label_mr' => Schema::hasColumn('master_education', 'code_mr') ? LocalizedText::value($degree, 'code_mr') : null,
                'full_form' => Schema::hasColumn('master_education', 'full_form') ? ($degree->full_form ?: null) : null,
                'category_id' => $degree->category_id ? (int) $degree->category_id : null,
                'category_label' => $degree->category?->name,
                'category_label_mr' => Schema::hasColumn('master_education_categories', 'name_mr')
                    ? LocalizedText::value($degree->category, 'name_mr')
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function occupationCategoryOptions(): array
    {
        if (! Schema::hasTable('master_occupation_categories')) {
            return [];
        }

        return OccupationCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (OccupationCategory $category): array => [
                'id' => (int) $category->id,
                'label' => $category->name,
                'label_en' => $category->name,
                'label_mr' => Schema::hasColumn('master_occupation_categories', 'name_mr') ? LocalizedText::value($category, 'name_mr') : null,
                'legacy_working_with_type_id' => $category->legacy_working_with_type_id
                    ? (int) $category->legacy_working_with_type_id
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function occupationOptions(): array
    {
        if (! Schema::hasTable('master_occupations')) {
            return [];
        }

        return OccupationMaster::query()
            ->with('category')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (OccupationMaster $occupation): array => [
                'id' => (int) $occupation->id,
                'label' => $occupation->name,
                'label_en' => $occupation->name,
                'label_mr' => Schema::hasColumn('master_occupations', 'name_mr') ? LocalizedText::value($occupation, 'name_mr') : null,
                'category_id' => $occupation->category_id ? (int) $occupation->category_id : null,
                'category_label' => $occupation->category?->name,
                'category_label_mr' => Schema::hasColumn('master_occupation_categories', 'name_mr')
                    ? LocalizedText::value($occupation->category, 'name_mr')
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customOccupationOptions(int $userId): array
    {
        if ($userId <= 0 || ! Schema::hasTable('master_occupation_custom')) {
            return [];
        }

        return OccupationCustom::query()
            ->where('user_id', $userId)
            ->orderBy('raw_name')
            ->get()
            ->map(fn (OccupationCustom $occupation): array => [
                'id' => (int) $occupation->id,
                'label' => $occupation->raw_name,
                'label_en' => $occupation->raw_name,
                'label_mr' => null,
                'status' => $occupation->status,
            ])
            ->values()
            ->all();
    }
}
