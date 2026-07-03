<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;

class IntakeCriticalFieldParserProposalService
{
    private const LOW_CONFIDENCE_THRESHOLD = 0.65;

    private const CRITICAL_FIELDS = [
        'full_name',
        'date_of_birth',
        'primary_contact_number',
    ];

    /** @var array<string, int> */
    private const MARATHI_MONTHS = [
        'जानेवारी' => 1,
        'फेब्रुवारी' => 2,
        'मार्च' => 3,
        'एप्रिल' => 4,
        'मे' => 5,
        'जून' => 6,
        'जुलै' => 7,
        'ऑगस्ट' => 8,
        'सप्टेंबर' => 9,
        'ऑक्टोबर' => 10,
        'नोव्हेंबर' => 11,
        'डिसेंबर' => 12,
    ];

    /** @var array<string, int> */
    private const ENGLISH_MONTHS = [
        'jan' => 1,
        'january' => 1,
        'feb' => 2,
        'february' => 2,
        'mar' => 3,
        'march' => 3,
        'apr' => 4,
        'april' => 4,
        'may' => 5,
        'jun' => 6,
        'june' => 6,
        'jul' => 7,
        'july' => 7,
        'aug' => 8,
        'august' => 8,
        'sep' => 9,
        'sept' => 9,
        'september' => 9,
        'oct' => 10,
        'october' => 10,
        'nov' => 11,
        'november' => 11,
        'dec' => 12,
        'december' => 12,
    ];

    /**
     * @param  array<string, mixed>|null  $signals
     * @param  list<string>|null  $onlyFields
     * @return array<string, mixed>
     */
    public function analyze(BiodataIntake $intake, ?array $signals = null, ?array $onlyFields = null): array
    {
        $signals ??= $this->arrayValue(data_get($intake->routing_recommendation_json, 'signals'));
        $missingCriticalFields = $this->criticalMissingFields($intake, $signals);

        if ($onlyFields !== null) {
            $onlyCriticalFields = $this->criticalFieldList($onlyFields);
            $missingCriticalFields = array_values(array_intersect($missingCriticalFields, $onlyCriticalFields));
        }

        return $this->analyzeRawText((string) ($intake->raw_ocr_text ?? ''), $missingCriticalFields);
    }

