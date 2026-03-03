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
];
