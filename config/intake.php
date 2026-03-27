<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Intake Preview Suggestion Autofill (TEMPORARY UX)
    |--------------------------------------------------------------------------
    | Confidence threshold for auto_prefill. When suggestion confidence >= this
    | value and (usage_count >= usage_count_threshold OR field is required+empty),
    | the preview input is prefilled with suggested_value (revert still available).
    | Restore to 0.90 when stable. Env: INTAKE_SUGGESTION_AUTOFILL_CONFIDENCE.
    */
    'suggestion_autofill_confidence' => (float) env('INTAKE_SUGGESTION_AUTOFILL_CONFIDENCE', 0.70),

    /*
    | Usage count threshold for auto_prefill when field is not empty.
    | For required+empty we allow autofill with conf >= suggestion_autofill_confidence
    | even if usage_count is below this. Env: INTAKE_SUGGESTION_AUTOFILL_USAGE_COUNT.
    */
    'suggestion_autofill_usage_count' => (int) env('INTAKE_SUGGESTION_AUTOFILL_USAGE_COUNT', 25),

    /*
    |--------------------------------------------------------------------------
    | AI first v2 parser (Marathi-aware, stricter schema)
    |--------------------------------------------------------------------------
    | max_chars: max raw text length sent to API. Env: INTAKE_AI_V2_MAX_CHARS.
    | model: override OpenAI model for v2 (e.g. gpt-4o). Null = use services.openai.model.
    */
    'ai_first_v2' => [
        'max_chars' => (int) env('INTAKE_AI_V2_MAX_CHARS', 12000),
        'model' => env('INTAKE_AI_V2_MODEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Vision Extract (Direct File -> AI -> parse)
    |--------------------------------------------------------------------------
    | model: override OpenAI model used for vision transcription. Null/empty = services.openai.model
    | Env: INTAKE_AI_VISION_MODEL
    */
    'ai_vision_extract' => [
        // Provider used by ai_vision_extract_v1.
        // Default must preserve existing project expectation (OpenAI-first).
        'provider' => env('INTAKE_AI_VISION_PROVIDER', 'openai'),
        // Sarvam Document Intelligence params.
        'sarvam_language' => env('INTAKE_SARVAM_DOC_LANG', 'mr-IN'),
        'sarvam_output_format' => env('INTAKE_SARVAM_DOC_FORMAT', 'md'), // md|html|json
        // Keep polling bounded; ParseIntakeJob is synchronous.
        'sarvam_poll_seconds' => (int) env('INTAKE_SARVAM_POLL_SECONDS', 25),
        // OpenAI model override for legacy/fallback only (if provider=openai).
        'model' => env('INTAKE_AI_VISION_MODEL'),
        // Generic sanity gate on extracted text before parsing.
        // Keep defaults permissive; short biodata pages can still be valid.
        'min_extracted_chars' => (int) env('INTAKE_AI_VISION_MIN_CHARS', 180),
        'min_extracted_non_space' => (int) env('INTAKE_AI_VISION_MIN_NON_SPACE', 120),
        'min_extracted_lines' => (int) env('INTAKE_AI_VISION_MIN_LINES', 2),
        // AI request payload only (OpenAI / Sarvam upload): optional upscale + orientation; never persists over original upload.
        'ai_request_enhance_enabled' => filter_var(env('INTAKE_AI_VISION_ENHANCE_IMAGE', true), FILTER_VALIDATE_BOOLEAN),
        'ai_request_max_edge' => (int) env('INTAKE_AI_VISION_REQUEST_MAX_EDGE', 2048),
        'ai_request_min_edge_to_upscale' => (int) env('INTAKE_AI_VISION_MIN_EDGE_UPSCALE', 1280),
        // OpenAI vision: auto|low|high — higher uses more tokens, often better text fidelity.
        'vision_detail' => env('INTAKE_AI_VISION_DETAIL', 'high'),
        'vision_max_tokens' => (int) env('INTAKE_AI_VISION_MAX_TOKENS', 4096),
    ],
];
