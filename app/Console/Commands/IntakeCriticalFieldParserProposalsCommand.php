<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class IntakeCriticalFieldParserProposalsCommand extends Command
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

    protected $signature = 'intake:critical-field-parser-proposals
        {--limit=100 : Maximum latest intakes with stored routing/confidence data to inspect}
        {--json : Print the report as JSON}
        {--field= : Include only one critical field: full_name, date_of_birth, primary_contact_number}
        {--action= : Include only rows with this recommended_action}
        {--include-locked : Include locked intakes}
        {--show-safe-values : Show masked phone and unambiguous normalized DOB values}';

    protected $description = 'Read-only parser proposal report for missing critical fields from stored raw OCR text.';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $field = $this->fieldOption();
        if ($field === false) {
            return self::FAILURE;
        }

        $action = $this->tokenOption('action');
        $includeLocked = (bool) $this->option('include-locked');
        $showSafeValues = (bool) $this->option('show-safe-values');
        $rows = $this->loadIntakes($limit, $includeLocked)
            ->map(fn (BiodataIntake $intake): ?array => $this->proposalRow($intake, $field, $action, $showSafeValues))
            ->filter()
            ->values();

        $report = [
            'success' => true,
            'filters' => [
                'limit' => $limit,
                'field' => $field,
                'action' => $action,
                'include_locked' => $includeLocked,
                'show_safe_values' => $showSafeValues,
            ],
            'summary' => $this->summary($rows),
            'rows' => $rows->all(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderReport($report);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, BiodataIntake>
     */
    private function loadIntakes(int $limit, bool $includeLocked): Collection
    {
        $query = BiodataIntake::query()
            ->select([
                'id',
                'raw_ocr_text',
                'parsed_json',
                'parse_status',
                'intake_locked',
                'quality_summary_json',
                'field_confidence_json',
                'routing_recommendation_json',
                'routing_telemetry_json',
                'created_at',
            ])
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('field_confidence_json')
                    ->orWhereNotNull('routing_recommendation_json');
            })
            ->latest('id')
            ->limit($limit);

        if (! $includeLocked) {
            $query->where(function (Builder $query): void {
                $query
                    ->whereNull('intake_locked')
                    ->orWhere('intake_locked', false);
            });
        }

        return $query->get();
    }

    private function proposalRow(BiodataIntake $intake, ?string $fieldFilter, ?string $actionFilter, bool $showSafeValues): ?array
    {
        $recommendation = $this->arrayValue($intake->routing_recommendation_json);
        $signals = $this->arrayValue($recommendation['signals'] ?? []);
        $telemetry = $this->arrayValue($intake->routing_telemetry_json);
        $recommendedAction = $this->safeToken($recommendation['recommended_action'] ?? null, 'unknown');
        if ($actionFilter !== null && $recommendedAction !== $actionFilter) {
            return null;
        }

        $criticalMissingFields = $this->criticalMissingFields($intake, $signals);
        if ($fieldFilter !== null) {
            $criticalMissingFields = array_values(array_intersect($criticalMissingFields, [$fieldFilter]));
        }

        if ($criticalMissingFields === []) {
            return null;
        }

        $rawText = (string) ($intake->raw_ocr_text ?? '');
        $phone = $this->phoneProposal($rawText);
        $dob = $this->dobProposal($rawText);
        $name = $this->nameProposal($rawText);
        $proposalByField = [
            'full_name' => $name['proposed'] === 'yes',
            'date_of_birth' => $dob['proposed'] === 'yes',
            'primary_contact_number' => $phone['proposed'] === 'yes',
        ];
        $ambiguousByField = [
            'date_of_birth' => $dob['proposed'] === 'ambiguous',
        ];
        $suggestedNextAction = $this->suggestedNextAction($criticalMissingFields, $proposalByField, $ambiguousByField);

        return [
            'intake_id' => (int) $intake->id,
            'recommended_action' => $recommendedAction,
            'quality_score' => $this->qualityScore($intake, $signals, $telemetry),
            'critical_missing_fields' => $criticalMissingFields,
            'full_name_proposed' => $name['proposed'],
            'full_name_candidate_line_count' => $name['candidate_line_count'],
            'full_name_word_count' => $name['word_count'],
            'full_name_confidence' => $name['confidence'],
            'dob_proposed' => $dob['proposed'],
            'dob_pattern_type' => $dob['pattern_type'],
            'dob_confidence' => $dob['confidence'],
            'dob_normalized' => $showSafeValues && $dob['proposed'] === 'yes' ? $dob['normalized'] : null,
            'phone_proposed' => $phone['proposed'],
            'phone_candidate_count' => $phone['candidate_count'],
            'phone_confidence' => $phone['confidence'],
            'masked_phone' => $showSafeValues && $phone['proposed'] === 'yes' ? $phone['masked'] : null,
            'suggested_next_action' => $suggestedNextAction,
            'notes' => $this->notes($criticalMissingFields, $proposalByField, $ambiguousByField, $suggestedNextAction),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $fieldCounts = array_fill_keys(self::CRITICAL_FIELDS, 0);
        $confidenceCounts = [
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'none' => 0,
        ];
        $proposalsFound = 0;
        $noProposal = 0;
        $ambiguous = 0;
        $estimatedSarvamAvoidable = 0;
        $estimatedProviderNeeded = 0;

        foreach ($rows as $row) {
            $hasProposal = false;
            $hasAmbiguous = false;
            foreach (self::CRITICAL_FIELDS as $field) {
                if (! in_array($field, $this->tokenList($row['critical_missing_fields'] ?? []), true)) {
                    continue;
                }

                $proposal = $this->proposalStatusForField($row, $field);
                $confidence = $this->confidenceForField($row, $field);
                if ($proposal === 'yes') {
                    $fieldCounts[$field]++;
                    $hasProposal = true;
                } elseif ($proposal === 'ambiguous') {
                    $fieldCounts[$field]++;
                    $hasAmbiguous = true;
                }

                $confidenceCounts[$confidence] = ($confidenceCounts[$confidence] ?? 0) + 1;
            }

            if ($hasProposal) {
                $proposalsFound++;
            }
            if ($hasAmbiguous) {
                $ambiguous++;
            }
            if (! $hasProposal && ! $hasAmbiguous) {
                $noProposal++;
            }
            if (($row['suggested_next_action'] ?? null) === 'parser_improvement_candidate') {
                $estimatedSarvamAvoidable++;
            }
            if (($row['suggested_next_action'] ?? null) === 'provider_candidate') {
                $estimatedProviderNeeded++;
            }
        }

        return [
            'total_scanned' => $rows->count(),
            'proposals_found_count' => $proposalsFound,
            'no_proposal_count' => $noProposal,
            'ambiguous_count' => $ambiguous,
            'proposal_field_counts' => $fieldCounts,
            'confidence_counts' => $confidenceCounts,
            'estimated_sarvam_avoidable_count' => $estimatedSarvamAvoidable,
            'estimated_provider_needed_count' => $estimatedProviderNeeded,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $summary = $this->arrayValue($report['summary'] ?? []);

        $this->table(['Metric', 'Value'], [
            ['Total scanned', $summary['total_scanned'] ?? 0],
            ['Proposals found', $summary['proposals_found_count'] ?? 0],
            ['No proposal', $summary['no_proposal_count'] ?? 0],
            ['Ambiguous', $summary['ambiguous_count'] ?? 0],
            ['Estimated Sarvam avoidable', $summary['estimated_sarvam_avoidable_count'] ?? 0],
            ['Estimated provider needed', $summary['estimated_provider_needed_count'] ?? 0],
        ]);

        $this->table(
            ['Proposal field', 'Count'],
            $this->countRows($this->arrayValue($summary['proposal_field_counts'] ?? []), 'none')
        );

        $this->table(
            ['Confidence', 'Count'],
            $this->countRows($this->arrayValue($summary['confidence_counts'] ?? []), 'none')
        );

        $rows = $this->arrayValue($report['rows'] ?? []);
        $this->table([
            'Intake',
            'Action',
            'Quality',
            'Critical missing',
            'Name proposed',
            'Name lines',
            'Name words',
            'Name confidence',
            'DOB proposed',
            'DOB pattern',
            'DOB value',
            'DOB confidence',
            'Phone proposed',
            'Phone candidates',
            'Masked phone',
            'Phone confidence',
            'Suggested next action',
            'Notes',
        ], array_map(fn (array $row): array => [
            $row['intake_id'],
            $row['recommended_action'],
            $row['quality_score'] ?? 'n/a',
            implode(',', $this->tokenList($row['critical_missing_fields'] ?? [])) ?: '-',
            $this->safeToken($row['full_name_proposed'] ?? null, 'no'),
            $row['full_name_candidate_line_count'] ?? 0,
            $row['full_name_word_count'] ?? 0,
            $this->safeToken($row['full_name_confidence'] ?? null, 'none'),
            $this->safeToken($row['dob_proposed'] ?? null, 'no'),
            $this->safeToken($row['dob_pattern_type'] ?? null, 'none'),
            $this->safeToken($row['dob_normalized'] ?? null, 'hidden'),
            $this->safeToken($row['dob_confidence'] ?? null, 'none'),
            $this->safeToken($row['phone_proposed'] ?? null, 'no'),
            $row['phone_candidate_count'] ?? 0,
            $this->safeToken($row['masked_phone'] ?? null, 'hidden'),
            $this->safeToken($row['phone_confidence'] ?? null, 'none'),
            $this->safeToken($row['suggested_next_action'] ?? null, 'manual_review'),
            implode(',', $this->tokenList($row['notes'] ?? [])) ?: '-',
        ], $rows));
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

    /**
     * @param  list<string>  $criticalMissingFields
     * @param  array<string, bool>  $proposalByField
     * @param  array<string, bool>  $ambiguousByField
     */
    private function suggestedNextAction(array $criticalMissingFields, array $proposalByField, array $ambiguousByField): string
    {
        $hasMissingWithoutProposal = false;
        foreach ($criticalMissingFields as $field) {
            if (! empty($ambiguousByField[$field])) {
                return 'manual_review';
            }

            if (empty($proposalByField[$field])) {
                $hasMissingWithoutProposal = true;
            }
        }

        if (! $hasMissingWithoutProposal) {
            return 'parser_improvement_candidate';
        }

        return 'provider_candidate';
    }

    /**
     * @param  list<string>  $criticalMissingFields
     * @param  array<string, bool>  $proposalByField
     * @param  array<string, bool>  $ambiguousByField
     * @return list<string>
     */
    private function notes(array $criticalMissingFields, array $proposalByField, array $ambiguousByField, string $suggestedNextAction): array
    {
        $notes = ['read_only_stored_text_proposal'];
        foreach ($criticalMissingFields as $field) {
            if (! empty($proposalByField[$field])) {
                $notes[] = $field.'_proposal_found';
            } elseif (! empty($ambiguousByField[$field])) {
                $notes[] = $field.'_proposal_ambiguous';
            } else {
                $notes[] = $field.'_proposal_missing';
            }
        }
        $notes[] = 'suggested_'.$suggestedNextAction;

        return array_values(array_unique($notes));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function proposalStatusForField(array $row, string $field): string
    {
        return match ($field) {
            'full_name' => $this->safeToken($row['full_name_proposed'] ?? null, 'no'),
            'date_of_birth' => $this->safeToken($row['dob_proposed'] ?? null, 'no'),
            'primary_contact_number' => $this->safeToken($row['phone_proposed'] ?? null, 'no'),
            default => 'no',
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function confidenceForField(array $row, string $field): string
    {
        $confidence = match ($field) {
            'full_name' => $row['full_name_confidence'] ?? 'none',
            'date_of_birth' => $row['dob_confidence'] ?? 'none',
            'primary_contact_number' => $row['phone_confidence'] ?? 'none',
            default => 'none',
        };

        return in_array($confidence, ['high', 'medium', 'low', 'none'], true) ? $confidence : 'none';
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

        $status = strtolower($this->safeToken($signal['status'] ?? null, ''));

        return in_array($status, ['low', 'missing', 'unknown'], true);
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $telemetry
     */
    private function qualityScore(BiodataIntake $intake, array $signals, array $telemetry): ?float
    {
        return $this->nullableFloat(
            $signals['quality_score']
            ?? data_get($intake->quality_summary_json, 'score')
            ?? $telemetry['last_quality_score']
            ?? null
        );
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

    private function fieldOption(): string|null|false
    {
        $field = $this->tokenOption('field');
        if ($field === null) {
            return null;
        }

        if (! in_array($field, self::CRITICAL_FIELDS, true)) {
            $this->error('Invalid --field value. Allowed: '.implode(', ', self::CRITICAL_FIELDS).'.');

            return false;
        }

        return $field;
    }

    private function tokenOption(string $option): ?string
    {
        $value = $this->option($option);
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->safeToken($value);
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
     * @param  array<string, mixed>  $counts
     * @return list<array{0: string, 1: mixed}>
     */
    private function countRows(array $counts, string $emptyLabel): array
    {
        if ($counts === []) {
            return [[$emptyLabel, 0]];
        }

        $rows = [];
        foreach ($counts as $key => $count) {
            $rows[] = [$this->safeToken($key), $count];
        }

        return $rows;
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
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
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
            fn (mixed $item): string => $this->safeToken($item, ''),
            $value
        ), static fn (string $item): bool => $item !== ''));
    }

    private function safeToken(mixed $value, string $fallback = 'n/a'): string
    {
        if (! is_scalar($value)) {
            return $fallback;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }

        $value = preg_replace('/\b\d{6,}\b/', '[redacted-number]', $value) ?? $value;
        $value = preg_replace('/\bsk-[A-Za-z0-9_-]+\b/i', '[redacted-secret]', $value) ?? $value;

        if (! preg_match('/^[A-Za-z0-9_.:\/+\-\[\]\*]+$/', $value)) {
            return '[redacted-text]';
        }

        return strlen($value) > 80 ? substr($value, 0, 77).'...' : $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 4);
    }
}
