<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Services\Ocr\OcrQualityEvaluator;

class IntakeQualitySignalService
{
    /** @var list<string> */
    private const FAILURE_CODES = [
        BiodataIntakeOcrAttempt::FAILURE_UNREADABLE_IMAGE,
        BiodataIntakeOcrAttempt::FAILURE_TWO_COLUMN_ORDER_ISSUE,
        BiodataIntakeOcrAttempt::FAILURE_LABEL_VALUE_SPLIT,
        BiodataIntakeOcrAttempt::FAILURE_MARATHI_DIGIT_NORMALIZATION_ISSUE,
        BiodataIntakeOcrAttempt::FAILURE_PARSER_NO_FIELDS,
        BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED,
        BiodataIntakeOcrAttempt::FAILURE_PROVIDER_TIMEOUT,
        BiodataIntakeOcrAttempt::FAILURE_PROVIDER_ERROR,
        BiodataIntakeOcrAttempt::FAILURE_EMPTY_TEXT,
        BiodataIntakeOcrAttempt::FAILURE_UNKNOWN,
    ];

    /** @var array<string, list<string>> */
    private const FIELD_PATHS = [
        'full_name' => ['core.full_name', 'core.name', 'core.candidate_name'],
        'date_of_birth' => ['core.date_of_birth', 'core.dob', 'core.birth_date'],
        'height' => ['core.height_cm', 'core.height', 'core.height_text'],
        'education' => ['core.highest_education', 'core.education', 'education_history.0.degree', 'education_history.0.course_name'],
        'occupation' => ['core.occupation_title', 'core.occupation', 'core.profession', 'career_history.0.designation'],
        'primary_contact_number' => ['contacts.0.phone_number', 'contacts.0.mobile', 'core.primary_contact_number', 'core.mobile'],
        'address' => ['addresses.0.address_line', 'self_addresses.0.address_line', 'parents_addresses.0.address_line', 'core.address_line'],
        'religion' => ['core.religion_id', 'core.religion', 'core.religion_label'],
        'caste' => ['core.caste_id', 'core.caste', 'core.caste_label'],
    ];

    public function __construct(private readonly OcrQualityEvaluator $qualityEvaluator) {}

    /**
     * @param  array<string, mixed>|null  $ocrQuality
     * @param  array<string, mixed>|null  $layoutMeta
     * @param  array<int, mixed>|null  $lines
     * @param  array<int, mixed>|null  $blocks
     * @return array<string, mixed>
     */
    public function qualitySummary(
        string $text,
        ?array $ocrQuality = null,
        ?array $layoutMeta = null,
        ?array $lines = null,
        ?array $blocks = null,
    ): array {
        $text = trim($text);
        $quality = is_array($ocrQuality) && isset($ocrQuality['score'])
            ? $ocrQuality
            : $this->qualityEvaluator->evaluate($text);

        $lineCount = $this->lineCount($text);
        $layoutScore = $this->layoutScore($lines, $blocks, $layoutMeta);
        $score = isset($quality['score']) && is_numeric($quality['score'])
            ? (float) $quality['score']
            : 0.0;

        if ($text === '') {
            $score = 0.0;
        } elseif ($lineCount >= 5 && $score < 0.95) {
            $score = min(1.0, $score + 0.05);
        }

        return [
            'score' => round(max(0.0, min(1.0, $score)), 3),
            'is_low' => $text === '' || (bool) ($quality['is_low'] ?? false) || $score < 0.45,
            'failure_code' => $text === '' ? BiodataIntakeOcrAttempt::FAILURE_EMPTY_TEXT : null,
            'reasons' => array_values(array_unique(array_filter(
                is_array($quality['reasons'] ?? null) ? $quality['reasons'] : []
            ))),
            'char_count' => mb_strlen($text),
            'line_count' => $lineCount,
            'layout_score' => $layoutScore,
        ];
    }

