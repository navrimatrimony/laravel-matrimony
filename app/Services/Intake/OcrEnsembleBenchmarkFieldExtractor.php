<?php

namespace App\Services\Intake;

use App\Models\Caste;
use App\Models\Religion;
use App\Models\SubCaste;
use App\Support\HeightDisplay;
use App\Support\MobileNumber;
use Illuminate\Support\Carbon;

/**
 * Read benchmark fields from approval_snapshot_json (ground truth SSOT).
 */
class OcrEnsembleBenchmarkFieldExtractor
{
    /** @var list<string> */
    public const ALL_FIELDS = [
        'full_name',
        'date_of_birth',
        'gender',
        'primary_contact_number',
        'height',
        'education',
        'occupation',
        'income',
        'religion',
        'caste',
        'sub_caste',
        'state',
        'district',
        'taluka',
        'village',
        'marital_status',
    ];

    /** @var list<string> */
    public const CRITICAL_FIELDS = [
        'full_name',
        'date_of_birth',
        'primary_contact_number',
        'religion',
        'gender',
    ];

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, string|null>
     */
    public function extract(array $document): array
    {
        $fields = [];
        foreach (self::ALL_FIELDS as $field) {
            $fields[$field] = $this->extractOne($document, $field);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function extractOne(array $document, string $field): ?string
    {
        return match ($field) {
            'full_name' => $this->firstString($document, ['core.full_name', 'full_name']),
            'date_of_birth' => $this->normalizeDob($this->firstString($document, ['core.date_of_birth', 'core.dob', 'date_of_birth', 'dob'])),
            'gender' => $this->normalizeGender($this->firstString($document, ['core.gender', 'gender'])),
            'primary_contact_number' => $this->firstString($document, [
                'core.primary_contact_number',
                'core.mobile',
                'primary_contact_number',
                'mobile',
                'contacts.0.phone_number',
            ]),
            'height' => $this->extractHeight($document),
            'education' => $this->firstString($document, [
                'core.highest_education',
                'core.highest_education_other',
                'highest_education',
                'education',
            ]),
            'occupation' => $this->firstString($document, [
                'core.occupation',
                'core.occupation_title',
                'core.occupation_custom',
                'occupation',
                'occupation_title',
            ]),
            'income' => $this->firstString($document, ['core.income', 'income', 'core.annual_income', 'annual_income']),
            'religion' => $this->communityLabel($document, 'religion'),
            'caste' => $this->communityLabel($document, 'caste'),
            'sub_caste' => $this->communityLabel($document, 'sub_caste'),
            'state' => $this->firstString($document, ['core.state', 'state', 'core.native_place.state', 'native_place.state']),
            'district' => $this->firstString($document, ['core.district', 'district', 'core.native_place.district', 'native_place.district']),
            'taluka' => $this->firstString($document, ['core.taluka', 'taluka', 'core.native_place.taluka', 'native_place.taluka']),
            'village' => $this->firstString($document, ['core.village', 'village', 'core.native_place.village', 'native_place.village']),
            'marital_status' => $this->firstString($document, ['core.marital_status', 'marital_status']),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $paths
     */
    private function firstString(array $document, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($document, $path);
            if ($value === null || is_array($value) || is_bool($value)) {
                continue;
            }
            $string = trim((string) $value);
            if ($string !== '') {
                return $string;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function extractHeight(array $document): ?string
    {
        $heightCm = data_get($document, 'core.height_cm', data_get($document, 'height_cm'));
        if (is_numeric($heightCm)) {
            return HeightDisplay::formatCm((int) round((float) $heightCm));
        }

        return $this->firstString($document, ['core.height', 'height', 'core.height_text', 'height_text']);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function communityLabel(array $document, string $field): ?string
    {
        $idKey = $field === 'sub_caste' ? 'sub_caste_id' : $field.'_id';
        $id = data_get($document, 'core.'.$idKey);
        if (is_numeric($id)) {
            $row = match ($field) {
                'religion' => Religion::query()->find((int) $id),
                'caste' => Caste::query()->find((int) $id),
                'sub_caste' => SubCaste::query()->find((int) $id),
                default => null,
            };
            if ($row !== null) {
                return trim((string) ($row->display_label ?? $row->label ?? $row->label_mr ?? '')) ?: null;
            }
        }

        return $this->firstString($document, ['core.'.$field, $field]);
    }

    private function normalizeDob(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return trim($value);
        }
    }

    private function normalizeGender(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $lower = strtolower(trim($value));

        return in_array($lower, ['male', 'female', 'unknown'], true) ? $lower : trim($value);
    }

    /**
     * @param  array<string, string|null>  $truth
     * @param  array<string, string|null>  $prediction
     */
    public function countMismatches(array $truth, array $prediction): int
    {
        $count = 0;
        foreach (self::ALL_FIELDS as $field) {
            $truthValue = $truth[$field] ?? null;
            if ($truthValue === null || trim($truthValue) === '') {
                continue;
            }
            if (! OcrEnsembleBenchmarkFieldMatcher::match($field, $truthValue, $prediction[$field] ?? null)) {
                $count++;
            }
        }

        return $count;
    }

    public function normalizeMobile(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        return MobileNumber::normalize($value) ?? (preg_replace('/\D/u', '', $value) ?? '');
    }
}
