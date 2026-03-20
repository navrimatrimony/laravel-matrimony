<?php

namespace App\Services;

use App\Models\BiodataIntake;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Stores optional user-prepared crops for intake OCR. Never touches the original upload (file_path).
 * Path convention: ocr-manual-prepared/{intake_id}/manual.png — no DB column required.
 */
class IntakeManualOcrPreparedService
{
    public function relativePath(BiodataIntake $intake): string
    {
        return 'ocr-manual-prepared/'.$intake->id.'/manual.png';
    }

    public function absolutePath(BiodataIntake $intake): string
    {
        return storage_path('app/private/'.$this->relativePath($intake));
    }

    public function exists(BiodataIntake $intake): bool
    {
        $abs = $this->absolutePath($intake);
        clearstatcache(true, $abs);

        return is_file($abs) && is_readable($abs);
    }

    /**
     * Recursive mkdir for manual.png parent; avoids relying on Flysystem createDirectory quirks on some hosts.
     */
    private function ensureParentDirectoryForFile(string $absoluteFilePath): void
    {
        $dir = dirname($absoluteFilePath);
        if (is_dir($dir)) {
            return;
        }
        if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException('manual_prepared_directory_create_failed');
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function saveFromUploadedFile(BiodataIntake $intake, UploadedFile $file): void
    {
        $realPath = $file->getRealPath();
        if ($realPath === false || ! is_readable($realPath)) {
            throw new \InvalidArgumentException('upload_not_readable');
        }

        $binary = file_get_contents($realPath);
        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException('upload_empty');
        }

        $info = @getimagesizefromstring($binary);
        if (! is_array($info)) {
            throw new \InvalidArgumentException('invalid_image');
        }

        $w = (int) ($info[0] ?? 0);
        $h = (int) ($info[1] ?? 0);
        $type = (int) ($info[2] ?? 0);
        $maxDim = (int) config('ocr.intake_manual_crop.max_dimension', 10000);
        if ($w < 32 || $h < 32 || $w > $maxDim || $h > $maxDim) {
            throw new \InvalidArgumentException('image_dimensions_out_of_range');
        }

        $img = match ($type) {
            IMAGETYPE_PNG => @imagecreatefromstring($binary),
            IMAGETYPE_JPEG => @imagecreatefromstring($binary),
            IMAGETYPE_WEBP => @imagecreatefromstring($binary),
            IMAGETYPE_GIF => @imagecreatefromstring($binary),
            IMAGETYPE_BMP => @imagecreatefromstring($binary),
            default => false,
        };

        if ($img === false) {
            throw new \InvalidArgumentException('unsupported_image_type');
        }

        if (! imageistruecolor($img) && function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($img);
        }

        imagealphablending($img, false);
        imagesavealpha($img, true);

        $full = $this->absolutePath($intake);
        $this->ensureParentDirectoryForFile($full);

        if (! imagepng($img, $full, 6)) {
            imagedestroy($img);
            throw new \RuntimeException('png_write_failed');
        }

        imagedestroy($img);
    }

    public function delete(BiodataIntake $intake): bool
    {
        $rel = $this->relativePath($intake);
        if (! Storage::disk('local')->exists($rel)) {
            return false;
        }

        return Storage::disk('local')->delete($rel);
    }

