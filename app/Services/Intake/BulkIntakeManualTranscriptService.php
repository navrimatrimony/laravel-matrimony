<?php

namespace App\Services\Intake;

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\IntakeSourceContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BulkIntakeManualTranscriptService
{
    public function __construct(
        private readonly IntakeOcrAttemptRecorder $ocrAttemptRecorder,
        private readonly IntakeExtractionReuseResolver $reuseResolver,
        private readonly IntakeSourceContextRecorder $sourceContextRecorder,
        private readonly BulkIntakeBatchService $batchService
    ) {}

    /**
     * @return array{item: BulkIntakeBatchItem, intake: BiodataIntake, ocr_attempt: BiodataIntakeOcrAttempt, queued: bool}
     */
    public function saveTranscriptForItem(BulkIntakeBatchItem $item, User $actor, string $transcript, bool $queueParse): array
    {
        $transcript = trim($transcript);
        if (mb_strlen($transcript, 'UTF-8') < 20) {
            throw ValidationException::withMessages([
                'transcript' => 'Transcript must be at least 20 characters.',
            ]);
        }

        return DB::transaction(function () use ($item, $actor, $transcript, $queueParse): array {
            $lockedItem = BulkIntakeBatchItem::query()
                ->lockForUpdate()
                ->with('batch')
                ->findOrFail($item->id);

            if ($lockedItem->biodata_intake_id === null) {
                throw $this->invalidTranscriptState('Linked biodata intake is missing.');
            }

            $lockedIntake = BiodataIntake::query()
                ->lockForUpdate()
                ->findOrFail((int) $lockedItem->biodata_intake_id);

            if ((bool) $lockedIntake->approved_by_user || (bool) $lockedIntake->intake_locked) {
                throw $this->invalidTranscriptState('Manual transcript cannot be saved for approved or locked intake.');
            }

            $transcriptHash = hash('sha256', $transcript);
            $savedAt = now()->toIso8601String();

            $ocrAttempt = $this->ocrAttemptRecorder->record($lockedIntake, [
                'engine' => BiodataIntakeOcrAttempt::ENGINE_MANUAL_TRANSCRIPT,
                'source' => 'bulk_manual_transcript',
                'created_by_user_id' => (int) $actor->id,
                'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_ADMIN,
                'source_surface' => BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
                'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
                'raw_text' => $transcript,
                'is_primary' => true,
                'selected_by' => BiodataIntakeOcrAttempt::ACTOR_ADMIN,
                'selected_by_user_id' => (int) $actor->id,
                'selected_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_ADMIN,
                'selected_reason' => 'manual_transcript_for_empty_ocr_recovery',
                'engine_meta_json' => [
                    'action' => 'manual_transcript_saved',
                    'bulk_intake_batch_id' => $lockedItem->bulk_intake_batch_id,
                    'bulk_intake_batch_item_id' => $lockedItem->id,
                    'raw_ocr_text_unchanged' => true,
                ],
            ]);

            $this->reuseResolver->putCachedParseInputText((int) $lockedIntake->id, $transcript, false);

            $lockedIntake->forceFill([
                'last_parse_input_text' => $transcript,
                'parse_status' => 'pending',
                'last_error' => null,
            ])->save();

            $meta = is_array($lockedItem->item_meta_json) ? $lockedItem->item_meta_json : [];
            $itemStatus = $queueParse
                ? BulkIntakeBatchItem::STATUS_PARSE_QUEUED
                : $this->statusAfterManualTranscriptSave($lockedItem);

            $itemMeta = array_merge($meta, [
                'manual_transcript_saved_at' => $savedAt,
                'manual_transcript_saved_by_user_id' => (int) $actor->id,
                'manual_transcript_hash' => $transcriptHash,
                'manual_transcript_length' => mb_strlen($transcript, 'UTF-8'),
            ]);

            if ($queueParse) {
                IntakeExtractionReuseResolver::flagNextParseJobAsParseInputOnly((int) $lockedIntake->id);
                ParseIntakeJob::dispatch((int) $lockedIntake->id, true);
                $itemMeta['parse_queue_mode'] = 'manual_transcript_parse_input_only';
                $itemMeta['parse_input_only'] = true;
                $itemMeta['queued_by_user_id'] = (int) $actor->id;
                $itemMeta['queued_by_actor_type'] = BulkIntakeBatch::ACTOR_ADMIN;
                $itemMeta['queued_at'] = $savedAt;
            }

            $lockedItem->forceFill([
                'item_status' => $itemStatus,
                'item_meta_json' => $itemMeta,
                'failure_code' => null,
                'failure_message' => null,
            ])->save();

            $this->sourceContextRecorder->recordForIntake($lockedIntake, [
                'source_type' => IntakeSourceContext::SOURCE_ADMIN_MANUAL,
                'source_surface' => IntakeSourceContext::SURFACE_ADMIN_PANEL,
                'actor_type' => IntakeSourceContext::ACTOR_ADMIN,
                'actor_user_id' => (int) $actor->id,
                'bulk_intake_batch_id' => $lockedItem->bulk_intake_batch_id,
                'bulk_intake_batch_item_id' => $lockedItem->id,
                'idempotency_key' => 'bulk-manual-transcript:'.$lockedItem->id.':'.$transcriptHash,
                'source_meta_json' => [
                    'action' => 'manual_transcript_saved',
                    'queue_parse' => $queueParse,
                    'manual_transcript_hash' => $transcriptHash,
                    'manual_transcript_length' => mb_strlen($transcript, 'UTF-8'),
                    'raw_ocr_text_unchanged' => true,
                ],
            ]);

            if ($lockedItem->batch !== null) {
                $this->batchService->refreshCounters($lockedItem->batch);
            }

            return [
                'item' => $lockedItem->refresh(),
                'intake' => $lockedIntake->refresh(),
                'ocr_attempt' => $ocrAttempt->refresh(),
                'queued' => $queueParse,
            ];
        });
    }

    private function statusAfterManualTranscriptSave(BulkIntakeBatchItem $item): string
    {
        if ((string) $item->item_status === BulkIntakeBatchItem::STATUS_PROFILE_DRAFT_CREATED) {
            return BulkIntakeBatchItem::STATUS_PROFILE_DRAFT_CREATED;
        }

        return BulkIntakeBatchItem::STATUS_INTAKE_CREATED;
    }

    private function invalidTranscriptState(string $message): ValidationException
    {
        return ValidationException::withMessages([
            'transcript' => $message,
        ]);
    }
}
