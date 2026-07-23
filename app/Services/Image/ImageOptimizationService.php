<?php

namespace App\Services\Image;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class ImageOptimizationService
{
    public const TARGET_WIDTH = 720;

    public const TARGET_HEIGHT = 960;

    /**
     * Cover-crop to 3:4 and encode WebP (shared by member + Suchak photo paths).
     *
     * @return array{bytes:string,quality:int}
     */
    public function encodeCoverWebp(string $sourcePath): array
    {
        if (! is_file($sourcePath)) {
            throw new \RuntimeException('Source image not found for optimization.');
        }

        $manager = new ImageManager(new Driver);
        $image = $manager->read($sourcePath);

        // Resize + crop to 3:4 (cover) and re-encode strips EXIF by design.
        $image = $image->cover(self::TARGET_WIDTH, self::TARGET_HEIGHT);

        $minBytes = 120 * 1024;
        $maxBytes = 180 * 1024;
        $quality = 82;
        $best = null;
        $bestQuality = $quality;

        while ($quality >= 45) {
            $encoded = $image->toWebp(quality: $quality);
            $bytes = $encoded->toString();
            $len = strlen($bytes);

            // Prefer first within range; otherwise keep closest-under-max candidate.
            if ($len >= $minBytes && $len <= $maxBytes) {
                $best = $bytes;
                $bestQuality = $quality;
                break;
            }
            if ($len <= $maxBytes) {
                $best = $bytes;
                $bestQuality = $quality;
                // If already under max, nudging quality up won't happen in this loop.
                break;
            }

            $quality -= 7;
        }

        if ($best === null) {
            // Fallback: encode at conservative quality.
            $bestQuality = 60;
            $best = $image->toWebp(quality: $bestQuality)->toString();
        }

        return [
            'bytes' => $best,
            'quality' => $bestQuality,
        ];
    }

    /**
     * Optimize a source image and store it on the public disk.
     *
     * @return array{filename:string,relative_path:string,bytes:int,quality:int}
     */
    public function optimizeAndStoreProfilePhoto(string $sourcePath, string $outputFilenameBase): array
    {
        $encoded = $this->encodeCoverWebp($sourcePath);

        $filename = $outputFilenameBase.'.webp';
        $relative = 'matrimony_photos/'.$filename;

        Storage::disk('public')->put($relative, $encoded['bytes'], ['visibility' => 'public']);

        return [
            'filename' => $filename,
            'relative_path' => $relative,
            'bytes' => strlen($encoded['bytes']),
            'quality' => $encoded['quality'],
        ];
    }
}