    /**
     * Save a derived OCR-prepared PNG from 4-corner perspective points.
     *
     * Points must be in the coordinate system of the (optionally rotated) image.
     * Rotation semantics: clockwise degrees (0,90,180,270) relative to the original upload.
     *
     * @param  array<string, mixed>  $points
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function saveFromPerspectivePoints(BiodataIntake $intake, array $points, int $rotationDegCW): void
    {
        if ($intake->file_path === null || $intake->file_path === '') {
            throw new \InvalidArgumentException('intake_missing_source');
        }

        $srcFull = storage_path('app/private/'.$intake->file_path);
        if (! is_file($srcFull) || ! is_readable($srcFull)) {
            throw new \InvalidArgumentException('intake_source_missing_or_unreadable');
        }

        $normalizedPoints = $this->normalizeCornerPoints($points);
        $rotationDegCW = $rotationDegCW % 360;
        if ($rotationDegCW < 0) {
            $rotationDegCW += 360;
        }

        // Prefer Imagick for perspective warp; on failure fall back to GD axis-aligned crop.
        if (class_exists(\Imagick::class)) {
            try {
                $this->saveFromPerspectivePointsWithImagick($intake, $srcFull, $normalizedPoints, $rotationDegCW);

                return;
            } catch (\Throwable $e) {
                Log::warning('Intake manual crop Imagick failed, using GD fallback', [
                    'intake_id' => $intake->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->saveFromPerspectivePointsWithGd($intake, $srcFull, $normalizedPoints, $rotationDegCW);
    }

    /**
     * @param  array<string, mixed>  $points
     * @return array{tl: array{x: float, y: float}, tr: array{x: float, y: float}, br: array{x: float, y: float}, bl: array{x: float, y: float}}
     */
    private function normalizeCornerPoints(array $points): array
    {
        $keys = ['tl', 'tr', 'br', 'bl'];
        $out = [];
        foreach ($keys as $k) {
            $p = $points[$k] ?? null;
            if (! is_array($p)) {
                throw new \InvalidArgumentException('points_missing_'.$k);
            }
            if (! array_key_exists('x', $p) || ! array_key_exists('y', $p) || ! is_numeric($p['x']) || ! is_numeric($p['y'])) {
                throw new \InvalidArgumentException('points_invalid_'.$k);
            }
            $out[$k] = [
                'x' => (float) $p['x'],
                'y' => (float) $p['y'],
            ];
        }

        return $out;
    }