    /**
     * @param  array<int, mixed>|null  $lines
     * @param  array<int, mixed>|null  $blocks
     * @param  array<string, mixed>|null  $layoutMeta
     */
    public function layoutScore(?array $lines = null, ?array $blocks = null, ?array $layoutMeta = null): ?float
    {
        $lineCount = is_array($lines) ? count($lines) : null;
        $blockCount = is_array($blocks) ? count($blocks) : null;
        $width = isset($layoutMeta['image_width']) && is_numeric($layoutMeta['image_width'])
            ? (int) $layoutMeta['image_width']
            : null;
        $height = isset($layoutMeta['image_height']) && is_numeric($layoutMeta['image_height'])
            ? (int) $layoutMeta['image_height']
            : null;

        if ($lineCount === null && $blockCount === null && $width === null && $height === null) {
            return null;
        }

        $score = 0.25;
        if (($lineCount ?? 0) >= 8) {
            $score += 0.35;
        } elseif (($lineCount ?? 0) >= 3) {
            $score += 0.2;
        }
        if (($blockCount ?? 0) >= 2) {
            $score += 0.15;
        }
        if (($width ?? 0) >= 700 && ($height ?? 0) >= 900) {
            $score += 0.15;
        }

        return round(max(0.0, min(1.0, $score)), 3);
    }

    /**
     * @return array<string, array{score: float, present: bool, source_path: ?string, reason: string}>
     */
    public function fieldConfidence(array $parsedJson): array
    {
        $confidence = [];
        foreach (self::FIELD_PATHS as $field => $paths) {
            [$value, $path] = $this->firstValueAtPath($parsedJson, $paths);
            $present = ! $this->isBlank($value);
            $score = $present ? $this->scorePresentValue($field, $value) : 0.1;
            $confidence[$field] = [
                'score' => $score,
                'present' => $present,
                'source_path' => $path,
                'reason' => $present ? 'parsed_value_present' : 'missing_parsed_value',
            ];
        }

        return $confidence;
    }

