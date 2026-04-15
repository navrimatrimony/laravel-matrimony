<?php

namespace App\Services\Showcase;

use App\Models\AdminSetting;
use App\Models\District;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only policy for {@see DemoProfileDefaultsService::fullAttributesForDemoProfile} when bulk-creating showcase profiles.
 * Stored as JSON in {@see AdminSetting} key `showcase_bulk_create_policy`.
 *
 * Photo selection is governed elsewhere — not configured here.
 */
class ShowcaseBulkCreateSettings
{
    public const SETTING_KEY = 'showcase_bulk_create_policy';

    /** Core keys safe to force null for showcase rows (nullable / optional presentation). */
    public const NEVER_FILL_KEY_OPTIONS = [
        'blood_group_id' => 'Blood group',
        'complexion_id' => 'Complexion',
        'physical_build_id' => 'Physical build',
        'weight_kg' => 'Weight',
        'birth_time' => 'Birth time',
        'mother_tongue_id' => 'Mother tongue',
        'sub_caste_id' => 'Sub-caste',
        'spectacles_lens' => 'Spectacles / lens',
        'physical_condition' => 'Physical condition',
        'family_type_id' => 'Family type',
        'income_currency_id' => 'Income currency',
        'diet_id' => 'Diet',
        'smoking_status_id' => 'Smoking',
        'drinking_status_id' => 'Drinking',
        'specialization' => 'Specialization',
        'occupation_title' => 'Occupation title',
        'company_name' => 'Company name',
        'annual_income' => 'Annual income',
        'family_income' => 'Family income',
        'working_with_type_id' => 'Working with type',
        'profession_id' => 'Profession',
        'father_name' => 'Father name',
        'father_occupation' => 'Father occupation',
        'mother_name' => 'Mother name',
        'mother_occupation' => 'Mother occupation',
        'about_me' => 'About me (narrative)',
        'expectations' => 'Expectations (narrative)',
    ];

