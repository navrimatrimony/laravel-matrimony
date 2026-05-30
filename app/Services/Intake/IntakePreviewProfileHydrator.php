<?php

namespace App\Services\Intake;

use App\Models\MatrimonyProfile;
use App\Services\ExtendedFieldService;
use App\Support\BilingualMasterLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Build the preview form "profile" object from an existing MatrimonyProfile (wizard-compatible).
 */
class IntakePreviewProfileHydrator
{
    /**
     * @param  array<string, mixed>  $mergedCore  Display core after profile-first overlay
     */
    public function hydrate(MatrimonyProfile $profile, array $mergedCore): object
    {
        $profile->loadMissing(['religion', 'caste', 'subCaste']);

        $obj = (object) [
            'full_name' => trim((string) ($mergedCore['full_name'] ?? $profile->full_name ?? '')),
            'date_of_birth' => $mergedCore['date_of_birth'] ?? $profile->date_of_birth,
            'gender_id' => $mergedCore['gender_id'] ?? $profile->gender_id,
            'birth_time' => trim((string) ($mergedCore['birth_time'] ?? $profile->birth_time ?? '')),
            'birth_city_id' => $mergedCore['birth_city_id'] ?? $profile->birth_city_id,
            'religion_id' => $mergedCore['religion_id'] ?? $profile->religion_id ?? '',
            'caste_id' => $mergedCore['caste_id'] ?? $profile->caste_id ?? '',
            'sub_caste_id' => $mergedCore['sub_caste_id'] ?? $profile->sub_caste_id ?? '',
            'marital_status_id' => $mergedCore['marital_status_id'] ?? $profile->marital_status_id ?? '',
            'mother_tongue_id' => $mergedCore['mother_tongue_id'] ?? $profile->mother_tongue_id ?? null,
            'has_children' => $mergedCore['has_children'] ?? $profile->has_children,
            'has_siblings' => $mergedCore['has_siblings'] ?? $profile->has_siblings,
            'religion_label' => BilingualMasterLabel::preferred($profile->religion?->label_mr, $profile->religion?->label_en, $profile->religion?->label),
            'caste_label' => BilingualMasterLabel::preferred($profile->caste?->label_mr, $profile->caste?->label_en, $profile->caste?->label),
            'subcaste_label' => BilingualMasterLabel::preferred($profile->subCaste?->label_mr, $profile->subCaste?->label_en, $profile->subCaste?->label),
        ];

        foreach ($mergedCore as $k => $v) {
            if (! property_exists($obj, $k)) {
                $obj->{$k} = $v;
            }
        }

        if (! empty($obj->religion_id) && empty($obj->religion_label)) {
            $r = $profile->religion ?? \App\Models\Religion::find($obj->religion_id);
            if ($r) {
                $obj->religion_label = BilingualMasterLabel::preferred($r->label_mr, $r->label_en, $r->label);
            }
        }
        if (! empty($obj->caste_id) && empty($obj->caste_label)) {
            $c = $profile->caste ?? \App\Models\Caste::find($obj->caste_id);
            if ($c) {
                $obj->caste_label = BilingualMasterLabel::preferred($c->label_mr, $c->label_en, $c->label);
            }
        }
        if (! empty($obj->sub_caste_id) && empty($obj->subcaste_label)) {
            $s = $profile->subCaste ?? \App\Models\SubCaste::find($obj->sub_caste_id);
            if ($s) {
                $obj->subcaste_label = BilingualMasterLabel::preferred($s->label_mr, $s->label_en, $s->label);
            }
        }

        $obj->birthPlaceDisplay = '';
        $birthCityId = $obj->birth_city_id ?? null;
        if (! empty($birthCityId)) {
            $obj->birthPlaceDisplay = \App\Models\Location::query()->find((int) $birthCityId)?->localizedName() ?? '';
        } elseif (! empty($profile->birth_place_text)) {
            $obj->birthPlaceDisplay = trim((string) $profile->birth_place_text);
        } elseif (! empty($mergedCore['birth_place_text'])) {
            $obj->birthPlaceDisplay = trim((string) $mergedCore['birth_place_text']);
        } elseif (! empty($mergedCore['birth_place']) && is_scalar($mergedCore['birth_place'])) {
            $obj->birthPlaceDisplay = trim((string) $mergedCore['birth_place']);
        }

        $obj->extended = ExtendedFieldService::getValuesForProfile($profile);

        return $obj;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function horoscopeRowForProfile(MatrimonyProfile $profile): array
    {
        if (! Schema::hasTable('profile_horoscope_data')) {
            return [];
        }
        $row = DB::table('profile_horoscope_data')->where('profile_id', $profile->id)->first();
        if (! $row) {
            return [];
        }

        return (array) $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function educationHistoryForProfile(MatrimonyProfile $profile): array
    {
        if (! Schema::hasTable('profile_education')) {
            return [];
        }

        return DB::table('profile_education')
            ->where('profile_id', $profile->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) ($r->id ?? 0),
                'degree' => (string) ($r->degree ?? ''),
                'institution' => (string) ($r->university ?? $r->institution ?? ''),
                'specialization' => (string) ($r->specialization ?? ''),
                'year' => $r->year_completed ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function careerHistoryForProfile(MatrimonyProfile $profile): array
    {
        if (! Schema::hasTable('profile_career')) {
            return [];
        }

        return DB::table('profile_career')
            ->where('profile_id', $profile->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->values()
            ->all();
    }
}
