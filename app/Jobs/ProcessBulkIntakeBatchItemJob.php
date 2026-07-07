<?php

namespace App\Jobs;

use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Intake\BulkIntakeBatchService;
use App\Services\Intake\IntakeCreationService;
use App\Services\Intake\IntakeSourceContextRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessBulkIntakeBatchItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(
        public int $bulkIntakeBatchItemId,
        public int $actorUserId,
        public bool $queueFreeParseAfterUpload = true
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('bulk-intake-item:'.$this->bulkIntakeBatchItemId))
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(
        BulkIntakeBatchService $batchService,
        IntakeCreationService $intakeCreation,
        IntakeSourceContextRecorder $sourceContextRecorder
    ): void {
        $item = BulkIntakeBatchItem::query()->find($this->bulkIntakeBatchItemId);
        if (! $item instanceof BulkIntakeBatchItem) {
            return;
        }

        $actor = User::query()->find($this->actorUserId);
        if (! $actor instanceof User) {
            $batchService->failItem($item, 'bulk_actor_missing', 'Bulk intake actor was not found.');

            return;
        }

        try {
            $batchService->processPendingItem(
                $item,
                $actor,
                $intakeCreation,
                $sourceContextRecorder,
                $this->queueFreeParseAfterUpload
            );
        } catch (Throwable $e) {
            $freshItem = BulkIntakeBatchItem::query()->find($this->bulkIntakeBatchItemId);
            if ($freshItem instanceof BulkIntakeBatchItem) {
                $batchService->failItem($freshItem, 'bulk_item_processing_failed', $e->getMessage());
            }

            Log::warning('ProcessBulkIntakeBatchItemJob failed for one item', [
                'bulk_intake_batch_item_id' => $this->bulkIntakeBatchItemId,
                'actor_user_id' => $this->actorUserId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        $item = BulkIntakeBatchItem::query()->find($this->bulkIntakeBatchItemId);
        if (! $item instanceof BulkIntakeBatchItem) {
            return;
        }

        app(BulkIntakeBatchService::class)->failItem($item, 'bulk_item_job_failed', $e->getMessage());
    }
}
