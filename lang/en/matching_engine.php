<?php

return [
    'nav_group' => 'Matching engine',
    'nav_overview' => 'Overview',
    'nav_fields' => 'Fields & scoring',
    'nav_filters' => 'Hard filters',
    'nav_behavior' => 'Behavior',
    'nav_boosts' => 'Boost rules',
    'nav_ai' => 'AI suggestions',
    'nav_preview' => 'Live preview',
    'nav_audit' => 'Audit log',

    'overview_title' => 'Central matching engine',
    'overview_intro' => 'Configure weights, gates, behavior signals, and boost caps. Changes are versioned; legacy config/matching.php applies only when DB tables are absent.',

    'saved' => 'Matching engine settings saved.',
    'rolled_back' => 'Configuration restored from the selected snapshot.',
    'sum_weights_error' => 'Sum of active field weights must be between 1 and 100 (current: :sum).',

    'behavior_positive' => 'Recent engagement boost (+:n)',
    'behavior_negative' => 'Recent pass/skip signal (−:n)',
    'boost_layer' => 'Ranking boost layer (+:n)',
    'penalty_religion_preferred' => 'Outside your preferred religions (soft penalty)',
    'penalty_marital_preferred' => 'Outside your preferred marital statuses (soft penalty)',
    'penalty_caste_preferred' => 'Outside your preferred castes (soft penalty)',

    'read_only' => 'You have read-only access. Only super admins, legacy admins, or data admins can edit.',
    'strict_warning' => 'Strict mode removes candidates at query time. Pool size may drop sharply.',

    'ai_title' => 'Advisory suggestions',
    'ai_intro' => 'Heuristic checks only — nothing is applied automatically. Review and change settings manually.',
    'ai_run' => 'Refresh suggestions',

    'preview_title' => 'Live preview',
    'preview_intro' => 'Pick a matrimony profile id and inspect ranked matches with structured explanations.',
    'preview_run' => 'Run preview',
    'preview_profile_id' => 'Matrimony profile ID',

    'audit_title' => 'Audit log',
    'audit_intro' => 'Snapshots taken before each save. Roll back restores the full engine state from that snapshot.',
    'rollback' => 'Restore this version',
    'rollback_confirm' => 'Restore this snapshot? Current settings will be replaced.',

    'field_weight_total' => 'Active weight sum',
    'fields_heading' => 'Scoring fields',
    'filters_heading' => 'Seeker-side hard filters',
    'behavior_heading' => 'Behavior weights',
    'boost_heading' => 'Boost rules',
    'runtime_heading' => 'Runtime',
    'candidate_pool' => 'Candidate pool limit (blank = use config/matching.php)',
    'persist_cache' => 'Persist ranked matches to profile_matches',
    'behavior_cap' => 'Max absolute behavior adjustment (0–50)',
    'use_config_placeholder' => 'Use default',
];