    /**
     * @return array<string, float>
     */
    public function fieldScoresFromText(?string $text): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return [];
        }

        return [
            'full_name' => $this->containsAny($text, ['नाव', 'name']) ? 0.55 : 0.2,
            'date_of_birth' => preg_match('/(?:जन्म|dob|birth).{0,20}\d{1,4}/iu', $text) ? 0.6 : 0.2,
            'height' => preg_match('/(?:उंची|height|cm|feet|ft)/iu', $text) ? 0.55 : 0.2,
            'education' => $this->containsAny($text, ['शिक्षण', 'education', 'b.com', 'b.a', 'be ', 'degree']) ? 0.6 : 0.2,
            'occupation' => $this->containsAny($text, ['नोकरी', 'व्यवसाय', 'occupation', 'job', 'developer']) ? 0.6 : 0.2,
            'primary_contact_number' => preg_match('/(?:\+?91)?[6-9]\d{9}/', $text) ? 0.75 : 0.2,
            'address' => $this->containsAny($text, ['पत्ता', 'address', 'गाव', 'city']) ? 0.5 : 0.2,
            'religion' => $this->containsAny($text, ['धर्म', 'religion']) ? 0.5 : 0.2,
            'caste' => $this->containsAny($text, ['जात', 'caste']) ? 0.5 : 0.2,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $parsedJson
     * @param  array<string, mixed>|null  $ocrQuality
     * @return array<string, mixed>
     */
    public function intakeSignalAttributes(
        string $text,
        ?array $parsedJson = null,
        ?string $failureCode = null,
        ?array $ocrQuality = null,
    ): array {
        $qualitySummary = $this->qualitySummary($text, $ocrQuality);
        $failureCodes = $this->failureCodes($text, $parsedJson, $failureCode);

        return [
            'quality_summary_json' => $qualitySummary,
            'failure_codes_json' => $failureCodes,
            'field_confidence_json' => is_array($parsedJson) ? $this->fieldConfidence($parsedJson) : null,
        ];
    }

    public function storeForIntake(
        BiodataIntake $intake,
        string $text,
        ?array $parsedJson = null,
        ?string $failureCode = null,
        ?array $ocrQuality = null,
    ): BiodataIntake {
        $intake->forceFill($this->intakeSignalAttributes($text, $parsedJson, $failureCode, $ocrQuality))->save();

        return $intake->refresh();
    }

    /**
     * @param  array<string, mixed>|null  $parsedJson
     * @return list<string>
     */
    public function failureCodes(string $text, ?array $parsedJson = null, ?string $failureCode = null): array
    {
        $codes = [];
        $normalized = $this->normalizeFailureCode($failureCode);
        if ($normalized !== null) {
            $codes[] = $normalized;
        }
        if (trim($text) === '') {
            $codes[] = BiodataIntakeOcrAttempt::FAILURE_EMPTY_TEXT;
        }
        if (is_array($parsedJson) && $this->hasNoKeyParsedFields($parsedJson)) {
            $codes[] = trim($text) === ''
                ? BiodataIntakeOcrAttempt::FAILURE_PARSER_NO_FIELDS
                : BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED;
        }

        return array_values(array_unique($codes));
    }

    public function normalizeFailureCode(?string $code): ?string
    {
        $code = strtolower(trim((string) $code));
        if ($code === '') {
            return null;
        }
        if (in_array($code, self::FAILURE_CODES, true)) {
            return $code;
        }
        if (str_contains($code, 'timeout')) {
            return BiodataIntakeOcrAttempt::FAILURE_PROVIDER_TIMEOUT;
        }
        if (str_contains($code, 'provider') || str_contains($code, 'sarvam') || str_contains($code, 'openai')) {
            return BiodataIntakeOcrAttempt::FAILURE_PROVIDER_ERROR;
        }
        if (str_contains($code, 'empty') || str_contains($code, 'blank')) {
            return BiodataIntakeOcrAttempt::FAILURE_EMPTY_TEXT;
        }
        if (str_contains($code, 'too_short') || str_contains($code, 'unusable') || str_contains($code, 'unreadable')) {
            return BiodataIntakeOcrAttempt::FAILURE_UNREADABLE_IMAGE;
        }
        if (str_contains($code, 'no_fields')) {
            return BiodataIntakeOcrAttempt::FAILURE_PARSER_NO_FIELDS;
        }

        return BiodataIntakeOcrAttempt::FAILURE_UNKNOWN;
    }

    /**
     * @param  list<string>  $paths
     * @return array{0: mixed, 1: ?string}
     */
    private function firstValueAtPath(array $data, array $paths): array
    {
        foreach ($paths as $path) {
            $value = data_get($data, $path);
            if (! $this->isBlank($value)) {
                return [$value, $path];
            }
        }

        return [null, null];
    }

    private function scorePresentValue(string $field, mixed $value): float
    {
        if ($field === 'primary_contact_number') {
            return preg_match('/(?:\+?91)?[6-9]\d{9}/', (string) $value) ? 0.9 : 0.45;
        }
        if (in_array($field, ['religion', 'caste'], true) && is_numeric($value)) {
            return 0.9;
        }

        return 0.85;
    }

    private function hasNoKeyParsedFields(array $parsedJson): bool
    {
        foreach ($this->fieldConfidence($parsedJson) as $row) {
            if (($row['present'] ?? false) === true) {
                return false;
            }
        }

        return true;
    }

    private function lineCount(string $text): int
    {
        if (trim($text) === '') {
            return 0;
        }
        $lines = preg_split('/\R/u', $text);

        return is_array($lines) ? count(array_filter($lines, static fn (string $line): bool => trim($line) !== '')) : 1;
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' || in_array($trimmed, ['-', '—', '–'], true);
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        $lower = mb_strtolower($text);
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($lower, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
