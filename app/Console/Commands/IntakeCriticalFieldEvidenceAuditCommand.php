<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class IntakeCriticalFieldEvidenceAuditCommand extends Command
{
    private const LOW_CONFIDENCE_THRESHOLD = 0.65;

    private const CRITICAL_FIELDS = [
        'full_name',
        'date_of_birth',
        'primary_contact_number',
    ];

    protected $signature = 'intake:critical-field-evidence-audit
        {--limit=100 : Maximum latest intakes with stored routing/confidence data to inspect}
        {--json : Print the report as JSON}
        {--field= : Include only one critical field: full_name, date_of_birth, primary_contact_number}
        {--action= : Include only rows with this recommended_action}
        {--include-locked : Include locked intakes}';

    protected $description = 'Read-only audit for critical field evidence present in stored raw OCR text.';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $field = $this->fieldOption();
        if ($field === false) {
            return self::FAILURE;
        }

        $action = $this->tokenOption('action');
        $includeLocked = (bool) $this->option('include-locked');
        $rows = $this->loadIntakes($limit, $includeLocked)
            ->map(fn (BiodataIntake $intake): ?array => $this->auditRow($intake, $field, $action))
            ->filter()
            ->values();

        $report = [
            'success' => true,
            'filters' => [
                'limit' => $limit,
                'field' => $field,
                'action' => $action,
                'include_locked' => $includeLocked,
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

    private function auditRow(BiodataIntake $intake, ?string $fieldFilter, ?string $actionFilter): ?array
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
        $hasRawOcrText = trim($rawText) !== '';
        $phoneEvidence = $this->phoneEvidence($rawText);
        $dateEvidence = $this->dateEvidence($rawText);
        $nameEvidence = $this->nameEvidence($rawText);
        $evidenceByField = [
            'primary_contact_number' => (bool) $phoneEvidence['present'],
            'date_of_birth' => (bool) $dateEvidence['present'],
            'full_name' => (bool) $nameEvidence['present'],
        ];

        $parserMissedLikelyFields = [];
        $rawEvidenceAbsentFields = [];
        foreach ($criticalMissingFields as $field) {
            if (! empty($evidenceByField[$field])) {
                $parserMissedLikelyFields[] = $field;

                continue;
            }

            $rawEvidenceAbsentFields[] = $field;
        }

        $suggestedNextAction = $this->suggestedNextAction($parserMissedLikelyFields, $rawEvidenceAbsentFields, $hasRawOcrText);

        return [
            'intake_id' => (int) $intake->id,
            'recommended_action' => $recommendedAction,
            'quality_score' => $this->qualityScore($intake, $signals, $telemetry),
            'critical_missing_fields' => $criticalMissingFields,
            'has_raw_ocr_text' => $this->boolSignal($signals['has_raw_ocr_text'] ?? null, $hasRawOcrText),
            'has_parsed_json' => $this->boolSignal($signals['has_parsed_json'] ?? null, $this->hasNonEmptyArray($intake->parsed_json)),
            'phone_like_present' => (bool) $phoneEvidence['present'],
            'phone_like_count' => (int) $phoneEvidence['count'],
            'date_like_present' => (bool) $dateEvidence['present'],
            'date_like_pattern_types' => $dateEvidence['pattern_types'],
            'name_like_present' => (bool) $nameEvidence['present'],
            'name_like_line_count' => (int) $nameEvidence['line_count'],
            'name_like_word_count' => (int) $nameEvidence['word_count'],
            'parser_missed_likely_fields' => $parserMissedLikelyFields,
            'raw_evidence_absent_fields' => $rawEvidenceAbsentFields,
            'suggested_next_action' => $suggestedNextAction,
            'notes' => $this->notes($hasRawOcrText, $parserMissedLikelyFields, $rawEvidenceAbsentFields, $suggestedNextAction),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $criticalMissingCounts = array_fill_keys(self::CRITICAL_FIELDS, 0);
        $rawEvidenceCounts = array_fill_keys(self::CRITICAL_FIELDS, 0);
        $parserMissedLikelyCount = 0;
        $rawEvidenceAbsentCount = 0;
        $needsProviderCount = 0;
        $parserMappingCandidateCount = 0;
        $callSarvamCount = 0;

        foreach ($rows as $row) {
            if (($row['recommended_action'] ?? null) === 'call_sarvam') {
                $callSarvamCount++;
            }

            foreach ($this->tokenList($row['critical_missing_fields'] ?? []) as $field) {
                $criticalMissingCounts[$field] = ($criticalMissingCounts[$field] ?? 0) + 1;
            }

            foreach ($this->tokenList($row['parser_missed_likely_fields'] ?? []) as $field) {
                $rawEvidenceCounts[$field] = ($rawEvidenceCounts[$field] ?? 0) + 1;
            }

            if ($this->tokenList($row['parser_missed_likely_fields'] ?? []) !== []) {
                $parserMissedLikelyCount++;
            }

            if ($this->tokenList($row['raw_evidence_absent_fields'] ?? []) !== []) {
                $rawEvidenceAbsentCount++;
            }

            if (($row['suggested_next_action'] ?? null) === 'provider_candidate') {
                $needsProviderCount++;
            }

            if (($row['suggested_next_action'] ?? null) === 'parser_mapping_review') {
                $parserMappingCandidateCount++;
            }
        }

        return [
            'total_scanned' => $rows->count(),
            'call_sarvam_count' => $callSarvamCount,
            'critical_missing_field_counts' => $criticalMissingCounts,
            'raw_evidence_likely_present_counts' => $rawEvidenceCounts,
            'parser_missed_likely_count' => $parserMissedLikelyCount,
            'raw_evidence_absent_count' => $rawEvidenceAbsentCount,
            'needs_provider_count' => $needsProviderCount,
            'parser_mapping_candidate_count' => $parserMappingCandidateCount,
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
            ['Call Sarvam count', $summary['call_sarvam_count'] ?? 0],
            ['Parser missed likely', $summary['parser_missed_likely_count'] ?? 0],
            ['Raw evidence absent', $summary['raw_evidence_absent_count'] ?? 0],
            ['Needs provider', $summary['needs_provider_count'] ?? 0],
            ['Parser mapping candidates', $summary['parser_mapping_candidate_count'] ?? 0],
        ]);

        $this->table(
            ['Critical missing field', 'Count'],
            $this->countRows($this->arrayValue($summary['critical_missing_field_counts'] ?? []), 'none')
        );

        $this->table(
            ['Raw evidence likely field', 'Count'],
            $this->countRows($this->arrayValue($summary['raw_evidence_likely_present_counts'] ?? []), 'none')
        );

        $rows = $this->arrayValue($report['rows'] ?? []);
        $this->table([
            'Intake',
            'Action',
            'Quality',
            'Critical missing',
            'Raw OCR',
            'Parsed',
            'Phone present',
            'Phone count',
            'Date present',
            'Date patterns',
            'Name present',
            'Name lines',
            'Name words',
            'Parser missed likely',
            'Raw evidence absent',
            'Suggested next action',
            'Notes',
        ], array_map(fn (array $row): array => [
            $row['intake_id'],
            $row['recommended_action'],
            $row['quality_score'] ?? 'n/a',
            implode(',', $this->tokenList($row['critical_missing_fields'] ?? [])) ?: '-',
            $this->yesNo($row['has_raw_ocr_text'] ?? null),
            $this->yesNo($row['has_parsed_json'] ?? null),
            $this->yesNo($row['phone_like_present'] ?? null),
            $row['phone_like_count'] ?? 0,
            $this->yesNo($row['date_like_present'] ?? null),
            implode(',', $this->tokenList($row['date_like_pattern_types'] ?? [])) ?: '-',
            $this->yesNo($row['name_like_present'] ?? null),
            $row['name_like_line_count'] ?? 0,
            $row['name_like_word_count'] ?? 0,
            implode(',', $this->tokenList($row['parser_missed_likely_fields'] ?? [])) ?: '-',
            implode(',', $this->tokenList($row['raw_evidence_absent_fields'] ?? [])) ?: '-',
            $this->safeToken($row['suggested_next_action'] ?? null, 'manual_review'),
            implode(',', $this->tokenList($row['notes'] ?? [])) ?: '-',
        ], $rows));
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
     * @return array{present: bool, count: int}
     */
    private function phoneEvidence(string $text): array
    {
        $text = $this->normalizeDigits($text);
        preg_match_all('/(?<!\d)(?:\+?\s*91[\s.\-]*)?[6-9](?:[\s.\-]*\d){9}(?!\d)/', $text, $matches);
        $count = count($matches[0] ?? []);

        return [
            'present' => $count > 0,
            'count' => $count,
        ];
    }

    /**
     * @return array{present: bool, pattern_types: list<string>}
     */
    private function dateEvidence(string $text): array
    {
        $text = $this->normalizeDigits($text);
        $patterns = [];

        if (preg_match('/(?<!\d)(?:\d{1,2}\s*[\/.\-]\s*\d{1,2}\s*[\/.\-]\s*\d{2,4}|\d{4}\s*[\/.\-]\s*\d{1,2}\s*[\/.\-]\s*\d{1,2})(?!\d)/u', $text)) {
            $patterns[] = 'numeric_date';
        }

        if (preg_match('/(?:जानेवारी|फेब्रुवारी|मार्च|एप्रिल|मे|जून|जुलै|ऑगस्ट|सप्टेंबर|ऑक्टोबर|नोव्हेंबर|डिसेंबर)/u', $text)) {
            $patterns[] = 'marathi_month_name';
        }

        if (preg_match('/\b(?:jan|january|feb|february|mar|march|apr|april|may|jun|june|jul|july|aug|august|sep|sept|september|oct|october|nov|november|dec|december)\b/i', $text)) {
            $patterns[] = 'english_month_name';
        }

        return [
            'present' => $patterns !== [],
            'pattern_types' => array_values(array_unique($patterns)),
        ];
    }

    /**
     * @return array{present: bool, line_count: int, word_count: int}
     */
    private function nameEvidence(string $text): array
    {
        $lines = preg_split('/\R/u', $text);
        $lines = is_array($lines) ? $lines : [];
        $candidateLineCount = 0;
        $maxWordCount = 0;

        foreach ($lines as $index => $line) {
            $line = trim((string) $line);
            if ($line === '' || $this->phoneEvidence($line)['present'] || $this->dateEvidence($line)['present']) {
                continue;
            }

            $wordCount = $this->letterWordCount($line);
            $hasNameLabel = preg_match('/(?:नाव|नांव|name|candidate|वधू|वर|मुलगा|मुलगी)/iu', $line) === 1;
            $earlyLikelyNameLine = $index < 5 && $wordCount >= 2 && $wordCount <= 6;
            if ($wordCount >= 2 && ($hasNameLabel || $earlyLikelyNameLine)) {
                $candidateLineCount++;
                $maxWordCount = max($maxWordCount, $wordCount);
            }
        }

        return [
            'present' => $candidateLineCount > 0,
            'line_count' => $candidateLineCount,
            'word_count' => $maxWordCount,
        ];
    }

    private function suggestedNextAction(array $parserMissedLikelyFields, array $rawEvidenceAbsentFields, bool $hasRawOcrText): string
    {
        if ($parserMissedLikelyFields !== []) {
            return 'parser_mapping_review';
        }

        if ($rawEvidenceAbsentFields !== [] && $hasRawOcrText) {
            return 'provider_candidate';
        }

        return 'manual_review';
    }

    /**
     * @return list<string>
     */
    private function notes(bool $hasRawOcrText, array $parserMissedLikelyFields, array $rawEvidenceAbsentFields, string $suggestedNextAction): array
    {
        $notes = [];
        $notes[] = $hasRawOcrText ? 'stored_raw_text_available' : 'stored_raw_text_missing';
        if ($parserMissedLikelyFields !== []) {
            $notes[] = 'raw_evidence_likely_present_for_missing_field';
        }
        if ($rawEvidenceAbsentFields !== []) {
            $notes[] = 'raw_evidence_absent_for_missing_field';
        }
        $notes[] = 'suggested_'.$suggestedNextAction;

        return array_values(array_unique($notes));
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

        if (! preg_match('/^[A-Za-z0-9_.:\/+\-\[\]]+$/', $value)) {
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

    private function boolSignal(mixed $value, bool $fallback): bool
    {
        if ($value === null) {
            return $fallback;
        }

        return $this->boolValue($value);
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
        }

        return false;
    }

    private function yesNo(mixed $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return $this->boolValue($value) ? 'yes' : 'no';
    }

    private function hasNonEmptyArray(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }
}
