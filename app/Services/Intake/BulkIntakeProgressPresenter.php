<?php

namespace App\Services\Intake;

use App\Jobs\ProcessBulkIntakeBatchItemJob;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class BulkIntakeProgressPresenter
{
    public const BULK_QUEUE_NAME = ProcessBulkIntakeBatchItemJob::QUEUE_NAME;

    /**
     * @return array{
     *     total: int,
     *     pending: int,
     *     processing: int,
     *     intake_created: int,
     *     parse_queued: int,
     *     parsed: int,
     *     parse_error: int,
     *     needs_review: int,
     *     failed: int,
     *     completed_or_terminal: int,
     *     percent_done: int,
     *     approx_eta_seconds: int|null,
     *     approx_eta_label: string,
     *     active_work_label: string,
     *     last_activity_at: string|null,
     *     queue_backlog: int,
     *     failed_jobs_count: int,
     *     worker_warning: string|null,
     *     user_message: string,
     *     ocr_failed: int,
     *     error_summary: list<string>
     * }
     */
    public function progressForBatch(BulkIntakeBatch $batch): array
    {
        $rows = $this->itemRowsForBatch($batch);
        $queueHealth = $this->queueHealthForBatch($batch);

        $total = $rows->count();
        $parsed = $rows->filter(fn ($row): bool => $this->rowParsed($row))->count();
        $parseError = $rows->filter(fn ($row): bool => $this->rowParseError($row))->count();
        $needsReview = $rows->where('item_status', BulkIntakeBatchItem::STATUS_NEEDS_REVIEW)->count();
        $failed = $rows->where('item_status', BulkIntakeBatchItem::STATUS_FAILED)->count();
        $parseQueued = $rows->filter(fn ($row): bool => (string) $row->item_status === BulkIntakeBatchItem::STATUS_PARSE_QUEUED
            && ! $this->rowParsed($row)
            && ! $this->rowParseError($row)
        )->count();
        $completedOrTerminal = $rows->filter(fn ($row): bool => $this->rowParsed($row)
            || $this->rowParseError($row)
            || (string) $row->item_status === BulkIntakeBatchItem::STATUS_NEEDS_REVIEW
            || (string) $row->item_status === BulkIntakeBatchItem::STATUS_FAILED
        )->count();

        $percentDone = $total > 0 ? (int) floor(($completedOrTerminal / $total) * 100) : 0;
        $etaSeconds = $this->approxEtaSeconds($batch, $total, $completedOrTerminal);
        $lastActivityAt = $this->lastActivityAt($batch, $rows);
        $activeWorkCount = $rows->where('item_status', BulkIntakeBatchItem::STATUS_PENDING)->count()
            + $rows->where('item_status', BulkIntakeBatchItem::STATUS_PROCESSING)->count()
            + $parseQueued;

        return [
            'total' => $total,
            'pending' => $rows->where('item_status', BulkIntakeBatchItem::STATUS_PENDING)->count(),
            'processing' => $rows->where('item_status', BulkIntakeBatchItem::STATUS_PROCESSING)->count(),
            'intake_created' => $rows->where('item_status', BulkIntakeBatchItem::STATUS_INTAKE_CREATED)->count(),
            'parse_queued' => $parseQueued,
            'parsed' => $parsed,
            'parse_error' => $parseError,
            'needs_review' => $needsReview,
            'failed' => $failed,
            'completed_or_terminal' => $completedOrTerminal,
            'percent_done' => $percentDone,
            'approx_eta_seconds' => $etaSeconds,
            'approx_eta_label' => $this->etaLabel($etaSeconds, $completedOrTerminal, $total),
            'active_work_label' => $this->activeWorkLabel($rows, $parseQueued, (int) $queueHealth['queue_backlog']),
            'last_activity_at' => $lastActivityAt?->toIso8601String(),
            'queue_backlog' => (int) $queueHealth['queue_backlog'],
            'failed_jobs_count' => (int) $queueHealth['failed_jobs_count'],
            'worker_warning' => $this->workerWarning($activeWorkCount, $lastActivityAt),
            'user_message' => 'Bulk processing runs in background. You can leave this page open and refresh later. Website and app requests are not blocked by this batch.',
            'ocr_failed' => $this->ocrFailedCount($rows),
            'error_summary' => $this->errorSummary($needsReview, $parseError, $this->ocrFailedCount($rows), $failed),
        ];
    }

    /**
     * @return array{queue_backlog: int, failed_jobs_count: int}
     */
    public function queueHealthForBatch(BulkIntakeBatch $batch): array
    {
        return [
            'queue_backlog' => $this->queueBacklog(),
            'failed_jobs_count' => $this->failedJobsCount(),
        ];
    }

    /**
     * @return Collection<int, BulkIntakeBatchItem>
     */
    private function itemRowsForBatch(BulkIntakeBatch $batch): Collection
    {
        return BulkIntakeBatchItem::query()
            ->leftJoin('biodata_intakes', 'biodata_intakes.id', '=', 'bulk_intake_batch_items.biodata_intake_id')
            ->where('bulk_intake_batch_items.bulk_intake_batch_id', $batch->id)
            ->get([
                'bulk_intake_batch_items.id',
                'bulk_intake_batch_items.bulk_intake_batch_id',
                'bulk_intake_batch_items.biodata_intake_id',
                'bulk_intake_batch_items.item_status',
                'bulk_intake_batch_items.failure_code',
                'bulk_intake_batch_items.failure_message',
                'bulk_intake_batch_items.item_meta_json',
                'bulk_intake_batch_items.created_at',
                'bulk_intake_batch_items.updated_at',
                'biodata_intakes.parse_status as intake_parse_status',
                'biodata_intakes.last_error as intake_last_error',
                'biodata_intakes.updated_at as intake_updated_at',
                DB::raw($this->parsedJsonPresentSql().' as parsed_json_present'),
            ]);
    }

    private function parsedJsonPresentSql(): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return "case when biodata_intakes.parsed_json is not null and trim(biodata_intakes.parsed_json::text) not in ('', '[]', '{}', 'null') then 1 else 0 end";
        }

        return "case when biodata_intakes.parsed_json is not null and trim(cast(biodata_intakes.parsed_json as char)) not in ('', '[]', '{}', 'null') then 1 else 0 end";
    }

    private function rowParsed(object $row): bool
    {
        return (string) $row->intake_parse_status === 'parsed' && (int) $row->parsed_json_present === 1;
    }

    private function rowParseError(object $row): bool
    {
        return (string) $row->intake_parse_status === 'error' || trim((string) $row->intake_last_error) !== '';
    }

    private function approxEtaSeconds(BulkIntakeBatch $batch, int $total, int $completedOrTerminal): ?int
    {
        if ($total <= 0 || $completedOrTerminal <= 0) {
            return null;
        }

        $remaining = max(0, $total - $completedOrTerminal);
        if ($remaining === 0) {
            return 0;
        }

        $startedAt = $batch->created_at instanceof Carbon ? $batch->created_at : $this->timeOrNull($batch->created_at);
        if (! $startedAt instanceof Carbon) {
            return null;
        }

        $elapsedSeconds = max(1, $startedAt->diffInSeconds(now()));
        $averageSeconds = $elapsedSeconds / max(1, $completedOrTerminal);

        return (int) ceil($averageSeconds * $remaining);
    }

    private function etaLabel(?int $etaSeconds, int $completedOrTerminal, int $total): string
    {
        if ($total > 0 && $completedOrTerminal >= $total) {
            return 'complete';
        }

        if ($etaSeconds === null) {
            return 'calculating';
        }

        if ($etaSeconds <= 0) {
            return '< 1 min';
        }

        if ($etaSeconds < 60) {
            return '< 1 min';
        }

        if ($etaSeconds < 3600) {
            return (string) ((int) ceil($etaSeconds / 60)).' min';
        }

        $hours = intdiv($etaSeconds, 3600);
        $minutes = (int) ceil(($etaSeconds % 3600) / 60);

        return trim($hours.' hr '.($minutes > 0 ? $minutes.' min' : ''));
    }

    private function activeWorkLabel(Collection $rows, int $parseQueued, int $queueBacklog): string
    {
        if ($queueBacklog > 0) {
            return 'background queue is processing';
        }

        if ($rows->where('item_status', BulkIntakeBatchItem::STATUS_PROCESSING)->isNotEmpty()) {
            return 'processing';
        }

        if ($parseQueued > 0) {
            return 'parse queued';
        }

        if ($rows->where('item_status', BulkIntakeBatchItem::STATUS_PENDING)->isNotEmpty()) {
            return 'pending';
        }

        return 'complete';
    }

    private function lastActivityAt(BulkIntakeBatch $batch, Collection $rows): ?Carbon
    {
        $latest = $this->timeOrNull($batch->created_at);

        foreach ($rows as $row) {
            foreach ([
                $row->created_at ?? null,
                $row->updated_at ?? null,
                $row->intake_updated_at ?? null,
            ] as $value) {
                $latest = $this->maxTime($latest, $this->timeOrNull($value));
            }

            $meta = is_array($row->item_meta_json) ? $row->item_meta_json : [];
            foreach ([
                'queued_at',
                'auto_queued_at',
                'processing_started_at',
                'intake_created_at',
                'parse_queued_at',
                'parse_skipped_at',
                'auto_parse_skipped_at',
                'failed_at',
                'needs_review_marked_at',
                'needs_review_cleared_at',
            ] as $key) {
                $latest = $this->maxTime($latest, $this->timeOrNull($meta[$key] ?? null));
            }
        }

        return $latest;
    }

    private function maxTime(?Carbon $left, ?Carbon $right): ?Carbon
    {
        if (! $left instanceof Carbon) {
            return $right;
        }

        if (! $right instanceof Carbon) {
            return $left;
        }

        return $right->greaterThan($left) ? $right : $left;
    }

    private function timeOrNull(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function workerWarning(int $activeWorkCount, ?Carbon $lastActivityAt): ?string
    {
        if ($activeWorkCount <= 0 || ! $lastActivityAt instanceof Carbon) {
            return null;
        }

        return $lastActivityAt->lessThan(now()->subMinutes(10))
            ? 'No recent progress detected. Queue worker may be stopped or busy.'
            : null;
    }

    private function queueBacklog(): int
    {
        if (! Schema::hasTable('jobs') || ! Schema::hasColumn('jobs', 'queue')) {
            return 0;
        }

        return (int) DB::table('jobs')->where('queue', self::BULK_QUEUE_NAME)->count();
    }

    private function failedJobsCount(): int
    {
        if (! Schema::hasTable('failed_jobs') || ! Schema::hasColumn('failed_jobs', 'queue')) {
            return 0;
        }

        return (int) DB::table('failed_jobs')->where('queue', self::BULK_QUEUE_NAME)->count();
    }

    private function ocrFailedCount(Collection $rows): int
    {
        return $rows->filter(function ($row): bool {
            $meta = is_array($row->item_meta_json) ? $row->item_meta_json : [];

            return (string) $row->failure_code === 'empty_ocr_text'
                || (string) ($meta['ocr_failure_code'] ?? '') === 'empty_ocr_text';
        })->count();
    }

    /**
     * @return list<string>
     */
    private function errorSummary(int $needsReview, int $parseError, int $ocrFailed, int $failed): array
    {
        $summary = [];

        if ($needsReview > 0) {
            $summary[] = $needsReview === 1 ? '1 item needs review' : $needsReview.' items need review';
        }

        if ($parseError > 0) {
            $summary[] = $parseError === 1 ? '1 parser error' : $parseError.' parser errors';
        }

        if ($ocrFailed > 0) {
            $summary[] = $ocrFailed === 1 ? '1 OCR failed' : $ocrFailed.' OCR failed';
        }

        if ($failed > 0) {
            $summary[] = $failed === 1 ? '1 item failed' : $failed.' items failed';
        }

        return $summary;
    }
}
