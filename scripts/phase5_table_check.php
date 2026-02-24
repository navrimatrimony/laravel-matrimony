<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$tables = [
    'biodata_intakes', 'profile_change_history', 'conflict_records', 'profile_contacts',
    'profile_children', 'profile_education', 'profile_career', 'profile_addresses',
    'profile_property_summary', 'profile_property_assets', 'profile_visibility_settings',
    'profile_horoscope_data', 'profile_preferences', 'profile_extended_attributes',
    'profile_legal_cases', 'contact_unlock_policy', 'contact_access_log',
    'unlock_rules_engine', 'user_engagement_stats', 'subscription_plan',
    'user_subscription', 'mutation_log',
];
foreach ($tables as $t) {
    echo $t . ' => ' . (\Illuminate\Support\Facades\Schema::hasTable($t) ? 'OK' : 'MISSING') . PHP_EOL;
}
