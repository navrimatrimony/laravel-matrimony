<?php

namespace App\Services;

use App\Models\BiodataIntake;
use thiagoalessio\TesseractOCR\TesseractOCR;

class OcrService
{
    /**
     * Extract text from a stored file path (e.g. after Request::file()->store('intakes')).
     * Call this BEFORE creating BiodataIntake. Throws on failure.
     *
     * @throws \RuntimeException When file is missing, unreadable, or extraction fails.
     */
    public function extractTextFromPath(string $storagePath, ?string $originalFilename = null): string
    {
        if ($storagePath === '') {
            throw new \RuntimeException('OCR extraction failed: no file path.');
        }

        $fullPath = storage_path('app/private/' . $storagePath);

        if (! is_file($fullPath) || ! is_readable($fullPath)) {
            throw new \RuntimeException('OCR extraction failed: file not found or not readable.');
        }

        $ext = strtolower(pathinfo($originalFilename ?? $storagePath, PATHINFO_EXTENSION));
        $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
        $isPdf = $ext === 'pdf';

        if ($isImage || $isPdf) {
            if ($isPdf) {
                return '';
            }
            return $this->runTesseract($fullPath);
        }

        $contents = @file_get_contents($fullPath);
        if ($contents === false) {
            throw new \RuntimeException('OCR extraction failed: could not read file.');
        }

        return $contents;
    }

    /**
     * Extract text from intake: file (image/PDF) or existing raw_ocr_text.
     * Does NOT modify the intake or database. Used only when intake already exists (e.g. legacy).
     */
    public function extractText(BiodataIntake $intake): string
    {
        if ($intake->file_path === null || $intake->file_path === '') {
            return (string) ($intake->raw_ocr_text ?? '');
        }

        $fullPath = storage_path('app/private/' . $intake->file_path);

        if (! is_file($fullPath) || ! is_readable($fullPath)) {
            throw new \RuntimeException('OCR extraction failed: file not found or not readable.');
        }

        $ext = strtolower(pathinfo($intake->original_filename ?? $intake->file_path, PATHINFO_EXTENSION));
        $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
        $isPdf = $ext === 'pdf';

        if ($isImage || $isPdf) {
            if ($isPdf) {
                return '';
            }
            return $this->runTesseract($fullPath);
        }

        $contents = @file_get_contents($fullPath);
        if ($contents === false) {
            throw new \RuntimeException('OCR extraction failed: could not read file.');
        }

        return $contents;
    }

    /**
     * Run Tesseract OCR on an image file. Returns trimmed text or empty string on failure.
     */
    private function runTesseract(string $fullPath): string
    {
        try {
            $ocr = new TesseractOCR($fullPath);
            $ocr->executable(config('services.tesseract.path'));
            $ocr->lang('mar+eng');
            $text = $ocr->run();
            return trim($text);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
