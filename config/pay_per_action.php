<?php

/**
 * Pay-per-action: visible à-la-carte charges when plan quota is exhausted.
 * Amounts in paise (₹1 = 100 paise). Admin-tunable; {@see \App\Services\UserWalletService}.
 */
return [
    'enabled' => (bool) env('PAY_PER_ACTION_ENABLED', false),

    /** Normalized feature key (matches {@see \App\Services\FeatureUsageService} keys). */
    'actions' => [
        'chat_send_limit' => [
            'enabled' => (bool) env('PPA_CHAT_SEND_ENABLED', false),
            'price_paise' => (int) env('PPA_CHAT_SEND_PRICE_PAISE', 500),
            'label' => 'Extra chat message',
        ],
    ],
];
