<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$mobile = '1111111112';
$password = '11111111';

$user = User::query()->where('mobile', $mobile)->first();
if ($user === null) {
    echo "User with mobile {$mobile} not found.\n";
    exit(1);
}

$user->password = Hash::make($password);
$user->save();

echo "Password reset for user id={$user->id} mobile={$user->mobile}\n";
echo "Login: mobile {$mobile} / password {$password}\n";
