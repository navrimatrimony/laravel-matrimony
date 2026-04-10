<?php

namespace App\Console\Commands;

use App\Models\ProfilePhoto;
use App\Services\Admin\AdminSettingService;
use App\Services\Image\NudeNetService;
use App\Services\Image\PhotoModerationScanPayload;
use App\Services\Image\ProfilePhotoUrlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ModerationRescanOldPhotosCommand extends Command
{
    protected $signature = 'moderation:rescan-old
                            {--dry-run : List targets without writing}
                            {--limit= : Maximum number of photos to process}';

    protected $description = 'Re-run NudeNet on profile_photos rows with null moderation_scan_json only (does not overwrite existing JSON).';

    public function handle(NudeNetService $nudenet): int
    {
        if (! Schema::hasTable('profile_photos') || ! Schema::hasColumn('profile_photos', 'moderation_scan_json')) {
            $this->error('profile_photos.moderation_scan_json is not available.');

            return self::FAILURE;
        }

        $limit = $this->option('limit');
        $max = $limit !== null && $limit !== '' ? max(1, (int) $limit) : null;
        $dry = (bool) $this->option('dry-run');

        $query = ProfilePhoto::query()
            ->whereNull('moderation_scan_json')
            ->whereNotNull('file_path')
            ->orderBy('id');

        $total = (clone $query)->count();
        $this->info("Found {$total} photo row(s) with null moderation_scan_json.");

        $processed = 0;
        $skipped = 0;
        $errors = 0;

        $query->chunkById(50, function ($photos) use ($nudenet, $dry, $max, &$processed, &$skipped, &$errors): bool {
            foreach ($photos as $photo) {
                if ($max !== null && $processed >= $max) {
                    return false;
                }

                /** @var ProfilePhoto $photo */
                $path = $this->resolveAbsolutePath($photo);
                if ($path === null) {
                    $this->warn("Skip id={$photo->id}: file not on disk.");
                    $skipped++;

                    continue;
                }

                if ($dry) {
                    $this->line("Would rescan photo id={$photo->id} path={$photo->file_path}");
                    $processed++;

                    continue;
                }

                try {
                    $nn = $nudenet->detect($path);
                    $payload = PhotoModerationScanPayload::fromModerationResult([
                        'meta' => ['nudenet' => $nn],
                    ]);
                    if (! is_array($payload)) {
                        $errors++;
                        $this->error("Photo id={$photo->id}: could not build scan payload.");

                        continue;
                    }

                    $st = strtolower((string) ($payload['api_status'] ?? 'pending'));
                    $approvalRequired = AdminSettingService::isPhotoApprovalRequired();
                    $approved = match ($st) {
                        'safe' => $approvalRequired ? 'pending' : 'approved',
                        'unsafe' => 'rejected',
                        'review', 'flagged' => 'pending',
                        default => 'pending',
                    };

                    $photo->moderation_scan_json = $payload;
                    $photo->approved_status = $approved;
                    $photo->save();
                    $processed++;
                    $this->info("Updated photo id={$photo->id} api_status={$st} approved_status={$approved}");
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error("Photo id={$photo->id}: {$e->getMessage()}");
                }

                if ($max !== null && $processed >= $max) {
                    return false;
                }
            }

            return true;
        });

        $this->newLine();
        $this->info("Done. processed={$processed} skipped={$skipped} errors={$errors}".($dry ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }

    private function resolveAbsolutePath(ProfilePhoto $photo): ?string
    {
        $fn = ltrim((string) $photo->file_path, '/');
        if ($fn === '') {
            return null;
        }
        if (ProfilePhotoUrlService::isPendingPlaceholder($fn)) {
            return ProfilePhotoUrlService::resolvePendingTempAbsolutePath($fn);
        }

        return ProfilePhotoUrlService::resolveStoredPublicAbsolutePath($fn);
    }
}
