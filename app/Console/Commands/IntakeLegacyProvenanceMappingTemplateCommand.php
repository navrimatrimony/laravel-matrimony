<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IntakeLegacyProvenanceMappingTemplateCommand extends Command
{
    private const FILTERABLE_CONFIDENCES = ['medium', 'low', 'none'];

    private const AUTHORIZED_ACTORS = [
        BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
        BiodataIntakeOcrAttempt::ACTOR_SUCHAK,
    ];

    private const MANUAL_SURFACES = [
        BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
        BiodataIntakeOcrAttempt::SURFACE_MOBILE_APP,
        BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
        BiodataIntakeOcrAttempt::SURFACE_API,
    ];

    private const COLUMNS = [
        'intake_id',
        'reviewed_snapshot_present',
        'reviewed_at_present',
        'current_actor_type',
        'current_actor_id_present',
        'current_surface',
        'suggested_actor_type',
        'suggested_actor_id_present',
        'suggested_surface',
        'evidence_source',
        'confidence',
        'can_backfill_safely',
        'reason',
        'manual_actor_type',
        'manual_actor_id',
        'manual_surface',
        'manual_notes',
        'reviewer_decision',
    ];

    protected $signature = 'intake:legacy-provenance-mapping-template
        {--limit=500 : Maximum latest legacy reviewed intakes to inspect}
        {--json : Print the template as JSON}
        {--csv : Print the template as CSV}
        {--id= : Inspect one intake id}
        {--confidence= : Include only rows with confidence: medium, low, none}
        {--output= : Write CSV under storage/app, for example storage/app/intake-legacy-provenance-template.csv}';

    protected $description = 'Read-only export of manual mapping template rows for legacy intake review provenance.';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $id = $this->intOption('id');
        $confidence = $this->confidenceOption();

        if ($id === false || $confidence === false) {
            return self::FAILURE;
        }

        $rows = $this->loadIntakes($limit, $id)
            ->map(fn (BiodataIntake $intake): array => $this->templateRow($intake))
            ->filter(fn (array $row): bool => $confidence === null || ($row['confidence'] ?? 'none') === $confidence)
            ->values();

        $report = [
            'success' => true,
            'filters' => [
                'limit' => $limit,
                'id' => $id,
                'confidence' => $confidence,
            ],
            'manual_mapping_allowed_values' => [
                'manual_actor_type' => self::AUTHORIZED_ACTORS,
                'manual_surface' => self::MANUAL_SURFACES,
            ],
            'summary' => $this->summary($rows),
            'columns' => self::COLUMNS,
            'rows' => $rows->all(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $outputOption = $this->stringOption('output');
        if ((bool) $this->option('csv') || $outputOption !== null) {
            $csv = $this->csv($rows);

            if ($outputOption !== null) {
                $path = $this->resolveOutputPath($outputOption);
                if ($path === false) {
                    return self::FAILURE;
                }

                $writtenPath = $this->writeCsv($path, $csv);
                $this->info('CSV export written: '.$writtenPath);

                return self::SUCCESS;
            }

            $this->line($csv);

            return self::SUCCESS;
        }

        $this->renderReport($report);

        return self::SUCCESS;
    }

    /**
     * @return EloquentCollection<int, BiodataIntake>
     */
    private function loadIntakes(int $limit, ?int $id): EloquentCollection
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
            ->whereNotNull('approval_snapshot_json')
            ->latest('id')
            ->limit($limit);

        if ($id !== null) {
            $query->whereKey($id);
        }

        return $query->get()
            ->filter(fn (BiodataIntake $intake): bool => $this->hasUnknownReviewedProvenance($intake))
            ->values();
    }

    private function templateRow(BiodataIntake $intake): array
    {
        $currentActor = $this->actorBucket($intake->review_actor_type);
        $currentSurface = $this->surfaceBucket($intake->review_surface);
        $evidence = $this->bestEvidence($intake, $currentActor, $currentSurface);

        return [
            'intake_id' => (int) $intake->id,
            'reviewed_snapshot_present' => $this->yesNo($this->hasReviewedSnapshot($intake)),
            'reviewed_at_present' => $this->yesNo($intake->reviewed_at !== null),
            'current_actor_type' => $currentActor,
            'current_actor_id_present' => $this->yesNo($intake->reviewed_by_user_id !== null),
            'current_surface' => $currentSurface,
            'suggested_actor_type' => $evidence['possible_actor_type'],
            'suggested_actor_id_present' => $this->yesNo($evidence['possible_actor_id_present']),
            'suggested_surface' => $evidence['possible_surface'],
            'evidence_source' => $evidence['evidence_source'],
            'confidence' => $evidence['confidence'],
            'can_backfill_safely' => $this->yesNo($evidence['can_backfill_safely']),
            'reason' => $evidence['reason'],
            'manual_actor_type' => '',
            'manual_actor_id' => '',
            'manual_surface' => '',
            'manual_notes' => '',
            'reviewer_decision' => '',
        ];
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
        $surface = in_array($existingSurface, self::MANUAL_SURFACES, true)
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

        return $this->evidence(
            $this->actorBucket($attempt->created_by_actor_type),
            $attempt->created_by_user_id !== null,
            $this->surfaceBucket($attempt->source_surface),
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
     * @param  Collection<int, array<string, string|int>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        return [
            'total_rows' => $rows->count(),
            'medium_confidence_rows' => $rows->where('confidence', 'medium')->count(),
            'low_confidence_rows' => $rows->where('confidence', 'low')->count(),
            'no_evidence_rows' => $rows->where('confidence', 'none')->count(),
            'safe_backfill_candidate_rows' => $rows->where('can_backfill_safely', 'yes')->count(),
            'output_overwrite_policy' => 'timestamp_suffix_when_file_exists',
        ];
    }

    /**
     * @param  Collection<int, array<string, string|int>>  $rows
     */
    private function csv(Collection $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['# allowed_manual_actor_type_values: '.implode(',', self::AUTHORIZED_ACTORS)]);
        fputcsv($handle, ['# allowed_manual_surface_values: '.implode(',', self::MANUAL_SURFACES)]);
        fputcsv($handle, self::COLUMNS);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (string $column): string|int => $row[$column] ?? '',
                self::COLUMNS
            ));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return rtrim((string) $csv, "\r\n");
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $summary = $this->arrayValue($report['summary'] ?? []);
        $this->line('Allowed manual_actor_type values: '.implode(', ', self::AUTHORIZED_ACTORS));
        $this->line('Allowed manual_surface values: '.implode(', ', self::MANUAL_SURFACES));
        $this->line('CSV overwrite policy: timestamp suffix when file exists.');

        $this->table(['Metric', 'Value'], [
            ['Total rows', $summary['total_rows'] ?? 0],
            ['Medium confidence rows', $summary['medium_confidence_rows'] ?? 0],
            ['Low confidence rows', $summary['low_confidence_rows'] ?? 0],
            ['No evidence rows', $summary['no_evidence_rows'] ?? 0],
            ['Safe backfill candidate rows', $summary['safe_backfill_candidate_rows'] ?? 0],
        ]);

        $rows = $this->arrayValue($report['rows'] ?? []);
        $this->table(self::COLUMNS, array_map(
            fn (array $row): array => array_map(
                fn (string $column): string|int => $row[$column] ?? '',
                self::COLUMNS
            ),
            $rows
        ));
    }

    private function resolveOutputPath(string $output): string|false
    {
        $output = trim($output);
        if ($output === '') {
            $this->error('Invalid --output option.');

            return false;
        }

        $normalized = str_replace('\\', '/', $output);
        $segments = array_filter(explode('/', $normalized), fn (string $segment): bool => $segment !== '');
        if (in_array('..', $segments, true)) {
            $this->error('--output must stay under storage/app.');

            return false;
        }

        $storageRoot = $this->normalizePath(storage_path('app'));
        if (str_starts_with($this->normalizePath($output), $storageRoot.'/')) {
            $path = $output;
        } elseif (str_starts_with($normalized, 'storage/app/')) {
            $path = base_path($normalized);
        } else {
            $path = storage_path('app/'.$normalized);
        }

        $normalizedPath = $this->normalizePath($path);
        if ($normalizedPath !== $storageRoot && ! str_starts_with($normalizedPath, $storageRoot.'/')) {
            $this->error('--output must stay under storage/app.');

            return false;
        }

        return $path;
    }

    private function writeCsv(string $path, string $csv): string
    {
        $path = $this->nonOverwritingPath($path);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $csv.PHP_EOL);

        return $path;
    }

    private function nonOverwritingPath(string $path): string
    {
        if (! File::exists($path)) {
            return $path;
        }

        $directory = dirname($path);
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $suffix = now()->format('Ymd_His');
        $candidate = $directory.DIRECTORY_SEPARATOR.$filename.'_'.$suffix.($extension !== '' ? '.'.$extension : '');
        $counter = 2;

        while (File::exists($candidate)) {
            $candidate = $directory.DIRECTORY_SEPARATOR.$filename.'_'.$suffix.'_'.$counter.($extension !== '' ? '.'.$extension : '');
            $counter++;
        }

        return $candidate;
    }

    private function hasUnknownReviewedProvenance(BiodataIntake $intake): bool
    {
        if (! $this->hasReviewedSnapshot($intake)) {
            return false;
        }

        return ! in_array($this->actorBucket($intake->review_actor_type), self::AUTHORIZED_ACTORS, true)
            || $intake->reviewed_by_user_id === null
            || ! in_array($this->surfaceBucket($intake->review_surface), self::MANUAL_SURFACES, true);
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
            && in_array($this->surfaceBucket($surface), self::MANUAL_SURFACES, true);
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
        if (! in_array($confidence, self::FILTERABLE_CONFIDENCES, true)) {
            $this->error('Invalid --confidence option. Use medium, low, or none.');

            return false;
        }

        return $confidence;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
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

        return in_array($surface, [...self::MANUAL_SURFACES, BiodataIntakeOcrAttempt::SURFACE_SERVER], true)
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

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
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

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
