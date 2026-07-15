<?php

namespace App\Console\Commands;

use App\Jobs\ProcessBulkIntakeBatchItemJob;
use App\Models\BulkIntakeBatch;
use App\Models\User;
use App\Services\Intake\BulkIntakeBatchService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/**
 * Benchmark-only: ingest a local OCR folder into a bulk intake batch and queue OCR.
 * Does not change production API or MutationService paths.
 */
class OcrEnsembleBenchmarkIngestFolderCommand extends Command
{
    protected $signature = 'ocr-ensemble:benchmark-ingest-folder
        {folder : Absolute or storage-relative folder (e.g. storage/app/ocr-dev-batches/Batch-001)}
        {--batch-name= : Optional bulk batch name}
        {--admin-id= : Admin user id (default: first admin)}
        {--parse : Queue free parse after upload (default: true)}
        {--no-parse : Skip free parse after upload}
        {--limit=0 : Max files to ingest (0 = all)}';

    protected $description = 'Ingest OCR-dev batch folder into bulk intake and queue ProcessBulkIntakeBatchItemJob (benchmark).';

    public function handle(BulkIntakeBatchService $batchService): int
    {
        $folderArg = trim((string) $this->argument('folder'));
        $folder = $this->resolveFolder($folderArg);
        if ($folder === null) {
            $this->error('Folder not found: '.$folderArg);

            return self::FAILURE;
        }

        $adminId = (int) ($this->option('admin-id') ?: 0);
        $admin = $adminId > 0
            ? User::query()->where('is_admin', true)->find($adminId)
            : User::query()->where('is_admin', true)->orderBy('id')->first();

        if (! $admin instanceof User) {
            $this->error('No admin user found.');

            return self::FAILURE;
        }

        $queueParse = ! (bool) $this->option('no-parse');
        if ($this->option('parse')) {
            $queueParse = true;
        }

        $limit = max(0, (int) $this->option('limit'));
        $files = $this->collectMediaFiles($folder);
        if ($limit > 0) {
            $files = array_slice($files, 0, $limit);
        }

        if ($files === []) {
            $this->error('No media files found in '.$folder);

            return self::FAILURE;
        }

        $batchName = trim((string) ($this->option('batch-name') ?: ''));
        if ($batchName === '') {
            $batchName = 'OCR-DEV-'.basename($folder).'-'.now()->format('Ymd-His');
        }

        $batch = $batchService->createBatch([
            'uploaded_by_user_id' => $admin->id,
            'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
            'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
            'batch_name' => $batchName,
            'batch_status' => BulkIntakeBatch::STATUS_PENDING,
            'intake_creation_policy' => BulkIntakeBatch::POLICY_EXISTING_CHAIN,
            'ocr_policy' => BulkIntakeBatch::OCR_POLICY_FREE_OCR_FIRST,
            'meta_json' => [
                'ocr_dev_batch' => true,
                'source_folder' => $folder,
                'sprint' => 'sprint2_engine_eval',
                'owner_user_mode' => 'unclaimed_bulk_staging',
                'parse_dispatch' => $queueParse ? 'auto_free_parse_after_upload' : 'deferred',
            ],
        ]);

        $sequence = 1;
        foreach ($files as $absolutePath) {
            $uploaded = new UploadedFile(
                $absolutePath,
                basename($absolutePath),
                mime_content_type($absolutePath) ?: null,
                null,
                true
            );
            $item = $batchService->createPendingItemFromUploadedFile($batch, $uploaded, $sequence, $queueParse);
            ProcessBulkIntakeBatchItemJob::dispatch((int) $item->id, (int) $admin->id, $queueParse)
                ->onQueue(ProcessBulkIntakeBatchItemJob::QUEUE_NAME);
            $this->line(sprintf('queued seq=%d file=%s item=%d', $sequence, basename($absolutePath), $item->id));
            $sequence++;
        }

        $batchService->refreshCounters($batch);

        $this->newLine();
        $this->info('Ingest complete.');
        $this->line('bulk_batch_id='.$batch->id);
        $this->line('files='.count($files));
        $this->line('queue=bulk-intake');
        $this->line('parse='.($queueParse ? 'yes' : 'no'));

        return self::SUCCESS;
    }

    private function resolveFolder(string $folderArg): ?string
    {
        if (is_dir($folderArg)) {
            return realpath($folderArg) ?: $folderArg;
        }

        $fromBase = base_path($folderArg);
        if (is_dir($fromBase)) {
            return realpath($fromBase) ?: $fromBase;
        }

        $fromStorage = storage_path('app/'.ltrim(str_replace('\\', '/', $folderArg), '/'));
        if (is_dir($fromStorage)) {
            return realpath($fromStorage) ?: $fromStorage;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function collectMediaFiles(string $folder): array
    {
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff'];
        $out = [];
        foreach (File::files($folder) as $file) {
            $ext = strtolower($file->getExtension());
            if (! in_array($ext, $allowed, true)) {
                continue;
            }
            $out[] = $file->getPathname();
        }
        natcasesort($out);

        return array_values($out);
    }
}
