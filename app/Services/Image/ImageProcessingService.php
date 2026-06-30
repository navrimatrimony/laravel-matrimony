<?php

namespace App\Services\Image;

use App\Jobs\ProcessProfilePhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImageProcessingService
{
    /**
     * Save the uploaded file to a temp path and dispatch background processing.
     *
     * Returns a "pending" filename that can be stored in DB immediately.
     */
    public function enqueueProfilePhotoProcessing(
        UploadedFile $file,
        int $profileId,
        string $uploadedVia = 'user_web',
        string $primaryMode = ProcessProfilePhoto::PRIMARY_MODE_REPLACE_PRIMARY_UPDATE_EXISTING,
    ): string
    {
        Log::info('INSIDE IMAGE PROCESSING SERVICE');

        $this->validateUploadedImage($file);

        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? $ext : 'jpg';
        $tmpName = (string) Str::uuid().'.'.$ext;
        $tmpPath = $file->move($tmpDir, $tmpName)->getPathname();

        Log::info('DISPATCHING PHOTO JOB', [
            'user_id' => auth()->id(),
            'profile_id' => $profileId,
            'path' => $tmpPath,
        ]);

        $this->dispatchProcessingJob($tmpPath, $profileId, $uploadedVia, $primaryMode);

        // Not publicly served; used only as a placeholder so the UI remains consistent until processing finishes.
        return 'pending/'.$tmpName;
    }

    /**
     * Copy an existing local image file to the same temp area used by uploads and dispatch the normal processing job.
     */
    public function enqueueExistingProfilePhotoPath(
        string $sourcePath,
        int $profileId,
        string $uploadedVia,
        string $primaryMode,
    ): ?string {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            return null;
        }

        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'jpg');
        $extension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? $extension : 'jpg';
        $tmpName = (string) Str::uuid().'.'.$extension;
        $tmpPath = $tmpDir.DIRECTORY_SEPARATOR.$tmpName;

        if (! copy($sourcePath, $tmpPath)) {
            throw new \RuntimeException('Unable to copy existing profile photo candidate to temp processing path.');
        }

        $this->dispatchProcessingJob($tmpPath, $profileId, $uploadedVia, $primaryMode);

        return 'pending/'.$tmpName;
    }

    private function dispatchProcessingJob(string $tmpPath, int $profileId, string $uploadedVia, string $primaryMode): void
    {
        if (config('photo_processing.force_direct_handle', false)) {
            Log::warning('PHOTO JOB: forcing direct handle() (bypassing queue)', [
                'profile_id' => $profileId,
            ]);
            app(ProcessProfilePhoto::class, [
                'tempImagePath' => $tmpPath,
                'profileId' => $profileId,
                'uploadedVia' => $uploadedVia,
                'primaryMode' => $primaryMode,
            ])->handle(
                app(\App\Services\Image\ImageModerationService::class),
                app(\App\Services\Image\ImageOptimizationService::class),
            );
        } else {
            ProcessProfilePhoto::dispatch($tmpPath, $profileId, $uploadedVia, $primaryMode);
        }
    }

    private function validateUploadedImage(UploadedFile $file): void
    {
        // Controllers already validate. This is defense-in-depth for queued processing.
        if (! $file->isValid()) {
            throw new \RuntimeException('Invalid upload (file upload error).');
        }
        $mime = (string) ($file->getMimeType() ?? '');
        if ($mime === '' || ! str_starts_with($mime, 'image/')) {
            throw new \RuntimeException('Invalid upload (not an image).');
        }
    }
}
