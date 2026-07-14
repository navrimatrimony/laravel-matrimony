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
    | Free Tesseract multi-pass OCR
    |--------------------------------------------------------------------------
    |
    | Local Tesseract only. No paid provider calls are made here.
    | Attempts are bounded so upload/bulk workers cannot run unbounded OCR loops.
    |
    */
    'tesseract_multipass' => [
        'enabled' => env('OCR_TESSERACT_MULTIPASS_ENABLED', true),
        'psm_modes' => [6, 4, 11],
        'preprocessing_presets' => ['resolved', 'photo_capture', 'high_contrast'],
        'english_fallback_enabled' => env('OCR_TESSERACT_ENG_FALLBACK_ENABLED', true),
        'max_attempts' => (int) env('OCR_TESSERACT_MULTIPASS_MAX_ATTEMPTS', 24),
        'max_runtime_seconds' => (int) env('OCR_TESSERACT_MULTIPASS_MAX_RUNTIME_SECONDS', 60),
        'attempt_timeout_seconds' => (int) env('OCR_TESSERACT_ATTEMPT_TIMEOUT_SECONDS', 20),
        'preprocess_timeout_seconds' => (int) env('OCR_TESSERACT_PREPROCESS_TIMEOUT_SECONDS', 15),
        'imagemagick_cli_enabled' => env('OCR_IMAGEMAGICK_CLI_ENABLED', true),
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

    /*
    |--------------------------------------------------------------------------
    | OCR Ensemble pipeline (Phase 1+) — bulk intake file path when flag on
    |--------------------------------------------------------------------------
    */
    'ensemble' => [
        'phase1' => [
            'pipeline_version' => 'phase1_v1',
            'preprocessing_version' => 'opencv_minimal_v1',
            'preprocessing_preset' => env('OCR_ENSEMBLE_PHASE1_PRESET', 'photo_capture'),
        ],
        'phase2' => [
            'benchmark' => [
                'timeout_seconds' => (int) env('OCR_ENSEMBLE_BENCHMARK_TIMEOUT_SECONDS', 180),
                'sidecar_url' => env('OCR_ENSEMBLE_PADDLE_SIDECAR_URL', ''),
                'cli_runner' => env('OCR_ENSEMBLE_PADDLE_CLI_RUNNER', ''),
                'python_binary' => env('OCR_ENSEMBLE_PADDLE_PYTHON', 'python3'),
                'paddle' => [
                    'sidecar_url' => env('OCR_ENSEMBLE_PADDLE_SIDECAR_URL', ''),
                    'cli_runner' => env('OCR_ENSEMBLE_PADDLE_CLI_RUNNER', ''),
                    'python_binary' => env('OCR_ENSEMBLE_PADDLE_PYTHON', 'python3'),
                ],
                'easyocr' => [
                    'sidecar_url' => env('OCR_ENSEMBLE_EASYOCR_SIDECAR_URL', ''),
                    'cli_runner' => env('OCR_ENSEMBLE_EASYOCR_CLI_RUNNER', ''),
                    'python_binary' => env('OCR_ENSEMBLE_EASYOCR_PYTHON', 'python3'),
                ],
            ],
        ],
        'phase3' => [
            'enabled' => filter_var(env('OCR_ENSEMBLE_PHASE3_ENABLED', true), FILTER_VALIDATE_BOOL),
            'pipeline_version' => 'phase3_v1',
            'schema_version' => 'phase3_v1',
            'assembly_version' => 'parse_input_v1',
        ],
        'phase4' => [
            'enabled' => filter_var(env('OCR_ENSEMBLE_PHASE4_ENABLED', true), FILTER_VALIDATE_BOOL),
            'pipeline_version' => 'phase4_v1',
            'schema_version' => 'phase4_judge_v1',
            'client' => [
                'endpoint' => env(
                    'OCR_ENSEMBLE_PHASE4_SARVAM_ENDPOINT',
                    env('SARVAM_CHAT_COMPLETIONS_URL', 'https://api.sarvam.ai/v1/chat/completions')
                ),
                'api_key' => env('OCR_ENSEMBLE_PHASE4_SARVAM_API_KEY', env('SARVAM_API_SUBSCRIPTION_KEY')),
                // Optional Phase-4-only override. When empty, Judge resolves via services.sarvam.chat_model (SARVAM_CHAT_MODEL).
                'model' => env('OCR_ENSEMBLE_PHASE4_SARVAM_MODEL'),
                'timeout_seconds' => (int) env('OCR_ENSEMBLE_PHASE4_TIMEOUT_SECONDS', 30),
                'connect_timeout_seconds' => (int) env('OCR_ENSEMBLE_PHASE4_CONNECT_TIMEOUT_SECONDS', 10),
                'max_attempts' => (int) env('OCR_ENSEMBLE_PHASE4_MAX_ATTEMPTS', 3),
                'retry_base_ms' => (int) env('OCR_ENSEMBLE_PHASE4_RETRY_BASE_MS', 200),
                'retry_max_ms' => (int) env('OCR_ENSEMBLE_PHASE4_RETRY_MAX_MS', 2000),
            ],
        ],
        'phase5' => [
            'enabled' => filter_var(env('OCR_ENSEMBLE_PHASE5_ENABLED', true), FILTER_VALIDATE_BOOL),
            'pipeline_version' => 'phase5_v1',
            'schema_version' => 'phase5_comparison_v1',
            'surface' => 'correct_candidate',
        ],
    ],
];
