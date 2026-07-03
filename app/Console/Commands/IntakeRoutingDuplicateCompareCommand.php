<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Services\Intake\IntakeDuplicateFieldMatchEvaluator;
use App\Services\Intake\IntakeSmartRoutingPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class IntakeRoutingDuplicateCompareCommand extends Command
{
    private const FIELD_GROUPS = [
        'core',
        'contact',
        'education',
        'address',
    ];

    protected $signature = 'intake:routing-duplicate-compare
        {id : Biodata intake id to compare}
        {--json : Print the comparison as JSON}
        {--fields= : Comma-separated field groups: core,contact,education,address}
        {--show-snapshot : Include redacted snapshot summaries}
        {--include-locked : Include locked intakes in this read-only comparison}';

    protected $description = 'Compare a routing dry-run duplicate candidate with its reference intake without mutating data.';

    public function __construct(
        private readonly IntakeSmartRoutingPolicy $policy,
        private readonly IntakeDuplicateFieldMatchEvaluator $fieldMatchEvaluator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        if ($id < 1) {
            return $this->failWithMessage('Intake id must be a positive integer.');
        }

        $fieldGroups = $this->fieldGroups();
        if ($fieldGroups === false) {
            return self::FAILURE;
        }

        $current = $this->loadIntake($id);
        if (! $current instanceof BiodataIntake) {
            return $this->failWithMessage("Biodata intake {$id} was not found.");
        }

        $recommendation = $this->arrayValue($current->routing_recommendation_json);
        $signals = $this->arrayValue($recommendation['signals'] ?? []);
        $referenceId = $this->nullableInt($signals['duplicate_reference_intake_id'] ?? null);
        $reference = $referenceId !== null ? $this->loadIntake($referenceId) : null;

        $message = null;
        if ($referenceId === null) {
            $message = 'No duplicate_reference_intake_id found in routing recommendation signals.';
        } elseif (! $reference instanceof BiodataIntake) {
            $message = "Duplicate reference intake {$referenceId} was not found.";
        }
        $fieldMatch = $reference instanceof BiodataIntake
            ? $this->fieldMatchEvaluator->evaluate($current, $reference)
            : $this->fieldMatchEvaluator->emptyEvaluation();

        $payload = [
            'success' => true,
            'can_compare' => $reference instanceof BiodataIntake,
            'message' => $message,
            'options' => [
                'fields' => $fieldGroups,
                'show_snapshot' => (bool) $this->option('show-snapshot'),
                'include_locked' => (bool) $this->option('include-locked'),
            ],
            'routing_decision' => $this->routingDecision($recommendation, $signals, $referenceId, $fieldMatch),
            'current_intake' => $this->intakeSummary($current, (bool) $this->option('show-snapshot')),
            'reference_intake' => $reference instanceof BiodataIntake
                ? $this->intakeSummary($reference, (bool) $this->option('show-snapshot'))
                : null,
            'field_comparison' => $reference instanceof BiodataIntake
                ? $this->fieldComparison($current, $reference, $fieldGroups)
                : [],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderPayload($payload);

        return self::SUCCESS;
    }

    private function failWithMessage(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'success' => false,
                'message' => $message,
            ], JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    private function loadIntake(int $id): ?BiodataIntake
    {
        return BiodataIntake::query()
            ->select([
                'id',
                'uploaded_by',
                'matrimony_profile_id',
                'intake_status',
                'parse_status',
                'parsed_json',
                'approval_snapshot_json',
                'approved_by_user',
                'approved_at',
                'reviewed_by_user_id',
                'review_actor_type',
                'review_surface',
                'reviewed_at',
                'snapshot_schema_version',
                'intake_locked',
                'parser_version',
                'content_hash',
                'quality_summary_json',
                'routing_recommendation_json',
                'routing_telemetry_json',
                'created_at',
                'updated_at',
            ])
            ->with(['ocrAttempts' => function ($query): void {
                $query->select([
                    'id',
                    'intake_id',
                    'engine',
                    'status',
                    'quality_score',
                    'layout_score',
                    'normalized_text_hash',
                    'image_hash',
                    'is_primary',
                    'duration_ms',
                    'cost_units',
                    'created_at',
                ]);
            }])
            ->find($id);
    }

    /**
     * @param  array<string, mixed>  $recommendation
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $fieldMatch
     * @return array<string, mixed>
     */
    private function routingDecision(array $recommendation, array $signals, ?int $referenceId, array $fieldMatch): array
    {
        $policyEvaluation = $this->policy->evaluate($recommendation);
        $mismatchCodes = $this->stringList(
            $fieldMatch['duplicate_field_mismatch_codes']
                ?? $signals['duplicate_field_mismatch_codes']
                ?? []
        );

        return [
            'recommended_action' => $this->summaryString($recommendation['recommended_action'] ?? 'unknown'),
            'reason_codes' => $this->stringList($recommendation['reason_codes'] ?? []),
            'confidence' => $this->numericValue($recommendation['confidence'] ?? null),
            'would_skip_paid_vision' => $this->yesNo($recommendation['would_skip_paid_vision'] ?? null),
            'would_call_paid_vision' => $this->yesNo($recommendation['would_call_paid_vision'] ?? null),
            'duplicate_reference_intake_id' => $referenceId,
            'duplicate_reuse_eligible' => $this->yesNo($signals['duplicate_reuse_eligible'] ?? null),
            'duplicate_reuse_trust' => $this->summaryString($signals['duplicate_reuse_trust'] ?? null),
            'duplicate_reference_reason' => $this->summaryString($signals['duplicate_reference_reason'] ?? null),
            'duplicate_signal_source' => $this->summaryString($signals['duplicate_signal_source'] ?? null),
            'duplicate_match_type' => $this->summaryString($signals['duplicate_match_type'] ?? null),
            'matched_hash_type' => $this->summaryString($signals['matched_hash_type'] ?? null),
            'duplicate_reference_has_verifiable_ocr_evidence' => $this->yesNo($signals['duplicate_reference_has_verifiable_ocr_evidence'] ?? null),
            'duplicate_reference_quality_source' => $this->summaryString($signals['duplicate_reference_quality_source'] ?? null),
            'duplicate_reference_ocr_attempt_count' => $this->nullableInt($signals['duplicate_reference_ocr_attempt_count'] ?? null),
            'duplicate_reference_sarvam_attempt_count' => $this->nullableInt($signals['duplicate_reference_sarvam_attempt_count'] ?? null),
            'backfilled_quality_not_trusted' => $this->yesNo($signals['backfilled_quality_not_trusted'] ?? null),
            'backfilled_quality_trusted' => $this->backfilledQualityTrustedLabel($signals['backfilled_quality_not_trusted'] ?? null),
            'duplicate_field_match_eligible' => $this->yesNo($fieldMatch['duplicate_field_match_eligible'] ?? $signals['duplicate_field_match_eligible'] ?? null),
            'duplicate_field_match_score' => $this->numericValue($fieldMatch['duplicate_field_match_score'] ?? $signals['duplicate_field_match_score'] ?? null),
            'duplicate_field_mismatch_codes' => $mismatchCodes,
            'current_reference_contact_match' => $this->summaryString($fieldMatch['current_reference_contact_match'] ?? $signals['current_reference_contact_match'] ?? null),
            'current_reference_dob_match' => $this->summaryString($fieldMatch['current_reference_dob_match'] ?? $signals['current_reference_dob_match'] ?? null),
            'current_reference_name_match' => $this->summaryString($fieldMatch['current_reference_name_match'] ?? $signals['current_reference_name_match'] ?? null),
            'current_reference_core_fields_compared' => $this->nullableInt($fieldMatch['current_reference_core_fields_compared'] ?? $signals['current_reference_core_fields_compared'] ?? null),
            'policy_enabled' => $this->yesNo($policyEvaluation['enabled'] ?? null),
            'policy_dry_run_only' => $this->yesNo($policyEvaluation['dry_run_only'] ?? null),
            'policy_allowed_live_action' => $this->policyDisplayString($policyEvaluation['allowed_live_action'] ?? null),
            'policy_blocked_reason' => $this->policyDisplayString($policyEvaluation['blocked_reason'] ?? null),
            'policy_guardrails' => $this->policyGuardrailSummary($this->arrayValue($policyEvaluation['guardrails'] ?? [])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function intakeSummary(BiodataIntake $intake, bool $showSnapshot): array
    {
        $attempts = $this->ocrAttempts($intake);
        $summary = [
            'id' => (int) $intake->id,
            'intake_status' => $this->summaryString($intake->intake_status ?? null),
            'parse_status' => $this->summaryString($intake->parse_status ?? null),
            'locked' => $this->yesNo($intake->intake_locked ?? null),
            'has_parsed_json' => $this->yesNo($this->hasNonEmptyArray($intake->parsed_json)),
            'has_approval_snapshot_json' => $this->yesNo($this->hasNonEmptyArray($intake->approval_snapshot_json)),
            'approved_by_user' => $this->yesNo($intake->approved_by_user ?? null),
            'approved_at' => $this->dateDisplay($intake->approved_at ?? null),
            'reviewed_by_user_id' => $this->nullableInt($intake->reviewed_by_user_id ?? null),
            'review_actor_type' => $this->summaryString($intake->review_actor_type ?? null),
            'review_surface' => $this->summaryString($intake->review_surface ?? null),
            'reviewed_at' => $this->dateDisplay($intake->reviewed_at ?? null),
            'quality_score' => $this->qualityScore($intake, $attempts),
            'ocr_attempt_count' => $attempts->count(),
            'cheap_ocr_attempt_count' => $this->cheapOcrAttemptCount($attempts),
            'sarvam_attempt_count' => $this->sarvamAttemptCount($attempts),
            'primary_ocr_attempt_exists' => $this->yesNo($this->primaryOcrAttemptExists($attempts)),
            'content_hash_present' => $this->yesNo(trim((string) ($intake->content_hash ?? '')) !== ''),
            'normalized_text_hash_present' => $this->yesNo($this->normalizedTextHashPresent($attempts)),
            'image_hash_present' => $this->yesNo($this->imageHashPresent($attempts)),
            'candidate_name' => $this->displayValue($this->candidateName($intake)),
            'date_of_birth' => $this->displayValue($this->dateOfBirth($intake)),
            'primary_contact' => $this->displayContact($this->primaryContact($intake)),
        ];

        if ($showSnapshot) {
            $summary['snapshot_summary'] = $this->snapshotSummary($intake);
        }

        return $summary;
    }

    /**
     * @param  list<string>  $fieldGroups
     * @return list<array<string, string>>
     */
    private function fieldComparison(BiodataIntake $current, BiodataIntake $reference, array $fieldGroups): array
    {
        $rows = [];
        foreach ($this->fieldDefinitions() as $group => $definitions) {
            if (! in_array($group, $fieldGroups, true)) {
                continue;
            }

            foreach ($definitions as $definition) {
                $field = $definition['field'];
                $currentRaw = $this->fieldRawValue($current, $field);
                $referenceRaw = $this->fieldRawValue($reference, $field);

                $rows[] = [
                    'group' => $group,
                    'field' => $field,
                    'current' => $this->fieldDisplayValue($field, $currentRaw),
                    'reference' => $this->fieldDisplayValue($field, $referenceRaw),
                    'match' => $this->matchLabel($field, $currentRaw, $referenceRaw),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, list<array{field: string}>>
     */
    private function fieldDefinitions(): array
    {
        return [
            'core' => [
                ['field' => 'candidate_name'],
                ['field' => 'date_of_birth'],
            ],
            'contact' => [
                ['field' => 'primary_contact'],
            ],
            'education' => [
                ['field' => 'education'],
            ],
            'address' => [
                ['field' => 'address_present'],
            ],
        ];
    }

    private function fieldRawValue(BiodataIntake $intake, string $field): mixed
    {
        return match ($field) {
            'candidate_name' => $this->candidateName($intake),
            'date_of_birth' => $this->dateOfBirth($intake),
            'primary_contact' => $this->primaryContact($intake),
            'education' => $this->education($intake),
            'address_present' => $this->addressPresent($intake),
            default => null,
        };
    }

    private function fieldDisplayValue(string $field, mixed $value): string
    {
        if ($field === 'primary_contact') {
            return $this->displayContact($value);
        }

        if ($field === 'address_present') {
            return $this->yesNo($value);
        }

        return $this->displayValue($value);
    }

    private function matchLabel(string $field, mixed $current, mixed $reference): string
    {
        $current = $this->normalizeComparable($field, $current);
        $reference = $this->normalizeComparable($field, $reference);

        if ($current === null && $reference === null) {
            return 'unknown';
        }

        if ($current === null || $reference === null) {
            return 'no';
        }

        return $current === $reference ? 'yes' : 'no';
    }

    private function normalizeComparable(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if ($field === 'primary_contact') {
            $digits = preg_replace('/\D+/', '', $value) ?? '';

            return $digits !== '' ? $digits : null;
        }

        return strtolower($value);
    }

    /**
     * @return array<string, string>
     */
    private function snapshotSummary(BiodataIntake $intake): array
    {
        return [
            'source' => $this->hasNonEmptyArray($intake->approval_snapshot_json) ? 'approval_snapshot_json' : 'parsed_json',
            'candidate_name' => $this->displayValue($this->candidateName($intake)),
            'date_of_birth' => $this->displayValue($this->dateOfBirth($intake)),
            'primary_contact' => $this->displayContact($this->primaryContact($intake)),
            'education' => $this->displayValue($this->education($intake)),
            'address_present' => $this->yesNo($this->addressPresent($intake)),
        ];
    }

    private function candidateName(BiodataIntake $intake): mixed
    {
        $data = $this->snapshotData($intake);

        return data_get($data, 'core.full_name')
            ?? data_get($data, 'core.name')
            ?? data_get($data, 'candidate.full_name');
    }

    private function dateOfBirth(BiodataIntake $intake): mixed
    {
        $data = $this->snapshotData($intake);

        return data_get($data, 'core.date_of_birth')
            ?? data_get($data, 'core.dob')
            ?? data_get($data, 'candidate.date_of_birth');
    }

    private function primaryContact(BiodataIntake $intake): mixed
    {
        $data = $this->snapshotData($intake);
        $coreContact = data_get($data, 'core.primary_contact_number')
            ?? data_get($data, 'core.phone_number')
            ?? data_get($data, 'core.mobile_number');

        if ($coreContact !== null && trim((string) $coreContact) !== '') {
            return $coreContact;
        }

        $contacts = data_get($data, 'contacts');
        if (! is_array($contacts)) {
            return null;
        }

        $firstContact = null;
        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $candidate = $contact['phone_number'] ?? $contact['mobile_number'] ?? $contact['phone'] ?? null;
            if ($firstContact === null && $candidate !== null) {
                $firstContact = $candidate;
            }

            if (! empty($contact['is_primary']) && $candidate !== null) {
                return $candidate;
            }
        }

        return $firstContact;
    }

    private function education(BiodataIntake $intake): mixed
    {
        $data = $this->snapshotData($intake);

        return data_get($data, 'core.highest_education')
            ?? data_get($data, 'core.education')
            ?? data_get($data, 'core.education_level')
            ?? data_get($data, 'education_history.0.degree')
            ?? data_get($data, 'education_history.0.qualification')
            ?? data_get($data, 'education_history.0.course');
    }

    private function addressPresent(BiodataIntake $intake): bool
    {
        $data = $this->snapshotData($intake);
        $addresses = data_get($data, 'addresses');
        if (is_array($addresses) && $addresses !== []) {
            return true;
        }

        foreach ([
            'core.address',
            'core.current_address',
            'core.permanent_address',
            'core.native_place',
            'core.city',
            'core.current_city',
        ] as $key) {
            $value = data_get($data, $key);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotData(BiodataIntake $intake): array
    {
        $approval = $this->arrayValue($intake->approval_snapshot_json);
        if ($approval !== []) {
            return $approval;
        }

        return $this->arrayValue($intake->parsed_json);
    }

    /**
     * @param  Collection<int, BiodataIntakeOcrAttempt>  $attempts
     */
    private function qualityScore(BiodataIntake $intake, Collection $attempts): ?float
    {
        $quality = $this->numericValue(data_get($intake->quality_summary_json, 'score'));
        if ($quality !== null) {
            return $quality;
        }

        $quality = $this->numericValue(data_get($intake->routing_telemetry_json, 'last_quality_score'));
        if ($quality !== null) {
            return $quality;
        }

        $primary = $attempts->first(fn (BiodataIntakeOcrAttempt $attempt): bool => (bool) $attempt->is_primary);
        if ($primary instanceof BiodataIntakeOcrAttempt && $primary->quality_score !== null) {
            return (float) $primary->quality_score;
        }

        $scores = $attempts
            ->map(fn (BiodataIntakeOcrAttempt $attempt): ?float => $attempt->quality_score !== null ? (float) $attempt->quality_score : null)
            ->filter(fn (?float $score): bool => $score !== null)
            ->values();

        return $scores->isNotEmpty() ? (float) $scores->max() : null;
    }

    /**
     * @param  Collection<int, BiodataIntakeOcrAttempt>  $attempts
     */
    private function cheapOcrAttemptCount(Collection $attempts): int
    {
        return $attempts
            ->filter(fn (BiodataIntakeOcrAttempt $attempt): bool => in_array($attempt->engine, [
                BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
                BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
            ], true))
            ->count();
    }

    /**
     * @param  Collection<int, BiodataIntakeOcrAttempt>  $attempts
     */
    private function sarvamAttemptCount(Collection $attempts): int
    {
        return $attempts
            ->filter(fn (BiodataIntakeOcrAttempt $attempt): bool => $attempt->engine === BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION)
            ->count();
    }

    /**
     * @param  Collection<int, BiodataIntakeOcrAttempt>  $attempts
     */
    private function primaryOcrAttemptExists(Collection $attempts): bool
    {
        return $attempts->contains(fn (BiodataIntakeOcrAttempt $attempt): bool => (bool) $attempt->is_primary);
    }

    /**
     * @param  Collection<int, BiodataIntakeOcrAttempt>  $attempts
     */
    private function normalizedTextHashPresent(Collection $attempts): bool
    {
        return $attempts->contains(
            fn (BiodataIntakeOcrAttempt $attempt): bool => trim((string) ($attempt->normalized_text_hash ?? '')) !== ''
        );
    }

    /**
     * @param  Collection<int, BiodataIntakeOcrAttempt>  $attempts
     */
    private function imageHashPresent(Collection $attempts): bool
    {
        return $attempts->contains(
            fn (BiodataIntakeOcrAttempt $attempt): bool => trim((string) ($attempt->image_hash ?? '')) !== ''
        );
    }

    /**
     * @return Collection<int, BiodataIntakeOcrAttempt>
     */
    private function ocrAttempts(BiodataIntake $intake): Collection
    {
        $attempts = $intake->getRelation('ocrAttempts');

        return $attempts instanceof Collection ? $attempts : collect();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderPayload(array $payload): void
    {
        if (is_string($payload['message'] ?? null) && $payload['message'] !== '') {
            $this->warn($payload['message']);
        }

        $this->line('Routing decision summary');
        $this->table(['Field', 'Value'], $this->assocRows($this->arrayValue($payload['routing_decision'] ?? [])));

        $this->line('Current intake summary');
        $this->table(['Field', 'Value'], $this->assocRows($this->arrayValue($payload['current_intake'] ?? []), true));
        $this->renderSnapshotSummary('Current snapshot summary', $this->arrayValue(data_get($payload, 'current_intake.snapshot_summary')));

        $reference = $payload['reference_intake'] ?? null;
        if (is_array($reference)) {
            $this->line('Reference intake summary');
            $this->table(['Field', 'Value'], $this->assocRows($reference, true));
            $this->renderSnapshotSummary('Reference snapshot summary', $this->arrayValue(data_get($reference, 'snapshot_summary')));
        }

        $comparison = $payload['field_comparison'] ?? [];
        if (is_array($comparison) && $comparison !== []) {
            $this->line('Field comparison');
            $this->table(['Group', 'Field', 'Current', 'Reference', 'Match'], array_map(
                static fn (array $row): array => [
                    $row['group'],
                    $row['field'],
                    $row['current'],
                    $row['reference'],
                    $row['match'],
                ],
                $comparison
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<array{0: string, 1: string}>
     */
    private function assocRows(array $values, bool $skipSnapshot = false): array
    {
        $rows = [];
        foreach ($values as $key => $value) {
            if ($skipSnapshot && $key === 'snapshot_summary') {
                continue;
            }

            $rows[] = [(string) $key, $this->tableValue($value)];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function renderSnapshotSummary(string $title, array $summary): void
    {
        if ($summary === []) {
            return;
        }

        $this->line($title);
        $this->table(['Field', 'Value'], $this->assocRows($summary));
    }

    private function tableValue(mixed $value): string
    {
        if (is_array($value)) {
            $strings = array_values(array_filter(array_map(
                fn (mixed $item): string => $this->tableValue($item),
                $value
            ), static fn (string $item): bool => $item !== ''));

            return $strings !== [] ? implode(',', $strings) : 'none';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if ($value === null) {
            return 'none';
        }

        return $this->displayValue($value);
    }

    /**
     * @return list<string>|false
     */
    private function fieldGroups(): array|false
    {
        $raw = $this->option('fields');
        if ($raw === null || trim((string) $raw) === '') {
            return self::FIELD_GROUPS;
        }

        $groups = array_values(array_unique(array_filter(array_map(
            static fn (string $group): string => trim($group),
            explode(',', (string) $raw)
        ), static fn (string $group): bool => $group !== '')));

        $invalid = array_values(array_diff($groups, self::FIELD_GROUPS));
        if ($invalid !== []) {
            $this->error('Invalid --fields value. Allowed: '.implode(',', self::FIELD_GROUPS).'. Invalid: '.implode(',', $invalid).'.');

            return false;
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $guardrails
     */
    private function policyGuardrailSummary(array $guardrails): string
    {
        $allowlist = $this->stringList($guardrails['allow_sarvam_skip_actions'] ?? []);

        $parts = [
            'skip='.$this->yesNo($guardrails['skip_paid_vision_enabled'] ?? null),
            'reuse='.$this->yesNo($guardrails['reuse_previous_enabled'] ?? null),
            'min_conf='.($this->numericValue($guardrails['min_confidence'] ?? null) ?? 'n/a'),
            'confidence='.($this->numericValue($guardrails['confidence'] ?? null) ?? 'n/a'),
            'eligible='.$this->yesNo($guardrails['duplicate_reuse_eligible'] ?? null),
            'ref_reviewed='.$this->yesNo($guardrails['duplicate_reference_has_reviewed_snapshot'] ?? null),
            'allowlist='.($allowlist !== [] ? implode(',', $allowlist) : 'n/a'),
        ];

        return implode('; ', $parts);
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
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $value
        ), static fn (string $item): bool => $item !== ''));
    }

    private function hasNonEmptyArray(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }

    private function numericValue(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function summaryString(mixed $value): string
    {
        if (! is_scalar($value)) {
            return 'n/a';
        }

        $value = trim((string) $value);

        return $value !== '' ? $this->redactText($value) : 'n/a';
    }

    private function policyDisplayString(mixed $value): string
    {
        if (! is_scalar($value)) {
            return 'none';
        }

        $value = trim((string) $value);

        return $value !== '' ? $this->redactText($value) : 'none';
    }

    private function displayValue(mixed $value): string
    {
        if (! is_scalar($value)) {
            return 'missing';
        }

        $value = trim((string) $value);
        if ($value === '') {
            return 'missing';
        }

        return $this->redactText($value);
    }

    private function displayContact(mixed $value): string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return 'missing';
        }

        return $this->maskDigits((string) $value);
    }

    private function dateDisplay(mixed $value): string
    {
        if (is_object($value) && method_exists($value, 'toDateTimeString')) {
            return $value->toDateTimeString();
        }

        return $this->displayValue($value);
    }

    private function yesNo(mixed $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return $this->boolValue($value) ? 'yes' : 'no';
    }

    private function backfilledQualityTrustedLabel(mixed $backfilledQualityNotTrusted): string
    {
        if ($backfilledQualityNotTrusted === null) {
            return 'n/a';
        }

        return $this->boolValue($backfilledQualityNotTrusted) ? 'no' : 'n/a';
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

    private function redactText(string $value): string
    {
        $value = preg_replace_callback(
            '/\b\d{6,}\b/',
            fn (array $matches): string => $this->maskDigits($matches[0]),
            $value
        ) ?? $value;
        $value = preg_replace('/\bsk-[A-Za-z0-9_-]+\b/i', '[redacted-secret]', $value) ?? $value;

        return strlen($value) > 120 ? substr($value, 0, 117).'...' : $value;
    }

    private function maskDigits(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        $length = strlen($digits);

        if ($length === 0) {
            return 'missing';
        }

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).substr($digits, -4);
    }
}
