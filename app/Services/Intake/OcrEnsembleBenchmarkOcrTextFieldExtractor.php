<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Services\Ocr\OcrNormalize;
use App\Services\Parsing\MarathiOcrFieldRescueService;
use App\Services\Parsing\MarathiSeparatedLabelValueExtractor;
use App\Support\HeightDisplay;
use Illuminate\Support\Carbon;

/**
 * Benchmark-only field extraction from raw OCR text (not parsed_json / not full parser).
 */
class OcrEnsembleBenchmarkOcrTextFieldExtractor
{
    public function __construct(
        private readonly MarathiOcrFieldRescueService $rescueService,
    ) {}

    public function extractFromIntake(BiodataIntake $intake): array
    {
        $text = $this->ocrTextForIntake($intake);

        return $this->extractFromText($text);
    }

    public function ocrTextForIntake(BiodataIntake $intake): string
    {
        $attempt = $intake->ocrAttempts
            ?->first(static fn (BiodataIntakeOcrAttempt $row): bool => (bool) $row->is_primary)
            ?? $intake->ocrAttempts?->sortBy('id')->first();

        $fromAttempt = trim((string) ($attempt?->raw_text ?? ''));
        if ($fromAttempt !== '') {
            return $fromAttempt;
        }

        return trim((string) ($intake->raw_ocr_text ?? ''));
    }

    /**
     * @return array<string, string|null>
     */
    public function extractFromText(string $text): array
    {
        $text = OcrNormalize::normalizeDigits($text);
        $lines = $this->lines($text);
        $core = $this->rescueService->rescueCoreFields($lines, []);

        $hints = MarathiSeparatedLabelValueExtractor::extract($lines);
        if (is_array($hints)) {
            if (! empty($hints['full_name']) && empty($core['full_name'])) {
                $core['full_name'] = (string) $hints['full_name'];
            }
            if (! empty($hints['date_of_birth']) && empty($core['date_of_birth'])) {
                $core['date_of_birth'] = $this->normalizeDob((string) $hints['date_of_birth']);
            }
            if (! empty($hints['primary_contact']) && empty($core['primary_contact_number'])) {
                $core['primary_contact_number'] = (string) $hints['primary_contact'];
            }
            if (! empty($hints['highest_education']) && empty($core['highest_education'])) {
                $core['highest_education'] = (string) $hints['highest_education'];
            }
            if (! empty($hints['occupation_raw']) && empty($core['occupation_title'])) {
                $core['occupation_title'] = (string) $hints['occupation_raw'];
            }
        }

        $religion = $this->labelValue($lines, ['धर्म', 'धम', 'Religion']);
        $caste = $this->labelValue($lines, ['जात', 'Caste']);
        $subCaste = $this->labelValue($lines, ['पोटजात', 'उपजात', 'Sub caste', 'Sub-caste']);
        $income = $this->labelValue($lines, ['उत्पन्न', 'वार्षिक उत्पन्न', 'Income', 'Annual income']);
        $marital = $this->labelValue($lines, ['वैवाहिक स्थिती', 'वैवाहिक', 'Marital status']);
        $village = $this->labelValue($lines, ['गाव', 'Village']) ?? ($core['birth_place_text'] ?? null);

        $height = null;
        if (isset($core['height_cm']) && is_numeric($core['height_cm'])) {
            $height = HeightDisplay::formatCm((int) round((float) $core['height_cm']));
        }

        return [
            'full_name' => $this->stringOrNull($core['full_name'] ?? null),
            'date_of_birth' => $this->normalizeDob($this->stringOrNull($core['date_of_birth'] ?? null)),
            'gender' => $this->normalizeGender($this->stringOrNull($core['gender'] ?? null)),
            'primary_contact_number' => $this->stringOrNull($core['primary_contact_number'] ?? null),
            'height' => $height,
            'education' => $this->stringOrNull($core['highest_education'] ?? null),
            'occupation' => $this->stringOrNull($core['occupation_title'] ?? null),
            'income' => $income,
            'religion' => $religion,
            'caste' => $caste,
            'sub_caste' => $subCaste,
            'state' => $this->labelValue($lines, ['राज्य', 'State']),
            'district' => $this->labelValue($lines, ['जिल्हा', 'District']),
            'taluka' => $this->labelValue($lines, ['तालुका', 'Taluka']),
            'village' => $village,
            'marital_status' => $marital,
        ];
    }

    /**
     * @return list<string>
     */
    private function lines(string $text): array
    {
        $parts = preg_split('/\R+/u', $text) ?: [];
        $lines = [];
        foreach ($parts as $part) {
            $line = trim(preg_replace('/\s+/u', ' ', (string) $part) ?? '');
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $labels
     */
    private function labelValue(array $lines, array $labels): ?string
    {
        foreach ($lines as $index => $line) {
            foreach ($labels as $label) {
                $pattern = '/'.preg_quote($label, '/').'\s*[:：\-]?\s*(.+)$/iu';
                if (preg_match($pattern, $line, $matches) === 1) {
                    $value = trim((string) ($matches[1] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
                if (stripos($line, $label) !== false && preg_match('/'.preg_quote($label, '/').'\s*[:：]?\s*$/iu', $line) === 1) {
                    $next = trim((string) ($lines[$index + 1] ?? ''));
                    if ($next !== '') {
                        return $next;
                    }
                }
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_bool($value)) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
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
}
