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
        private readonly OcrEnsembleBenchmarkCommunityExtractor $communityExtractor,
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

        $community = $this->communityExtractor->extract($lines);
        $education = $this->stringOrNull($core['highest_education'] ?? null) ?? $this->extractEducation($lines);
        $occupation = $this->stringOrNull($core['occupation_title'] ?? null) ?? $this->extractOccupation($lines);
        $dob = $this->normalizeDob($this->stringOrNull($core['date_of_birth'] ?? null)) ?? $this->extractDob($lines);
        $income = $this->labelValue($lines, ['उत्पन्न', 'वार्षिक उत्पन्न', 'Income', 'Annual income', 'पगार', 'वेतन']);
        $marital = $this->labelValue($lines, ['वैवाहिक स्थिती', 'वैवाहिक', 'Marital status', 'विवाह']);
        $village = $this->labelValue($lines, ['गाव', 'Village', 'जन्म स्थळ', 'जन्म ठिकाण']) ?? ($core['birth_place_text'] ?? null);

        $height = null;
        if (isset($core['height_cm']) && is_numeric($core['height_cm'])) {
            $height = HeightDisplay::formatCm((int) round((float) $core['height_cm']));
        }

        return [
            'full_name' => $this->cleanBenchmarkName($this->stringOrNull($core['full_name'] ?? null)),
            'date_of_birth' => $dob,
            'gender' => $this->normalizeGender($this->stringOrNull($core['gender'] ?? null)),
            'primary_contact_number' => $this->stringOrNull($core['primary_contact_number'] ?? null),
            'height' => $height,
            'education' => $education,
            'occupation' => $occupation,
            'income' => $income,
            'religion' => $community['religion'],
            'caste' => $community['caste'],
            'sub_caste' => $community['sub_caste'],
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
                $quoted = preg_quote($label, '/');
                if (preg_match('/(?:^|[\s,*•\-])(?:'.$quoted.')\s*(?::\s*-\s*|[:\-：]\s*|[८8]\s*|\s+)\s*(.+)$/ui', $line, $matches) === 1) {
                    $value = trim((string) ($matches[1] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
                if (preg_match('/(?:'.$quoted.')\s*[:：]?\s*$/ui', $line) === 1) {
                    $next = trim((string) ($lines[$index + 1] ?? ''));
                    if ($next !== '') {
                        return $next;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function extractEducation(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/शिक्षण|शैक्षणिक|education/ui', $line) !== 1) {
                continue;
            }
            if (preg_match('/(?:शिक्षण|शैक्षणिक\s*पात्रता|education)\s*(?::\s*-\s*|[:\-：]\s*|[८8]\s*|\s+)\s*(.+)$/ui', $line, $m) === 1) {
                $value = trim((string) $m[1]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach ($lines as $line) {
            if (preg_match('/\b(?:B\.?\s*E\.?|BE|B\.?\s*Tech|B\.?\s*Com|M\.?\s*Sc|MBA|MBBS|BAMS|BDS|MCA|BCA|SSC|HSC|Diploma)\b/ui', $line) === 1) {
                return trim($line);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function extractOccupation(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/नोकरी|नौकरी|व्यवसाय|occupation|profession|designation|job/ui', $line) !== 1) {
                continue;
            }
            if (preg_match('/(?:नोकरी|नौकरी|व्यवसाय|occupation|profession|designation|job)\s*(?::\s*-\s*|[:\-：]\s*|[८8]\s*|\s+)\s*(.+)$/ui', $line, $m) === 1) {
                $value = trim((string) $m[1]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function extractDob(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/जन्म\s*तारीख|जन्मतारीख|जन्म\s*दिनांक|DOB|date\s*of\s*birth/ui', $line) !== 1) {
                continue;
            }
            if (preg_match('/(\d{1,2}\s*[\/.-]\s*\d{1,2}\s*[\/.-]\s*\d{2,4})/u', $line, $m) === 1) {
                return $this->normalizeDob($m[1]);
            }
        }

        return null;
    }

    private function cleanBenchmarkName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        $name = trim($name);
        $name = preg_replace('/^(?:\*[\s]*)?(?:कु\.|कुं\.|कुमारी\s+|चि\.|चिरंजीव\s+|श्री\.|श्रीमती\s+|सौ\.)/u', '', $name) ?? $name;

        return trim($name) === '' ? null : trim($name);
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
