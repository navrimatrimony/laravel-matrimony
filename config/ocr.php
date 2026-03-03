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
];
