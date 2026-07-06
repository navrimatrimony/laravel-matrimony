<?php

namespace App\Services\Intake;

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

class BulkIntakeBatchService
{
    public function createBatch(array $attributes): BulkIntakeBatch
    {
        return BulkIntakeBatch::create([
            'uploaded_by_user_id' => $this->intOrNull($attributes['uploaded_by_user_id'] ?? null),
            'uploaded_by_actor_type' => $this->normalizeActorType($attributes['uploaded_by_actor_type'] ?? null),
            'source_surface' => $this->normalizeSurface($attributes['source_surface'] ?? null) ?? BulkIntakeBatch::SURFACE_ADMIN_PANEL,
            'batch_name' => $this->stringOrNull($attributes['batch_name'] ?? null),
            'batch_status' => $this->stringOrNull($attributes['batch_status'] ?? null) ?? BulkIntakeBatch::STATUS_PENDING,
            'intake_creation_policy' => $this->stringOrNull($attributes['intake_creation_policy'] ?? null) ?? BulkIntakeBatch::POLICY_EXISTING_CHAIN,
            'ocr_policy' => $this->stringOrNull($attributes['ocr_policy'] ?? null) ?? BulkIntakeBatch::OCR_POLICY_FREE_OCR_FIRST,
            'ai_cost_estimate' => $attributes['ai_cost_estimate'] ?? null,
            'ai_cost_actual' => $attributes['ai_cost_actual'] ?? null,
            'meta_json' => is_array($attributes['meta_json'] ?? null) ? $attributes['meta_json'] : null,
        ]);
    }

