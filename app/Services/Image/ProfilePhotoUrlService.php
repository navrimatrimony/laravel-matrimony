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
     * Relative paths of every photo a viewer may actually be shown for this profile, in album order.
     *
     * THE single source for "which photos does this profile have?". The list card, the profile hero,
     * the photo album and the discovery rank all read this, so a profile can never resolve to a photo
     * on one screen and to nothing on another. A path whose bytes are missing is dropped here — that
     * is deliberate: a filename in the database is not a photo, and handing one out is how a card with
     * no photo and a detail screen with a broken image end up disagreeing about the same profile.
     *
     * Order: effectively-approved `profile_photos` rows (primary first), then the legacy
     * `matrimony_profiles.profile_photo` column.
     *
     * @return list<string>
     */
    public static function visiblePhotoRelativePaths(MatrimonyProfile $profile): array
    {
        $paths = [];

        foreach (self::approvedGalleryPhotoRows($profile) as $photo) {
            $path = self::normalizeMatrimonyPhotoPath((string) $photo->file_path);
            if ($path === null || self::isPendingPlaceholder($path)) {
                continue;
            }
            if (! self::storedFileExistsForRelativePath($path)) {
                continue;
            }
            $paths[] = $path;
        }

        $legacy = self::usableLegacyPhotoRelativePath($profile);
        if ($legacy !== null) {
            $paths[] = $legacy;
        }

        return array_values(array_unique($paths));
    }

    /**
     * Public URLs for {@see visiblePhotoRelativePaths()} — every URL returned points at bytes that exist.
     *
     * @return list<string>
     */
    public static function visiblePhotoUrls(MatrimonyProfile $profile): array
    {
        $service = app(self::class);

        $urls = array_map(
            static fn (string $path): string => $service->publicUrl($path, $profile),
            self::visiblePhotoRelativePaths($profile),
        );

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * Whether this profile has at least one renderable photo. Cheaper than {@see visiblePhotoUrls()}
     * (no URL building) and answers by exactly the same rule, so "has a photo" can never disagree
     * with "here is the photo".
     */
    public static function hasVisiblePhoto(MatrimonyProfile $profile): bool
    {
        return self::visiblePhotoRelativePaths($profile) !== [];
    }

    /**
     * The legacy `profile_photo` value only when it is still usable: approved, not a pending
     * placeholder awaiting the queue job, and backed by bytes on disk.
     *
     * API payloads echo this raw column and both apps build a URL from it client-side, so a name
     * whose file is gone must not be handed out.
     */
    public static function usableLegacyPhotoRelativePath(MatrimonyProfile $profile): ?string
    {
        $legacy = self::normalizeMatrimonyPhotoPath((string) ($profile->profile_photo ?? ''));

        if (
            $legacy === null
            || $profile->photo_approved === false
            || self::isPendingPlaceholder($legacy)
            || ! self::storedFileExistsForRelativePath($legacy)
        ) {
            return null;
        }

        return $legacy;
    }

    /**
     * The raw `profile_photo` value as an API payload should echo it.
     *
     * Both apps turn this bare filename into a URL client-side, so it follows exactly the same
     * "bytes exist" rule as {@see visiblePhotoUrls()} — otherwise a screen that reads this field
     * shows a broken image for a profile whose card correctly shows none. A `pending/…` placeholder
     * still passes while its temp bytes are on disk: that is an upload being processed, which the
     * apps render as a processing state rather than as a photo.
     */
    public static function apiLegacyPhotoValue(MatrimonyProfile $profile): ?string
    {
        $raw = trim((string) ($profile->profile_photo ?? ''));

        if ($raw !== ''
            && $profile->photo_approved !== false
            && self::isPendingPlaceholder($raw)
            && self::storedFileExistsForRelativePath($raw)
        ) {
            return $raw;
        }

        return self::usableLegacyPhotoRelativePath($profile) !== null ? $raw : null;
    }

    /**
     * Effectively-approved gallery rows, in album order. Prefers an already eager-loaded `photos`
     * relation (which may be unconstrained, hence the PHP-side status filter) and otherwise queries,
     * so callers get the same set whether or not they eager-loaded.
     *
     * @return iterable<int, ProfilePhoto>
     */
    private static function approvedGalleryPhotoRows(MatrimonyProfile $profile): iterable
    {
        if ($profile->relationLoaded('photos')) {
            return $profile->photos
                ->filter(static fn (ProfilePhoto $photo): bool => $photo->effectiveApprovedStatus() === 'approved')
                ->values();
        }

        if (! Schema::hasTable('profile_photos')) {
            return [];
        }

        $query = ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->effectivelyApproved()
            ->orderByDesc('is_primary');

        if (Schema::hasColumn('profile_photos', 'sort_order')) {
            $query->orderBy('sort_order');
        }

        return $query->orderBy('id')->get(['id', 'profile_id', 'file_path', 'is_primary']);
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
