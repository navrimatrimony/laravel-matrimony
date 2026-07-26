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

    /**
     * Tiered relaxation ladder (PO decision 2026-07-26).
     *
     * The engine runs the tiers in order and stops at the FIRST tier whose surviving candidate count
     * reaches `floor`. Nothing in `never_relaxed` is ever loosened at any tier — opposite gender, the
     * legal minimum marriage age ({@see \App\Support\MarriageAgePolicy}), lifecycle exclusions and
     * per-pair skip exclusions hold at every tier.
     */
    'relaxation' => [
        'floor' => (int) env('MATCHING_RELAXATION_FLOOR', 12),

        /**
         * Ordered ladder. Each tier lists the preference row ids whose `not_matched` verdict stops
         * being an exclusion at that tier (it becomes a scored penalty plus a visible warning).
         * Tiers are cumulative: tier N relaxes its own fields plus every earlier tier's.
         */
        'tiers' => [
            0 => [],
            1 => ['income', 'height'],
            2 => ['location'],
            3 => ['caste'],
        ],

        /** Points removed from the aggregate preference score per tolerated soft mismatch. */
        'soft_penalty_points' => (int) env('MATCHING_SOFT_PENALTY_POINTS', 6),

        /** Radius used when tier 2 widens a district/taluka preference to nearby geography. */
        'nearby_radius_km' => (int) env('MATCHING_NEARBY_RADIUS_KM', 75),

        /** Max nearby talukas pulled per preferred taluka when widening. */
        'nearby_limit' => (int) env('MATCHING_NEARBY_LIMIT', 12),
    ],

    /**
     * Per-seeker community locking (PO ruling 2026-07-26): an explicit refusal of intercaste marriage
     * locks the seeker's feed to their own community. "Never asked" must never be read as a refusal —
     * `profile_partner_community_flags.interested_in_intercaste` is `boolean default(false)`, so an
     * absent row is silence, not a no.
     */
    'community_lock' => [
        'enabled' => (bool) env('MATCHING_COMMUNITY_LOCK', true),

        /**
         * Only these signals may lock a seeker. Every one of them requires a row the seeker's own
         * answer produced; none of them fire on a defaulted/absent row.
         */
        'signals' => [
            /** profile_partner_community_flags row EXISTS and interested_in_intercaste = false. */
            'explicit_intercaste_refusal' => true,

            /** partner_preference_metadata.strictness_json marks caste/religion as required. */
            'strictness_metadata' => true,

            /**
             * profile_preferred_castes contains ONLY the seeker's own caste. Registration auto-seeds
             * this pivot at strictness `preferred`, so it is only honoured when the metadata does NOT
             * say `preferred`/`open` — otherwise the whole auto-seeded base would be caste-locked.
             */
            'own_caste_only_pivot' => true,
        ],
    ],

    /**
     * Auto-derived partner preferences (PO-approved 2026-07-26). Applied ONLY when the seeker left the
     * field empty; an explicitly stated value always wins and is never overwritten. Derived values are
     * SOFT — they can raise or lower a score and are flagged `derived` for the app, but they can never
     * exclude a candidate.
     */
    'derived_preferences' => [
        'enabled' => (bool) env('MATCHING_DERIVED_PREFERENCES', true),

        /** Years, relative to the seeker's own age, for the partner they are assumed to want. */
        'age' => [
            'male' => ['younger' => 5, 'older' => 1],
            'female' => ['younger' => 1, 'older' => 5],
        ],

        /** Centimetres, relative to the seeker's own height. */
        'height' => [
            'male' => ['shorter' => 15, 'taller' => 0],
            'female' => ['shorter' => 0, 'taller' => 15],
        ],

        /** Education steps (master_education.sort_order) either side of the seeker's own degree. */
        'education' => ['steps' => 1],

        /** Marital status assumption: like-for-like with the seeker's own status. */
        'marital_status' => 'like_for_like',

        /** District assumption: seeker's own district first, then geographically nearby. */
        'district' => 'same_then_nearby',
    ],
];
