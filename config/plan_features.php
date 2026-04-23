<?php

/**
 * Admin-facing plan feature definitions (SSOT for keys + validation types).
 * Values are stored in {@see \App\Models\PlanFeature}; runtime gates use {@see \App\Services\FeatureUsageService}.
 */
return [
    'chat_send_limit' => [
        'type' => 'limit',
        'label' => 'Initiate new chats (quota)',
    ],
    'chat_initiate_new_chats_only' => [
        'type' => 'boolean',
        'label' => 'Count quota only on first message per conversation',
    ],
    'advanced_profile_search' => [
        'type' => 'boolean',
        'label' => 'Advanced profile search (education, income, caste filters)',
    ],
    'profile_whatsapp_direct' => [
        'type' => 'boolean',
        'label' => 'Direct WhatsApp link on profiles',
    ],
    'chat_image_messages' => [
        'type' => 'boolean',
        'label' => 'Chat Image Messages',
    ],
    'daily_profile_view_limit' => [
        'type' => 'limit',
        'label' => 'Daily Profile View Limit',
    ],
    'contact_view_limit' => [
        'type' => 'limit',
        'label' => 'Contact View Limit',
    ],
    'interest_send_limit' => [
        'type' => 'limit',
        'label' => 'Interest Send Limit',
    ],
    'interest_view_limit' => [
        'type' => 'limit',
        'label' => 'Incoming Interest View Limit',
    ],
    'interest_view_reset_period' => [
        'type' => 'string',
        'label' => 'Incoming Interest View Reset Period',
    ],
    'who_viewed_me_access' => [
        'type' => 'boolean',
        'label' => 'Who Viewed Me Access',
    ],
    'who_viewed_me_preview_limit' => [
        'type' => 'limit',
        'label' => 'Who viewed — preview (0 = hidden; >0 = free preview cap; paid ignores cap)',
    ],
    'photo_blur_limit' => [
        'type' => 'limit',
        'label' => 'Photo Blur Limit',
    ],
    'photo_full_access' => [
        'type' => 'boolean',
        'label' => 'Photo Full Access',
    ],
    'profile_boost_per_week' => [
        'type' => 'limit',
        'label' => 'Profile Boost / Week',
    ],
    'priority_listing' => [
        'type' => 'boolean',
        'label' => 'Priority Listing',
    ],
    'mediator_requests_per_month' => [
        'type' => 'limit',
        'label' => 'Mediator Requests / Month',
    ],
    'referral_bonus_days' => [
        'type' => 'days',
        'label' => 'Referral Bonus Days',
    ],
    'chat_can_read' => [
        'type' => 'boolean',
        'label' => 'Chat Read Access',
    ],
];
