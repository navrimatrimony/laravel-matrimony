<?php

namespace App\Services\Showcase;

use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Admin-managed eng/{gender}/{religion}/{marital}/{age_bucket} showcase photo library on disk.
 */
class ShowcasePhotoPoolService
{
    public const ROOT_SEGMENT = 'eng';

    /** @var list<string> */
    public const GENDERS = ['male', 'female'];

    /** @var list<string> */
    public const AGE_BUCKETS = ['18-24', '25-30', '31-35', '36-45', '46-plus'];

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function diskRoot(): string
    {
        return public_path('uploads/matrimony_photos');
    }

    public function engRoot(): string
    {
        return $this->diskRoot().'/'.self::ROOT_SEGMENT;
    }

    /**
     * @return array{gender: string, religion_key: string, marital_key: string, age_bucket: string}|null
     */
    public function resolveCategoryFromIds(int $religionId, int $maritalStatusId, string $ageBucket, string $gender): ?array
    {
        if (! in_array($gender, self::GENDERS, true) || ! in_array($ageBucket, self::AGE_BUCKETS, true)) {
            return null;
        }

        $religionKey = $this->folderKeyFromMasterValue(Religion::query()->whereKey($religionId)->value('key'));
        $maritalKey = $this->folderKeyFromMasterValue(MasterMaritalStatus::query()->whereKey($maritalStatusId)->value('key'));
        if ($religionKey === null || $maritalKey === null) {
            return null;
        }

        return [
            'gender' => $gender,
            'religion_key' => $religionKey,
            'marital_key' => $maritalKey,
            'age_bucket' => $ageBucket,
        ];
    }

