<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Photo processing debug switches
    |--------------------------------------------------------------------------
    |
    | force_direct_handle:
    | When true, profile photo processing will run immediately in the web
    | request by calling the job's handle() directly (bypasses queue).
    |
    | Use only for debugging (Step 6) and keep OFF in normal operation.
    |
    */
    'force_direct_handle' => (bool) env('PHOTO_PROCESSING_FORCE_DIRECT_HANDLE', false),

    /*
    | NudeNet REST wrappers sometimes return safe:true while also including detections[]. If any
    | detection score is >= this threshold, we treat the image as unsafe (defense in depth).
    */
    'nudenet_unsafe_score_min' => (float) env('NUDENET_UNSAFE_SCORE_MIN', 0.25),
];
