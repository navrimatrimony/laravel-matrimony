<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class IntakeReviewProvenanceCaptureAuditCommand extends Command
{
    private const AUTHORIZED_ACTORS = [
        BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
        BiodataIntakeOcrAttempt::ACTOR_SUCHAK,
    ];

    private const ACTOR_BUCKETS = [
        BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
        BiodataIntakeOcrAttempt::ACTOR_SUCHAK,
        BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'unknown',
    ];

    private const VALID_SURFACES = [
        BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
        BiodataIntakeOcrAttempt::SURFACE_MOBILE_APP,
        BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
        BiodataIntakeOcrAttempt::SURFACE_API,
    ];

    private const SURFACE_BUCKETS = [
        BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
        BiodataIntakeOcrAttempt::SURFACE_MOBILE_APP,
        BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
        BiodataIntakeOcrAttempt::SURFACE_API,
        'unknown',
    ];

    protected $signature = 'intake:review-provenance-capture-audit
        {--limit=500 : Maximum latest reviewed intakes to inspect}
        {--json : Print JSON}
        {--since= : Optional date filter for reviewed_at, YYYY-MM-DD}
        {--actor= : Optional actor filter admin/profile_user/suchak/system/unknown}';

    protected $description = 'Read-only audit of future human review provenance capture for biodata intakes.';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $since = $this->sinceOption();
        $actor = $this->actorOption();

        if ($since === false || $actor === false) {
            return self::FAILURE;
        }

        $rows = $this->loadIntakes($limit, $since)
            ->map(fn (BiodataIntake $intake): array => $this->auditRow($intake))
            ->filter(fn (array $row): bool => $actor === null || ($row['review_actor_type'] ?? 'unknown') === $actor)
            ->values();

        $report = [
            'success' => true,
            'filters' => [
                'limit' => $limit,
                'since' => $since?->toDateString(),
                'actor' => $actor,
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
    private function loadIntakes(int $limit, ?Carbon $since): EloquentCollection
    {
        $query = BiodataIntake::query()
            ->select([
                'id',
                'approval_snapshot_json',
                'reviewed_by_user_id',
                'review_actor_type',
                'review_surface',
                'reviewed_at',
                'approval_status',
                'approved_by_user',
                'raw_ocr_text',
                'parsed_json',
                'parse_status',
                'quality_summary_json',
                'failure_codes_json',
                'field_confidence_json',
                'routing_recommendation_json',
                'matrimony_profile_id',
            ])
            ->whereNotNull('approval_snapshot_json')
            ->latest('id')
            ->limit($limit);

        if ($since !== null) {
            $query->whereDate('reviewed_at', '>=', $since->toDateString());
        }

        return $query->get();
    }

    private function auditRow(BiodataIntake $intake): array
    {
        $actor = $this->actorBucket($intake->review_actor_type);
        $surface = $this->surfaceBucket($intake->review_surface);
        $reviewerIdPresent = $intake->reviewed_by_user_id !== null;
        $reviewedAtPresent = $intake->reviewed_at !== null;
        $authorizedActor = in_array($actor, self::AUTHORIZED_ACTORS, true);
        $validSurface = in_array($surface, self::VALID_SURFACES, true);

        $blockers = $this->blockerCodes($actor, $surface, $reviewerIdPresent, $reviewedAtPresent);

        return [
            'intake_id' => (int) $intake->id,
            'reviewed_at' => $intake->reviewed_at?->toDateTimeString(),
            'review_actor_type' => $actor,
            'reviewed_by_user_id_present' => $reviewerIdPresent,
            'review_surface' => $surface,
            'approval_status' => $this->approvalStatus($intake),
            'provenance_status' => $this->provenanceStatus(
                $actor,
                $surface,
                $reviewerIdPresent,
                $reviewedAtPresent,
                $authorizedActor,
                $validSurface
            ),
            'blocker_codes' => $blockers,
        ];
    }

    /**
     * @return list<string>
     */
    private function blockerCodes(string $actor, string $surface, bool $reviewerIdPresent, bool $reviewedAtPresent): array
    {
        $blockers = [];

        if (! in_array($actor, self::AUTHORIZED_ACTORS, true)) {
            $blockers[] = 'system_or_unknown_actor';
        }

        if (! $reviewerIdPresent) {
            $blockers[] = 'missing_reviewer_id';
        }

        if (! in_array($surface, self::VALID_SURFACES, true)) {
            $blockers[] = 'missing_surface';
        }

        if (! $reviewedAtPresent) {
            $blockers[] = 'missing_reviewed_at';
        }

        return $blockers;
    }

    private function provenanceStatus(
        string $actor,
        string $surface,
        bool $reviewerIdPresent,
        bool $reviewedAtPresent,
        bool $authorizedActor,
        bool $validSurface
    ): string {
        if ($authorizedActor && $reviewerIdPresent && $validSurface && $reviewedAtPresent) {
            return 'complete_authorized_human_provenance';
        }

        if ($actor === 'unknown' && ! $reviewerIdPresent && $surface === 'unknown') {
            return 'legacy_unknown_provenance';
        }

        if (! $authorizedActor) {
            return 'system_or_unknown_actor';
        }

        return 'incomplete_future_review_provenance';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $actorCounts = array_fill_keys(self::ACTOR_BUCKETS, 0);
        $surfaceCounts = array_fill_keys(self::SURFACE_BUCKETS, 0);
        $complete = 0;
        $legacy = 0;
        $incomplete = 0;
        $missingReviewer = 0;
        $missingSurface = 0;
        $missingReviewedAt = 0;
        $systemOrUnknown = 0;

        foreach ($rows as $row) {
            $actor = $this->actorBucket($row['review_actor_type'] ?? null);
            $surface = $this->surfaceBucket($row['review_surface'] ?? null);
            $actorCounts[$actor] = ($actorCounts[$actor] ?? 0) + 1;
            $surfaceCounts[$surface] = ($surfaceCounts[$surface] ?? 0) + 1;

            if (($row['provenance_status'] ?? null) === 'complete_authorized_human_provenance') {
                $complete++;
            }
            if (($row['provenance_status'] ?? null) === 'legacy_unknown_provenance') {
                $legacy++;
            }
            if (($row['provenance_status'] ?? null) === 'incomplete_future_review_provenance') {
                $incomplete++;
            }

            $blockers = $this->tokenList($row['blocker_codes'] ?? []);
            if (in_array('missing_reviewer_id', $blockers, true)) {
                $missingReviewer++;
            }
            if (in_array('missing_surface', $blockers, true)) {
                $missingSurface++;
            }
            if (in_array('missing_reviewed_at', $blockers, true)) {
                $missingReviewedAt++;
            }
            if (in_array('system_or_unknown_actor', $blockers, true)) {
                $systemOrUnknown++;
            }
        }

        $hasProvenanceRisk = $legacy > 0
            || $incomplete > 0
            || $systemOrUnknown > 0
            || $missingReviewer > 0
            || $missingSurface > 0
            || $missingReviewedAt > 0;

        return [
            'total_reviewed_snapshots_scanned' => $rows->count(),
            'complete_authorized_human_provenance_count' => $complete,
            'legacy_unknown_provenance_count' => $legacy,
            'incomplete_future_review_provenance_count' => $incomplete,
            'missing_reviewer_id_count' => $missingReviewer,
            'missing_surface_count' => $missingSurface,
            'missing_reviewed_at_count' => $missingReviewedAt,
            'system_or_unknown_actor_count' => $systemOrUnknown,
            'actor_counts' => $actorCounts,
            'surface_counts' => $surfaceCounts,
            'recommendation' => $this->recommendation($legacy, $incomplete, $systemOrUnknown, $missingSurface, $missingReviewer, $missingReviewedAt),
            'safety_status' => $hasProvenanceRisk
                ? 'not_ready_legacy_or_incomplete_provenance_present'
                : 'pass_when_future_reviews_complete',
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $summary = $this->arrayValue($report['summary'] ?? []);
        $this->table(['Metric', 'Value'], [
            ['Total reviewed snapshots scanned', $summary['total_reviewed_snapshots_scanned'] ?? 0],
            ['Complete authorized human provenance', $summary['complete_authorized_human_provenance_count'] ?? 0],
            ['Legacy unknown provenance', $summary['legacy_unknown_provenance_count'] ?? 0],
            ['Incomplete future review provenance', $summary['incomplete_future_review_provenance_count'] ?? 0],
            ['Missing reviewer id', $summary['missing_reviewer_id_count'] ?? 0],
            ['Missing surface', $summary['missing_surface_count'] ?? 0],
            ['Missing reviewed at', $summary['missing_reviewed_at_count'] ?? 0],
            ['System or unknown actor', $summary['system_or_unknown_actor_count'] ?? 0],
            ['Recommendation', $summary['recommendation'] ?? 'fix_review_surface_or_actor_capture_before_learning'],
            ['Safety status', $summary['safety_status'] ?? 'not_ready_legacy_or_incomplete_provenance_present'],
        ]);

        $this->table(['Actor', 'Count'], $this->countRows($this->arrayValue($summary['actor_counts'] ?? [])));
        $this->table(['Surface', 'Count'], $this->countRows($this->arrayValue($summary['surface_counts'] ?? [])));

        $rows = $this->arrayValue($report['rows'] ?? []);
        $this->table([
            'Intake',
            'Reviewed at',
            'Actor',
            'Reviewer ID',
            'Surface',
            'Approval status',
            'Provenance status',
            'Blockers',
        ], array_map(fn (array $row): array => [
            $row['intake_id'] ?? 0,
            $row['reviewed_at'] ?? '-',
            $this->actorBucket($row['review_actor_type'] ?? null),
            $this->yesNo((bool) ($row['reviewed_by_user_id_present'] ?? false)),
            $this->surfaceBucket($row['review_surface'] ?? null),
            $this->safeToken($row['approval_status'] ?? null, 'unknown'),
            $this->safeToken($row['provenance_status'] ?? null, 'unknown'),
            implode(',', $this->tokenList($row['blocker_codes'] ?? [])) ?: '-',
        ], $rows));
    }

    private function recommendation(
        int $legacy,
        int $incomplete,
        int $systemOrUnknown,
        int $missingSurface,
        int $missingReviewer,
        int $missingReviewedAt
    ): string {
        if ($incomplete > 0 || $missingSurface > $legacy || $missingReviewer > $legacy || $missingReviewedAt > $legacy || $systemOrUnknown > $legacy) {
            return 'fix_review_surface_or_actor_capture_before_learning';
        }

        if ($legacy > 0) {
            return 'legacy_rows_need_manual_mapping_csv; do_not_backfill_automatically';
        }

        return 'future_review_capture_ok; learning_promotion_still_disabled';
    }

    private function approvalStatus(BiodataIntake $intake): string
    {
        return $this->safeToken($intake->approval_status ?? null, $intake->approved_by_user ? 'approved' : 'unknown');
    }

    private function sinceOption(): Carbon|false|null
    {
        $value = $this->option('since');
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', (string) $value);
        } catch (\Throwable) {
            $date = false;
        }

        if ($date === false || $date->format('Y-m-d') !== (string) $value) {
            $this->error('Invalid --since option. Use YYYY-MM-DD.');

            return false;
        }

        return $date->startOfDay();
    }

    private function actorOption(): string|false|null
    {
        $value = $this->option('actor');
        if ($value === null || $value === '') {
            return null;
        }

        $actor = $this->safeToken($value, 'unknown');
        if ($actor === 'user') {
            $actor = BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER;
        }
        if (! in_array($actor, self::ACTOR_BUCKETS, true)) {
            $this->error('Invalid --actor option. Use admin, profile_user, suchak, system, or unknown.');

            return false;
        }

        return $actor;
    }

    private function actorBucket(mixed $value): string
    {
        $actor = $this->safeToken($value, 'unknown');
        if ($actor === 'user') {
            return BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER;
        }

        return in_array($actor, self::ACTOR_BUCKETS, true) ? $actor : 'unknown';
    }

    private function surfaceBucket(mixed $value): string
    {
        $surface = $this->safeToken($value, 'unknown');

        return in_array($surface, self::SURFACE_BUCKETS, true) ? $surface : 'unknown';
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

        return array_values(array_filter(
            array_map(fn (mixed $item): string => $this->safeToken($item, ''), $value),
            fn (string $item): bool => $item !== ''
        ));
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{0: string, 1: int}>
     */
    private function countRows(array $counts): array
    {
        $rows = [];
        foreach ($counts as $key => $count) {
            $rows[] = [$this->safeToken($key, 'unknown'), (int) $count];
        }

        return $rows;
    }

    private function safeToken(mixed $value, string $default): string
    {
        if ($value === null) {
            return $default;
        }

        $token = Str::of((string) $value)
            ->lower()
            ->replaceMatches('/[^a-z0-9_.;:-]+/', '_')
            ->trim('_')
            ->toString();

        return $token !== '' ? $token : $default;
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
