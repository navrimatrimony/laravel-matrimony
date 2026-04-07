<?php

return [

    'candidate_pool_limit' => (int) env('MATCHING_CANDIDATE_POOL', 200),

    'persist_cache' => (bool) env('MATCHING_PERSIST_CACHE', false),

    /**
     * When true, candidates must list the seeker’s religion in profile_preferred_religions (if any are set).
     */
    'strict_religion_filter' => (bool) env('MATCHING_STRICT_RELIGION', false),

    /**
     * When true, candidates must have marital_status_id in the seeker’s profile_preferred_marital_statuses
     * (or legacy profile_preference_criteria.preferred_marital_status_id when the pivot is empty).
     */
    'strict_marital_filter' => (bool) env('MATCHING_STRICT_MARITAL', false),
];
