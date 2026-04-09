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
     * Optimize a source image and store it on the public disk.
     *
     * @return array{filename:string,relative_path:string,bytes:int,quality:int}
     */
    public function optimizeAndStoreProfilePhoto(string $sourcePath, string $outputFilenameBase): array
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

        $filename = $outputFilenameBase.'.webp';
        $relative = 'matrimony_photos/'.$filename;

        Storage::disk('public')->put($relative, $best, ['visibility' => 'public']);

        return [
            'filename' => $filename,
            'relative_path' => $relative,
            'bytes' => strlen($best),
            'quality' => $bestQuality,
        ];
    }
}
