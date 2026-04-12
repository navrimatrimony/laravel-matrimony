<?php

/**
 * Ordered catalog for plan feature rows on the public pricing page.
 * Keys match {@see \App\Services\SubscriptionService} feature constants where applicable.
 */
return [
    'rows' => [
        ['key' => 'chat_send_limit', 'type' => 'limit', 'label_key' => 'subscriptions.pricing_feature_chat_send'],
        ['key' => 'interest_send_limit', 'type' => 'limit', 'label_key' => 'subscriptions.pricing_feature_interest_send'],
        ['key' => 'contact_view_limit', 'type' => 'limit', 'label_key' => 'subscriptions.feature_contact'],
        ['key' => 'daily_profile_view_limit', 'type' => 'limit', 'label_key' => 'subscriptions.feature_daily_profile_views'],
        ['key' => 'chat_image_messages', 'type' => 'truthy', 'label_key' => 'subscriptions.feature_chat_images'],
        ['key' => '_ai_match_boost', 'type' => 'ai_boost', 'label_key' => 'subscriptions.row_ai_boost'],
    ],

    /** How many rows show before “See all features”. */
    'primary_visible' => 3,
];
