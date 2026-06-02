<?php

declare(strict_types=1);

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class IntakePhotoCandidateCropService
{
    /** @var list<string> */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    public function relativePath(BiodataIntake $intake): string
    {
        return 'intake-photo-candidates/'.$intake->id.'/candidate.jpg';
    }

    public function absolutePath(BiodataIntake $intake): string
    {
        return storage_path('app/private/'.$this->relativePath($intake));
    }

    public function exists(BiodataIntake $intake): bool
    {
        $path = $this->absolutePath($intake);
        clearstatcache(true, $path);

        return is_file($path) && is_readable($path);
    }

    public function isImageIntake(BiodataIntake $intake): bool
    {
        $relativePath = trim((string) ($intake->file_path ?? ''));
        if ($relativePath === '') {
            return false;
        }

        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (! in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return false;
        }

        $path = storage_path('app/private/'.$relativePath);

        return is_file($path) && is_readable($path);
    }

    public function originalImageUrl(BiodataIntake $intake): ?string
    {
        return $this->isImageIntake($intake) ? route('intake.biodata-original', $intake) : null;
    }

    /**
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function saveFromUploadedFile(BiodataIntake $intake, UploadedFile $file): void
    {
        if (! $this->isImageIntake($intake)) {
            throw new \InvalidArgumentException('intake_source_not_image');
        }

        $realPath = $file->getRealPath();
        if ($realPath === false || ! is_readable($realPath)) {
            throw new \InvalidArgumentException('candidate_upload_not_readable');
        }

        $binary = file_get_contents($realPath);
        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException('candidate_upload_empty');
        }

        $info = @getimagesizefromstring($binary);
        if (! is_array($info)) {
            throw new \InvalidArgumentException('candidate_upload_invalid_image');
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $type = (int) ($info[2] ?? 0);
        if ($width < 80 || $height < 80 || $width > 5000 || $height > 5000) {
            throw new \InvalidArgumentException('candidate_upload_dimensions_out_of_range');
        }

        $image = match ($type) {
            IMAGETYPE_JPEG,
            IMAGETYPE_PNG,
            IMAGETYPE_WEBP,
            IMAGETYPE_GIF,
            IMAGETYPE_BMP => @imagecreatefromstring($binary),
            default => false,
        };
        if ($image === false) {
            throw new \InvalidArgumentException('candidate_upload_unsupported_image');
        }

        if (! imageistruecolor($image) && function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($image);
        }

        $this->saveAsJpeg($intake, $image);
    }

    public function delete(BiodataIntake $intake): bool
    {
        $relativePath = $this->relativePath($intake);
        if (! Storage::disk('local')->exists($relativePath)) {
            return false;
        }

        return Storage::disk('local')->delete($relativePath);
    }

    private function saveAsJpeg(BiodataIntake $intake, \GdImage $image): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 80 || $height < 80) {
            imagedestroy($image);
            throw new \InvalidArgumentException('candidate_crop_too_small');
        }

        $maxSide = 720;
        $scale = min($maxSide / max($width, $height), 1.0);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            imagedestroy($image);
            throw new \RuntimeException('candidate_canvas_create_failed');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $path = $this->absolutePath($intake);
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            imagedestroy($canvas);
            imagedestroy($image);
            throw new \RuntimeException('candidate_directory_create_failed');
        }

        if (! imagejpeg($canvas, $path, 86)) {
            imagedestroy($canvas);
            imagedestroy($image);
            throw new \RuntimeException('candidate_jpeg_write_failed');
        }

        imagedestroy($canvas);
        imagedestroy($image);
    }
}
