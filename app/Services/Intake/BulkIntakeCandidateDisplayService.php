<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use App\Models\MasterGender;
use App\Models\OccupationCustom;
use App\Models\OccupationMaster;
use App\Services\Ocr\OcrNormalize;
use App\Support\HeightDisplay;
use Illuminate\Support\Carbon;

class BulkIntakeCandidateDisplayService
{
    private const SAFE_TEXT_LIMIT = 70;

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
     *     display_source: string,
     *     reviewed_snapshot_present: bool,
     *     missing_fields: list<string>,
     *     name_source: string|null,
     *     name_needs_review: bool,
     *     dob_needs_review: bool,
     *     height_needs_review: bool,
     *     education_needs_review: bool,
     *     occupation_needs_review: bool,
     *     display_warnings: list<string>
     * }
     */
    public function candidateForItem(BulkIntakeBatchItem $item): array
    {
        return $this->candidateForIntake($this->intakeForDisplay($item));
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
     *     display_source: string,
     *     reviewed_snapshot_present: bool,
     *     missing_fields: list<string>,
     *     name_source: string|null,
     *     name_needs_review: bool,
     *     dob_needs_review: bool,
     *     height_needs_review: bool,
     *     education_needs_review: bool,
     *     occupation_needs_review: bool,
     *     display_warnings: list<string>
     * }
     */
    public function candidateForIntake(?BiodataIntake $intake): array
    {
        $parsed = is_array($intake?->parsed_json) ? $intake->parsed_json : [];
        $reviewed = is_array($intake?->approval_snapshot_json) ? $intake->approval_snapshot_json : [];
        $reviewedSnapshotPresent = $reviewed !== [];
        $displaySource = $reviewedSnapshotPresent ? 'approval_snapshot_json' : 'parsed_json';
        $display = $reviewedSnapshotPresent ? $reviewed : $parsed;
        $core = is_array($display['core'] ?? null) ? $display['core'] : [];
        $warnings = [];

        $name = $this->candidateName($display, $intake, $warnings, $displaySource);
        $dobAge = $this->dobAge($display, $warnings);
        $height = $this->height($display, $warnings, $reviewedSnapshotPresent);
        $education = $this->safeDisplayField($this->educationRaw($display), 'education', $warnings);
        $occupation = $this->safeDisplayField($this->occupationRaw($display), 'occupation', $warnings);

        $result = [
            'full_name' => $name['value'],
            'mobile' => $this->mobileDisplay($display, $intake),
            'date_of_birth' => $dobAge['date_of_birth'],
            'age' => $dobAge['age'],
            'height' => $height['value'],
            'gender' => $this->gender($core),
            'city' => $this->city($display),
            'education' => $education['value'],
            'occupation' => $occupation['value'],
            'parse_status' => $intake?->parse_status,
            'parsed_json_present' => $parsed !== [],
            'display_source' => $displaySource,
            'reviewed_snapshot_present' => $reviewedSnapshotPresent,
            'missing_fields' => [],
            'name_source' => $name['source'],
            'name_needs_review' => $name['needs_review'],
            'dob_needs_review' => $dobAge['needs_review'],
            'height_needs_review' => $height['needs_review'],
            'education_needs_review' => $education['needs_review'],
            'occupation_needs_review' => $occupation['needs_review'],
            'display_warnings' => array_values(array_unique($warnings)),
        ];

        $result['missing_fields'] = $this->missingFields($result);

        return $result;
    }

    private function intakeForDisplay(BulkIntakeBatchItem $item): ?BiodataIntake
    {
        $loaded = $item->relationLoaded('biodataIntake') ? $item->biodataIntake : null;
        if ($loaded instanceof BiodataIntake) {
            $attributes = $loaded->getAttributes();
            if (
                array_key_exists('raw_ocr_text', $attributes)
                && array_key_exists('last_parse_input_text', $attributes)
                && array_key_exists('approval_snapshot_json', $attributes)
            ) {
                return $loaded;
            }
        }

        $intakeId = $item->biodata_intake_id ?? $loaded?->id;
        if ($intakeId === null) {
            return null;
        }

        return BiodataIntake::query()->find((int) $intakeId, [
            'id',
            'parse_status',
            'parsed_json',
            'approval_snapshot_json',
            'raw_ocr_text',
            'last_parse_input_text',
        ]);
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<string>  $warnings
     * @return array{value: string|null, source: string|null, needs_review: bool}
     */
    private function candidateName(array $parsed, ?BiodataIntake $intake, array &$warnings, string $displaySource): array
    {
        $rawName = $this->firstString($parsed, [
            'core.full_name',
            'full_name',
            'candidate.full_name',
            'candidate_name',
            'name',
            'profile.full_name',
        ]);

        if ($rawName !== null) {
            $clean = $this->cleanNameCandidate($rawName);
            if ($clean['accepted']) {
                if ($clean['needs_review']) {
                    $warnings[] = 'name_cleaned';
                }

                return [
                    'value' => $clean['value'],
                    'source' => $displaySource,
                    'needs_review' => $clean['needs_review'],
                ];
            }

            $warnings[] = 'name_rejected_'.$clean['reason'];
        }

        if ($parsed === []) {
            return [
                'value' => null,
                'source' => null,
                'needs_review' => false,
            ];
        }

        $fallback = $this->fallbackNameFromOcr($intake);
        if ($fallback !== null) {
            $warnings[] = 'name_ocr_fallback';

            return [
                'value' => $fallback,
                'source' => 'ocr_fallback',
                'needs_review' => true,
            ];
        }

        return [
            'value' => null,
            'source' => null,
            'needs_review' => false,
        ];
    }

    /**
     * @return array{accepted: bool, value: string|null, needs_review: bool, reason: string}
     */
    private function cleanNameCandidate(string $value): array
    {
        $original = $this->normalizeDisplayString($value);
        $name = $original;

        $name = preg_replace('/^[\s\p{P}\p{S}\d]+/u', '', $name) ?? $name;
        $name = preg_replace('/^(?:af|aoe|ous|a0e|os|oe|ch)\s+/iu', '', $name) ?? $name;
        $name = preg_replace('/^(?:[a-z]{1,5}\s+){1,3}(?=[\x{0900}-\x{097F}])/iu', '', $name) ?? $name;
        $name = preg_replace('/^(?:name|candidate\s+name|full\s+name|नाव|पूर्ण\s+नाव)\s*[:：\-\s]+/iu', '', $name) ?? $name;

        do {
            $before = $name;
            $name = preg_replace('/^(?:बायोडाटा|कु\.?|कुमार|कुमारी|चि\.?|चिरंजीव|सौ\.?|श्रीमती|श्री\.?)\s+/u', '', $name) ?? $name;
            $name = trim($name, " \t\n\r\0\x0B:-|.,;");
        } while ($name !== $before);

        $name = $this->normalizeDisplayString($name);
        $needsReview = $name !== $original;

        if ($name === '') {
            return ['accepted' => false, 'value' => null, 'needs_review' => false, 'reason' => 'empty'];
        }

        if (in_array(mb_strtolower($name, 'UTF-8'), ['बायोडाटा', 'bio data', 'biodata'], true)) {
            return ['accepted' => false, 'value' => null, 'needs_review' => false, 'reason' => 'title'];
        }

        if (mb_strlen($name, 'UTF-8') > 80) {
            return ['accepted' => false, 'value' => null, 'needs_review' => false, 'reason' => 'too_long'];
        }

        if ($this->containsRelationLabel($name)) {
            return ['accepted' => false, 'value' => null, 'needs_review' => false, 'reason' => 'relation_label'];
        }

        if (preg_match('/(?:\+?91[\s-]?)?[6-9]\d{9}/', preg_replace('/\s+/', '', $name) ?? '') === 1) {
            return ['accepted' => false, 'value' => null, 'needs_review' => false, 'reason' => 'phone_number'];
        }

        if ($this->junkRatio($name) > 0.30) {
            return ['accepted' => false, 'value' => null, 'needs_review' => false, 'reason' => 'junk_ratio'];
        }

        if (! preg_match('/\p{L}/u', $name)) {
            return ['accepted' => false, 'value' => null, 'needs_review' => false, 'reason' => 'no_letters'];
        }

        return [
            'accepted' => true,
            'value' => $name,
            'needs_review' => $needsReview,
            'reason' => 'ok',
        ];
    }

    private function fallbackNameFromOcr(?BiodataIntake $intake): ?string
    {
        $text = trim((string) ($intake?->last_parse_input_text ?? ''));
        if ($text === '') {
            $text = trim((string) ($intake?->raw_ocr_text ?? ''));
        }
        if ($text === '') {
            return null;
        }

        $lines = preg_split('/\R/u', $text) ?: [];
        $lines = array_values(array_filter(
            array_map(fn (string $line): string => $this->normalizeDisplayString($line), $lines),
            static fn (string $line): bool => $line !== ''
        ));
        $lines = array_slice($lines, 0, 15);

        foreach ($lines as $index => $line) {
            if (! $this->hasNameMarker($line)) {
                continue;
            }

            $clean = $this->cleanNameCandidate($line);
            if ($clean['accepted']) {
                return $clean['value'];
            }

            $next = $lines[$index + 1] ?? null;
            if ($next !== null) {
                $cleanNext = $this->cleanNameCandidate($next);
                if ($cleanNext['accepted']) {
                    return $cleanNext['value'];
                }
            }
        }

        return null;
    }

    private function hasNameMarker(string $line): bool
    {
        foreach (['बायोडाटा', 'कुमार', 'कुमारी', 'चि.', 'चिरंजीव', 'सौ.', 'श्रीमती', 'नाव', 'Name'] as $marker) {
            if (mb_stripos($line, $marker, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<string>  $warnings
     * @return array{date_of_birth: string|null, age: int|null, needs_review: bool}
     */
    private function dobAge(array $parsed, array &$warnings): array
    {
        $dateOfBirth = $this->firstString($parsed, [
            'core.date_of_birth',
            'core.dob',
            'date_of_birth',
            'dob',
        ]);

        if ($dateOfBirth !== null) {
            $age = $this->ageFromDate($dateOfBirth);
            if ($age !== null && $age >= 18 && $age <= 75) {
                return [
                    'date_of_birth' => $dateOfBirth,
                    'age' => $age,
                    'needs_review' => false,
                ];
            }

            $warnings[] = 'invalid_age_range';

            return [
                'date_of_birth' => null,
                'age' => null,
                'needs_review' => true,
            ];
        }

        $age = $this->firstInt($parsed, [
            'core.age',
            'age',
            'candidate.age',
        ]);

        if ($age === null) {
            return ['date_of_birth' => null, 'age' => null, 'needs_review' => false];
        }

        if ($age >= 18 && $age <= 75) {
            return ['date_of_birth' => null, 'age' => $age, 'needs_review' => false];
        }

        $warnings[] = 'invalid_age_range';

        return ['date_of_birth' => null, 'age' => null, 'needs_review' => true];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<string>  $warnings
     * @return array{value: string|null, needs_review: bool}
     */
    private function height(array $parsed, array &$warnings, bool $isReviewedSnapshot = false): array
    {
        if (! $isReviewedSnapshot && $this->confidenceZeroOrLowStatus($parsed, ['core.height_cm', 'height_cm'])) {
            $warnings[] = 'height_low_confidence';

            return ['value' => null, 'needs_review' => true];
        }

        $heightCm = data_get($parsed, 'core.height_cm', data_get($parsed, 'height_cm'));
        if (is_numeric($heightCm)) {
            $height = (float) $heightCm;
            if ($height >= 120 && $height <= 220) {
                $needsReview = $height >= 190;
                if ($needsReview) {
                    $warnings[] = 'height_review';
                }

                return [
                    'value' => $this->heightDisplayFromCm($height),
                    'needs_review' => $needsReview,
                ];
            }

            $warnings[] = 'height_invalid_range';

            return ['value' => null, 'needs_review' => true];
        }

        $height = $this->firstString($parsed, [
            'core.height',
            'height',
        ]);
        if ($height !== null && mb_strlen($height, 'UTF-8') <= 30 && preg_match('/\d/u', $height)) {
            $heightCmFromText = $this->heightCmFromText($height);
            if ($heightCmFromText !== null) {
                $needsReview = ! $isReviewedSnapshot || $heightCmFromText >= 190;
                if ($needsReview) {
                    $warnings[] = $heightCmFromText >= 190 ? 'height_review' : 'height_text_review';
                }

                return [
                    'value' => $this->heightDisplayFromCm($heightCmFromText),
                    'needs_review' => $needsReview,
                ];
            }

            $warnings[] = 'height_text_review';

            return ['value' => $height, 'needs_review' => true];
        }

        return ['value' => null, 'needs_review' => false];
    }

    private function heightDisplayFromCm(float $heightCm): string
    {
        return HeightDisplay::formatFeetInches((int) round($heightCm));
    }

    private function heightCmFromText(string $value): ?float
    {
        $text = OcrNormalize::normalizeDigits($this->normalizeDisplayString($value));

        if (preg_match('/^\s*(\d{3})(?:\.\d+)?\s*(?:cm|cms|centimeter|centimeters)?\s*$/i', $text, $m) === 1) {
            $heightCm = (float) $m[1];

            return $heightCm >= 120 && $heightCm <= 220 ? $heightCm : null;
        }

        if (preg_match('/^\s*([3-7])\s*(?:[\'’′]|ft\.?|feet|foot)\s*([0-9]{1,2})?\s*(?:"|”|″|in\.?|inch|inches)?\s*$/iu', $text, $m) === 1) {
            $feet = (int) $m[1];
            $inches = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
            if ($inches > 11) {
                return null;
            }

            $heightCm = ($feet * 12 + $inches) * 2.54;

            return $heightCm >= 120 && $heightCm <= 220 ? $heightCm : null;
        }

        $normalized = OcrNormalize::normalizeHeight($text);
        if (is_string($normalized) && preg_match('/([3-7])\s*[\'’′]\s*([0-9]{1,2})\s*(?:"|”|″)?/u', $normalized, $m) === 1) {
            $inches = (int) $m[2];
            if ($inches > 11) {
                return null;
            }

            $heightCm = (((int) $m[1]) * 12 + $inches) * 2.54;

            return $heightCm >= 120 && $heightCm <= 220 ? $heightCm : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function mobileDisplay(array $parsed, ?BiodataIntake $intake): ?string
    {
        $ocrSource = null;
        if ($intake !== null) {
            $parseInput = trim((string) ($intake->last_parse_input_text ?? ''));
            $rawOcr = trim((string) ($intake->raw_ocr_text ?? ''));
            $ocrSource = $parseInput !== '' ? $parseInput : ($rawOcr !== '' ? $rawOcr : null);
        }

        return app(BulkIntakeCandidateMobileCollector::class)->displayFromSources($parsed, $ocrSource);
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
     */
    private function city(array $parsed): ?string
    {
        $city = $this->safeLocation($this->firstString($parsed, [
            'core.city',
            'core.city_text',
            'core.birth_place_text',
            'city',
            'city_text',
            'location_display',
        ]));
        if ($city !== null) {
            return $city;
        }

        foreach ([data_get($parsed, 'core.native_place'), $parsed['native_place'] ?? null] as $place) {
            if (! is_array($place)) {
                continue;
            }

            $city = $this->safeLocation($this->firstString($place, [
                'city',
                'location_display',
                'address_line',
            ]));
            if ($city !== null) {
                return $city;
            }
        }

        $addresses = $parsed['addresses'] ?? [];
        if (! is_array($addresses)) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! is_array($address)) {
                continue;
            }

            $city = $this->safeLocation($this->firstString($address, ['city', 'location_display']));
            if ($city !== null) {
                return $city;
            }
        }

        return null;
    }

    private function safeLocation(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = $this->normalizeDisplayString($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > self::SAFE_TEXT_LIMIT) {
            return null;
        }

        if ($this->unrelatedMarkerCount($value) > 0) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function educationRaw(array $parsed): ?string
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
    private function occupationRaw(array $parsed): ?string
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

    /**
     * @param  list<string>  $warnings
     * @return array{value: string|null, needs_review: bool}
     */
    private function safeDisplayField(?string $value, string $field, array &$warnings): array
    {
        if ($value === null) {
            return ['value' => null, 'needs_review' => false];
        }

        $value = $this->normalizeDisplayString($value);
        if ($value === '') {
            return ['value' => null, 'needs_review' => false];
        }

        $markerCount = $this->unrelatedMarkerCount($value);
        if ($markerCount >= 2 || $this->containsRelationLabel($value)) {
            $warnings[] = $field.'_review';

            return ['value' => 'Review', 'needs_review' => true];
        }

        if (mb_strlen($value, 'UTF-8') > self::SAFE_TEXT_LIMIT) {
            $warnings[] = $field.'_truncated';

            return [
                'value' => mb_substr($value, 0, self::SAFE_TEXT_LIMIT, 'UTF-8').'...',
                'needs_review' => true,
            ];
        }

        return ['value' => $value, 'needs_review' => false];
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

        $string = $this->normalizeDisplayString((string) $value);

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

    private function normalizeDisplayString(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value));

        return is_string($value) ? trim($value) : '';
    }

    private function containsRelationLabel(string $value): bool
    {
        $lower = mb_strtolower($value, 'UTF-8');
        foreach (['वडील', 'आई', 'मामा', 'आत्या', 'भाऊ', 'बहीण', 'बहिण', 'काका', 'चुलते', 'आजोळ'] as $label) {
            if (mb_stripos($lower, $label, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return preg_match('/(^|\s)(father|mother|brother|sister|uncle|aunt)(\s|$)/iu', $value) === 1;
    }

    private function junkRatio(string $value): float
    {
        $compact = preg_replace('/\s+/u', '', $value) ?? '';
        $length = mb_strlen($compact, 'UTF-8');
        if ($length === 0) {
            return 1.0;
        }

        $junk = preg_match_all('/[^\p{L}\p{M}.]/u', $compact);

        return ((int) $junk) / $length;
    }

    private function unrelatedMarkerCount(string $value): int
    {
        $count = 0;
        foreach ([
            'मोबाईल',
            'मोबाइल',
            'Mobile',
            'DOB',
            'जन्म',
            'वडील',
            'आई',
            'मामा',
            'भाऊ',
            'बहिण',
            'पत्ता',
            'Address',
            'Height',
            'उंची',
            'धर्म',
            'जात',
        ] as $marker) {
            if (mb_stripos($value, $marker, 0, 'UTF-8') !== false) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<string>  $paths
     */
    private function confidenceZeroOrLowStatus(array $parsed, array $paths): bool
    {
        $confidence = is_array($parsed['confidence_map'] ?? null) ? $parsed['confidence_map'] : [];
        foreach ($paths as $path) {
            if (array_key_exists($path, $confidence) && (float) $confidence[$path] <= 0.0) {
                return true;
            }
        }

        foreach (['field_status', 'status_map', 'missing_map'] as $root) {
            foreach ($paths as $path) {
                $status = data_get($parsed, $root.'.'.$path);
                if (is_string($status) && $status === 'low_confidence') {
                    return true;
                }
            }
        }

        return false;
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
     *     display_source: string,
     *     reviewed_snapshot_present: bool,
     *     missing_fields: list<string>,
     *     name_source: string|null,
     *     name_needs_review: bool,
     *     dob_needs_review: bool,
     *     height_needs_review: bool,
     *     education_needs_review: bool,
     *     occupation_needs_review: bool,
     *     display_warnings: list<string>
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
