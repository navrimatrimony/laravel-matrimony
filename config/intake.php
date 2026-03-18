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
];
