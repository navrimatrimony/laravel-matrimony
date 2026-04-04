<?php

return [

    'candidate_pool_limit' => (int) env('MATCHING_CANDIDATE_POOL', 200),

    'persist_cache' => (bool) env('MATCHING_PERSIST_CACHE', false),

    /**
     * When true, candidates must list the seeker’s religion in profile_preferred_religions (if any are set).
     */
    'strict_religion_filter' => (bool) env('MATCHING_STRICT_RELIGION', false),

    /**
     * When true, candidates must match seeker preferred_marital_status_id when it is set.
     */
    'strict_marital_filter' => (bool) env('MATCHING_STRICT_MARITAL', false),
];