    /** Keys that may be re-randomised from full master pools after baseline + fixed overrides. */
    public const RANDOM_FILL_KEY_OPTIONS = [
        'blood_group_id' => 'Blood group',
        'complexion_id' => 'Complexion',
        'physical_build_id' => 'Physical build',
        'weight_kg' => 'Weight',
        'family_type_id' => 'Family type',
        'income_currency_id' => 'Income currency',
        'mother_tongue_id' => 'Mother tongue',
        'birth_time' => 'Birth time',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function policy(): array
    {
        $raw = (string) AdminSetting::getValue(self::SETTING_KEY, '');
        if ($raw === '') {
            return self::defaults();
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? self::normalize($decoded) : self::defaults();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $d = self::defaults();
        $d['religion_ids'] = self::intList($data['religion_ids'] ?? []);
        $d['caste_ids'] = self::intList($data['caste_ids'] ?? []);
        $d['country_ids'] = self::intList($data['country_ids'] ?? []);
        $d['state_ids'] = self::intList($data['state_ids'] ?? []);
        $d['district_ids'] = self::intList($data['district_ids'] ?? []);
        $d['marital_status_ids'] = self::intList($data['marital_status_ids'] ?? []);
        $d['diet_ids'] = self::intList($data['diet_ids'] ?? []);
        $d['master_education_ids'] = self::intList($data['master_education_ids'] ?? []);

        $amin = self::boundInt($data['age_min'] ?? $d['age_min'], 18, 80, $d['age_min']);
        $amax = self::boundInt($data['age_max'] ?? $d['age_max'], 18, 80, $d['age_max']);
        if ($amin > $amax) {
            [$amin, $amax] = [$amax, $amin];
        }
        $d['age_min'] = $amin;
        $d['age_max'] = $amax;

        $hmin = self::boundInt($data['height_cm_min'] ?? $d['height_cm_min'], 120, 220, $d['height_cm_min']);
        $hmax = self::boundInt($data['height_cm_max'] ?? $d['height_cm_max'], 120, 220, $d['height_cm_max']);
        if ($hmin > $hmax) {
            [$hmin, $hmax] = [$hmax, $hmin];
        }
        $d['height_cm_min'] = $hmin;
        $d['height_cm_max'] = $hmax;

        $d['never_fill_keys'] = self::stringListInSet(
            $data['never_fill_keys'] ?? [],
            array_keys(self::NEVER_FILL_KEY_OPTIONS)
        );
        $d['random_fill_keys'] = self::stringListInSet(
            $data['random_fill_keys'] ?? [],
            array_keys(self::RANDOM_FILL_KEY_OPTIONS)
        );
        $d['about_me_templates'] = self::stringTemplates($data['about_me_templates'] ?? []);
        $d['expectations_templates'] = self::stringTemplates($data['expectations_templates'] ?? []);
        $d['fixed_spectacles_lens'] = self::fixedSpectacles($data['fixed_spectacles_lens'] ?? '');
        $d['fixed_complexion_ids'] = self::intList($data['fixed_complexion_ids'] ?? []);
        $d['fixed_physical_build_ids'] = self::intList($data['fixed_physical_build_ids'] ?? []);
        $d['fixed_smoking_status_id'] = self::nullablePositiveInt($data['fixed_smoking_status_id'] ?? null);
        $d['fixed_drinking_status_id'] = self::nullablePositiveInt($data['fixed_drinking_status_id'] ?? null);

        return $d;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'v' => 1,
            'religion_ids' => [],
            'caste_ids' => [],
            'country_ids' => [],
            'state_ids' => [],
            'district_ids' => [],
            'marital_status_ids' => [],
            'diet_ids' => [],
            'master_education_ids' => [],
            'age_min' => 23,
            'age_max' => 35,
            'height_cm_min' => 155,
            'height_cm_max' => 182,
            'never_fill_keys' => [],
            'random_fill_keys' => [],
            'about_me_templates' => [],
            'expectations_templates' => [],
            'fixed_spectacles_lens' => '',
            'fixed_complexion_ids' => [],
            'fixed_physical_build_ids' => [],
            'fixed_smoking_status_id' => null,
            'fixed_drinking_status_id' => null,
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function filterNeverFillKeys(array $keys): array
    {
        return self::stringListInSet($keys, array_keys(self::NEVER_FILL_KEY_OPTIONS));
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private static function intList(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $i = (int) $id;
            if ($i > 0) {
                $out[] = $i;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<mixed>  $items
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function stringListInSet(array $items, array $allowed): array
    {
        $set = array_flip($allowed);
        $out = [];
        foreach ($items as $item) {
            $k = is_string($item) ? trim($item) : '';
            if ($k !== '' && isset($set[$k])) {
                $out[] = $k;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<string>
     */
    private static function stringTemplates(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $s = is_string($row) ? trim($row) : '';
            if ($s !== '' && mb_strlen($s) <= 2000) {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }

    private static function fixedSpectacles(mixed $v): string
    {
        $s = is_string($v) ? trim(strtolower($v)) : '';

        return in_array($s, ['no', 'spectacles', 'contact_lens', 'both'], true) ? $s : '';
    }

    private static function nullablePositiveInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $i = (int) $v;

        return $i > 0 ? $i : null;
    }

    private static function boundInt(mixed $v, int $min, int $max, int $fallback): int
    {
        $i = (int) $v;
        if ($i < $min) {
            return $fallback;
        }
        if ($i > $max) {
            return $fallback;
        }

        return $i;
    }

    /**
     * Districts that appear on at least one non-showcase profile (same pool as showcase residence picker), for bulk policy multiselect.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, District>
     */
    public static function eligibleNonDemoDistrictModels(int $limit = 1200): \Illuminate\Database\Eloquent\Collection
    {
        $ids = DB::table('matrimony_profiles')
            ->where('is_demo', false)
            ->whereNull('deleted_at')
            ->whereNotNull('district_id')
            ->distinct()
            ->pluck('district_id')
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->values()
            ->all();
        if ($ids === []) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return District::query()
            ->whereIn('id', $ids)
            ->with(['state' => fn ($q) => $q->with('country')])
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }
}
