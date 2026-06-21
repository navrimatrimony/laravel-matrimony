<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EducationDegree;
use App\Models\MasterBloodGroup;
use App\Models\MasterComplexion;
use App\Models\MasterMotherTongue;
use App\Models\MasterPhysicalBuild;
use App\Models\OccupationCategory;
use App\Models\OccupationCustom;
use App\Models\OccupationMaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProfileSetupLookupController extends Controller
{
    private const SPECTACLES_KEYS = ['no', 'spectacles', 'contact_lens', 'both'];

    private const PHYSICAL_CONDITION_KEYS = [
        'none',
        'physically_challenged',
        'hearing_condition',
        'vision_condition',
        'other',
        'prefer_not_to_say',
    ];

    /**
     * GET /api/v1/profile/basic-physical-options
     * Read-only mobile options for Phase 1A profile setup fields.
     */
    public function basicPhysicalOptions(): JsonResponse
    {
        return response()->json([
            'mother_tongues' => $this->masterOptions(MasterMotherTongue::class, 'master_mother_tongues'),
            'complexions' => $this->masterOptions(MasterComplexion::class, 'master_complexions'),
            'blood_groups' => $this->masterOptions(MasterBloodGroup::class, 'master_blood_groups'),
            'physical_builds' => $this->masterOptions(MasterPhysicalBuild::class, 'master_physical_builds'),
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
        ]);
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array<int, array<string, mixed>>
     */
    private function masterOptions(string $modelClass, string $table): array
    {
        $hasLabelEn = Schema::hasColumn($table, 'label_en');
        $hasLabelMr = Schema::hasColumn($table, 'label_mr');
        $hasSortOrder = Schema::hasColumn($table, 'sort_order');
        $columns = ['id', 'key', 'label'];
        if ($hasLabelEn) {
            $columns[] = 'label_en';
        }
        if ($hasLabelMr) {
            $columns[] = 'label_mr';
        }

        return $modelClass::query()
            ->where('is_active', true)
            ->when($hasSortOrder, fn ($query) => $query->orderBy('sort_order'))
            ->orderBy('label')
            ->get($columns)
            ->map(fn (Model $row): array => [
                'id' => (int) $row->getAttribute('id'),
                'key' => $row->getAttribute('key'),
                'label' => $row->getAttribute('label'),
                'label_en' => $hasLabelEn
                    ? ($row->getAttribute('label_en') ?: $row->getAttribute('label'))
                    : $row->getAttribute('label'),
                'label_mr' => $hasLabelMr ? ($row->getAttribute('label_mr') ?: null) : null,
            ])
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
                'label_mr' => Schema::hasColumn('master_education', 'code_mr') ? ($degree->code_mr ?: null) : null,
                'full_form' => Schema::hasColumn('master_education', 'full_form') ? ($degree->full_form ?: null) : null,
                'category_id' => $degree->category_id ? (int) $degree->category_id : null,
                'category_label' => $degree->category?->name,
                'category_label_mr' => Schema::hasColumn('master_education_categories', 'name_mr')
                    ? ($degree->category?->name_mr ?: null)
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
                'label_mr' => Schema::hasColumn('master_occupation_categories', 'name_mr') ? ($category->name_mr ?: null) : null,
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
                'label_mr' => Schema::hasColumn('master_occupations', 'name_mr') ? ($occupation->name_mr ?: null) : null,
                'category_id' => $occupation->category_id ? (int) $occupation->category_id : null,
                'category_label' => $occupation->category?->name,
                'category_label_mr' => Schema::hasColumn('master_occupation_categories', 'name_mr')
                    ? ($occupation->category?->name_mr ?: null)
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