    /**
     * @param  array{tl: array{x: float, y: float}, tr: array{x: float, y: float}, br: array{x: float, y: float}, bl: array{x: float, y: float}}  $points
     */
    private function saveFromPerspectivePointsWithImagick(BiodataIntake $intake, string $srcFull, array $points, int $rotationDegCW): void
    {
        $img = new \Imagick;
        try {
            $img->readImage($srcFull);
            $img->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $img->setBackgroundColor('white');

            try {
                $img->autoOrient();
            } catch (\Throwable) {
                // ignore
            }

            if ($rotationDegCW !== 0) {
                $img->rotateImage(new \ImagickPixel('white'), (float) $rotationDegCW);
            }

            $w = $img->getImageWidth();
            $h = $img->getImageHeight();
            if ($w <= 0 || $h <= 0) {
                throw new \RuntimeException('imagick_source_has_invalid_dimensions');
            }

            $maxDim = (int) config('ocr.intake_manual_crop.max_dimension', 10000);
            if ($w > $maxDim || $h > $maxDim) {
                throw new \RuntimeException('manual_prepared_dimensions_out_of_range');
            }

            // Perspective distort is expensive on full camera megapixels; downscale before warp, scale corner coords.
            $distortMaxSide = max(800, (int) config('ocr.intake_manual_crop.distort_max_side', 2400));
            $maxSide = max($w, $h);
            if ($maxSide > $distortMaxSide && $maxSide > 0) {
                $scale = $distortMaxSide / $maxSide;
                $nw = max(1, (int) round($w * $scale));
                $nh = max(1, (int) round($h * $scale));
                $img->resizeImage($nw, $nh, \Imagick::FILTER_LANCZOS, 1);
                foreach (['tl', 'tr', 'br', 'bl'] as $k) {
                    $points[$k]['x'] *= $scale;
                    $points[$k]['y'] *= $scale;
                }
                $w = $img->getImageWidth();
                $h = $img->getImageHeight();
            }

            // Clamp points into image bounds.
            foreach (['tl', 'tr', 'br', 'bl'] as $k) {
                $points[$k]['x'] = max(0.0, min((float) ($w - 1), (float) $points[$k]['x']));
                $points[$k]['y'] = max(0.0, min((float) ($h - 1), (float) $points[$k]['y']));
            }

            $tl = $points['tl'];
            $tr = $points['tr'];
            $br = $points['br'];
            $bl = $points['bl'];

            $widthA = hypot($tr['x'] - $tl['x'], $tr['y'] - $tl['y']);
            $widthB = hypot($br['x'] - $bl['x'], $br['y'] - $bl['y']);
            $heightA = hypot($bl['x'] - $tl['x'], $bl['y'] - $tl['y']);
            $heightB = hypot($br['x'] - $tr['x'], $br['y'] - $tr['y']);

            $destW = (int) round(max(1.0, max($widthA, $widthB)));
            $destH = (int) round(max(1.0, max($heightA, $heightB)));

            // Apply perspective warp (4-point).
            // Control points for Imagick: source_x, source_y, dest_x, dest_y.
            $controlPoints = [
                $tl['x'], $tl['y'], 0, 0,
                $tr['x'], $tr['y'], (float) $destW, 0,
                $br['x'], $br['y'], (float) $destW, (float) $destH,
                $bl['x'], $bl['y'], 0, (float) $destH,
            ];

            $img->setImageVirtualPixelMethod(\Imagick::VIRTUALPIXELMETHOD_BACKGROUND);
            $img->distortImage(\Imagick::DISTORTION_PERSPECTIVE, $controlPoints, true);

            $full = $this->absolutePath($intake);
            $this->ensureParentDirectoryForFile($full);

            $img->setImageFormat('png');
            $img->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);

            if (! $img->writeImage($full)) {
                throw new \RuntimeException('manual_imagick_write_failed');
            }
        } finally {
            $img->clear();
            $img->destroy();
        }
    }

    /**
     * @param  array{tl: array{x: float, y: float}, tr: array{x: float, y: float}, br: array{x: float, y: float}, bl: array{x: float, y: float}}  $points
     */
    private function saveFromPerspectivePointsWithGd(BiodataIntake $intake, string $srcFull, array $points, int $rotationDegCW): void
    {
        $binary = @file_get_contents($srcFull);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('manual_gd_source_read_failed');
        }

        $img = @imagecreatefromstring($binary);
        if ($img === false) {
            throw new \RuntimeException('manual_gd_decode_failed');
        }

        $w = imagesx($img);
        $h = imagesy($img);

        // GD imagerotate rotates anticlockwise for positive degrees, so invert to match CW semantics.
        if ($rotationDegCW !== 0) {
            $bg = imagecolorallocate($img, 255, 255, 255);
            $img = imagerotate($img, -$rotationDegCW, $bg, true);
            if ($img === false) {
                throw new \RuntimeException('manual_gd_rotate_failed');
            }
            $w = imagesx($img);
            $h = imagesy($img);
        }

        foreach (['tl', 'tr', 'br', 'bl'] as $k) {
            $points[$k]['x'] = max(0.0, min((float) ($w - 1), (float) $points[$k]['x']));
            $points[$k]['y'] = max(0.0, min((float) ($h - 1), (float) $points[$k]['y']));
        }

        $minX = min($points['tl']['x'], $points['tr']['x'], $points['br']['x'], $points['bl']['x']);
        $minY = min($points['tl']['y'], $points['tr']['y'], $points['br']['y'], $points['bl']['y']);
        $maxX = max($points['tl']['x'], $points['tr']['x'], $points['br']['x'], $points['bl']['x']);
        $maxY = max($points['tl']['y'], $points['tr']['y'], $points['br']['y'], $points['bl']['y']);

        $pad = (int) round(max(6, min(($maxX - $minX), ($maxY - $minY)) * 0.02));
        $x = (int) max(0, floor($minX - $pad));
        $y = (int) max(0, floor($minY - $pad));
        $cw = (int) min($w - $x, ceil($maxX - $minX + 2 * $pad));
        $ch = (int) min($h - $y, ceil($maxY - $minY + 2 * $pad));

        if ($cw <= 0 || $ch <= 0) {
            imagedestroy($img);
            throw new \RuntimeException('manual_gd_crop_invalid_dimensions');
        }

        $cropped = imagecrop($img, ['x' => $x, 'y' => $y, 'width' => $cw, 'height' => $ch]);
        if ($cropped === false) {
            imagedestroy($img);
            throw new \RuntimeException('manual_gd_crop_failed');
        }

        $full = $this->absolutePath($intake);
        $this->ensureParentDirectoryForFile($full);

        if (! imagepng($cropped, $full, 6)) {
            imagedestroy($cropped);
            imagedestroy($img);
            throw new \RuntimeException('manual_gd_png_write_failed');
        }

        imagedestroy($cropped);
        imagedestroy($img);
    }
}
