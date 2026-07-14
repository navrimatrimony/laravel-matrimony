<?php

namespace App\Services\Intake;

use App\Jobs\ParseIntakeJob;
use App\Jobs\ProcessBulkIntakeBatchItemJob;
use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Ocr\OcrNormalize;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class BulkIntakeBatchService
{
    public const BULK_QUEUE_NAME = ProcessBulkIntakeBatchItemJob::QUEUE_NAME;

    private const USABLE_OCR_TEXT_MIN_LENGTH = 20;

    private const EMPTY_OCR_FAILURE_CODE = 'empty_ocr_text';

    private const EMPTY_OCR_FAILURE_MESSAGE = 'OCR did not extract usable text from this file.';

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

    public function createPendingItemFromUploadedFile(
        BulkIntakeBatch $batch,
        UploadedFile $file,
        int $sequence,
        bool $queueFreeParseAfterUpload
    ): BulkIntakeBatchItem {
        $fileHash = $this->hashFile($file);
        $storedPath = $file->store('bulk-intake-sources/'.$batch->id);
        if (! is_string($storedPath) || $storedPath === '') {
            throw new RuntimeException('Bulk intake source file could not be stored.');
        }

        return $this->addItem($batch, [
            'item_sequence' => $sequence,
            'input_type' => BulkIntakeBatchItem::INPUT_FILE,
            'original_filename' => $file->getClientOriginalName(),
            'source_file_path' => $storedPath,
            'file_hash' => $fileHash,
            'idempotency_key' => $this->itemIdempotencyKey($batch, $sequence, 'file', $fileHash),
            'item_status' => BulkIntakeBatchItem::STATUS_PENDING,
            'item_meta_json' => [
                'async_processing' => true,
                'queue_free_parse_after_upload' => $queueFreeParseAfterUpload,
                'source_file_path' => $storedPath,
                'source_original_filename' => $file->getClientOriginalName(),
                'source_mime_type' => $file->getClientMimeType(),
                'background_status' => 'queued',
                'queued_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function createPendingItemFromRawText(
        BulkIntakeBatch $batch,
        string $rawText,
        int $sequence,
        bool $queueFreeParseAfterUpload
    ): BulkIntakeBatchItem {
        $rawText = trim($rawText);
        if ($rawText === '') {
            throw new RuntimeException('Bulk intake text item is empty.');
        }

        $textHash = hash('sha256', $rawText);

        return $this->addItem($batch, [
            'item_sequence' => $sequence,
            'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
            'raw_text_hash' => $textHash,
            'idempotency_key' => $this->itemIdempotencyKey($batch, $sequence, 'text', $textHash),
            'item_status' => BulkIntakeBatchItem::STATUS_PENDING,
            'summary_text' => mb_substr($rawText, 0, 500),
            'item_meta_json' => [
                'async_processing' => true,
                'queue_free_parse_after_upload' => $queueFreeParseAfterUpload,
                'source_text' => $rawText,
                'background_status' => 'queued',
                'queued_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function processPendingItem(
        BulkIntakeBatchItem $item,
        User $actor,
        IntakeCreationService $intakeCreation,
        IntakeSourceContextRecorder $sourceContextRecorder,
        bool $queueFreeParseAfterUpload = true
    ): BulkIntakeBatchItem {
        $claimedItem = $this->claimItemForProcessing($item);

        if ($claimedItem->biodata_intake_id !== null) {
            // B2: resume Phase 3/4 when intake exists but field_resolution_json is still missing.
            $this->resumeEnsembleFieldResolutionIfNeeded($claimedItem);

            if ($queueFreeParseAfterUpload) {
                $this->queueAutoFreeParseAfterUploadForItem($claimedItem, $actor);
            }

            $batch = $claimedItem->batch;
            if ($batch !== null) {
                $this->refreshCounters($batch);
            }

            return $claimedItem->refresh();
        }

        $file = null;
        $rawText = null;

        if ($claimedItem->input_type === BulkIntakeBatchItem::INPUT_FILE) {
            $file = $this->uploadedFileFromPendingItem($claimedItem);
        } else {
            $rawText = $this->sourceTextFromPendingItem($claimedItem);
            if (trim($rawText) === '') {
                return $this->markItemEmptyOcrNeedsReview($claimedItem, 'bulk_item_processing');
            }
        }

        $processedItem = $this->createUnclaimedIntakeForItem(
            $claimedItem,
            $actor,
            $intakeCreation,
            $sourceContextRecorder,
            $file,
            $rawText
        );

        $this->runPhase3FieldResolutionIfApplicable($processedItem);

        $this->runPhase4SarvamJudgeIfApplicable($processedItem);

        if ($queueFreeParseAfterUpload) {
            $this->queueAutoFreeParseAfterUploadForItem($processedItem, $actor);
        } else {
            $batch = $processedItem->batch;
            if ($batch !== null) {
                $this->refreshCounters($batch);
            }
        }

        return $processedItem->refresh();
    }

    private function claimItemForProcessing(BulkIntakeBatchItem $item): BulkIntakeBatchItem
    {
        return DB::transaction(function () use ($item): BulkIntakeBatchItem {
            $lockedItem = BulkIntakeBatchItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($lockedItem->biodata_intake_id !== null) {
                return $lockedItem->refresh();
            }

            $meta = is_array($lockedItem->item_meta_json) ? $lockedItem->item_meta_json : [];
            $processingMeta = [
                'background_status' => 'processing',
                'processing_started_at' => now()->toIso8601String(),
            ];
            if (
                app(IntakeOcrEnsembleGate::class)->isEnabled()
                && (string) $lockedItem->input_type === BulkIntakeBatchItem::INPUT_FILE
            ) {
                $processingMeta['ocr_ensemble_status'] = 'ocr_ensemble_processing';
                $processingMeta['ocr_ensemble_pipeline'] = IntakeOcrEnsemblePhase1Service::PIPELINE_VERSION;
            }
            $lockedItem->forceFill([
                'item_status' => BulkIntakeBatchItem::STATUS_PROCESSING,
                'failure_code' => null,
                'failure_message' => null,
                'item_meta_json' => array_merge($meta, $processingMeta),
            ])->save();

            $batch = $lockedItem->batch;
            if ($batch !== null) {
                $this->refreshCounters($batch);
            }

            return $lockedItem->refresh();
        });
    }

    private function uploadedFileFromPendingItem(BulkIntakeBatchItem $item): UploadedFile
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $sourcePath = $this->stringOrNull($meta['source_file_path'] ?? null)
            ?? $this->stringOrNull($item->source_file_path);
        if ($sourcePath === null) {
            throw new RuntimeException('Bulk intake source file path is missing.');
        }

        $absolutePath = Storage::path($sourcePath);
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException('Bulk intake source file is not readable.');
        }

        $originalName = $this->stringOrNull($meta['source_original_filename'] ?? null)
            ?? $this->stringOrNull($item->original_filename)
            ?? basename($sourcePath);

        return new UploadedFile(
            $absolutePath,
            $originalName,
            $this->stringOrNull($meta['source_mime_type'] ?? null),
            null,
            true
        );
    }

    private function sourceTextFromPendingItem(BulkIntakeBatchItem $item): string
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $sourceText = $meta['source_text'] ?? null;
        if (is_string($sourceText)) {
            return $sourceText;
        }

        return (string) ($item->summary_text ?? '');
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

            $meta = is_array($lockedItem->item_meta_json) ? $lockedItem->item_meta_json : [];
            $lockedItem->forceFill([
                'biodata_intake_id' => $intake->id,
                'source_file_path' => $intake->file_path,
                'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
                'failure_code' => null,
                'failure_message' => null,
                'item_meta_json' => array_merge($meta, [
                    'background_status' => 'intake_created',
                    'intake_created_at' => now()->toIso8601String(),
                ]),
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
        IntakeSourceContextRecorder $sourceContextRecorder,
        bool $queueFreeParseAfterUpload = true
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

                $item = $this->createUnclaimedIntakeForItem($item, $actor, $intakeCreation, $sourceContextRecorder, $file, null);
                if ($queueFreeParseAfterUpload) {
                    $this->queueAutoFreeParseAfterUploadForItem($item, $actor);
                }
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

                $item = $this->createUnclaimedIntakeForItem($item, $actor, $intakeCreation, $sourceContextRecorder, null, $rawText);
                if ($queueFreeParseAfterUpload) {
                    $this->queueAutoFreeParseAfterUploadForItem($item, $actor);
                }
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

        $prepared = $file instanceof UploadedFile
            ? $intakeCreation->prepareForBulkFile(null, $file)
            : $intakeCreation->prepare(null, null, $rawText);

        return DB::transaction(function () use ($item, $actor, $intakeCreation, $sourceContextRecorder, $prepared): BulkIntakeBatchItem {
            $lockedItem = BulkIntakeBatchItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($lockedItem->biodata_intake_id !== null) {
                return $lockedItem->refresh();
            }

            $intake = $intakeCreation->persistPreparedForUnclaimedBulk($prepared);

            $meta = is_array($lockedItem->item_meta_json) ? $lockedItem->item_meta_json : [];
            $ocrEnsembleMeta = $this->ocrEnsembleItemMetaFromPrepared($prepared);
            $lockedItem->forceFill([
                'biodata_intake_id' => $intake->id,
                'source_file_path' => $intake->file_path,
                'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
                'failure_code' => null,
                'failure_message' => null,
                'item_meta_json' => array_merge($meta, [
                    'background_status' => 'intake_created',
                    'intake_created_at' => now()->toIso8601String(),
                ], $ocrEnsembleMeta),
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
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $item->forceFill([
            'item_status' => BulkIntakeBatchItem::STATUS_FAILED,
            'failure_code' => $failureCode,
            'failure_message' => mb_substr($message, 0, 2000),
            'item_meta_json' => array_merge($meta, [
                'background_status' => 'failed',
                'failed_at' => now()->toIso8601String(),
                'failure_code' => $failureCode,
                'failure_message' => mb_substr($message, 0, 2000),
            ]),
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
        return $this->queueFreeParseForItemWithMode(
            $item,
            $actor,
            BulkIntakeBatch::OCR_POLICY_FREE_OCR_FIRST,
            ['queued_at' => now()->toIso8601String()]
        );
    }

    /**
     * @return array{queued: int, skipped: int, failed: int, skipped_reasons: array<string, int>}
     */
    public function queueAutoFreeParseAfterUploadForItem(BulkIntakeBatchItem $item, User $actor): array
    {
        return $this->queueFreeParseForItemWithMode(
            $item,
            $actor,
            'auto_free_parse_after_upload',
            ['auto_queued_at' => now()->toIso8601String()]
        );
    }

    /**
     * @param  array<string, mixed>  $timestampMeta
     * @return array{queued: int, skipped: int, failed: int, skipped_reasons: array<string, int>}
     */
    private function queueFreeParseForItemWithMode(BulkIntakeBatchItem $item, User $actor, string $queueMode, array $timestampMeta): array
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

            if (! $this->usableParseInputForQueue($intake)) {
                $this->markItemEmptyOcrNeedsReview($item, $queueMode);

                return $this->skipQueueSummary(self::EMPTY_OCR_FAILURE_CODE);
            }

            IntakeExtractionReuseResolver::flagNextParseJobAsParseInputOnly((int) $intake->id);
            ParseIntakeJob::dispatch((int) $intake->id, true)->onQueue(self::BULK_QUEUE_NAME);

            $meta = $this->withoutOcrFailureMeta(is_array($item->item_meta_json) ? $item->item_meta_json : []);
            $queuedAt = now()->toIso8601String();
            $item->forceFill([
                'item_status' => BulkIntakeBatchItem::STATUS_PARSE_QUEUED,
                'item_meta_json' => array_merge($meta, [
                    'background_status' => 'parse_queued',
                    'parse_queue_mode' => BulkIntakeBatch::OCR_POLICY_FREE_OCR_FIRST,
                    'parse_input_only' => true,
                    'queued_by_user_id' => (int) $actor->id,
                    'queued_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
                    'parse_queued_at' => $queuedAt,
                ], [
                    'parse_queue_mode' => $queueMode,
                ], $timestampMeta),
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

    private function usableParseInputForQueue(BiodataIntake $intake): bool
    {
        $cachedText = app(IntakeExtractionReuseResolver::class)->getCachedParseInputText((int) $intake->id);

        foreach ([
            (string) ($intake->last_parse_input_text ?? ''),
            (string) ($cachedText ?? ''),
            (string) ($intake->raw_ocr_text ?? ''),
        ] as $text) {
            if (mb_strlen($this->normalizedUsabilityText($text), 'UTF-8') >= self::USABLE_OCR_TEXT_MIN_LENGTH) {
                return true;
            }
        }

        return false;
    }

    private function normalizedUsabilityText(string $text): string
    {
        $normalized = trim(OcrNormalize::normalizeRawTextForParsing($text));
        $normalized = preg_replace('/\s+/u', ' ', $normalized);

        return is_string($normalized) ? trim($normalized) : '';
    }

    private function markItemEmptyOcrNeedsReview(BulkIntakeBatchItem $item, string $queueMode): BulkIntakeBatchItem
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        if ((string) $item->item_status !== BulkIntakeBatchItem::STATUS_NEEDS_REVIEW) {
            $meta['previous_item_status'] = (string) $item->item_status;
        }

        $markedAt = now()->toIso8601String();
        $meta = array_merge($meta, [
            'ocr_text_usable' => false,
            'ocr_text_min_length' => self::USABLE_OCR_TEXT_MIN_LENGTH,
            'ocr_failure_code' => self::EMPTY_OCR_FAILURE_CODE,
            'ocr_failure_message' => self::EMPTY_OCR_FAILURE_MESSAGE,
            'parse_queue_attempt_mode' => $queueMode,
            'parse_skipped_reason' => self::EMPTY_OCR_FAILURE_CODE,
            'parse_skipped_at' => $markedAt,
        ]);

        if ($queueMode === 'auto_free_parse_after_upload') {
            $meta['auto_parse_skipped_reason'] = self::EMPTY_OCR_FAILURE_CODE;
            $meta['auto_parse_skipped_at'] = $markedAt;
        }

        $item->forceFill([
            'item_status' => BulkIntakeBatchItem::STATUS_NEEDS_REVIEW,
            'item_meta_json' => $meta,
            'failure_code' => self::EMPTY_OCR_FAILURE_CODE,
            'failure_message' => self::EMPTY_OCR_FAILURE_MESSAGE,
        ])->save();

        $batch = $item->batch;
        if ($batch !== null) {
            $this->refreshCounters($batch);
        }

        return $item->refresh();
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function withoutOcrFailureMeta(array $meta): array
    {
        unset(
            $meta['ocr_text_usable'],
            $meta['ocr_text_min_length'],
            $meta['ocr_failure_code'],
            $meta['ocr_failure_message'],
            $meta['parse_queue_attempt_mode'],
            $meta['parse_skipped_reason'],
            $meta['parse_skipped_at'],
            $meta['auto_parse_skipped_reason'],
            $meta['auto_parse_skipped_at']
        );

        return $meta;
    }

    public function markItemNeedsReview(BulkIntakeBatchItem $item, User $actor, ?string $reason = null): BulkIntakeBatchItem
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        if ((string) $item->item_status !== BulkIntakeBatchItem::STATUS_NEEDS_REVIEW) {
            $meta['previous_item_status'] = (string) $item->item_status;
        }

        $meta['needs_review_marked_by_user_id'] = (int) $actor->id;
        $meta['needs_review_marked_at'] = now()->toIso8601String();
        $meta['needs_review_reason'] = $this->stringOrNull($reason);

        $item->forceFill([
            'item_status' => BulkIntakeBatchItem::STATUS_NEEDS_REVIEW,
            'item_meta_json' => $meta,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        $batch = $item->batch;
        if ($batch !== null) {
            $this->refreshCounters($batch);
        }

        return $item->refresh();
    }

    public function clearItemNeedsReview(BulkIntakeBatchItem $item, User $actor): BulkIntakeBatchItem
    {
        $item->loadMissing('biodataIntake');

        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $previousStatus = $this->stringOrNull($meta['previous_item_status'] ?? null);
        $targetStatus = $previousStatus ?? $this->defaultStatusAfterReviewClear($item);

        unset($meta['previous_item_status']);
        $meta['needs_review_cleared_by_user_id'] = (int) $actor->id;
        $meta['needs_review_cleared_at'] = now()->toIso8601String();
        $meta['needs_review_restored_item_status'] = $targetStatus;

        $item->forceFill([
            'item_status' => $targetStatus,
            'item_meta_json' => $meta,
        ])->save();

        $batch = $item->batch;
        if ($batch !== null) {
            $this->refreshCounters($batch);
        }

        return $item->refresh();
    }

    /**
     * @return array{total: int, pending: int, processing: int, unclaimed: int, claimed: int, intakes_created: int, parse_pending: int, parse_queued: int, parsed: int, parse_error: int, needs_review: int, failed: int}
     */
    public function buildBatchReviewSummary(BulkIntakeBatch $batch): array
    {
        $items = $batch->items()
            ->with('biodataIntake:id,uploaded_by,parse_status,last_error,parsed_json')
            ->get();

        return [
            'total' => $items->count(),
            'pending' => $items->where('item_status', BulkIntakeBatchItem::STATUS_PENDING)->count(),
            'processing' => $items->where('item_status', BulkIntakeBatchItem::STATUS_PROCESSING)->count(),
            'unclaimed' => $items->filter(fn (BulkIntakeBatchItem $item): bool => $item->biodataIntake instanceof BiodataIntake && $item->biodataIntake->uploaded_by === null)->count(),
            'claimed' => $items->filter(fn (BulkIntakeBatchItem $item): bool => $item->biodataIntake instanceof BiodataIntake && $item->biodataIntake->uploaded_by !== null)->count(),
            'intakes_created' => $items->whereNotNull('biodata_intake_id')->count(),
            'parse_pending' => $items->filter(fn (BulkIntakeBatchItem $item): bool => $item->biodataIntake instanceof BiodataIntake && (string) $item->biodataIntake->parse_status === 'pending')->count(),
            'parse_queued' => $items->where('item_status', BulkIntakeBatchItem::STATUS_PARSE_QUEUED)->count(),
            'parsed' => $items->filter(fn (BulkIntakeBatchItem $item): bool => $item->biodataIntake instanceof BiodataIntake && (string) $item->biodataIntake->parse_status === 'parsed')->count(),
            'parse_error' => $items->filter(fn (BulkIntakeBatchItem $item): bool => $item->biodataIntake instanceof BiodataIntake && (string) $item->biodataIntake->parse_status === 'error')->count(),
            'needs_review' => $items->where('item_status', BulkIntakeBatchItem::STATUS_NEEDS_REVIEW)->count(),
            'failed' => $items->where('item_status', BulkIntakeBatchItem::STATUS_FAILED)->count(),
        ];
    }

    public function refreshCounters(BulkIntakeBatch $batch): BulkIntakeBatch
    {
        $items = $batch->items();
        $profilesCreated = (clone $items)
            ->whereHas('biodataIntake', fn ($intakeQuery) => $intakeQuery->whereNotNull('matrimony_profile_id'))
            ->count();

        $batch->forceFill([
            'total_items' => (clone $items)->count(),
            'total_files' => (clone $items)->where('input_type', BulkIntakeBatchItem::INPUT_FILE)->count(),
            'total_texts' => (clone $items)->where('input_type', BulkIntakeBatchItem::INPUT_TEXT)->count(),
            'total_intakes_created' => (clone $items)->whereNotNull('biodata_intake_id')->count(),
            'total_profiles_created' => $profilesCreated,
            'total_conflicts_generated' => 0,
            'total_needs_review' => (clone $items)->where('item_status', BulkIntakeBatchItem::STATUS_NEEDS_REVIEW)->count(),
            'total_failed' => (clone $items)->where('item_status', BulkIntakeBatchItem::STATUS_FAILED)->count(),
        ])->save();

        return $this->refreshBatchLifecycleStatus($batch->refresh());
    }

    private function refreshBatchLifecycleStatus(BulkIntakeBatch $batch): BulkIntakeBatch
    {
        if ((string) $batch->batch_status === BulkIntakeBatch::STATUS_CANCELLED) {
            return $batch;
        }

        $items = $batch->items()
            ->with('biodataIntake:id,parse_status,parsed_json')
            ->get(['id', 'bulk_intake_batch_id', 'biodata_intake_id', 'item_status']);

        if ($items->isEmpty()) {
            $batch->forceFill([
                'batch_status' => BulkIntakeBatch::STATUS_PENDING,
                'completed_at' => null,
            ])->save();

            return $batch->refresh();
        }

        $active = $items->contains(function (BulkIntakeBatchItem $item): bool {
            if (in_array((string) $item->item_status, [
                BulkIntakeBatchItem::STATUS_PENDING,
                BulkIntakeBatchItem::STATUS_PROCESSING,
            ], true)) {
                return true;
            }

            if ((string) $item->item_status !== BulkIntakeBatchItem::STATUS_PARSE_QUEUED) {
                return false;
            }

            $intake = $item->biodataIntake;

            return ! $intake instanceof BiodataIntake || (string) $intake->parse_status === 'pending';
        });

        $failedCount = $items->where('item_status', BulkIntakeBatchItem::STATUS_FAILED)->count();
        $status = $active
            ? BulkIntakeBatch::STATUS_PROCESSING
            : ($failedCount === $items->count() ? BulkIntakeBatch::STATUS_FAILED : BulkIntakeBatch::STATUS_COMPLETED);

        $attributes = [
            'batch_status' => $status,
        ];
        if ($status === BulkIntakeBatch::STATUS_PROCESSING && $batch->started_at === null) {
            $attributes['started_at'] = now();
            $attributes['completed_at'] = null;
        }
        if (in_array($status, [BulkIntakeBatch::STATUS_COMPLETED, BulkIntakeBatch::STATUS_FAILED], true) && $batch->completed_at === null) {
            $attributes['completed_at'] = now();
        }

        $batch->forceFill($attributes)->save();

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

    private function defaultStatusAfterReviewClear(BulkIntakeBatchItem $item): string
    {
        $intake = $item->biodataIntake;
        if ($intake instanceof BiodataIntake && (string) $intake->parse_status === 'error') {
            return BulkIntakeBatchItem::STATUS_FAILED;
        }

        return BulkIntakeBatchItem::STATUS_INTAKE_CREATED;
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

    /**
     * @param  array<string, mixed>  $prepared
     * @return array<string, mixed>
     */
    private function ocrEnsembleItemMetaFromPrepared(array $prepared): array
    {
        if (! empty($prepared['ensemble_phase1'])) {
            return [
                'ocr_ensemble_status' => 'ocr_ready',
                'ocr_ensemble_pipeline' => IntakeOcrEnsemblePhase1Service::PIPELINE_VERSION,
            ];
        }

        if (! empty($prepared['ensemble_phase1_skipped'])) {
            return [
                'ocr_ensemble_status' => 'ocr_ready',
                'ocr_ensemble_pipeline' => IntakeOcrEnsemblePhase1Service::PIPELINE_VERSION,
                'ocr_ensemble_skip_reason' => (string) ($prepared['ensemble_skip_reason'] ?? 'reused_transcript'),
            ];
        }

        return [];
    }

    private function runPhase3FieldResolutionIfApplicable(BulkIntakeBatchItem $item): void
    {
        app(IntakeOcrEnsemblePhase3Service::class)->runForBulkItemIfApplicable($item);
    }

    private function runPhase4SarvamJudgeIfApplicable(BulkIntakeBatchItem $item): void
    {
        app(IntakeOcrEnsemblePhase4Service::class)->runForBulkItemIfApplicable($item);
    }

    /**
     * Retry resume (Phase 4.5 B2): when gates allow and envelope is missing, run Phase 3 then Phase 4.
     * Skips when Phase 3 already persisted field_resolution_json (no duplicate work).
     */
    private function resumeEnsembleFieldResolutionIfNeeded(BulkIntakeBatchItem $item): void
    {
        if (! $this->shouldResumeEnsembleFieldResolution($item)) {
            return;
        }

        $this->runPhase3FieldResolutionIfApplicable($item);
        $this->runPhase4SarvamJudgeIfApplicable($item);
    }

    private function shouldResumeEnsembleFieldResolution(BulkIntakeBatchItem $item): bool
    {
        $gate = app(IntakeOcrEnsembleGate::class);
        if (! $gate->isPhase3Enabled()) {
            return false;
        }

        if ((string) $item->input_type !== BulkIntakeBatchItem::INPUT_FILE) {
            return false;
        }

        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        if (($meta['ocr_ensemble_skip_reason'] ?? null) === 'reused_transcript') {
            return false;
        }

        $item->loadMissing('biodataIntake');
        $intake = $item->biodataIntake;
        if (! $intake instanceof BiodataIntake) {
            return false;
        }

        $json = $intake->field_resolution_json;

        return ! is_array($json) || $json === [];
    }
}
