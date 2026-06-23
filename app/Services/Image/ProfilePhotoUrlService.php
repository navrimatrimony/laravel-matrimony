<?php

namespace App\Services\Image;

use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ProfilePhotoUrlService
{
    public static function normalizeMatrimonyPhotoPath(?string $path): ?string
    {
        $path = str_replace('\\', '/', ltrim((string) $path, '/'));
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }
        if (preg_match('/^https?:\/\//i', $path) === 1) {
            return $path;
        }

        foreach ([
            'app/public/',
            'public/',
            'storage/matrimony_photos/',
            'uploads/matrimony_photos/',
            'matrimony_photos/',
        ] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }

        return $path !== '' && ! str_contains($path, '..') ? $path : null;
    }

    /**
     * Primary upload stores `pending/uuid.jpg` in DB before the queue job writes the real file — no public file yet.
     */
    public static function isPendingPlaceholder(?string $path): bool
    {
        $path = ltrim((string) $path, '/');

        return $path !== '' && str_starts_with($path, 'pending/');
    }

    /**
     * While DB holds `pending/{uuid}.ext`, bytes live under storage/app/tmp until ProcessProfilePhoto finishes.
     *
     * @return non-empty-string|null Absolute path if the temp file exists
     */
    public static function resolvePendingTempAbsolutePath(string $path): ?string
    {
        if (! self::isPendingPlaceholder($path)) {
            return null;
        }
        $base = basename(ltrim($path, '/'));
        if ($base === '' || str_contains($base, '..')) {
            return null;
        }
        $abs = storage_path('app/tmp/'.$base);

        return is_file($abs) ? $abs : null;
    }

    /**
     * Resolved path under storage/public/matrimony_photos or legacy public uploads.
     *
     * @return non-empty-string|null
     */
    public static function resolveStoredPublicAbsolutePath(string $filename): ?string
    {
        $filename = self::normalizeMatrimonyPhotoPath($filename);
        if ($filename === null || preg_match('/^https?:\/\//i', $filename) === 1) {
            return null;
        }
        $publicAbs = storage_path('app/public/matrimony_photos/'.$filename);
        if (is_file($publicAbs)) {
            return $publicAbs;
        }
        $legacyAbs = public_path('uploads/matrimony_photos/'.$filename);
        if (is_file($legacyAbs)) {
            return $legacyAbs;
        }

        return null;
    }

    /**
     * When profile_photo is still pending/… but tmp is gone, the job may have written matrimony_photos/{final}
     * while the profile row was not updated — use the primary gallery row filename if the file exists on disk.
     */
    public static function resolvePendingFallbackFromPrimaryGallery(MatrimonyProfile $profile): ?string
    {
        if (! Schema::hasTable('profile_photos')) {
            return null;
        }
        $row = ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->where('is_primary', true)
            ->orderByDesc('id')
            ->first(['file_path']);
        if ($row === null) {
            return null;
        }
        $fn = ltrim((string) $row->file_path, '/');
        if ($fn === '' || self::isPendingPlaceholder($fn)) {
            return null;
        }

        return self::resolveStoredPublicAbsolutePath($fn);
    }

    /**
     * Primary gallery row path when it is no longer a `pending/…` placeholder (for legacy core column sync).
     */
    public static function primaryNonPendingGalleryRelativePath(MatrimonyProfile $profile): ?string
    {
        if (! Schema::hasTable('profile_photos')) {
            return null;
        }
        $row = ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->where('is_primary', true)
            ->orderByDesc('id')
            ->first(['file_path']);
        if ($row === null) {
            return null;
        }
        $fn = ltrim((string) $row->file_path, '/');
        if ($fn === '' || str_contains($fn, '..') || self::isPendingPlaceholder($fn)) {
            return null;
        }

        return $fn;
    }

    /**
     * Whether bytes exist for a DB `profile_photo` / gallery relative path (tmp for pending placeholders, disk for final paths).
     */
    public static function storedFileExistsForRelativePath(string $relativePath): bool
    {
        $relativePath = self::normalizeMatrimonyPhotoPath($relativePath);
        if ($relativePath === null || preg_match('/^https?:\/\//i', $relativePath) === 1) {
            return false;
        }
        if (self::isPendingPlaceholder($relativePath)) {
            return self::resolvePendingTempAbsolutePath($relativePath) !== null;
        }

        return self::resolveStoredPublicAbsolutePath($relativePath) !== null;
    }

    /**
     * Backward compatible resolver:
     * - new: storage/app/public/matrimony_photos (served via /storage)
     * - old: public/uploads/matrimony_photos (served via /uploads)
     */
    public function publicUrl(string $filename, ?MatrimonyProfile $profile = null): string
    {
        $filename = self::normalizeMatrimonyPhotoPath($filename) ?? '';
        if (preg_match('/^https?:\/\//i', $filename) === 1) {
            return $filename;
        }

        if ($profile !== null && self::isPendingPlaceholder($filename)) {
            $fallback = self::primaryNonPendingGalleryRelativePath($profile);
            if ($fallback !== null && self::storedFileExistsForRelativePath($fallback)) {
                return $this->publicUrl($fallback);
            }
        }

        try {
            if (Storage::disk('public')->exists('matrimony_photos/'.$filename)) {
                return asset('storage/matrimony_photos/'.$filename);
            }
        } catch (\Throwable) {
            // Some legacy filenames (unicode/whitespace) can trigger Flysystem path validation exceptions.
            // In that case, prefer the legacy public path which doesn't require disk path normalization.
        }

        return asset('uploads/matrimony_photos/'.$filename);
    }
}
