<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OCR Suggestion Debug Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, logs detailed debug information for OCR suggestion engine.
    | Requires APP_DEBUG=true to be effective.
    |
    */
    'suggestion_debug' => env('OCR_SUGGESTION_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Nightly AI Generalization (Day-29)
    |--------------------------------------------------------------------------
    | usage_count >= threshold to be considered for AI generalization.
    | Batch and token budget caps to avoid oversized prompts.
    |
    */
    'ai_generalize_threshold' => (int) env('OCR_AI_GENERALIZE_THRESHOLD', 10),
    'ai_generalize_enabled' => env('OCR_AI_GENERALIZE_ENABLED', false),
    'ai_generalize_max_batch_size' => (int) env('OCR_AI_GENERALIZE_MAX_BATCH_SIZE', 5),
    'ai_generalize_max_chars_per_batch' => (int) env('OCR_AI_GENERALIZE_MAX_CHARS_PER_BATCH', 4000),

    /*
    |--------------------------------------------------------------------------
    | Image preprocessing (before Tesseract) — SSOT
    |--------------------------------------------------------------------------
    |
    | Derived temp files only; originals are never overwritten.
    | PDFs are not rasterized here; only raster image uploads use this path.
    |
    | UI "auto" = no request override: resolvePreset() uses extension_presets +
    | default_preset (and optional env OCR_PREPROCESSING_PRESET_OVERRIDE for admins).
    |
    */
    'preprocessing' => [
        'enabled' => env('OCR_PREPROCESSING_ENABLED', true),
        'temp_disk' => env('OCR_PREPROCESSING_TEMP_DISK', 'local'),
        'temp_dir' => env('OCR_PREPROCESSING_TEMP_DIR', 'ocr-preprocessed'),
        'cleanup_enabled' => env('OCR_PREPROCESSING_CLEANUP', true),
        /*
        | Local/dev only: keep derived PNG on disk after OCR when APP_DEBUG=true.
        | Production: always delete derived files after OCR unless cleanup is disabled.
        */
        'debug_keep_derived_when_app_debug' => env('OCR_PREPROCESS_DEBUG_KEEP', true),
        'debug_expose_derived_notice' => env('OCR_PREPROCESS_DEBUG_NOTICE', true),
        'default_preset' => env('OCR_PREPROCESSING_DEFAULT_PRESET', 'marathi_printed'),
        'preset_override' => env('OCR_PREPROCESSING_PRESET_OVERRIDE') ?: null,
        'max_upscale_width' => (int) env('OCR_PREPROCESSING_MAX_WIDTH', 2200),
        /*
        | GD photo_capture only: when true, apply legacy binarizing threshold after mild cleanup.
        | Default false — preserves gray-level strokes for Marathi OCR.
        */
        'photo_capture_gd_threshold_fallback' => filter_var(
            env('OCR_PHOTO_CAPTURE_GD_THRESHOLD_FALLBACK', false),
            FILTER_VALIDATE_BOOL
        ),
        'jpeg_quality' => (int) env('OCR_PREPROCESSING_JPEG_QUALITY', 92),
        /*
        | Default preset per extension (pdf reserved for future raster pipeline).
        | Key pdf_image_default documents the default for PDF-as-image when added.
        */
        'extension_presets' => [
            'pdf_image_default' => env('OCR_PREPROCESS_EXTENSION_PDF', 'marathi_printed'),
            'pdf' => env('OCR_PREPROCESS_EXTENSION_PDF', 'marathi_printed'),
            'jpg' => env('OCR_PREPROCESS_EXTENSION_JPG', 'marathi_printed'),
            'jpeg' => env('OCR_PREPROCESS_EXTENSION_JPEG', 'marathi_printed'),
            'png' => env('OCR_PREPROCESS_EXTENSION_PNG', 'marathi_printed'),
            'webp' => env('OCR_PREPROCESS_EXTENSION_WEBP', 'marathi_printed'),
            'bmp' => env('OCR_PREPROCESS_EXTENSION_BMP', 'marathi_printed'),
            'gif' => env('OCR_PREPROCESS_EXTENSION_GIF', 'marathi_printed'),
        ],
        'presets' => [
            'clean_document' => [
                'grayscale' => true,
                'denoise' => 'light',
                'contrast_boost' => 'light',
                'adaptive_threshold' => false,
                'binarize' => false,
                'deskew' => true,
                'crop_margins' => true,
                'orientation_detect' => true,
                'normalize_resolution' => true,
            ],
            'marathi_printed' => [
                'grayscale' => true,
                'denoise' => 'medium',
                'contrast_boost' => 'medium',
                'adaptive_threshold' => true,
                'gd_threshold_contrast' => -25,
                'adaptive_divisor' => 48,
                'adaptive_offset' => 8,
                'binarize' => true,
                'deskew' => true,
                'crop_margins' => true,
                'orientation_detect' => true,
                'normalize_resolution' => true,
            ],
            'noisy_scan' => [
                'grayscale' => true,
                'denoise' => 'strong',
                'contrast_boost' => 'strong',
                'adaptive_threshold' => true,
                'gd_threshold_contrast' => -40,
                'adaptive_divisor' => 22,
                'adaptive_offset' => 14,
                'binarize' => true,
                'deskew' => true,
                'crop_margins' => true,
                'orientation_detect' => true,
                'normalize_resolution' => true,
            ],
            /*
            | Mobile biodata photos: Imagick path uses autoOrient → deskew → trim → upscale →
            | grayscale + mild denoise/contrast + unsharp. No default binarization (GD fallback unchanged).
            */
            'photo_capture' => [
                'grayscale' => true,
                'denoise' => 'medium',
                'contrast_boost' => 'light',
                'adaptive_threshold' => false,
                'binarize' => false,
                'photo_unsharp' => true,
                'deskew' => true,
                'crop_margins' => true,
                'orientation_detect' => true,
                'normalize_resolution' => true,
            ],
            /*
            | Parse-time auto-retry: stronger contrast / binarization than marathi_printed.
            */
            'high_contrast' => [
                'grayscale' => true,
                'denoise' => 'medium',
                'contrast_boost' => 'strong',
                'adaptive_threshold' => true,
                'gd_threshold_contrast' => -38,
                'adaptive_divisor' => 28,
                'adaptive_offset' => 12,
                'binarize' => true,
                'deskew' => true,
                'crop_margins' => true,
                'orientation_detect' => true,
                'normalize_resolution' => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Parse-time OCR quality auto-retry (manual prepared image only)
    |--------------------------------------------------------------------------
    |
    | When manual.png exists, ParseIntakeJob may re-run OCR with alternate presets
    | if quality score is below threshold. raw_ocr_text is never modified.
    |
    */
    'auto_retry' => [
        'enabled' => true,
        'quality_threshold' => 0.6,
        'max_attempts' => 2,
        'retry_presets' => [
            'marathi_printed',
            'high_contrast',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Intake manual crop / rotate (preview) — derived PNG only
    |--------------------------------------------------------------------------
    |
    | Saved under storage/app/private/ocr-manual-prepared/{id}/manual.png.
    | ParseIntakeJob OCRs this file when present (raw_ocr_text stays immutable).
    | Preset 'off' = run Tesseract on the user crop as-is; set env to marathi_printed etc. if needed.
    |
    */
    'intake_manual_crop' => [
        'ocr_preprocessing_preset' => env('OCR_INTAKE_MANUAL_PRESET', 'off'),
        'max_dimension' => (int) env('OCR_INTAKE_MANUAL_MAX_DIM', 10000),
        // Imagick perspective warp: max long side before distort (speed / memory).
        'distort_max_side' => (int) env('OCR_INTAKE_MANUAL_DISTORT_MAX_SIDE', 2400),
    ],
];
