<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$profile = \App\Models\MatrimonyProfile::whereHas('user', fn ($q) => $q->where('email', 'manualtest@example.com'))->first();
if (!$profile) {
    echo "Manual test user profile not found.\n";
    exit(1);
}
$profile->update([
    'visibility_override' => true,
    'visibility_override_reason' => 'Test user for manual testing',
]);
echo "Profile ID {$profile->id} is now visible in search (visibility_override = true).\n";
