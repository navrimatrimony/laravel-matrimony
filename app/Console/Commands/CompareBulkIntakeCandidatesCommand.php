<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Services\Intake\BulkIntakeCandidateDisplayService;
use App\Services\Ocr\OcrNormalize;
use App\Services\OcrService;
use App\Support\MobileNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class CompareBulkIntakeCandidatesCommand extends Command
{
    private const FIELDS = [
        'full_name' => 'name',
        'mobile' => 'mobile',
        'date_of_birth' => 'dob',
        'age' => 'age',
        'height' => 'height',
        'gender' => 'gender',
        'city' => 'city',
        'education' => 'education',
        'occupation' => 'occupation',
    ];

    protected $signature = 'bulk-intake:compare-candidates
        {cleanTextBatchId : Clean raw text bulk intake batch id}
        {imageOcrBatchId : Image OCR bulk intake batch id}';

    protected $description = 'Read-only comparison of candidate display fields between clean text and image OCR bulk batches.';

    public function __construct(
        private readonly BulkIntakeCandidateDisplayService $candidateDisplay,
        private readonly OcrService $ocrService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $cleanBatchId = (int) $this->argument('cleanTextBatchId');
        $imageBatchId = (int) $this->argument('imageOcrBatchId');

        if ($cleanBatchId < 1 || $imageBatchId < 1) {
            $this->error('Both batch ids must be positive integers.');

            return self::FAILURE;
        }

        $cleanBatch = BulkIntakeBatch::query()->find($cleanBatchId);
        $imageBatch = BulkIntakeBatch::query()->find($imageBatchId);

        if (! $cleanBatch instanceof BulkIntakeBatch) {
            $this->error("Clean text batch {$cleanBatchId} was not found.");

            return self::FAILURE;
        }

        if (! $imageBatch instanceof BulkIntakeBatch) {
            $this->error("Image OCR batch {$imageBatchId} was not found.");

            return self::FAILURE;
        }

        $cleanItems = $this->itemsBySequence($cleanBatch);
        $imageItems = $this->itemsBySequence($imageBatch);
        $sequences = $this->sequences($cleanItems, $imageItems);

        $rows = [];
        $summary = $this->emptySummary(count($sequences));

        foreach ($sequences as $sequence) {
            $comparison = $this->compareSequence(
                (int) $sequence,
                $cleanItems->get($sequence),
                $imageItems->get($sequence)
            );

            $rows[] = $comparison['row'];
            $this->applySummary($summary, $comparison);
        }

        $this->info('Bulk intake candidate comparison');
        $this->line('Clean text batch: '.$cleanBatch->id.' | Image OCR batch: '.$imageBatch->id);
        $this->newLine();

        if ($rows === []) {
            $this->warn('No bulk intake items found in either batch.');
        } else {
            $this->table([
                'Seq',
                'Clean ID',
                'Image ID',
                'Clean Status',
                'Image Status',
                'Last Errors',
                'Name',
                'Mobile',
                'DOB',
                'Age',
                'Height',
                'Gender',
                'City',
                'Education',
                'Occupation',
                'Mismatch fields',
                'Suspected root cause',
                'Image OCR sample',
            ], $rows);
        }

        $this->renderSummary($summary);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, BulkIntakeBatchItem>
     */
    private function itemsBySequence(BulkIntakeBatch $batch): Collection
    {
        return $batch->items()
            ->with('biodataIntake')
            ->orderBy('item_sequence')
            ->get()
            ->keyBy(fn (BulkIntakeBatchItem $item): int => (int) $item->item_sequence);
    }

    /**
     * @param  Collection<int, BulkIntakeBatchItem>  $cleanItems
     * @param  Collection<int, BulkIntakeBatchItem>  $imageItems
     * @return list<int>
     */
    private function sequences(Collection $cleanItems, Collection $imageItems): array
    {
        return $cleanItems->keys()
            ->merge($imageItems->keys())
            ->map(fn ($sequence): int => (int) $sequence)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array{row: list<string>, matches: array<string, bool>, value_present: array<string, bool>, root_cause: string}
     */
    private function compareSequence(int $sequence, ?BulkIntakeBatchItem $cleanItem, ?BulkIntakeBatchItem $imageItem): array
    {
        $cleanIntake = $cleanItem?->biodataIntake;
        $imageIntake = $imageItem?->biodataIntake;
        $cleanCandidate = $this->candidateForItem($cleanItem);
        $imageCandidate = $this->candidateForItem($imageItem);
        $imageOcr = $this->imageOcrDiagnostics($imageIntake);

        $mismatchFields = [];
        $matches = [];
        $valuePresent = [];
        $pairs = [];

        foreach (self::FIELDS as $candidateKey => $fieldName) {
            $cleanValue = $this->valueString($cleanCandidate[$candidateKey] ?? null);
            $imageValue = $this->valueString($imageCandidate[$candidateKey] ?? null);
            $matches[$candidateKey] = $this->valuesMatch($candidateKey, $cleanValue, $imageValue);
            $valuePresent[$candidateKey] = $cleanValue !== '' || $imageValue !== '';
            $pairs[$candidateKey] = $this->pair($cleanValue, $imageValue);

            if (! $matches[$candidateKey]) {
                $mismatchFields[] = $fieldName;
            }
        }

        if (! $cleanItem instanceof BulkIntakeBatchItem || ! $cleanIntake instanceof BiodataIntake) {
            array_unshift($mismatchFields, 'missing_clean_intake');
        }

        if (! $imageItem instanceof BulkIntakeBatchItem || ! $imageIntake instanceof BiodataIntake) {
            array_unshift($mismatchFields, 'missing_image_intake');
        }

        $mismatchFields = array_values(array_unique($mismatchFields));
        $rootCause = $this->classifyRootCause(
            $mismatchFields,
            $cleanCandidate,
            $imageCandidate,
            $imageIntake,
            $imageOcr['text']
        );

        return [
            'row' => [
                (string) $sequence,
                $this->idLabel($cleanIntake),
                $this->idLabel($imageIntake),
                $this->safeCell($cleanIntake?->parse_status),
                $this->safeCell($imageIntake?->parse_status),
                $this->pair(
                    $this->shortText($cleanIntake?->last_error, 60),
                    $this->shortText($imageIntake?->last_error, 60)
                ),
                $pairs['full_name'],
                $pairs['mobile'],
                $pairs['date_of_birth'],
                $pairs['age'],
                $pairs['height'],
                $pairs['gender'],
                $pairs['city'],
                $pairs['education'],
                $pairs['occupation'],
                $mismatchFields === [] ? 'none' : implode(',', $mismatchFields),
                $rootCause,
                $imageOcr['sample'],
            ],
            'matches' => $matches,
            'value_present' => $valuePresent,
            'root_cause' => $rootCause,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateForItem(?BulkIntakeBatchItem $item): array
    {
        if (! $item instanceof BulkIntakeBatchItem) {
            return $this->emptyCandidate();
        }

        return $this->candidateDisplay->candidateForItem($item);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCandidate(): array
    {
        return [
            'full_name' => null,
            'mobile' => null,
            'date_of_birth' => null,
            'age' => null,
            'height' => null,
            'gender' => null,
            'city' => null,
            'education' => null,
            'occupation' => null,
        ];
    }

    /**
     * @return array{text: string, sample: string}
     */
    private function imageOcrDiagnostics(?BiodataIntake $intake): array
    {
        if (! $intake instanceof BiodataIntake) {
            return [
                'text' => '',
                'sample' => '-',
            ];
        }

        try {
            $resolved = $this->ocrService->resolveParseInputText($intake);
            $text = is_string($resolved['text'] ?? null) ? $resolved['text'] : '';
        } catch (Throwable $e) {
            $text = '';
        }

        $sample = $this->shortText($text, 250);

        return [
            'text' => $text,
            'sample' => $sample === '' ? '-' : $sample,
        ];
    }

    /**
     * @param  list<string>  $mismatchFields
     * @param  array<string, mixed>  $cleanCandidate
     * @param  array<string, mixed>  $imageCandidate
     */
    private function classifyRootCause(
        array $mismatchFields,
        array $cleanCandidate,
        array $imageCandidate,
        ?BiodataIntake $imageIntake,
        string $imageOcrText
    ): string {
        if ($mismatchFields === []) {
            return 'matched';
        }

        if (! $imageIntake instanceof BiodataIntake || in_array('missing_image_intake', $mismatchFields, true)) {
            return 'unknown';
        }

        if ($this->cleanTextMissing($mismatchFields, $cleanCandidate)) {
            return 'clean_text_missing';
        }

        if ($this->usableText($imageOcrText) === '') {
            return 'ocr_missing_text';
        }

        if ($this->displayTrustGateHidden($mismatchFields, $imageCandidate, $imageIntake)) {
            return 'display_trust_gate_hidden';
        }

        if ($this->imageOcrContainsExpectedValue($mismatchFields, $cleanCandidate, $imageOcrText)) {
            return 'parser_mapping_mismatch';
        }

        if ($this->imageHasMismatchedValues($mismatchFields, $imageCandidate)) {
            return 'ocr_noisy_text';
        }

        return 'unknown';
    }

    /**
     * @param  list<string>  $mismatchFields
     * @param  array<string, mixed>  $cleanCandidate
     */
    private function cleanTextMissing(array $mismatchFields, array $cleanCandidate): bool
    {
        foreach ($this->candidateKeysForMismatchFields($mismatchFields) as $candidateKey) {
            if ($this->valueString($cleanCandidate[$candidateKey] ?? null) === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $mismatchFields
     * @param  array<string, mixed>  $imageCandidate
     */
    private function displayTrustGateHidden(array $mismatchFields, array $imageCandidate, BiodataIntake $imageIntake): bool
    {
        foreach ($this->candidateKeysForMismatchFields($mismatchFields) as $candidateKey) {
            $rawValue = $this->rawParsedFieldValue($imageIntake, $candidateKey);
            $displayValue = $this->valueString($imageCandidate[$candidateKey] ?? null);

            if ($rawValue !== '' && ($displayValue === '' || strtolower($displayValue) === 'review')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $mismatchFields
     * @param  array<string, mixed>  $cleanCandidate
     */
    private function imageOcrContainsExpectedValue(array $mismatchFields, array $cleanCandidate, string $imageOcrText): bool
    {
        foreach ($this->candidateKeysForMismatchFields($mismatchFields) as $candidateKey) {
            $expected = $this->valueString($cleanCandidate[$candidateKey] ?? null);
            if ($expected !== '' && $this->textContainsValue($imageOcrText, $expected, $candidateKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $mismatchFields
     * @param  array<string, mixed>  $imageCandidate
     */
    private function imageHasMismatchedValues(array $mismatchFields, array $imageCandidate): bool
    {
        foreach ($this->candidateKeysForMismatchFields($mismatchFields) as $candidateKey) {
            if ($this->valueString($imageCandidate[$candidateKey] ?? null) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $mismatchFields
     * @return list<string>
     */
    private function candidateKeysForMismatchFields(array $mismatchFields): array
    {
        $flipped = array_flip(self::FIELDS);

        return collect($mismatchFields)
            ->map(fn (string $field): ?string => $flipped[$field] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    private function rawParsedFieldValue(BiodataIntake $intake, string $candidateKey): string
    {
        $parsed = is_array($intake->parsed_json) ? $intake->parsed_json : [];

        return $this->firstRawString($parsed, $this->rawParsedPaths($candidateKey));
    }

    /**
     * @return list<string>
     */
    private function rawParsedPaths(string $candidateKey): array
    {
        return match ($candidateKey) {
            'full_name' => ['core.full_name', 'full_name', 'candidate.full_name', 'candidate_name', 'name', 'profile.full_name'],
            'mobile' => ['core.primary_contact_number', 'core.mobile', 'core.user_contact_1', 'core.contact_number', 'primary_contact_number', 'mobile', 'user_contact_1', 'contact_number', 'contacts.0.phone_number', 'contacts.0.number', 'contacts.0.mobile'],
            'date_of_birth' => ['core.date_of_birth', 'core.dob', 'date_of_birth', 'dob'],
            'age' => ['core.age', 'age', 'candidate.age'],
            'height' => ['core.height_cm', 'height_cm', 'core.height', 'height'],
            'gender' => ['core.gender', 'core.gender_id', 'gender', 'gender_id'],
            'city' => ['core.city', 'core.city_text', 'core.birth_place_text', 'city', 'city_text', 'location_display', 'core.native_place.city', 'native_place.city', 'addresses.0.city', 'addresses.0.location_display'],
            'education' => ['core.highest_education', 'core.highest_education_other', 'highest_education', 'education', 'education_history.0.degree', 'education_history.0.specialization', 'education_history.0.institution'],
            'occupation' => ['core.occupation', 'core.occupation_title', 'core.occupation_custom', 'occupation', 'occupation_title', 'occupation_custom', 'core.occupation_master_id', 'core.occupation_custom_id', 'career_history.0.occupation_title', 'career_history.0.job_title'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $paths
     */
    private function firstRawString(array $source, array $paths): string
    {
        foreach ($paths as $path) {
            $value = data_get($source, $path);
            if ($value === null || is_array($value) || is_object($value) || is_bool($value)) {
                continue;
            }

            $string = $this->valueString($value);
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    private function textContainsValue(string $text, string $value, string $candidateKey): bool
    {
        if ($candidateKey === 'mobile') {
            $textDigits = preg_replace('/\D/u', '', OcrNormalize::normalizeDigits($text)) ?? '';
            $valueDigits = preg_replace('/\D/u', '', OcrNormalize::normalizeDigits($value)) ?? '';

            return $valueDigits !== '' && str_contains($textDigits, $valueDigits);
        }

        $haystack = $this->normalizedSearchText($text);
        $needle = $this->normalizedSearchText($value);

        return $needle !== '' && str_contains($haystack, $needle);
    }

    private function valuesMatch(string $candidateKey, string $cleanValue, string $imageValue): bool
    {
        return $this->normalizedFieldValue($candidateKey, $cleanValue) === $this->normalizedFieldValue($candidateKey, $imageValue);
    }

    private function normalizedFieldValue(string $candidateKey, string $value): string
    {
        $value = trim(OcrNormalize::normalizeDigits($value));
        if ($value === '') {
            return '';
        }

        if ($candidateKey === 'mobile') {
            return MobileNumber::normalize($value) ?? (preg_replace('/\D/u', '', $value) ?? '');
        }

        if ($candidateKey === 'age') {
            return preg_replace('/\D/u', '', $value) ?? '';
        }

        return $this->normalizedSearchText($value);
    }

    private function normalizedSearchText(string $value): string
    {
        $value = mb_strtolower(OcrNormalize::normalizeDigits($value), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;

        return trim($value);
    }

    private function usableText(string $value): string
    {
        $value = OcrNormalize::normalizeDigits($value);
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;

        return trim($value);
    }

    /**
     * @return array{
     *     total: int,
     *     field_matches: array<string, int>,
     *     ocr_issues: int,
     *     parser_mapping_issues: int,
     *     display_issues: int,
     *     clean_text_missing: int
     * }
     */
    private function emptySummary(int $total): array
    {
        return [
            'total' => $total,
            'field_matches' => array_fill_keys(array_keys(self::FIELDS), 0),
            'ocr_issues' => 0,
            'parser_mapping_issues' => 0,
            'display_issues' => 0,
            'clean_text_missing' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array{matches: array<string, bool>, value_present: array<string, bool>, root_cause: string}  $comparison
     */
    private function applySummary(array &$summary, array $comparison): void
    {
        foreach ($comparison['matches'] as $field => $matched) {
            if ($matched && (bool) ($comparison['value_present'][$field] ?? false)) {
                $summary['field_matches'][$field]++;
            }
        }

        match ($comparison['root_cause']) {
            'ocr_missing_text', 'ocr_noisy_text' => $summary['ocr_issues']++,
            'parser_mapping_mismatch' => $summary['parser_mapping_issues']++,
            'display_trust_gate_hidden' => $summary['display_issues']++,
            'clean_text_missing' => $summary['clean_text_missing']++,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function renderSummary(array $summary): void
    {
        $this->newLine();
        $this->info('Summary');
        $this->line('total compared: '.$summary['total']);
        $this->line('name matched count: '.$summary['field_matches']['full_name']);
        $this->line('mobile matched count: '.$summary['field_matches']['mobile']);
        $this->line('dob matched count: '.$summary['field_matches']['date_of_birth']);
        $this->line('height matched count: '.$summary['field_matches']['height']);
        $this->line('gender matched count: '.$summary['field_matches']['gender']);
        $this->line('city matched count: '.$summary['field_matches']['city']);
        $this->line('education matched count: '.$summary['field_matches']['education']);
        $this->line('occupation matched count: '.$summary['field_matches']['occupation']);
        $this->line('suspected OCR issues count: '.$summary['ocr_issues']);
        $this->line('suspected parser mapping issues count: '.$summary['parser_mapping_issues']);
        $this->line('suspected display issues count: '.$summary['display_issues']);
        $this->line('clean text missing count: '.$summary['clean_text_missing']);
    }

    private function pair(?string $clean, ?string $image): string
    {
        return $this->safeCell($clean).' / '.$this->safeCell($image);
    }

    private function idLabel(?BiodataIntake $intake): string
    {
        return $intake instanceof BiodataIntake ? (string) $intake->id : '-';
    }

    private function safeCell(mixed $value): string
    {
        $string = $this->valueString($value);

        return $string === '' ? '-' : $string;
    }

    private function valueString(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value) || is_bool($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function shortText(mixed $value, int $limit): string
    {
        $value = preg_replace('/\s+/u', ' ', $this->valueString($value)) ?? '';
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return mb_strlen($value, 'UTF-8') > $limit
            ? mb_substr($value, 0, $limit - 3, 'UTF-8').'...'
            : $value;
    }
}
