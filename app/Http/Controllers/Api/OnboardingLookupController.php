<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caste;
use App\Models\EducationCategory;
use App\Models\EducationDegree;
use App\Models\IncomeRange;
use App\Models\Location;
use App\Models\MasterDiet;
use App\Models\MasterDrinkingStatus;
use App\Models\MasterGender;
use App\Models\MasterIncomeCurrency;
use App\Models\MasterMaritalStatus;
use App\Models\MasterSmokingStatus;
use App\Models\MobileOnboardingMasterSuggestion;
use App\Models\OccupationCategory;
use App\Models\OccupationMaster;
use App\Models\Religion;
use App\Models\SubCaste;
use App\Models\WorkingWithType;
use App\Services\Location\LocationOpenPlaceSuggestionService;
use App\Services\Location\LocationService;
use App\Services\Onboarding\MobileOnboardingDraftService;
use App\Services\Onboarding\OnboardingLookupOptionFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OnboardingLookupController extends Controller
{
    private const PROFILE_FOR_WHOM = [
        ['key' => 'self', 'label_en' => 'For myself', 'label_mr' => 'स्वतःसाठी', 'gender_mode' => 'ask'],
        ['key' => 'son', 'label_en' => 'For my son', 'label_mr' => 'मुलासाठी', 'gender_mode' => 'male'],
        ['key' => 'daughter', 'label_en' => 'For my daughter', 'label_mr' => 'मुलीसाठी', 'gender_mode' => 'female'],
        ['key' => 'brother', 'label_en' => 'For my brother', 'label_mr' => 'भावासाठी', 'gender_mode' => 'male'],
        ['key' => 'sister', 'label_en' => 'For my sister', 'label_mr' => 'बहिणीसाठी', 'gender_mode' => 'female'],
        ['key' => 'relative', 'label_en' => 'For a relative', 'label_mr' => 'नातेवाईकासाठी', 'gender_mode' => 'ask'],
        ['key' => 'friend', 'label_en' => 'For a friend', 'label_mr' => 'मित्र/मैत्रिणीसाठी', 'gender_mode' => 'ask'],
    ];

    public function __construct(private readonly OnboardingLookupOptionFormatter $labels) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        app()->setLocale($locale);

        return response()->json([
            'success' => true,
            'locale' => $locale,
            'profile_for_whom' => array_map(fn (array $row): array => [
                'key' => $row['key'],
                'label' => $locale === 'mr' ? $row['label_mr'] : $row['label_en'],
                'gender_mode' => $row['gender_mode'],
                'translation_missing' => false,
            ], self::PROFILE_FOR_WHOM),
            'gender_options' => $this->simpleMasterOptions(MasterGender::class, 'master_genders', $locale, ['label_mr'], ['label'], ['key']),
            'marital_statuses' => $this->simpleMasterOptions(MasterMaritalStatus::class, 'master_marital_statuses', $locale, ['label_mr'], ['label'], ['key']),
            'children_rules' => [
                'show_for_keys' => ['divorced', 'annulled', 'separated', 'widowed'],
                'hide_for_keys' => ['never_married'],
            ],
            'height_options' => $this->heightOptions(),
            'diet_options' => $this->simpleMasterOptions(MasterDiet::class, 'master_diets', $locale, ['label_mr'], ['label'], ['key']),
            'smoking_options' => $this->simpleMasterOptions(MasterSmokingStatus::class, 'master_smoking_statuses', $locale, ['label_mr'], ['label'], ['key']),
            'drinking_options' => $this->simpleMasterOptions(MasterDrinkingStatus::class, 'master_drinking_statuses', $locale, ['label_mr'], ['label'], ['key']),
            'age_policy' => [
                'min_age' => 18,
                'max_age' => 80,
            ],
            'steps' => array_values(MobileOnboardingDraftService::STEPS),
        ]);
    }

    public function religions(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $query = Religion::query()->where('is_active', true);
        $this->applyLikeSearch($query, $params['q'], ['label', 'label_en', 'label_mr', 'key']);
        $query->orderBy('label')->orderBy('id');

        $popular = $this->popularRows(Religion::query()->where('is_active', true)->orderBy('label')->orderBy('id'), $params, fn (Religion $row): array => $this->religionItem($row, $params['locale'], true));

        return $this->listResponse($query, $params, fn (Religion $row): array => $this->religionItem($row, $params['locale']), $popular);
    }

    public function castes(Request $request): JsonResponse
    {
        $request->validate([
            'religion_id' => ['required', 'integer', Rule::exists('master_religions', 'id')->where('is_active', true)],
        ]);
        $params = $this->listParams($request);
        $religionId = (int) $request->integer('religion_id');

        $base = Caste::query()
            ->where('religion_id', $religionId)
            ->where('is_active', true);
        $query = clone $base;
        $this->applyLikeSearch($query, $params['q'], ['label', 'label_en', 'label_mr', 'key']);
        $query->orderBy('label')->orderBy('id');

        $popular = $this->popularRows((clone $base)->orderBy('label')->orderBy('id'), $params, fn (Caste $row): array => $this->casteItem($row, $params['locale'], true));

        return $this->listResponse($query, $params, fn (Caste $row): array => $this->casteItem($row, $params['locale']), $popular);
    }

    public function subCastes(Request $request): JsonResponse
    {
        $request->validate([
            'caste_id' => ['required', 'integer', Rule::exists('master_castes', 'id')->where('is_active', true)],
        ]);
        $params = $this->listParams($request);
        $casteId = (int) $request->integer('caste_id');

        $base = SubCaste::query()
            ->where('caste_id', $casteId)
            ->where('is_active', true)
            ->where('status', 'approved');
        $query = clone $base;
        $this->applyLikeSearch($query, $params['q'], ['label', 'label_en', 'label_mr', 'key']);
        $query->orderBy('label')->orderBy('id');

        $popular = $this->popularRows((clone $base)->orderBy('label')->orderBy('id'), $params, fn (SubCaste $row): array => $this->subCasteItem($row, $params['locale'], true));

        return $this->listResponse($query, $params, fn (SubCaste $row): array => $this->subCasteItem($row, $params['locale']), $popular);
    }

    public function locations(Request $request, LocationService $locationService): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'preferred_state_id' => ['nullable', 'integer', Rule::exists(Location::geoTable(), 'id')->where('hierarchy', 'state')->where('is_active', true)],
            'type' => ['nullable', 'string', Rule::in(['village', 'city', 'suburb'])],
        ]);
        $params = $this->listParams($request);
        app()->setLocale($params['locale']);

        if (mb_strlen($params['q'], 'UTF-8') < 2) {
            return $this->emptyListResponse($params);
        }

        $geo = Location::geoTable();
        $query = Location::query()
            ->where('is_active', true)
            ->where(function (Builder $builder) use ($params, $geo): void {
                $like = '%'.addcslashes($params['q'], '%_\\').'%';
                $builder->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like);
                if (Schema::hasColumn($geo, 'name_en')) {
                    $builder->orWhere('name_en', 'like', $like);
                }
                if (Schema::hasColumn($geo, 'name_mr')) {
                    $builder->orWhere('name_mr', 'like', $like);
                }
                if (Schema::hasColumn($geo, 'pincode')) {
                    $digits = preg_replace('/\D+/u', '', $params['q']);
                    if (is_string($digits) && strlen($digits) >= 3) {
                        $builder->orWhere('pincode', 'like', $digits.'%');
                    }
                }
            });

        $this->applyLocationTypeFilter($query, $request->input('type'));
        $query->orderByRaw("CASE WHEN hierarchy = 'village' AND tag = 'city' THEN 0 WHEN hierarchy = 'village' AND tag = 'suburban' THEN 1 WHEN hierarchy = 'village' AND tag = 'rural' THEN 2 WHEN hierarchy = 'taluka' THEN 3 WHEN hierarchy = 'district' THEN 4 WHEN hierarchy = 'state' THEN 5 ELSE 6 END")
            ->orderBy('name')
            ->orderBy('id');

        return $this->listResponse($query, $params, fn (Location $row): array => $this->locationItem($row, $params['locale'], $locationService), []);
    }

    public function storeLocationSuggestion(Request $request, LocationOpenPlaceSuggestionService $suggestions): JsonResponse
    {
        $locationTable = Location::geoTable();
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(['village', 'city', 'suburb'])],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'state_id' => ['required', 'integer', Rule::exists($locationTable, 'id')->where('hierarchy', 'state')->where('is_active', true)],
            'district_id' => ['required', 'integer', Rule::exists($locationTable, 'id')->where('hierarchy', 'district')->where('is_active', true)],
            'taluka_id' => ['nullable', 'integer', Rule::exists($locationTable, 'id')->where('hierarchy', 'taluka')->where('is_active', true)],
            'city_id' => ['nullable', 'integer', Rule::exists($locationTable, 'id')->where('hierarchy', 'village')->where('tag', 'city')->where('is_active', true)],
            'pincode' => ['nullable', 'string', 'max:16'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $this->validateSuggestionHierarchy($validated);

        $record = $suggestions->recordOrBumpUsage(
            rawInput: (string) $validated['name'],
            suggestedByUserId: (int) $request->user()->id,
            optionalHierarchy: [
                'state_id' => (int) $validated['state_id'],
                'district_id' => (int) $validated['district_id'],
                'taluka_id' => isset($validated['taluka_id']) ? (int) $validated['taluka_id'] : null,
            ],
            matchType: 'manual'
        );

        if ($record === null) {
            return response()->json([
                'success' => false,
                'message' => 'Suggestion queue is unavailable.',
            ], 503);
        }

        $suggestionUpdates = [];
        if (Schema::hasColumn('location_open_place_suggestions', 'suggested_type')) {
            $suggestionUpdates['suggested_type'] = $validated['type'];
        }
        if (Schema::hasColumn('location_open_place_suggestions', 'suggested_parent_id')) {
            $suggestionUpdates['suggested_parent_id'] = $this->suggestedLocationParentId($validated);
        }
        if (Schema::hasColumn('location_open_place_suggestions', 'analysis_json')) {
            $suggestionUpdates['analysis_json'] = array_filter([
                'pincode' => $validated['pincode'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'city_id' => $validated['city_id'] ?? null,
            ], fn ($value): bool => $value !== null && $value !== '');
        }
        if ($suggestionUpdates !== []) {
            $record->forceFill($suggestionUpdates)->save();
        }

        return response()->json([
            'success' => true,
            'request' => [
                'id' => (int) $record->id,
                'status' => (string) $record->status,
                'type' => (string) $validated['type'],
                'label' => (string) $record->raw_input,
            ],
            'message' => 'Location request submitted',
        ]);
    }

    public function education(Request $request): JsonResponse
    {
        $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:master_education_categories,id'],
        ]);
        $params = $this->listParams($request);
        $base = EducationDegree::query()->with('category');
        if (Schema::hasColumn('master_education_categories', 'is_active')) {
            $base->whereHas('category', fn (Builder $category): Builder => $category->where('is_active', true));
        }
        if ($request->filled('category_id')) {
            $base->where('category_id', (int) $request->integer('category_id'));
        }

        $query = clone $base;
        $this->applyLikeSearch($query, $params['q'], ['code', 'code_mr', 'full_form']);
        $query->orderBy('sort_order')->orderBy('code')->orderBy('id');

        $popular = $this->popularRows((clone $base)->orderBy('sort_order')->orderBy('code')->orderBy('id'), $params, fn (EducationDegree $row): array => $this->educationItem($row, $params['locale'], true));

        return $this->listResponse($query, $params, fn (EducationDegree $row): array => $this->educationItem($row, $params['locale']), $popular);
    }

    public function storeEducationSuggestion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'min:2', 'max:160'],
            'category_id' => ['required', 'integer', 'exists:master_education_categories,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->storeMasterSuggestion($request, 'education', $validated);
    }

    public function workingWith(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $query = WorkingWithType::query()->where('is_active', true);
        $this->applyLikeSearch($query, $params['q'], ['name', 'name_mr', 'slug']);
        $query->orderBy('sort_order')->orderBy('name')->orderBy('id');

        $popular = $this->popularRows(WorkingWithType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->orderBy('id'), $params, fn (WorkingWithType $row): array => $this->workingWithItem($row, $params['locale'], true));

        return $this->listResponse($query, $params, fn (WorkingWithType $row): array => $this->workingWithItem($row, $params['locale']), $popular);
    }

    public function occupations(Request $request): JsonResponse
    {
        $request->validate([
            'working_with_id' => ['nullable', 'integer', Rule::exists('working_with_types', 'id')->where('is_active', true)],
            'category_id' => ['nullable', 'integer', 'exists:master_occupation_categories,id'],
        ]);
        $params = $this->listParams($request);
        $base = OccupationMaster::query()->with('category');
        if ($request->filled('category_id')) {
            $base->where('category_id', (int) $request->integer('category_id'));
        }
        if ($request->filled('working_with_id')) {
            $workingWithId = (int) $request->integer('working_with_id');
            $base->whereHas('category', fn (Builder $category): Builder => $category->where('legacy_working_with_type_id', $workingWithId));
        }

        $query = clone $base;
        $this->applyLikeSearch($query, $params['q'], ['name', 'name_mr', 'normalized_name']);
        $query->orderBy('sort_order')->orderBy('name')->orderBy('id');

        $popular = $this->popularRows((clone $base)->orderBy('sort_order')->orderBy('name')->orderBy('id'), $params, fn (OccupationMaster $row): array => $this->occupationItem($row, $params['locale'], true));

        return $this->listResponse($query, $params, fn (OccupationMaster $row): array => $this->occupationItem($row, $params['locale']), $popular);
    }

    public function storeOccupationSuggestion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'min:2', 'max:160'],
            'category_id' => ['nullable', 'integer', 'exists:master_occupation_categories,id'],
            'working_with_id' => ['nullable', 'integer', Rule::exists('working_with_types', 'id')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->storeMasterSuggestion($request, 'occupation', $validated);
    }

    public function incomeOptions(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $currency = MasterIncomeCurrency::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderByRaw("CASE WHEN code = 'INR' THEN 0 ELSE 1 END")
            ->orderBy('code')
            ->first();

        return response()->json([
            'success' => true,
            'locale' => $locale,
            'currency' => $currency?->code ?? 'INR',
            'currency_id' => $currency?->id ? (int) $currency->id : null,
            'currency_symbol' => $currency?->displaySymbol() ?? '₹',
            'periods' => [
                $this->staticOption('monthly', 'Monthly', 'मासिक', $locale),
                $this->staticOption('annual', 'Annual', 'वार्षिक', $locale),
            ],
            'value_types' => [
                $this->staticOption('exact', 'Exact', 'नेमके', $locale),
                $this->staticOption('approximate', 'Approximate income', 'अंदाजे उत्पन्न', $locale),
                $this->staticOption('range', 'Range', 'श्रेणी', $locale),
                $this->staticOption('undisclosed', 'Undisclosed', 'न सांगणे', $locale),
            ],
            'ranges' => $this->incomeRanges($locale),
            'privacy_default' => 'private',
            'accepted_profile_keys' => [
                'income_period',
                'income_value_type',
                'income_amount',
                'income_min_amount',
                'income_max_amount',
                'income_currency_id',
                'income_private',
                'family_income_period',
                'family_income_value_type',
                'family_income_amount',
                'family_income_min_amount',
                'family_income_max_amount',
                'family_income_currency_id',
                'family_income_private',
            ],
        ]);
    }

    public function diet(Request $request): JsonResponse
    {
        return $this->simpleLookupList($request, MasterDiet::class, 'master_diets');
    }

    public function smoking(Request $request): JsonResponse
    {
        return $this->simpleLookupList($request, MasterSmokingStatus::class, 'master_smoking_statuses');
    }

    public function drinking(Request $request): JsonResponse
    {
        return $this->simpleLookupList($request, MasterDrinkingStatus::class, 'master_drinking_statuses');
    }

    private function listParams(Request $request): array
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1'],
            'locale' => ['nullable', 'string', 'max:5'],
            'include_popular' => ['nullable', 'boolean'],
        ]);

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'page' => max(1, (int) ($validated['page'] ?? 1)),
            'limit' => max(1, min(50, (int) ($validated['limit'] ?? 20))),
            'locale' => $this->locale($request),
            'include_popular' => array_key_exists('include_popular', $validated) ? $request->boolean('include_popular') : true,
        ];
    }

    private function locale(Request $request): string
    {
        $candidate = trim((string) $request->input('locale', $request->user()?->preferred_locale ?: 'en'));

        return str_starts_with(strtolower($candidate), 'mr') ? 'mr' : 'en';
    }

    private function listResponse(Builder $query, array $params, callable $map, array $popular): JsonResponse
    {
        app()->setLocale($params['locale']);
        $rows = (clone $query)
            ->offset(($params['page'] - 1) * $params['limit'])
            ->limit($params['limit'] + 1)
            ->get();
        $hasMore = $rows->count() > $params['limit'];
        $results = $rows->take($params['limit'])->map($map)->values()->all();

        return response()->json([
            'success' => true,
            'locale' => $params['locale'],
            'results' => $results,
            'popular' => $params['include_popular'] ? $popular : [],
            'pagination' => [
                'page' => $params['page'],
                'limit' => $params['limit'],
                'has_more' => $hasMore,
                'total' => null,
            ],
        ]);
    }

    private function emptyListResponse(array $params): JsonResponse
    {
        return response()->json([
            'success' => true,
            'locale' => $params['locale'],
            'results' => [],
            'popular' => [],
            'pagination' => [
                'page' => $params['page'],
                'limit' => $params['limit'],
                'has_more' => false,
                'total' => null,
            ],
        ]);
    }

    private function popularRows(Builder $query, array $params, callable $map): array
    {
        if (! $params['include_popular']) {
            return [];
        }

        return (clone $query)->limit(min(8, $params['limit']))->get()->map($map)->values()->all();
    }

    private function applyLikeSearch(Builder $query, string $q, array $columns): void
    {
        if ($q === '') {
            return;
        }
        $like = '%'.addcslashes($q, '%_\\').'%';
        $query->where(function (Builder $builder) use ($columns, $like): void {
            foreach ($columns as $column) {
                $builder->orWhere($column, 'like', $like);
            }
        });
    }

    private function religionItem(Religion $row, string $locale, bool $popular = false): array
    {
        $label = $this->labels->label($row, $locale, ['label_mr'], ['label_en', 'label']);

        return $this->optionItem((int) $row->id, $row->key, $label, $popular, ['religion_id' => (int) $row->id]);
    }

    private function casteItem(Caste $row, string $locale, bool $popular = false): array
    {
        $label = $this->labels->label($row, $locale, ['label_mr'], ['label_en', 'label']);

        return $this->optionItem((int) $row->id, $row->key, $label, $popular, [
            'religion_id' => $row->religion_id ? (int) $row->religion_id : null,
        ]);
    }

    private function subCasteItem(SubCaste $row, string $locale, bool $popular = false): array
    {
        $label = $this->labels->label($row, $locale, ['label_mr'], ['label_en', 'label']);

        return $this->optionItem((int) $row->id, $row->key, $label, $popular, [
            'caste_id' => $row->caste_id ? (int) $row->caste_id : null,
            'status' => $row->status,
        ]);
    }

    private function educationItem(EducationDegree $row, string $locale, bool $popular = false): array
    {
        $row->loadMissing('category');
        $label = $this->labels->label($row, $locale, ['code_mr'], ['code', 'full_form']);
        $categoryLabel = $row->category instanceof EducationCategory
            ? $this->labels->label($row->category, $locale, ['name_mr'], ['name'])
            : ['label' => null, 'translation_missing' => false];
        $rank = (int) (($row->category?->sort_order ?? 0) * 1000 + ($row->sort_order ?? 0));

        return $this->optionItem((int) $row->id, $row->code, $label, $popular, [
            'category_id' => $row->category_id ? (int) $row->category_id : null,
            'category_label' => $categoryLabel['label'],
            'category_translation_missing' => $categoryLabel['translation_missing'],
            'level_rank' => $rank,
            'level_rank_source' => Schema::hasColumn('master_education', 'level_rank') ? 'column' : 'category_sort_order',
            'requires_specialization' => false,
            'requires_college' => false,
        ]);
    }

    private function workingWithItem(WorkingWithType $row, string $locale, bool $popular = false): array
    {
        $label = $this->labels->label($row, $locale, ['name_mr'], ['name']);

        return $this->optionItem((int) $row->id, $row->slug, $label, $popular);
    }

    private function occupationItem(OccupationMaster $row, string $locale, bool $popular = false): array
    {
        $row->loadMissing('category.workingWithType');
        $label = $this->labels->label($row, $locale, ['name_mr'], ['name']);
        $categoryLabel = $row->category instanceof OccupationCategory
            ? $this->labels->label($row->category, $locale, ['name_mr'], ['name'])
            : ['label' => null, 'translation_missing' => false];
        $workingWith = $row->category?->workingWithType;
        $workingWithLabel = $workingWith instanceof WorkingWithType
            ? $this->labels->label($workingWith, $locale, ['name_mr'], ['name'])
            : ['label' => null, 'translation_missing' => false];

        return $this->optionItem((int) $row->id, null, $label, $popular, [
            'category_id' => $row->category_id ? (int) $row->category_id : null,
            'category_label' => $categoryLabel['label'],
            'category_translation_missing' => $categoryLabel['translation_missing'],
            'working_with_id' => $workingWith?->id ? (int) $workingWith->id : null,
            'working_with_label' => $workingWithLabel['label'],
        ]);
    }

    private function locationItem(Location $row, string $locale, LocationService $locationService): array
    {
        app()->setLocale($locale);
        $row->loadMissing('parent');
        $locationService->ensureAncestorsLoaded($row);
        $h = $locationService->fillHierarchyGaps($row, $locationService->getFullHierarchy($row));
        $type = $this->locationType($row);
        $isFinal = (string) $row->hierarchy === 'village' && in_array((string) ($row->tag ?? ''), ['city', 'suburban', 'rural'], true);
        $label = $locationService->getDisplayLabel($row);

        return [
            'id' => (int) $row->id,
            'location_id' => (int) $row->id,
            'key' => $row->slug,
            'label' => $label,
            'translation_missing' => false,
            'popular' => false,
            'display_hierarchy' => $label,
            'type' => $type,
            'tag' => $row->tag,
            'is_final_node' => $isFinal,
            'status' => $row->is_active ? 'approved' : 'inactive',
            'pincode' => $row->pincode,
            'state_id' => $h['state']?->id ? (int) $h['state']->id : null,
            'district_id' => $h['district']?->id ? (int) $h['district']->id : null,
            'taluka_id' => $h['taluka']?->id ? (int) $h['taluka']->id : null,
            'city_id' => $type === 'city'
                ? (int) $row->id
                : ($h['city']?->id ? (int) $h['city']->id : null),
            'parent' => [
                'state' => $this->parentNode($h['state'] ?? null),
                'district' => $this->parentNode($h['district'] ?? null),
                'taluka' => $this->parentNode($h['taluka'] ?? null),
                'city' => $this->parentNode($h['city'] ?? null),
            ],
            'meta' => [],
        ];
    }

    private function optionItem(int $id, mixed $key, array $label, bool $popular = false, array $meta = []): array
    {
        return [
            'id' => $id,
            'key' => $key,
            'label' => $label['label'],
            'translation_missing' => (bool) $label['translation_missing'],
            'popular' => $popular,
            'meta' => $meta,
        ];
    }

    private function simpleLookupList(Request $request, string $modelClass, string $table): JsonResponse
    {
        $params = $this->listParams($request);
        /** @var Builder $query */
        $query = $modelClass::query()->where('is_active', true);
        $this->applyLikeSearch($query, $params['q'], ['key', 'label', 'label_mr']);
        $query->orderBy('sort_order')->orderBy('label')->orderBy('id');
        $popular = $this->popularRows($modelClass::query()->where('is_active', true)->orderBy('sort_order')->orderBy('label')->orderBy('id'), $params, fn (Model $row): array => $this->simpleMasterItem($row, $table, $params['locale'], true));

        return $this->listResponse($query, $params, fn (Model $row): array => $this->simpleMasterItem($row, $table, $params['locale']), $popular);
    }

    private function simpleMasterItem(Model $row, string $table, string $locale, bool $popular = false): array
    {
        $mrColumns = Schema::hasColumn($table, 'label_mr') ? ['label_mr'] : [];
        $label = $this->labels->label($row, $locale, $mrColumns, ['label']);

        return $this->optionItem((int) $row->getKey(), $row->getAttribute('key'), $label, $popular);
    }

    private function simpleMasterOptions(string $modelClass, string $table, string $locale, array $mrColumns, array $enColumns, array $keyColumns = []): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        /** @var Builder $query */
        $query = $modelClass::query();
        if (Schema::hasColumn($table, 'is_active')) {
            $query->where('is_active', true);
        }
        if (Schema::hasColumn($table, 'sort_order')) {
            $query->orderBy('sort_order');
        }
        if (Schema::hasColumn($table, 'label')) {
            $query->orderBy('label');
        }
        $query->orderBy('id');

        return $query->get()->map(function (Model $row) use ($locale, $mrColumns, $enColumns, $keyColumns): array {
            $label = $this->labels->label($row, $locale, $mrColumns, $enColumns);
            $key = null;
            foreach ($keyColumns as $column) {
                $value = $row->getAttribute($column);
                if ($value !== null && $value !== '') {
                    $key = $value;
                    break;
                }
            }

            return $this->optionItem((int) $row->getKey(), $key, $label);
        })->values()->all();
    }

    private function heightOptions(): array
    {
        $out = [];
        for ($cm = 122; $cm <= 213; $cm++) {
            $inches = (int) round($cm / 2.54);
            $feet = intdiv($inches, 12);
            $inch = $inches % 12;
            $out[] = [
                'id' => $cm,
                'key' => (string) $cm,
                'label' => $feet."'".$inch.'"',
                'translation_missing' => false,
                'popular' => false,
                'meta' => ['cm' => $cm],
            ];
        }

        return $out;
    }

    private function staticOption(string $key, string $en, string $mr, string $locale): array
    {
        return [
            'key' => $key,
            'label' => $locale === 'mr' ? $mr : $en,
            'translation_missing' => false,
        ];
    }

    private function incomeRanges(string $locale): array
    {
        if (! Schema::hasTable('master_income_ranges')) {
            return [];
        }

        return IncomeRange::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (IncomeRange $row) use ($locale): array {
                $label = $this->labels->label($row, $locale, ['name_mr'], ['name']);

                return $this->optionItem((int) $row->id, $row->slug, $label);
            })
            ->values()
            ->all();
    }

    private function applyLocationTypeFilter(Builder $query, mixed $type): void
    {
        if ($type === 'village') {
            $query->where('hierarchy', 'village')->where('tag', 'rural');
        } elseif ($type === 'city') {
            $query->where('hierarchy', 'village')->where('tag', 'city');
        } elseif ($type === 'suburb') {
            $query->where('hierarchy', 'village')->where('tag', 'suburban');
        }
    }

    private function locationType(Location $location): string
    {
        if ((string) $location->hierarchy !== 'village') {
            return (string) $location->hierarchy;
        }

        return match ((string) ($location->tag ?? '')) {
            'city' => 'city',
            'suburban' => 'suburb',
            default => 'village',
        };
    }

    private function parentNode(?Location $location): ?array
    {
        if (! $location instanceof Location) {
            return null;
        }

        return [
            'id' => (int) $location->id,
            'label' => $location->localizedName(),
        ];
    }

    private function validateSuggestionHierarchy(array $data): void
    {
        $stateId = (int) $data['state_id'];
        $district = Location::query()
            ->whereKey((int) $data['district_id'])
            ->where('hierarchy', 'district')
            ->where('parent_id', $stateId)
            ->first();
        if (! $district) {
            throw ValidationException::withMessages([
                'district_id' => ['The selected district must belong to the selected state.'],
            ]);
        }

        if ($data['type'] === 'village') {
            $talukaId = (int) ($data['taluka_id'] ?? 0);
            $taluka = Location::query()
                ->whereKey($talukaId)
                ->where('hierarchy', 'taluka')
                ->where('parent_id', (int) $district->id)
                ->first();
            if (! $taluka) {
                throw ValidationException::withMessages([
                    'taluka_id' => ['Taluka is required for village suggestions.'],
                ]);
            }
        }

        if ($data['type'] === 'suburb') {
            $cityId = (int) ($data['city_id'] ?? 0);
            $city = Location::query()
                ->whereKey($cityId)
                ->where('hierarchy', 'village')
                ->where('tag', 'city')
                ->first();
            if (! $city) {
                throw ValidationException::withMessages([
                    'city_id' => ['City is required for suburb suggestions.'],
                ]);
            }
        }
    }

    private function suggestedLocationParentId(array $data): ?int
    {
        if ($data['type'] === 'village') {
            return isset($data['taluka_id']) ? (int) $data['taluka_id'] : null;
        }
        if ($data['type'] === 'suburb') {
            return isset($data['city_id']) ? (int) $data['city_id'] : null;
        }

        return isset($data['district_id']) ? (int) $data['district_id'] : null;
    }

    private function storeMasterSuggestion(Request $request, string $type, array $validated): JsonResponse
    {
        if (! Schema::hasTable('mobile_onboarding_master_suggestions')) {
            return response()->json([
                'success' => false,
                'message' => 'Suggestion queue is unavailable.',
            ], 503);
        }

        $record = MobileOnboardingMasterSuggestion::query()->create([
            'type' => $type,
            'label' => trim((string) $validated['label']),
            'category_id' => $validated['category_id'] ?? null,
            'working_with_id' => $validated['working_with_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
            'suggested_by_user_id' => (int) $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'request' => [
                'id' => (int) $record->id,
                'status' => (string) $record->status,
                'type' => $type,
                'label' => (string) $record->label,
            ],
            'message' => ucfirst($type).' request submitted',
        ], 201);
    }
}
