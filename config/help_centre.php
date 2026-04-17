<?php

return [
    'ai' => [
        'enabled' => (bool) env('HELP_CENTRE_AI_ENABLED', false),
        'model' => env('HELP_CENTRE_AI_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'timeout' => (int) env('HELP_CENTRE_AI_TIMEOUT', 12),
    ],
    'sla' => [
        'first_response_hours' => (int) env('HELP_CENTRE_SLA_FIRST_RESPONSE_HOURS', 12),
    ],
];
