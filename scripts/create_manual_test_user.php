<?php

/**
 * One-off: Create a test user and profile for manual testing.
 * Run: php scripts/create_manual_test_user.php
 * Login: manualtest@example.com / password123
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\MatrimonyProfile;
use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use Illuminate\Support\Facades\Hash;

$email = 'manualtest@example.com';

$existing = User::where('email', $email)->first();
if ($existing) {
    $existing->matrimonyProfile?->forceDelete();
    $existing->delete();
    echo "Removed existing user.\n";
}

$user = User::create([
    'name' => 'Manual Test User',
    'email' => $email,
    'mobile' => null,
    'gender' => null,
    'password' => Hash::make('password123'),
]);

$genderId = MasterGender::where('key', 'male')->value('id') ?? 1;
$maritalId = MasterMaritalStatus::where('key', 'never_married')->value('id') ?? 1;

$profile = MatrimonyProfile::create([
    'user_id' => $user->id,
    'full_name' => 'Manual Test User',
    'gender_id' => $genderId,
    'date_of_birth' => '1995-01-15',
    'marital_status_id' => $maritalId,
    'lifecycle_state' => 'active',
    'profile_photo' => '1772529567_a.jpg',
    'photo_approved' => true,
    'visibility_override' => true,
    'visibility_override_reason' => 'Test user for manual testing',
]);

echo "OK\n";
echo "Email: {$email}\n";
echo "Password: password123\n";
echo "User ID: {$user->id}, Profile ID: {$profile->id}\n";
