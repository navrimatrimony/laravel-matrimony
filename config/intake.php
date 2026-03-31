<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Defaults for new intake_processing_mode / intake_primary_ai_provider (UI + ProviderResolver)
    |--------------------------------------------------------------------------
    | Used when AdminSetting keys are absent. Env: INTAKE_DEFAULT_PROCESSING_MODE, INTAKE_DEFAULT_PRIMARY_AI_PROVIDER.
    */
    'defaults' => [
        'primary_ai_provider' => env('INTAKE_DEFAULT_PRIMARY_AI_PROVIDER', 'openai'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sarvam chat completions (structured SSOT v2 parse — OpenAI-compatible endpoint)
    |--------------------------------------------------------------------------
    | Env: SARVAM_CHAT_COMPLETIONS_URL, INTAKE_SARVAM_STRUCTURED_MODEL
    */
    'sarvam_structured' => [
        'chat_completions_url' => env('SARVAM_CHAT_COMPLETIONS_URL', 'https://api.sarvam.ai/v1/chat/completions'),
        // Allowed Sarvam structured model(s): locked to Sarvam M only.
        // Do NOT allow env overrides here: cross-provider models like "bulbul-v3" are invalid for this flow.
        'model' => 'sarvam-m',
    ],

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
        // Provider used by ai_vision_extract_v1 when AdminSetting `intake_ai_vision_provider` is empty.
        // Admin can override per deployment via Admin → Intake engine settings (AI Vision).
        // Default must preserve existing project expectation (OpenAI-first).
        'provider' => env('INTAKE_AI_VISION_PROVIDER', 'openai'),
        // Sarvam Document Intelligence params. Requires PHP ext-zip (ZipArchive): image→ZIP upload and ZIP output download.
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

    /*
    |--------------------------------------------------------------------------
    | Paid vision extraction reuse (cache + historical peer raw_ocr_text read)
    |--------------------------------------------------------------------------
    | Fingerprint cache: best text per provider+identity for duplicate uploads.
    | Historical peers: other intakes’ immutable raw_ocr_text (never rewritten).
    | Use Redis/database cache in production for durability across restarts.
    */
    'paid_extraction_reuse' => [
        'parse_input_cache_ttl_days_paid' => (int) env('INTAKE_PARSE_INPUT_CACHE_DAYS_PAID', 365),
        'parse_input_cache_ttl_days_default' => (int) env('INTAKE_PARSE_INPUT_CACHE_DAYS_DEFAULT', 7),
        'fingerprint_best_ttl_days' => (int) env('INTAKE_PAID_FINGERPRINT_CACHE_DAYS', 365),
        'historical_peer_query_limit' => (int) env('INTAKE_PAID_HISTORICAL_PEER_LIMIT', 40),
    ],

    /*
    |--------------------------------------------------------------------------
    | Test-only: force ParseIntakeJob paid-vision path (mirrors testing_active_parser)
    |--------------------------------------------------------------------------
    */
    'testing_parse_job_uses_ai_vision' => env('INTAKE_TESTING_PARSE_JOB_USES_AI_VISION'),

    /*
    |--------------------------------------------------------------------------
    | DOB trace (opt-in): comma-separated biodata_intakes.id values
    |--------------------------------------------------------------------------
    | Logs DOB_TRACE_* lines through AiFirst parse, ParseIntakeJob save, preview read.
    | Env: DOB_TRACE_INTAKE_IDS=4,12
    */
    'dob_trace_intake_ids' => array_values(array_filter(array_map(
        static fn (string $s): int => (int) trim($s),
        explode(',', (string) env('DOB_TRACE_INTAKE_IDS', ''))
    ), static fn (int $id): bool => $id > 0)),

    /*
    |--------------------------------------------------------------------------
    | DOB parse debug (opt-in): rules-parser before/after normalize + recovery
    |--------------------------------------------------------------------------
    | Env: INTAKE_DOB_PARSE_DEBUG=true
    */
    'dob_parse_debug' => filter_var(env('INTAKE_DOB_PARSE_DEBUG', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Hard DOB extraction trace (AiFirst rule): raw_line, normalized_line, extracted_date
    | Env: INTAKE_DOB_EXTRACTION_TRACE=true
    |--------------------------------------------------------------------------
    */
    'dob_extraction_trace' => filter_var(env('INTAKE_DOB_EXTRACTION_TRACE', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Developer-only: show immutable upload OCR (raw_ocr_text) on review UIs
    |--------------------------------------------------------------------------
    | Requires APP_DEBUG=true. Normal review shows only parse input (same as parser).
    | Env: INTAKE_DEBUG_SHOW_STORED_RAW_OCR=true
    */
    'debug_show_stored_raw_ocr' => filter_var(env('INTAKE_DEBUG_SHOW_STORED_RAW_OCR', false), FILTER_VALIDATE_BOOLEAN),
];
