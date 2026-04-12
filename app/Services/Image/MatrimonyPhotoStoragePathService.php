<?php

namespace App\Services\Image;

use App\Models\MatrimonyPhotoBatchAllocation;
use App\Models\MatrimonyProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Member photo paths under storage/app/public/matrimony_photos:
 *
 *     {YY}/{MM}/{batchKey}/{profileId}/{leafFilename}
 *
 * - YY/MM from profile {@see MatrimonyProfile::$created_at} (app timezone).
 * - batchKey: 00–99 then 100, 101, … — max {@see self::BATCH_MAX_PROFILES} profiles per batch slot.
 * - All uploads for a profile share the same {@see MatrimonyProfile::$photo_storage_rel} prefix (set once).
 */
final class MatrimonyPhotoStoragePathService
{
    public const BATCH_MAX_PROFILES = 30;

    /**
     * Whether the stored value is safe to join under matrimony_photos/ (no traversal).
     */
    public static function isSafeRelativePath(string $relative): bool
    {
        $relative = str_replace('\\', '/', trim($relative));
        if ($relative === '' || str_starts_with($relative, '/')) {
            return false;
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $relative) === 1) {
            return false;
        }

        return true;
    }

    /**
     * Normalise for internal use (forward slashes, no leading slash).
     */
    public static function normaliseRelativePath(string $relative): string
    {
        return ltrim(str_replace('\\', '/', $relative), '/');
    }

    /**
     * Batch folder name from zero-based index (00–99, then 100, …).
     */
    public static function batchKeyFromIndex(int $batchIndex): string
    {
        if ($batchIndex < 0) {
            throw new \InvalidArgumentException('batch_index must be >= 0.');
        }
        if ($batchIndex < 100) {
            return str_pad((string) $batchIndex, 2, '0', STR_PAD_LEFT);
        }

        return (string) $batchIndex;
    }

    /**
     * Assign {@see MatrimonyProfile::$photo_storage_rel} once (YY/MM/batch/profile_id), using batch slots.
     */
    public static function ensurePhotoStorageRelAssigned(MatrimonyProfile $profile): void
    {
        if (! Schema::hasTable('matrimony_photo_batch_allocations')) {
            return;
        }

        $existing = trim((string) ($profile->photo_storage_rel ?? ''));
        if ($existing !== '') {
            return;
        }

        if (! $profile->id) {
            return;
        }

        $at = $profile->created_at ?: now();
        $yy = (int) $at->format('y');
        $mm = (int) $at->format('n');
        $yyStr = $at->format('y');
        $mmStr = $at->format('m');

        DB::transaction(function () use ($profile, $yy, $mm, $yyStr, $mmStr): void {
            $locked = MatrimonyProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }
            $already = trim((string) ($locked->photo_storage_rel ?? ''), '/');
            if ($already !== '') {
                $profile->photo_storage_rel = $locked->photo_storage_rel;
                $profile->photo_batch_allocation_id = $locked->photo_batch_allocation_id;

                return;
            }

            $slot = MatrimonyPhotoBatchAllocation::query()
                ->where('yy', $yy)
                ->where('mm', $mm)
                ->where('profiles_count', '<', self::BATCH_MAX_PROFILES)
                ->orderBy('batch_index')
                ->lockForUpdate()
                ->first();

            if ($slot === null) {
                $maxIdx = MatrimonyPhotoBatchAllocation::query()
                    ->where('yy', $yy)
                    ->where('mm', $mm)
                    ->max('batch_index');
                $nextIdx = $maxIdx === null ? 0 : ((int) $maxIdx) + 1;
                $slot = MatrimonyPhotoBatchAllocation::query()->create([
                    'yy' => $yy,
                    'mm' => $mm,
                    'batch_index' => $nextIdx,
                    'profiles_count' => 1,
                ]);
            } else {
                $slot->increment('profiles_count');
            }

            $batchKey = self::batchKeyFromIndex((int) $slot->batch_index);
            $rel = $yyStr.'/'.$mmStr.'/'.$batchKey.'/'.$profile->id;

            MatrimonyProfile::query()->whereKey($profile->id)->update([
                'photo_storage_rel' => $rel,
                'photo_batch_allocation_id' => $slot->id,
            ]);

            $profile->photo_storage_rel = $rel;
            $profile->photo_batch_allocation_id = $slot->id;
        });
    }

    /**
     * Relative path under matrimony_photos/ for a new file in this profile's gallery directory.
     *
     * @param  non-empty-string  $leafFilename  Basename only (e.g. uuid.webp)
     */
    public static function relativePathForNewFile(MatrimonyProfile $profile, string $leafFilename): string
    {
        $leafFilename = basename($leafFilename);
        if ($leafFilename === '' || $leafFilename === '.' || $leafFilename === '..') {
            throw new \InvalidArgumentException('Invalid leaf filename for photo storage.');
        }

        self::ensurePhotoStorageRelAssigned($profile);
        $base = trim((string) ($profile->photo_storage_rel ?? ''), '/');
        if ($base === '') {
            throw new \RuntimeException('photo_storage_rel could not be assigned for profile '.$profile->id);
        }

        return $base.'/'.$leafFilename;
    }

    /**
     * Ensure parent directories exist for a path relative to matrimony_photos/.
     */
    public static function ensureDirectoryForRelativePath(string $relativeUnderMatrimonyPhotos): void
    {
        $relativeUnderMatrimonyPhotos = self::normaliseRelativePath($relativeUnderMatrimonyPhotos);
        if (! self::isSafeRelativePath($relativeUnderMatrimonyPhotos)) {
            throw new \InvalidArgumentException('Unsafe relative path for matrimony_photos.');
        }
        $dir = pathinfo(storage_path('app/public/matrimony_photos/'.$relativeUnderMatrimonyPhotos), PATHINFO_DIRNAME);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * @deprecated Use {@see relativePathForNewFile()} — kept for transitional grep; forwards to new layout.
     */
    public static function nestedRelativePathForNewFile(int $profileId, string $leafFilename): string
    {
        $profile = MatrimonyProfile::query()->find($profileId);
        if (! $profile) {
            throw new \InvalidArgumentException('Profile not found: '.$profileId);
        }

        return self::relativePathForNewFile($profile, $leafFilename);
    }
}