    public function relativeFolder(string $gender, string $religionKey, string $maritalKey, string $ageBucket): string
    {
        return self::ROOT_SEGMENT.'/'.$gender.'/'.$religionKey.'/'.$maritalKey.'/'.$ageBucket;
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<string> relative paths saved (from matrimony_photos root)
     */
    public function uploadToCategory(array $files, string $gender, string $religionKey, string $maritalKey, string $ageBucket): array
    {
        $relativeDir = $this->relativeFolder($gender, $religionKey, $maritalKey, $ageBucket);
        $absoluteDir = $this->diskRoot().'/'.$relativeDir;
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }

        $saved = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }
            $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }
            $filename = $this->uniqueFilename($gender, $religionKey, $maritalKey, $ageBucket, $extension);
            $file->move($absoluteDir, $filename);
            $saved[] = $relativeDir.'/'.$filename;
        }

        return $saved;
    }

    public function deleteRelativePath(string $relativePath): void
    {
        $absolute = $this->absolutePathForRelative($relativePath);
        if (! is_file($absolute)) {
            throw new \InvalidArgumentException('Photo file not found.');
        }
        if (! @unlink($absolute)) {
            throw new \RuntimeException('Could not delete photo file.');
        }
        $this->removeEmptyParents(dirname($absolute));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPhotosInFolder(string $relativeFolder): array
    {
        $relativeFolder = trim(str_replace('\\', '/', $relativeFolder), '/');
        $used = $this->usedPaths();

        $absoluteDir = $this->diskRoot().'/'.$relativeFolder;
        if (! is_dir($absoluteDir) || ! is_readable($absoluteDir)) {
            return [];
        }

        $rows = [];
        $handle = opendir($absoluteDir);
        if ($handle === false) {
            return [];
        }

        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $filePath = $absoluteDir.DIRECTORY_SEPARATOR.$entry;
            if (! is_file($filePath)) {
                continue;
            }
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }
            $relative = $relativeFolder.'/'.$entry;
            $rows[] = [
                'relative_path' => $relative,
                'filename' => $entry,
                'url' => asset('uploads/matrimony_photos/'.$relative),
                'is_used' => isset($used[$relative]),
                'size_bytes' => (int) filesize($filePath),
            ];
        }
        closedir($handle);

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['filename'], (string) $b['filename']));

        return $rows;
    }

    /**
     * Scan eng/ tree for leaf buckets (coverage matrix).
     *
     * @return list<array<string, mixed>>
     */
    public function coverageMatrix(): array
    {
        $engRoot = $this->engRoot();
        if (! is_dir($engRoot)) {
            return [];
        }

        $used = $this->usedPaths();
        $rows = [];

        $genderDirs = array_filter(scandir($engRoot) ?: [], static fn (string $d): bool => $d !== '.' && $d !== '..');
        foreach ($genderDirs as $gender) {
            if (! in_array($gender, self::GENDERS, true)) {
                continue;
            }
            $genderPath = $engRoot.DIRECTORY_SEPARATOR.$gender;
            if (! is_dir($genderPath)) {
                continue;
            }
            foreach (array_filter(scandir($genderPath) ?: [], static fn (string $d): bool => $d !== '.' && $d !== '..') as $religionKey) {
                $religionPath = $genderPath.DIRECTORY_SEPARATOR.$religionKey;
                if (! is_dir($religionPath)) {
                    continue;
                }
                foreach (array_filter(scandir($religionPath) ?: [], static fn (string $d): bool => $d !== '.' && $d !== '..') as $maritalKey) {
                    $maritalPath = $religionPath.DIRECTORY_SEPARATOR.$maritalKey;
                    if (! is_dir($maritalPath)) {
                        continue;
                    }
                    foreach (array_filter(scandir($maritalPath) ?: [], static fn (string $d): bool => $d !== '.' && $d !== '..') as $ageBucket) {
                        if (! in_array($ageBucket, self::AGE_BUCKETS, true)) {
                            continue;
                        }
                        $bucketPath = $maritalPath.DIRECTORY_SEPARATOR.$ageBucket;
                        if (! is_dir($bucketPath)) {
                            continue;
                        }
                        $relativeFolder = $this->relativeFolder($gender, $religionKey, $maritalKey, $ageBucket);
                        [$total, $unused] = $this->countPhotosInAbsoluteDir($bucketPath, $relativeFolder, $used);
                        $rows[] = [
                            'gender' => $gender,
                            'religion_key' => $religionKey,
                            'marital_key' => $maritalKey,
                            'age_bucket' => $ageBucket,
                            'folder' => $relativeFolder,
                            'total' => $total,
                            'unused' => $unused,
                            'used' => max(0, $total - $unused),
                        ];
                    }
                }
            }
        }

        usort($rows, static function (array $a, array $b): int {
            return [$a['gender'], $a['religion_key'], $a['marital_key'], $a['age_bucket']]
                <=> [$b['gender'], $b['religion_key'], $b['marital_key'], $b['age_bucket']];
        });

        return $rows;
    }

    /**
     * Quick stats for admin bulk-create and dashboards.
     *
     * @return array{bucket_count: int, total_photos: int, exhausted_buckets: int, low_unused_buckets: int}
     */
    public function poolHealthSummary(): array
    {
        $matrix = $this->coverageMatrix();
        $exhausted = 0;
        $lowUnused = 0;
        foreach ($matrix as $row) {
            $total = (int) ($row['total'] ?? 0);
            $unused = (int) ($row['unused'] ?? 0);
            if ($total > 0 && $unused === 0) {
                $exhausted++;
            }
            if ($unused < 2) {
                $lowUnused++;
            }
        }

        return [
            'bucket_count' => count($matrix),
            'total_photos' => (int) array_sum(array_column($matrix, 'total')),
            'exhausted_buckets' => $exhausted,
            'low_unused_buckets' => $lowUnused,
        ];
    }

    /**
     * @return array<string, true>
     */
    public function usedPaths(): array
    {
        $usedPhotos = MatrimonyProfile::query()
            ->whereShowcase()
            ->whereNotNull('profile_photo')
            ->pluck('profile_photo')
            ->toArray();

        $paths = [];
        foreach ($usedPhotos as $photo) {
            $path = $this->normaliseStoredShowcasePhotoPath($photo);
            if ($path !== null) {
                $paths[$path] = true;
            }
        }

        return $paths;
    }

    public function absolutePathForRelative(string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if (! str_starts_with($relativePath, self::ROOT_SEGMENT.'/')) {
            throw new \InvalidArgumentException('Invalid showcase photo path.');
        }
        if (str_contains($relativePath, '..')) {
            throw new \InvalidArgumentException('Invalid showcase photo path.');
        }

        $absolute = realpath($this->diskRoot().'/'.$relativePath);
        $engBase = realpath($this->engRoot());
        if ($absolute === false || $engBase === false || ! str_starts_with($absolute, $engBase.DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Photo path is outside the eng pool.');
        }
        if (! is_file($absolute)) {
            throw new \InvalidArgumentException('Photo file not found.');
        }

        return $absolute;
    }

    private function uniqueFilename(string $gender, string $religionKey, string $maritalKey, string $ageBucket, string $extension): string
    {
        $prefix = $gender.'-'.$religionKey.'-'.$maritalKey.'-'.$ageBucket;

        return substr($prefix, 0, 80).'-'.Str::lower(Str::random(6)).'.'.$extension;
    }

    /**
     * @param  array<string, true>  $usedPaths
     * @return array{0: int, 1: int} total, unused
     */
    private function countPhotosInAbsoluteDir(string $absoluteDir, string $relativeFolder, array $usedPaths): array
    {
        $total = 0;
        $unused = 0;
        $handle = opendir($absoluteDir);
        if ($handle === false) {
            return [0, 0];
        }
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $filePath = $absoluteDir.DIRECTORY_SEPARATOR.$entry;
            if (! is_file($filePath)) {
                continue;
            }
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }
            $total++;
            $relative = $relativeFolder.'/'.$entry;
            if (! isset($usedPaths[$relative])) {
                $unused++;
            }
        }
        closedir($handle);

        return [$total, $unused];
    }

    private function folderKeyFromMasterValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));
        $value = preg_replace('/[\s-]+/', '_', $value) ?? '';
        $value = preg_replace('/[^a-z0-9_-]/', '', $value) ?? '';
        $value = trim($value, '_-');

        return $value !== '' ? $value : null;
    }

    private function normaliseStoredShowcasePhotoPath(mixed $photo): ?string
    {
        if (! is_string($photo)) {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', trim($photo)), '/');
        $prefix = 'uploads/matrimony_photos/';
        if (str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        }

        if (! str_starts_with($path, self::ROOT_SEGMENT.'/')) {
            return null;
        }

        return str_contains($path, '..') ? null : $path;
    }

    private function removeEmptyParents(string $dir): void
    {
        $stop = $this->engRoot();
        while ($dir !== $stop && str_starts_with($dir, $stop) && is_dir($dir)) {
            $items = array_diff(scandir($dir) ?: [], ['.', '..']);
            if ($items !== []) {
                return;
            }
            @rmdir($dir);
            $dir = dirname($dir);
        }
    }
}
