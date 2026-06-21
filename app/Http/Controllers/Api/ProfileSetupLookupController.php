<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasterBloodGroup;
use App\Models\MasterComplexion;
use App\Models\MasterMotherTongue;
use App\Models\MasterPhysicalBuild;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
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
}