    /**
     * @param  list<string>  $missingCriticalFields
     * @return array<string, mixed>
     */
    public function analyzeRawText(string $rawText, array $missingCriticalFields): array
    {
        $missingCriticalFields = $this->criticalFieldList($missingCriticalFields);
        $phone = $this->phoneProposal($rawText);
        $dob = $this->dobProposal($rawText);
        $name = $this->nameProposal($rawText);

        $statusByField = [
            'full_name' => $name['proposed'],
            'date_of_birth' => $dob['proposed'],
            'primary_contact_number' => $phone['proposed'],
        ];
        $confidenceByField = [
            'full_name' => $name['confidence'],
            'date_of_birth' => $dob['confidence'],
            'primary_contact_number' => $phone['confidence'],
        ];

        $rawEvidenceAbsentFields = [];
        $ambiguousFields = [];
        foreach ($missingCriticalFields as $field) {
            $status = $statusByField[$field] ?? 'no';
            if ($status === 'ambiguous') {
                $ambiguousFields[] = $field;

                continue;
            }

            if ($status !== 'yes') {
                $rawEvidenceAbsentFields[] = $field;
            }
        }

        $hasAmbiguousCriticalProposal = $ambiguousFields !== [];
        $allMissingHaveSafeProposal = $missingCriticalFields !== []
            && $rawEvidenceAbsentFields === []
            && ! $hasAmbiguousCriticalProposal;
        $outcome = match (true) {
            $hasAmbiguousCriticalProposal => 'manual_review',
            $allMissingHaveSafeProposal => 'parser_improvement_candidate',
            default => 'provider_candidate',
        };

        return [
            'missing_critical_fields' => $missingCriticalFields,
            'full_name_proposed' => $name['proposed'],
            'full_name_candidate_line_count' => $name['candidate_line_count'],
            'full_name_word_count' => $name['word_count'],
            'date_of_birth_proposed' => $dob['proposed'],
            'date_of_birth_pattern_type' => $dob['pattern_type'],
            'date_of_birth_normalized' => $dob['normalized'],
            'primary_contact_number_proposed' => $phone['proposed'],
            'primary_contact_number_candidate_count' => $phone['candidate_count'],
            'primary_contact_number_masked' => $phone['masked'],
            'proposal_confidence' => $confidenceByField,
            'all_missing_critical_fields_have_safe_proposal' => $allMissingHaveSafeProposal,
            'missing_critical_fields_resolved_by_proposal' => $allMissingHaveSafeProposal,
            'has_ambiguous_critical_proposal' => $hasAmbiguousCriticalProposal,
            'ambiguous_critical_fields' => $ambiguousFields,
            'raw_evidence_absent_fields' => $rawEvidenceAbsentFields,
            'parser_proposal_outcome' => $outcome,
        ];
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return list<string>
     */
    private function criticalMissingFields(BiodataIntake $intake, array $signals): array
    {
        $fromSignals = $this->criticalFieldList($signals['low_confidence_critical_fields'] ?? []);
        if ($fromSignals !== []) {
            return $fromSignals;
        }

        $fields = $this->criticalFieldList($signals['low_confidence_fields'] ?? []);
        $fieldConfidence = $this->arrayValue($intake->field_confidence_json);
        foreach (self::CRITICAL_FIELDS as $field) {
            $signal = $this->arrayValue($fieldConfidence[$field] ?? []);
            if ($signal !== [] && $this->isLowConfidenceSignal($signal)) {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param  array<string, mixed>  $signal
     */
    private function isLowConfidenceSignal(array $signal): bool
    {
        $score = $this->nullableFloat($signal['score'] ?? null);
        if ($score !== null && $score < self::LOW_CONFIDENCE_THRESHOLD) {
            return true;
        }

        $present = $signal['present'] ?? null;
        if ($present === false || $present === 0 || $present === '0' || $present === 'false') {
            return true;
        }

        $status = is_scalar($signal['status'] ?? null) ? strtolower(trim((string) $signal['status'])) : '';

        return in_array($status, ['low', 'missing', 'unknown'], true);
    }

    /**
     * @return array{proposed: string, candidate_count: int, confidence: string, masked: ?string}
     */
    private function phoneProposal(string $text): array
    {
        $text = $this->normalizeDigits($text);
        preg_match_all('/(?<!\d)(?:\+?\s*91[\s.\-]*)?([6-9](?:[\s.\-]*\d){9})(?!\d)/', $text, $matches);
        $normalized = [];
        foreach ($matches[1] ?? [] as $match) {
            $digits = preg_replace('/\D+/', '', $match) ?? '';
            if (strlen($digits) === 10) {
                $normalized[] = $digits;
            }
        }

        $normalized = array_values(array_unique($normalized));
        $count = count($normalized);
        $confidence = match (true) {
            $count === 1 => 'high',
            $count > 1 => 'medium',
            default => 'none',
        };

        return [
            'proposed' => $count > 0 ? 'yes' : 'no',
            'candidate_count' => $count,
            'confidence' => $confidence,
            'masked' => $count > 0 ? '******'.substr($normalized[0], -4) : null,
        ];
    }

    /**
     * @return array{proposed: string, normalized: ?string, pattern_type: string, confidence: string}
     */
    private function dobProposal(string $text): array
    {
        $text = $this->normalizeDigits($text);
        $candidates = [];
        $ambiguous = false;
        $patternType = 'none';

        if (preg_match_all('/(?<!\d)(\d{4})\s*[\/.\-]\s*(\d{1,2})\s*[\/.\-]\s*(\d{1,2})(?!\d)/u', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $date = $this->normalizedDate((int) $match[1], (int) $match[2], (int) $match[3]);
                if ($date !== null) {
                    $candidates[] = $date;
                    $patternType = 'numeric_date';
                }
            }
        }

        if (preg_match_all('/(?<!\d)(\d{1,2})\s*[\/.\-]\s*(\d{1,2})\s*[\/.\-]\s*(\d{2,4})(?!\d)/u', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $first = (int) $match[1];
                $second = (int) $match[2];
                $year = $this->normalizeYear((int) $match[3]);
                if ($year === null) {
                    continue;
                }

                if ($first <= 12 && $second <= 12) {
                    $ambiguous = true;
                    $patternType = 'numeric_date';

                    continue;
                }

                $day = $first > 12 ? $first : $second;
                $month = $first > 12 ? $second : $first;
                $date = $this->normalizedDate($year, $month, $day);
                if ($date !== null) {
                    $candidates[] = $date;
                    $patternType = 'numeric_date';
                }
            }
        }

        foreach (self::MARATHI_MONTHS as $monthName => $month) {
            if (preg_match_all('/(?<!\d)(\d{1,2})\s*'.$monthName.'\s*(\d{2,4})(?!\d)/u', $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $year = $this->normalizeYear((int) $match[2]);
                    $date = $year !== null ? $this->normalizedDate($year, $month, (int) $match[1]) : null;
                    if ($date !== null) {
                        $candidates[] = $date;
                        $patternType = 'marathi_month_name';
                    }
                }
            }
        }

        if (preg_match_all('/(?<!\d)(\d{1,2})\s+([A-Za-z]+)\s+(\d{2,4})(?!\d)/u', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $month = self::ENGLISH_MONTHS[strtolower($match[2])] ?? null;
                $year = $this->normalizeYear((int) $match[3]);
                $date = $month !== null && $year !== null ? $this->normalizedDate($year, $month, (int) $match[1]) : null;
                if ($date !== null) {
                    $candidates[] = $date;
                    $patternType = 'english_month_name';
                }
            }
        }

        $candidates = array_values(array_unique($candidates));
        if (count($candidates) === 1 && ! $ambiguous) {
            return [
                'proposed' => 'yes',
                'normalized' => $candidates[0],
                'pattern_type' => $patternType,
                'confidence' => 'high',
            ];
        }

        if ($ambiguous || count($candidates) > 1) {
            return [
                'proposed' => 'ambiguous',
                'normalized' => null,
                'pattern_type' => $patternType !== 'none' ? $patternType : 'numeric_date',
                'confidence' => 'low',
            ];
        }

        return [
            'proposed' => 'no',
            'normalized' => null,
            'pattern_type' => 'none',
            'confidence' => 'none',
        ];
    }

    /**
     * @return array{proposed: string, candidate_line_count: int, word_count: int, confidence: string}
     */
    private function nameProposal(string $text): array
    {
        $lines = preg_split('/\R/u', $text);
        $lines = is_array($lines) ? $lines : [];
        $candidateLineCount = 0;
        $maxWordCount = 0;
        $labelledCandidate = false;

        foreach ($lines as $index => $line) {
            $line = trim((string) $line);
            if ($line === '' || $this->phoneProposal($line)['proposed'] === 'yes' || $this->dobProposal($line)['proposed'] !== 'no') {
                continue;
            }

            $wordCount = $this->letterWordCount($line);
            if ($this->hasNonNameLabel($line)) {
                continue;
            }

            $hasNameLabel = preg_match('/(?:नाव|नांव|name|candidate|वधू|वर|मुलगा|मुलगी)/iu', $line) === 1;
            $earlyLikelyNameLine = $index < 5 && $wordCount >= 2 && $wordCount <= 6;
            if ($wordCount >= 2 && ($hasNameLabel || $earlyLikelyNameLine)) {
                $candidateLineCount++;
                $maxWordCount = max($maxWordCount, $wordCount);
                $labelledCandidate = $labelledCandidate || $hasNameLabel;
            }
        }

        $confidence = match (true) {
            $candidateLineCount === 1 && $labelledCandidate => 'high',
            $candidateLineCount > 0 => 'medium',
            default => 'none',
        };

        return [
            'proposed' => $candidateLineCount > 0 ? 'yes' : 'no',
            'candidate_line_count' => $candidateLineCount,
            'word_count' => $maxWordCount,
            'confidence' => $confidence,
        ];
    }

    private function hasNonNameLabel(string $line): bool
    {
        return preg_match(
            '/(?:\b(?:education|qualification|degree|occupation|job|mobile|phone|contact|dob|birth|date|address|height|age|caste|gotra|salary|income)\b|शिक्षण|व्यवसाय|नोकरी|मोबाईल|फोन|संपर्क|जन्म|तारीख|पत्ता|उंची|वय|जात|गोत्र|उत्पन्न)/iu',
            $line
        ) === 1;
    }

    private function normalizedDate(int $year, int $month, int $day): ?string
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        if ($year < 1900 || $year > (int) date('Y')) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function normalizeYear(int $year): ?int
    {
        if ($year >= 1000) {
            return $year;
        }

        if ($year >= 0 && $year <= 30) {
            return 2000 + $year;
        }

        if ($year >= 31 && $year <= 99) {
            return 1900 + $year;
        }

        return null;
    }

    private function normalizeDigits(string $text): string
    {
        return strtr($text, [
            '०' => '0',
            '१' => '1',
            '२' => '2',
            '३' => '3',
            '४' => '4',
            '५' => '5',
            '६' => '6',
            '७' => '7',
            '८' => '8',
            '९' => '9',
        ]);
    }

    private function letterWordCount(string $text): int
    {
        preg_match_all('/\p{L}+/u', $text, $matches);

        return count($matches[0] ?? []);
    }

    /**
     * @return list<string>
     */
    private function criticalFieldList(mixed $value): array
    {
        return array_values(array_filter(
            $this->tokenList($value),
            static fn (string $field): bool => in_array($field, self::CRITICAL_FIELDS, true)
        ));
    }

    /**
     * @return list<string>
     */
    private function tokenList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $value
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function nullableFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 4);
    }
}
