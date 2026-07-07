<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use App\Models\MasterGender;
use App\Models\OccupationCustom;
use App\Models\OccupationMaster;
use Illuminate\Support\Carbon;

class BulkIntakeCandidateDisplayService
{
    /**
     * @return array{
     *     full_name: string|null,
     *     mobile: string|null,
     *     date_of_birth: string|null,
     *     age: int|null,
     *     height: string|null,
     *     gender: string|null,
     *     city: string|null,
     *     education: string|null,
     *     occupation: string|null,
     *     parse_status: string|null,
     *     parsed_json_present: bool,
     *     missing_fields: list<string>
     * }
     */
    public function candidateForItem(BulkIntakeBatchItem $item): array
    {
        $item->loadMissing('biodataIntake:id,parse_status,parsed_json');

        return $this->candidateForIntake($item->biodataIntake);
    }

    /**
     * @return array{
     *     full_name: string|null,
     *     mobile: string|null,
     *     date_of_birth: string|null,
     *     age: int|null,
     *     height: string|null,
     *     gender: string|null,
     *     city: string|null,
     *     education: string|null,
     *     occupation: string|null,
     *     parse_status: string|null,
     *     parsed_json_present: bool,
     *     missing_fields: list<string>
     * }
     */
    public function candidateForIntake(?BiodataIntake $intake): array
    {
        $parsed = is_array($intake?->parsed_json) ? $intake->parsed_json : [];
        $core = is_array($parsed['core'] ?? null) ? $parsed['core'] : [];

        $dateOfBirth = $this->firstString($parsed, [
            'core.date_of_birth',
            'core.dob',
            'date_of_birth',
            'dob',
        ]);
        $ageFromDob = $this->ageFromDate($dateOfBirth);
        $age = $ageFromDob ?? $this->firstInt($parsed, [
            'core.age',
            'age',
            'candidate.age',
        ]);

        $result = [
            'full_name' => $this->firstString($parsed, [
                'core.full_name',
                'full_name',
                'candidate.full_name',
                'candidate_name',
                'name',
                'profile.full_name',
            ]),
            'mobile' => $this->mobile($parsed),
            'date_of_birth' => $dateOfBirth,
            'age' => $age,
            'height' => $this->height($parsed),
            'gender' => $this->gender($core),
            'city' => $this->city($parsed, $core),
            'education' => $this->education($parsed),
            'occupation' => $this->occupation($parsed),
            'parse_status' => $intake?->parse_status,
            'parsed_json_present' => $parsed !== [],
            'missing_fields' => [],
        ];

        $result['missing_fields'] = $this->missingFields($result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function mobile(array $parsed): ?string
    {
        $mobile = $this->firstString($parsed, [
            'core.primary_contact_number',
            'core.mobile',
            'core.user_contact_1',
            'core.contact_number',
            'primary_contact_number',
            'mobile',
            'user_contact_1',
            'contact_number',
        ]);
        if ($mobile !== null) {
            return $mobile;
        }

        $contacts = $parsed['contacts'] ?? [];
        if (! is_array($contacts)) {
            return null;
        }

        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $mobile = $this->firstString($contact, [
                'phone_number',
                'number',
                'mobile',
                'contact_number',
            ]);
            if ($mobile !== null) {
                return $mobile;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function height(array $parsed): ?string
    {
        $heightCm = data_get($parsed, 'core.height_cm', data_get($parsed, 'height_cm'));
        if (is_numeric($heightCm) && (float) $heightCm > 0) {
            return ((string) (int) round((float) $heightCm)).' cm';
        }

        $height = $this->firstString($parsed, [
            'core.height',
            'height',
        ]);
        if ($height !== null) {
            return $height;
        }

        if (is_string($heightCm) && trim($heightCm) !== '') {
            return trim($heightCm);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function gender(array $core): ?string
    {
        $gender = $this->stringValue($core['gender'] ?? null);
        if ($gender !== null) {
            $lower = strtolower($gender);

            return in_array($lower, ['male', 'female', 'other'], true) ? ucfirst($lower) : $gender;
        }

        $genderId = $this->intValue($core['gender_id'] ?? null);
        if ($genderId === null) {
            return null;
        }

        $row = MasterGender::query()->find($genderId);
        if ($row) {
            return (string) ($row->label ?? $row->key ?? $genderId);
        }

        return (string) $genderId;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $core
     */
    private function city(array $parsed, array $core): ?string
    {
        $city = $this->firstString($parsed, [
            'core.location',
            'core.city',
            'core.city_text',
            'core.address_line',
            'core.native_place',
            'core.work_location_text',
            'location',
            'city',
            'city_text',
            'address_line',
        ]);
        if ($city !== null) {
            return $city;
        }

        foreach ([$core['native_place'] ?? null, $parsed['native_place'] ?? null] as $place) {
            $city = $this->locationFromObject($place);
            if ($city !== null) {
                return $city;
            }
        }

        $addresses = $parsed['addresses'] ?? [];
        if (! is_array($addresses)) {
            return null;
        }

        foreach ($addresses as $address) {
            $city = $this->locationFromObject($address);
            if ($city !== null) {
                return $city;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function education(array $parsed): ?string
    {
        $education = $this->firstString($parsed, [
            'core.highest_education',
            'core.highest_education_other',
            'highest_education',
            'education',
        ]);
        if ($education !== null) {
            return $education;
        }

        $rows = $parsed['education_history'] ?? [];
        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $education = $this->firstString($row, ['degree', 'specialization', 'institution']);
            if ($education !== null) {
                return $education;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function occupation(array $parsed): ?string
    {
        $occupation = $this->firstString($parsed, [
            'core.occupation',
            'core.occupation_title',
            'core.occupation_custom',
            'occupation',
            'occupation_title',
            'occupation_custom',
        ]);
        if ($occupation !== null) {
            return $occupation;
        }

        $occupationMasterId = $this->intValue(data_get($parsed, 'core.occupation_master_id'));
        if ($occupationMasterId !== null) {
            $row = OccupationMaster::query()->find($occupationMasterId);

            return $row ? (string) ($row->name_mr ?? $row->name ?? $occupationMasterId) : (string) $occupationMasterId;
        }

        $occupationCustomId = $this->intValue(data_get($parsed, 'core.occupation_custom_id'));
        if ($occupationCustomId !== null) {
            $row = OccupationCustom::query()->find($occupationCustomId);

            return $row ? (string) ($row->raw_name ?? $row->normalized_name ?? $occupationCustomId) : (string) $occupationCustomId;
        }

        $rows = $parsed['career_history'] ?? [];
        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $occupation = $this->firstString($row, ['occupation_title', 'job_title', 'designation', 'role']);
            if ($occupation !== null) {
                return $occupation;
            }
        }

        return null;
    }

    private function ageFromDate(?string $date): ?int
    {
        if ($date === null || ! preg_match('/^\d{1,4}[\/\-.]\d{1,2}[\/\-.]\d{1,4}$/', $date)) {
            return null;
        }

        try {
            $age = Carbon::parse($date)->age;
        } catch (\Throwable) {
            return null;
        }

        return $age >= 0 && $age <= 120 ? $age : null;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $paths
     */
    private function firstString(array $source, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($source, $path);
            $string = $this->stringValue($value);
            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $paths
     */
    private function firstInt(array $source, array $paths): ?int
    {
        foreach ($paths as $path) {
            $int = $this->intValue(data_get($source, $path));
            if ($int !== null) {
                return $int;
            }
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' || in_array(strtolower($string), ['null', 'nil', 'n/a', 'na', 'none', 'undefined', '-'], true)
            ? null
            : $string;
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value))) {
            return (int) trim($value);
        }

        return null;
    }

    private function locationFromObject(mixed $value): ?string
    {
        if (! is_array($value)) {
            return $this->stringValue($value);
        }

        foreach (['city', 'location_text', 'location_display', 'place', 'village', 'taluka', 'district', 'state', 'address_line', 'raw', 'name'] as $key) {
            $string = $this->stringValue($value[$key] ?? null);
            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    /**
     * @param  array{
     *     full_name: string|null,
     *     mobile: string|null,
     *     date_of_birth: string|null,
     *     age: int|null,
     *     height: string|null,
     *     gender: string|null,
     *     city: string|null,
     *     education: string|null,
     *     occupation: string|null,
     *     parse_status: string|null,
     *     parsed_json_present: bool,
     *     missing_fields: list<string>
     * }  $candidate
     * @return list<string>
     */
    private function missingFields(array $candidate): array
    {
        $missing = [];
        foreach (['full_name', 'mobile', 'height', 'gender', 'city', 'education', 'occupation'] as $field) {
            if ($candidate[$field] === null) {
                $missing[] = $field;
            }
        }

        if ($candidate['date_of_birth'] === null && $candidate['age'] === null) {
            $missing[] = 'date_of_birth_or_age';
        }

        return $missing;
    }
}