    public function addItem(BulkIntakeBatch $batch, array $attributes): BulkIntakeBatchItem
    {
        return DB::transaction(function () use ($batch, $attributes): BulkIntakeBatchItem {
            $idempotencyKey = $this->stringOrNull($attributes['idempotency_key'] ?? null);
            if ($idempotencyKey !== null) {
                $existing = BulkIntakeBatchItem::where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    $this->refreshCounters($batch);

                    return $existing;
                }
            }

            $item = $batch->items()->create([
                'biodata_intake_id' => $this->intOrNull($attributes['biodata_intake_id'] ?? null),
                'item_sequence' => $this->intOrNull($attributes['item_sequence'] ?? null) ?? $this->nextSequence($batch),
                'input_type' => $this->normalizeInputType($attributes['input_type'] ?? null),
                'original_filename' => $this->stringOrNull($attributes['original_filename'] ?? null),
                'source_file_path' => $this->stringOrNull($attributes['source_file_path'] ?? null),
                'file_hash' => $this->hashOrNull($attributes['file_hash'] ?? null),
                'raw_text_hash' => $this->hashOrNull($attributes['raw_text_hash'] ?? null),
                'idempotency_key' => $idempotencyKey,
                'item_status' => $this->stringOrNull($attributes['item_status'] ?? null) ?? BulkIntakeBatchItem::STATUS_PENDING,
                'summary_text' => $this->stringOrNull($attributes['summary_text'] ?? null),
                'quality_score' => $attributes['quality_score'] ?? null,
                'failure_code' => $this->stringOrNull($attributes['failure_code'] ?? null),
                'failure_message' => $this->stringOrNull($attributes['failure_message'] ?? null),
                'item_meta_json' => is_array($attributes['item_meta_json'] ?? null) ? $attributes['item_meta_json'] : null,
            ]);

            $this->refreshCounters($batch);

            return $item;
        });
    }

    public function markProcessing(BulkIntakeBatch $batch): BulkIntakeBatch
    {
        $batch->forceFill([
            'batch_status' => BulkIntakeBatch::STATUS_PROCESSING,
            'started_at' => $batch->started_at ?? now(),
        ])->save();

        return $batch->refresh();
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, string>  $textItems
     */
    public function processExistingUserBatch(
        BulkIntakeBatch $batch,
        User $ownerUser,
        array $files,
        array $textItems,
        User $actor,
        IntakeCreationService $intakeCreation,
        IntakeSourceContextRecorder $sourceContextRecorder
    ): BulkIntakeBatch {
        $this->markProcessing($batch);

        $sequence = ((int) $batch->items()->max('item_sequence')) + 1;

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            try {
                $fileHash = $this->hashFile($file);
                $item = $this->addItem($batch, [
                    'item_sequence' => $sequence,
                    'input_type' => BulkIntakeBatchItem::INPUT_FILE,
                    'original_filename' => $file->getClientOriginalName(),
                    'file_hash' => $fileHash,
                    'idempotency_key' => $this->itemIdempotencyKey($batch, $sequence, 'file', $fileHash),
                    'item_status' => BulkIntakeBatchItem::STATUS_PENDING,
                ]);

                $this->createIntakeForItem($item, $ownerUser, $actor, $intakeCreation, $sourceContextRecorder, $file, null);
            } catch (Throwable $e) {
                if (isset($item) && $item instanceof BulkIntakeBatchItem) {
                    $this->failItem($item, 'bulk_file_intake_failed', $e->getMessage());
                }
            } finally {
                unset($item);
                $sequence++;
            }
        }

        foreach ($textItems as $rawText) {
            $rawText = trim((string) $rawText);
            if ($rawText === '') {
                continue;
            }

            try {
                $textHash = hash('sha256', $rawText);
                $item = $this->addItem($batch, [
                    'item_sequence' => $sequence,
                    'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
                    'raw_text_hash' => $textHash,
                    'idempotency_key' => $this->itemIdempotencyKey($batch, $sequence, 'text', $textHash),
                    'item_status' => BulkIntakeBatchItem::STATUS_PENDING,
                    'summary_text' => mb_substr($rawText, 0, 500),
                ]);

                $this->createIntakeForItem($item, $ownerUser, $actor, $intakeCreation, $sourceContextRecorder, null, $rawText);
            } catch (Throwable $e) {
                if (isset($item) && $item instanceof BulkIntakeBatchItem) {
                    $this->failItem($item, 'bulk_text_intake_failed', $e->getMessage());
                }
            } finally {
                unset($item);
                $sequence++;
            }
        }

        $this->refreshCounters($batch);

        $batch->forceFill([
            'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();

        return $this->refreshCounters($batch);
    }

    public function createIntakeForItem(
        BulkIntakeBatchItem $item,
        User $ownerUser,
        User $actor,
        IntakeCreationService $intakeCreation,
        IntakeSourceContextRecorder $sourceContextRecorder,
        ?UploadedFile $file = null,
        ?string $rawText = null
    ): BulkIntakeBatchItem {
        if ($item->biodata_intake_id !== null) {
            return $item->refresh();
        }

        $item->forceFill([
            'item_status' => BulkIntakeBatchItem::STATUS_PROCESSING,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        $prepared = $intakeCreation->prepare((int) $ownerUser->id, $file, $rawText);

        return DB::transaction(function () use ($item, $ownerUser, $actor, $intakeCreation, $sourceContextRecorder, $prepared): BulkIntakeBatchItem {
            $lockedItem = BulkIntakeBatchItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($lockedItem->biodata_intake_id !== null) {
                return $lockedItem->refresh();
            }

            $intake = $intakeCreation->persistPrepared((int) $ownerUser->id, $prepared);

            $lockedItem->forceFill([
                'biodata_intake_id' => $intake->id,
                'source_file_path' => $intake->file_path,
                'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
                'failure_code' => null,
                'failure_message' => null,
            ])->save();

            $sourceContextRecorder->recordForIntake($intake, [
                'source_type' => \App\Models\IntakeSourceContext::SOURCE_ADMIN_BULK,
                'source_surface' => \App\Models\IntakeSourceContext::SURFACE_ADMIN_PANEL,
                'actor_type' => \App\Models\IntakeSourceContext::ACTOR_ADMIN,
                'actor_user_id' => $actor->id,
                'bulk_intake_batch_id' => $lockedItem->bulk_intake_batch_id,
                'bulk_intake_batch_item_id' => $lockedItem->id,
                'idempotency_key' => 'admin_bulk_batch_item:'.$lockedItem->id,
                'source_meta_json' => [
                    'owner_user_id' => (int) $ownerUser->id,
                    'intake_creation_policy' => BulkIntakeBatch::POLICY_EXISTING_CHAIN,
                    'parse_dispatch' => 'deferred',
                ],
            ]);

            return $lockedItem->refresh();
        });
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, string>  $textItems
     */
    public function processUnclaimedBulkBatch(
        BulkIntakeBatch $batch,
        array $files,
        array $textItems,
        User $actor,
        IntakeCreationService $intakeCreation,
        IntakeSourceContextRecorder $sourceContextRecorder
    ): BulkIntakeBatch {
        $this->markProcessing($batch);

        $sequence = ((int) $batch->items()->max('item_sequence')) + 1;

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            try {
                $fileHash = $this->hashFile($file);
                $item = $this->addItem($batch, [
                    'item_sequence' => $sequence,
                    'input_type' => BulkIntakeBatchItem::INPUT_FILE,
                    'original_filename' => $file->getClientOriginalName(),
                    'file_hash' => $fileHash,
                    'idempotency_key' => $this->itemIdempotencyKey($batch, $sequence, 'file', $fileHash),
                    'item_status' => BulkIntakeBatchItem::STATUS_PENDING,
                ]);

                $this->createUnclaimedIntakeForItem($item, $actor, $intakeCreation, $sourceContextRecorder, $file, null);
            } catch (Throwable $e) {
                if (isset($item) && $item instanceof BulkIntakeBatchItem) {
                    $this->failItem($item, 'bulk_file_intake_failed', $e->getMessage());
                }
            } finally {
                unset($item);
                $sequence++;
            }
        }

        foreach ($textItems as $rawText) {
            $rawText = trim((string) $rawText);
            if ($rawText === '') {
                continue;
            }

            try {
                $textHash = hash('sha256', $rawText);
                $item = $this->addItem($batch, [
                    'item_sequence' => $sequence,
                    'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
                    'raw_text_hash' => $textHash,
                    'idempotency_key' => $this->itemIdempotencyKey($batch, $sequence, 'text', $textHash),
                    'item_status' => BulkIntakeBatchItem::STATUS_PENDING,
                    'summary_text' => mb_substr($rawText, 0, 500),
                ]);

                $this->createUnclaimedIntakeForItem($item, $actor, $intakeCreation, $sourceContextRecorder, null, $rawText);
            } catch (Throwable $e) {
                if (isset($item) && $item instanceof BulkIntakeBatchItem) {
                    $this->failItem($item, 'bulk_text_intake_failed', $e->getMessage());
                }
            } finally {
                unset($item);
                $sequence++;
            }
        }

        $this->refreshCounters($batch);

        $batch->forceFill([
            'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();

        return $this->refreshCounters($batch);
    }

    public function createUnclaimedIntakeForItem(
        BulkIntakeBatchItem $item,
        User $actor,
        IntakeCreationService $intakeCreation,
        IntakeSourceContextRecorder $sourceContextRecorder,
        ?UploadedFile $file = null,
        ?string $rawText = null
    ): BulkIntakeBatchItem {
        if ($item->biodata_intake_id !== null) {
            return $item->refresh();
        }

        $item->forceFill([
            'item_status' => BulkIntakeBatchItem::STATUS_PROCESSING,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        $prepared = $intakeCreation->prepare(null, $file, $rawText);

        return DB::transaction(function () use ($item, $actor, $intakeCreation, $sourceContextRecorder, $prepared): BulkIntakeBatchItem {
            $lockedItem = BulkIntakeBatchItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($lockedItem->biodata_intake_id !== null) {
                return $lockedItem->refresh();
            }

            $intake = $intakeCreation->persistPreparedForUnclaimedBulk($prepared);

            $lockedItem->forceFill([
                'biodata_intake_id' => $intake->id,
                'source_file_path' => $intake->file_path,
                'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
                'failure_code' => null,
                'failure_message' => null,
            ])->save();

            $sourceContextRecorder->recordForIntake($intake, [
                'source_type' => \App\Models\IntakeSourceContext::SOURCE_ADMIN_BULK,
                'source_surface' => \App\Models\IntakeSourceContext::SURFACE_ADMIN_PANEL,
                'actor_type' => \App\Models\IntakeSourceContext::ACTOR_ADMIN,
                'actor_user_id' => $actor->id,
                'bulk_intake_batch_id' => $lockedItem->bulk_intake_batch_id,
                'bulk_intake_batch_item_id' => $lockedItem->id,
                'idempotency_key' => 'admin_bulk_batch_item:'.$lockedItem->id,
                'source_meta_json' => [
                    'owner_user_id' => null,
                    'candidate_user_id' => null,
                    'owner_user_mode' => 'unclaimed_bulk_staging',
                    'consent_status' => 'pending',
                    'profile_creation_policy' => 'after_candidate_consent',
                    'intake_creation_policy' => BulkIntakeBatch::POLICY_EXISTING_CHAIN,
                    'parse_dispatch' => 'deferred',
                ],
            ]);

            return $lockedItem->refresh();
        });
    }

    public function failItem(BulkIntakeBatchItem $item, string $failureCode, string $message): BulkIntakeBatchItem
    {
        $item->forceFill([
            'item_status' => BulkIntakeBatchItem::STATUS_FAILED,
            'failure_code' => $failureCode,
            'failure_message' => mb_substr($message, 0, 2000),
        ])->save();

        $batch = $item->batch;
        if ($batch !== null) {
            $this->refreshCounters($batch);
        }

        return $item->refresh();
    }

    /**
     * @param  array<int, int>|null  $itemIds
     * @return array{queued: int, skipped: int, failed: int, skipped_reasons: array<string, int>}
     */
    public function queueFreeParseForBatch(BulkIntakeBatch $batch, User $actor, ?array $itemIds = null): array
    {
        $query = $batch->items()
            ->with('biodataIntake')
            ->orderBy('item_sequence');

        if ($itemIds !== null) {
            $query->whereIn('id', array_map('intval', $itemIds));
        }

        $summary = $this->emptyQueueSummary();

        $query->get()->each(function (BulkIntakeBatchItem $item) use ($actor, &$summary): void {
            $result = $this->queueFreeParseForItem($item, $actor);
            $summary['queued'] += (int) $result['queued'];
            $summary['skipped'] += (int) $result['skipped'];
            $summary['failed'] += (int) $result['failed'];
            foreach ($result['skipped_reasons'] as $reason => $count) {
                $summary['skipped_reasons'][$reason] = ($summary['skipped_reasons'][$reason] ?? 0) + (int) $count;
            }
        });

        $this->refreshCounters($batch);

        return $summary;
    }

    /**
     * @return array{queued: int, skipped: int, failed: int, skipped_reasons: array<string, int>}
     */
    public function queueFreeParseForItem(BulkIntakeBatchItem $item, User $actor): array
    {
        try {
            $item->loadMissing('biodataIntake');
            $intake = $item->biodataIntake;

            if (! $intake instanceof BiodataIntake) {
                return $this->skipQueueSummary('missing_biodata_intake');
            }

            if ((bool) $intake->approved_by_user || (bool) $intake->intake_locked) {
                return $this->skipQueueSummary('approved_or_locked');
            }

            if ((string) $intake->parse_status !== 'pending') {
                return $this->skipQueueSummary('parse_status_not_pending');
            }

            if ((string) $item->item_status === BulkIntakeBatchItem::STATUS_PARSE_QUEUED) {
                return $this->skipQueueSummary('already_parse_queued');
            }

            IntakeExtractionReuseResolver::flagNextParseJobAsParseInputOnly((int) $intake->id);
            ParseIntakeJob::dispatch((int) $intake->id, true);

            $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
            $item->forceFill([
                'item_status' => BulkIntakeBatchItem::STATUS_PARSE_QUEUED,
                'item_meta_json' => array_merge($meta, [
                    'parse_queue_mode' => BulkIntakeBatch::OCR_POLICY_FREE_OCR_FIRST,
                    'parse_input_only' => true,
                    'queued_by_user_id' => (int) $actor->id,
                    'queued_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
                    'queued_at' => now()->toIso8601String(),
                ]),
                'failure_code' => null,
                'failure_message' => null,
            ])->save();

            $batch = $item->batch;
            if ($batch !== null) {
                $this->refreshCounters($batch);
            }

            return [
                'queued' => 1,
                'skipped' => 0,
                'failed' => 0,
                'skipped_reasons' => [],
            ];
        } catch (Throwable $e) {
            $this->failItem($item, 'free_parse_queue_failed', $e->getMessage());

            return [
                'queued' => 0,
                'skipped' => 0,
                'failed' => 1,
                'skipped_reasons' => [],
            ];
        }
    }

    public function refreshCounters(BulkIntakeBatch $batch): BulkIntakeBatch
    {
        $items = $batch->items();

        $batch->forceFill([
            'total_items' => (clone $items)->count(),
            'total_files' => (clone $items)->where('input_type', BulkIntakeBatchItem::INPUT_FILE)->count(),
            'total_texts' => (clone $items)->where('input_type', BulkIntakeBatchItem::INPUT_TEXT)->count(),
            'total_intakes_created' => (clone $items)->whereNotNull('biodata_intake_id')->count(),
            'total_profiles_created' => 0,
            'total_conflicts_generated' => 0,
            'total_needs_review' => (clone $items)->where('item_status', BulkIntakeBatchItem::STATUS_NEEDS_REVIEW)->count(),
            'total_failed' => (clone $items)->where('item_status', BulkIntakeBatchItem::STATUS_FAILED)->count(),
        ])->save();

        return $batch->refresh();
    }

    private function nextSequence(BulkIntakeBatch $batch): int
    {
        return ((int) $batch->items()->max('item_sequence')) + 1;
    }

    private function normalizeActorType(mixed $value): string
    {
        $actorType = $this->stringOrNull($value);

        return in_array($actorType, BulkIntakeBatch::ALLOWED_ACTOR_TYPES, true)
            ? $actorType
            : BulkIntakeBatch::ACTOR_UNKNOWN;
    }

    private function normalizeSurface(mixed $value): ?string
    {
        $surface = $this->stringOrNull($value);

        return in_array($surface, BulkIntakeBatch::ALLOWED_SURFACES, true) ? $surface : null;
    }

    private function normalizeInputType(mixed $value): string
    {
        $inputType = $this->stringOrNull($value);

        return in_array($inputType, BulkIntakeBatchItem::ALLOWED_INPUT_TYPES, true)
            ? $inputType
            : BulkIntakeBatchItem::INPUT_UNKNOWN;
    }

    private function hashOrNull(mixed $value): ?string
    {
        $hash = $this->stringOrNull($value);

        return $hash !== null && preg_match('/^[a-f0-9]{64}$/i', $hash) === 1 ? strtolower($hash) : null;
    }

    private function hashFile(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return null;
        }

        $hash = @hash_file('sha256', $path);

        return is_string($hash) && $hash !== '' ? strtolower($hash) : null;
    }

    private function itemIdempotencyKey(BulkIntakeBatch $batch, int $sequence, string $kind, ?string $hash): string
    {
        return implode(':', array_filter([
            'bulk',
            (string) $batch->id,
            (string) $sequence,
            $kind,
            $hash,
        ], static fn (?string $part): bool => $part !== null && $part !== ''));
    }

    /**
     * @return array{queued: int, skipped: int, failed: int, skipped_reasons: array<string, int>}
     */
    private function emptyQueueSummary(): array
    {
        return [
            'queued' => 0,
            'skipped' => 0,
            'failed' => 0,
            'skipped_reasons' => [],
        ];
    }

    /**
     * @return array{queued: int, skipped: int, failed: int, skipped_reasons: array<string, int>}
     */
    private function skipQueueSummary(string $reason): array
    {
        return [
            'queued' => 0,
            'skipped' => 1,
            'failed' => 0,
            'skipped_reasons' => [
                $reason => 1,
            ],
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
