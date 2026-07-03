<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IntakeLegacyProvenanceBackfillAuditCommand extends Command
{
    private const CONFIDENCES = ['high', 'medium', 'low'];

    private const AUTHORIZED_ACTORS = [
        BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
        BiodataIntakeOcrAttempt::ACTOR_SUCHAK,
    ];

    private const SURFACES = [
        BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
        BiodataIntakeOcrAttempt::SURFACE_MOBILE_APP,
        BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
        BiodataIntakeOcrAttempt::SURFACE_API,
    ];

    protected $signature = 'intake:legacy-provenance-backfill-audit
        {--limit=500 : Maximum latest intakes to inspect}
        {--json : Print the report as JSON}
        {--id= : Inspect one intake id}
        {--confidence= : Include only rows with confidence: high, medium, low}
        {--include-unreviewed : Include intakes without reviewed snapshots}';

    protected $description = 'Read-only audit of safe provenance candidates for legacy reviewed intake snapshots.';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $id = $this->intOption('id');
        $confidence = $this->confidenceOption();

        if ($id === false || $confidence === false) {
            return self::FAILURE;
        }

        $includeUnreviewed = (bool) $this->option('include-unreviewed');
        $rows = $this->loadIntakes($limit, $id, $includeUnreviewed)
            ->map(fn (BiodataIntake $intake): array => $this->auditRow($intake))
            ->filter(fn (array $row): bool => $this->passesConfidenceFilter($row, $confidence))
            ->values();

        $report = [
            'success' => true,
            'filters' => [
                'limit' => $limit,
                'id' => $id,
                'confidence' => $confidence,
                'include_unreviewed' => $includeUnreviewed,
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
     * @return EloquentCollection<int, BiodataIntake>
     */
    private function loadIntakes(int $limit, ?int $id, bool $includeUnreviewed): EloquentCollection
    {
        $query = BiodataIntake::query()
            ->select([
                'id',
                'uploaded_by',
                'matrimony_profile_id',
                'approved_by_user',
                'approved_at',
                'approval_snapshot_json',
                'reviewed_by_user_id',
                'review_actor_type',
                'review_surface',
                'reviewed_at',
                'parse_status',
                'created_at',
                'updated_at',
                'quality_summary_json',
                'failure_codes_json',
                'field_confidence_json',
                'routing_recommendation_json',
            ])
            ->with([
                'reviewedByUser:id,is_admin,admin_role',
                'reviewedByUser.suchakAccount:id,user_id',
                'ocrAttempts' => function ($query): void {
                    $query->select([
                        'id',
                        'intake_id',
                        'created_by_user_id',
                        'created_by_actor_type',
                        'source_surface',
                        'created_at',
                    ]);
                },
            ])
            ->latest('id')
            ->limit($limit);

        if ($id !== null) {
            $query->whereKey($id);
        }

        if (! $includeUnreviewed) {
            $query->whereNotNull('approval_snapshot_json');
        }

        return $query->get()
            ->filter(fn (BiodataIntake $intake): bool => $this->hasUnknownReviewedProvenance($intake)
                || ($includeUnreviewed && ! $this->hasReviewedSnapshot($intake)))
            ->values();
    }

    private function auditRow(BiodataIntake $intake): array
    {
        $hasReviewedSnapshot = $this->hasReviewedSnapshot($intake);
        $existingActor = $this->actorBucket($intake->review_actor_type);
        $existingSurface = $this->surfaceBucket($intake->review_surface);
        $existingActorIdPresent = $intake->reviewed_by_user_id !== null;
        $reviewedAtPresent = $intake->reviewed_at !== null;

        if (! $hasReviewedSnapshot) {
            return $this->row(
                $intake,
                false,
                $reviewedAtPresent,
                $existingActor,
                $existingActorIdPresent,
                $existingSurface,
                'unknown',
                false,
                'unknown',
                'none',
                'none',
                false,
                'no_reviewed_snapshot'
            );
        }

        $evidence = $this->bestEvidence($intake, $existingActor, $existingSurface);

        return $this->row(
            $intake,
            true,
            $reviewedAtPresent,
            $existingActor,
            $existingActorIdPresent,
            $existingSurface,
            $evidence['possible_actor_type'],
            $evidence['possible_actor_id_present'],
            $evidence['possible_surface'],
            $evidence['evidence_source'],
            $evidence['confidence'],
            $evidence['can_backfill_safely'],
            $evidence['reason']
        );
    }

    /**
     * @return array{
     *     possible_actor_type: string,
     *     possible_actor_id_present: bool,
     *     possible_surface: string,
     *     evidence_source: string,
     *     confidence: string,
     *     can_backfill_safely: bool,
     *     reason: string
     * }
     */
    private function bestEvidence(BiodataIntake $intake, string $existingActor, string $existingSurface): array
    {
        foreach ([
            $this->directReviewedByEvidence($intake, $existingActor, $existingSurface),
            $this->snapshotMetadataEvidence($intake),
            $this->adminAuditEvidence($intake),
            $this->suchakActivityEvidence($intake),
            $this->suchakSourceLinkEvidence($intake),
            $this->ocrAttemptCreatorEvidence($intake),
            $this->dynamicIntakeColumnEvidence($intake),
            $this->uploadedByInference($intake),
        ] as $evidence) {
            if ($evidence !== null) {
                return $evidence;
            }
        }

        return $this->evidence(
            'unknown',
            false,
            'unknown',
            'none',
            'none',
            false,
            'no_reliable_provenance_evidence'
        );
    }

    private function directReviewedByEvidence(BiodataIntake $intake, string $existingActor, string $existingSurface): ?array
    {
        if ($intake->reviewed_by_user_id === null) {
            return null;
        }

        $actor = in_array($existingActor, self::AUTHORIZED_ACTORS, true)
            ? $existingActor
            : $this->actorFromUser($intake->reviewedByUser, (int) $intake->reviewed_by_user_id, (int) ($intake->uploaded_by ?? 0));
        $surface = in_array($existingSurface, self::SURFACES, true)
            ? $existingSurface
            : $this->defaultSurfaceForActor($actor);

        return $this->evidence(
            $actor,
            true,
            $surface,
            'reviewed_by_user_id',
            'high',
            $this->safeCandidate($actor, true, $surface, 'high'),
            'direct_reviewed_by_user_id_present'
        );
    }

    private function snapshotMetadataEvidence(BiodataIntake $intake): ?array
    {
        $snapshot = $this->arrayValue($intake->approval_snapshot_json);
        foreach (['metadata', '_metadata', 'review_metadata', 'review'] as $key) {
            $metadata = $this->arrayValue($snapshot[$key] ?? []);
            if ($metadata === []) {
                continue;
            }

            $actorIdPresent = $this->nullableInt(
                $metadata['reviewed_by_user_id']
                ?? $metadata['review_actor_id']
                ?? $metadata['actor_id']
                ?? null
            ) !== null;
            $actor = $this->actorBucket($metadata['review_actor_type'] ?? $metadata['actor_type'] ?? null);
            $surface = $this->surfaceBucket($metadata['review_surface'] ?? $metadata['surface'] ?? null);

            if ($actorIdPresent || $actor !== 'unknown' || $surface !== 'unknown') {
                $confidence = $actorIdPresent && $actor !== 'unknown' && $surface !== 'unknown'
                    ? 'high'
                    : 'medium';

                return $this->evidence(
                    $actor,
                    $actorIdPresent,
                    $surface,
                    'approval_snapshot_metadata',
                    $confidence,
                    $this->safeCandidate($actor, $actorIdPresent, $surface, $confidence),
                    'snapshot_metadata_contains_review_provenance'
                );
            }
        }

        return null;
    }

    private function adminAuditEvidence(BiodataIntake $intake): ?array
    {
        if (! Schema::hasTable('admin_audit_logs')) {
            return null;
        }

        $log = DB::table('admin_audit_logs')
            ->select(['admin_id', 'action_type', 'entity_type', 'entity_id', 'created_at'])
            ->where('entity_id', $intake->id)
            ->whereIn('entity_type', [
                'biodata_intake',
                'biodata_intakes',
                'intake',
                BiodataIntake::class,
            ])
            ->orderByDesc('id')
            ->first();

        if (! $log) {
            return null;
        }

        $action = $this->safeToken($log->action_type ?? '', '');
        $confidence = Str::contains($action, ['review', 'snapshot', 'approval', 'approve', 'intake'])
            ? 'high'
            : 'medium';

        return $this->evidence(
            BiodataIntakeOcrAttempt::ACTOR_ADMIN,
            ! empty($log->admin_id),
            BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
            'admin_audit_logs',
            $confidence,
            $this->safeCandidate(BiodataIntakeOcrAttempt::ACTOR_ADMIN, ! empty($log->admin_id), BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL, $confidence),
            $confidence === 'high' ? 'trusted_admin_review_event_exact_match' : 'admin_event_matches_intake_but_action_is_not_review_specific'
        );
    }

    private function suchakActivityEvidence(BiodataIntake $intake): ?array
    {
        if (! Schema::hasTable('suchak_activity_logs')) {
            return null;
        }

        $log = DB::table('suchak_activity_logs')
            ->select(['actor_user_id', 'actor_type', 'action_type', 'target_type', 'target_id', 'metadata_json', 'occurred_at'])
            ->where(function ($query) use ($intake): void {
                $query->where(function ($nested) use ($intake): void {
                    $nested->where('target_id', $intake->id)
                        ->whereIn('target_type', [
                            'biodata_intake',
                            'biodata_intakes',
                            'intake',
                            BiodataIntake::class,
                        ]);
                });

                $query->orWhere('metadata_json', 'like', '%"intake_id":'.((int) $intake->id).'%')
                    ->orWhere('metadata_json', 'like', '%"biodata_intake_id":'.((int) $intake->id).'%');
            })
            ->orderByDesc('id')
            ->first();

        if (! $log) {
            return null;
        }

        $actor = $this->actorBucket($log->actor_type ?? null);
        if ($actor === 'user') {
            $actor = BiodataIntakeOcrAttempt::ACTOR_SUCHAK;
        }
        if ($actor === 'unknown') {
            $actor = BiodataIntakeOcrAttempt::ACTOR_SUCHAK;
        }

        $action = $this->safeToken($log->action_type ?? '', '');
        $confidence = Str::contains($action, ['review', 'snapshot', 'intake'])
            ? 'high'
            : 'medium';

        return $this->evidence(
            $actor,
            ! empty($log->actor_user_id),
            BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
            'suchak_activity_logs',
            $confidence,
            $this->safeCandidate($actor, ! empty($log->actor_user_id), BiodataIntakeOcrAttempt::SURFACE_WEBSITE, $confidence),
            $confidence === 'high' ? 'trusted_suchak_review_event_exact_match' : 'suchak_activity_matches_intake_but_not_review_specific'
        );
    }

    private function suchakSourceLinkEvidence(BiodataIntake $intake): ?array
    {
        if (! Schema::hasTable('suchak_biodata_intake_links')) {
            return null;
        }

        $link = DB::table('suchak_biodata_intake_links')
            ->select(['created_by_user_id', 'source_status', 'created_at', 'updated_at'])
            ->where('biodata_intake_id', $intake->id)
            ->orderByDesc('id')
            ->first();

        if (! $link) {
            return null;
        }

        return $this->evidence(
            BiodataIntakeOcrAttempt::ACTOR_SUCHAK,
            ! empty($link->created_by_user_id),
            BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
            'suchak_biodata_intake_links',
            'medium',
            false,
            'suchak_source_link_suggests_origin_but_not_exact_review_actor'
        );
    }

    private function ocrAttemptCreatorEvidence(BiodataIntake $intake): ?array
    {
        $attempt = $intake->ocrAttempts
            ->first(fn (BiodataIntakeOcrAttempt $attempt): bool => $attempt->created_by_user_id !== null || $attempt->created_by_actor_type !== null);

        if (! $attempt) {
            return null;
        }

        $actor = $this->actorBucket($attempt->created_by_actor_type);
        $surface = $this->surfaceBucket($attempt->source_surface);

        return $this->evidence(
            $actor,
            $attempt->created_by_user_id !== null,
            $surface,
            'ocr_attempt_creator_metadata',
            'low',
            false,
            'ocr_attempt_creator_is_not_review_snapshot_provenance'
        );
    }

    private function dynamicIntakeColumnEvidence(BiodataIntake $intake): ?array
    {
        $columns = array_values(array_filter([
            Schema::hasColumn('biodata_intakes', 'updated_by_user_id') ? 'updated_by_user_id' : null,
            Schema::hasColumn('biodata_intakes', 'updated_by') ? 'updated_by' : null,
            Schema::hasColumn('biodata_intakes', 'created_by_user_id') ? 'created_by_user_id' : null,
            Schema::hasColumn('biodata_intakes', 'created_by') ? 'created_by' : null,
        ]));

        if ($columns === []) {
            return null;
        }

        $record = DB::table('biodata_intakes')
            ->select($columns)
            ->where('id', $intake->id)
            ->first();

        if (! $record) {
            return null;
        }

        foreach ($columns as $column) {
            if ($this->nullableInt($record->{$column} ?? null) !== null) {
                return $this->evidence(
                    'unknown',
                    true,
                    'unknown',
                    $column,
                    'low',
                    false,
                    'generic_created_or_updated_by_column_is_weak_review_evidence'
                );
            }
        }

        return null;
    }

    private function uploadedByInference(BiodataIntake $intake): ?array
    {
        if ($intake->uploaded_by === null) {
            return null;
        }

        if (! $intake->approved_by_user && $intake->approved_at === null) {
            return null;
        }

        return $this->evidence(
            BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
            true,
            BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
            'uploaded_by_approval_timestamp_inference',
            'low',
            false,
            'uploaded_by_or_approval_timestamp_is_not_exact_review_provenance'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        BiodataIntake $intake,
        bool $hasReviewedSnapshot,
        bool $reviewedAtPresent,
        string $existingActor,
        bool $existingActorIdPresent,
        string $existingSurface,
        string $possibleActor,
        bool $possibleActorIdPresent,
        string $possibleSurface,
        string $evidenceSource,
        string $confidence,
        bool $canBackfillSafely,
        string $reason
    ): array {
        return [
            'intake_id' => (int) $intake->id,
            'has_reviewed_snapshot' => $hasReviewedSnapshot,
            'reviewed_at_present' => $reviewedAtPresent,
            'existing_actor_type' => $existingActor,
            'existing_actor_id_present' => $existingActorIdPresent,
            'existing_surface' => $existingSurface,
            'possible_actor_type' => $possibleActor,
            'possible_actor_id_present' => $possibleActorIdPresent,
            'possible_surface' => $possibleSurface,
            'evidence_source' => $evidenceSource,
            'confidence' => $confidence,
            'can_backfill_safely' => $canBackfillSafely,
            'reason' => $reason,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $reviewedUnknown = $rows->filter(fn (array $row): bool => ! empty($row['has_reviewed_snapshot']))->count();
        $high = $rows->where('confidence', 'high')->count();
        $medium = $rows->where('confidence', 'medium')->count();
        $low = $rows->where('confidence', 'low')->count();
        $none = $rows->where('confidence', 'none')->count();
        $safe = $rows->where('can_backfill_safely', true)->count();
        $unsafe = $rows->count() - $safe;

        return [
            'total_rows_scanned' => $rows->count(),
            'total_reviewed_unknown_provenance' => $reviewedUnknown,
            'unreviewed_count' => $rows->where('has_reviewed_snapshot', false)->count(),
            'high_confidence_candidates' => $high,
            'medium_confidence_candidates' => $medium,
            'low_confidence_candidates' => $low,
            'no_evidence_count' => $none,
            'safe_backfill_candidate_count' => $safe,
            'unsafe_no_backfill_count' => $unsafe,
            'recommendation' => $this->recommendation($safe, $medium, $low, $none),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $summary = $this->arrayValue($report['summary'] ?? []);
        $this->table(['Metric', 'Value'], [
            ['Total rows scanned', $summary['total_rows_scanned'] ?? 0],
            ['Total reviewed unknown provenance', $summary['total_reviewed_unknown_provenance'] ?? 0],
            ['Unreviewed count', $summary['unreviewed_count'] ?? 0],
            ['High confidence candidates', $summary['high_confidence_candidates'] ?? 0],
            ['Medium confidence candidates', $summary['medium_confidence_candidates'] ?? 0],
            ['Low confidence candidates', $summary['low_confidence_candidates'] ?? 0],
            ['No evidence count', $summary['no_evidence_count'] ?? 0],
            ['Safe backfill candidate count', $summary['safe_backfill_candidate_count'] ?? 0],
            ['Unsafe/no-backfill count', $summary['unsafe_no_backfill_count'] ?? 0],
            ['Recommendation', $summary['recommendation'] ?? 'no_backfill'],
        ]);

        $rows = $this->arrayValue($report['rows'] ?? []);
        $this->table([
            'Intake',
            'Reviewed at',
            'Existing actor',
            'Actor ID',
            'Existing surface',
            'Possible actor',
            'Possible actor ID',
            'Possible surface',
            'Evidence source',
            'Confidence',
            'Safe',
            'Reason',
        ], array_map(fn (array $row): array => [
            $row['intake_id'] ?? '-',
            $this->yesNo($row['reviewed_at_present'] ?? null),
            $this->safeToken($row['existing_actor_type'] ?? null, 'unknown'),
            $this->yesNo($row['existing_actor_id_present'] ?? null),
            $this->safeToken($row['existing_surface'] ?? null, 'unknown'),
            $this->safeToken($row['possible_actor_type'] ?? null, 'unknown'),
            $this->yesNo($row['possible_actor_id_present'] ?? null),
            $this->safeToken($row['possible_surface'] ?? null, 'unknown'),
            $this->safeToken($row['evidence_source'] ?? null, 'none'),
            $this->safeToken($row['confidence'] ?? null, 'none'),
            $this->yesNo($row['can_backfill_safely'] ?? null),
            $this->safeToken($row['reason'] ?? null, 'none'),
        ], $rows));
    }

    private function hasUnknownReviewedProvenance(BiodataIntake $intake): bool
    {
        if (! $this->hasReviewedSnapshot($intake)) {
            return false;
        }

        return ! in_array($this->actorBucket($intake->review_actor_type), self::AUTHORIZED_ACTORS, true)
            || $intake->reviewed_by_user_id === null
            || ! in_array($this->surfaceBucket($intake->review_surface), self::SURFACES, true);
    }

    private function hasReviewedSnapshot(BiodataIntake $intake): bool
    {
        return $this->arrayValue($intake->approval_snapshot_json) !== [];
    }

    /**
     * @return array{
     *     possible_actor_type: string,
     *     possible_actor_id_present: bool,
     *     possible_surface: string,
     *     evidence_source: string,
     *     confidence: string,
     *     can_backfill_safely: bool,
     *     reason: string
     * }
     */
    private function evidence(
        string $actor,
        bool $actorIdPresent,
        string $surface,
        string $source,
        string $confidence,
        bool $canBackfillSafely,
        string $reason
    ): array {
        return [
            'possible_actor_type' => $this->actorBucket($actor),
            'possible_actor_id_present' => $actorIdPresent,
            'possible_surface' => $this->surfaceBucket($surface),
            'evidence_source' => $this->safeToken($source, 'none'),
            'confidence' => in_array($confidence, ['high', 'medium', 'low', 'none'], true) ? $confidence : 'none',
            'can_backfill_safely' => $canBackfillSafely,
            'reason' => $this->safeToken($reason, 'none'),
        ];
    }

    private function safeCandidate(string $actor, bool $actorIdPresent, string $surface, string $confidence): bool
    {
        return $confidence === 'high'
            && $actorIdPresent
            && in_array($this->actorBucket($actor), self::AUTHORIZED_ACTORS, true)
            && in_array($this->surfaceBucket($surface), self::SURFACES, true);
    }

    private function actorFromUser(?User $user, int $userId, int $uploadedBy): string
    {
        if ($user !== null && ((bool) ($user->is_admin ?? false) || $user->admin_role !== null)) {
            return BiodataIntakeOcrAttempt::ACTOR_ADMIN;
        }

        if ($user !== null && $user->relationLoaded('suchakAccount') && $user->suchakAccount !== null) {
            return BiodataIntakeOcrAttempt::ACTOR_SUCHAK;
        }

        if ($uploadedBy > 0 && $userId === $uploadedBy) {
            return BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER;
        }

        return BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER;
    }

    private function defaultSurfaceForActor(string $actor): string
    {
        return match ($actor) {
            BiodataIntakeOcrAttempt::ACTOR_ADMIN => BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
            BiodataIntakeOcrAttempt::ACTOR_SUCHAK => BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
            BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER => BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
            default => 'unknown',
        };
    }

    private function recommendation(int $safe, int $medium, int $low, int $none): string
    {
        if ($safe > 0) {
            return 'dry_run_backfill_possible';
        }

        if (($medium + $low) > 0) {
            return 'manual_mapping_required';
        }

        return 'no_backfill';
    }

    private function passesConfidenceFilter(array $row, ?string $confidence): bool
    {
        if ($confidence === null) {
            return true;
        }

        return ($row['confidence'] ?? 'none') === $confidence;
    }

    private function intOption(string $name): int|false|null
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error("Invalid --{$name} option.");

            return false;
        }

        return (int) $value;
    }

    private function confidenceOption(): string|false|null
    {
        $value = $this->option('confidence');
        if ($value === null || $value === '') {
            return null;
        }

        $confidence = $this->safeToken($value, '');
        if (! in_array($confidence, self::CONFIDENCES, true)) {
            $this->error('Invalid --confidence option. Use high, medium, or low.');

            return false;
        }

        return $confidence;
    }

    private function actorBucket(mixed $value): string
    {
        $actor = $this->safeToken($value, 'unknown');
        if ($actor === 'user') {
            return BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER;
        }

        return in_array($actor, [...self::AUTHORIZED_ACTORS, BiodataIntakeOcrAttempt::ACTOR_SYSTEM], true)
            ? $actor
            : 'unknown';
    }

    private function surfaceBucket(mixed $value): string
    {
        $surface = $this->safeToken($value, 'unknown');

        return in_array($surface, [...self::SURFACES, BiodataIntakeOcrAttempt::SURFACE_SERVER], true)
            ? $surface
            : 'unknown';
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT) === false ? null : (int) $value;
    }

    private function safeToken(mixed $value, string $default): string
    {
        if ($value === null) {
            return $default;
        }

        $token = Str::of((string) $value)
            ->lower()
            ->replaceMatches('/[^a-z0-9_.:-]+/', '_')
            ->trim('_')
            ->toString();

        return $token !== '' ? $token : $default;
    }

    private function yesNo(mixed $value): string
    {
        return ! empty($value) ? 'yes' : 'no';
    }
}
