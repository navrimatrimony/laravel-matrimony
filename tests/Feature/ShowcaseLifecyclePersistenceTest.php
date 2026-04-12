<?php

use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('matrimony profile mass assignment persists lifecycle_state active', function () {
    $user = User::factory()->create();
    $profile = MatrimonyProfile::create([
        'user_id' => $user->id,
        'full_name' => 'Lifecycle active',
        'lifecycle_state' => 'active',
    ]);

    expect($profile->fresh()->lifecycle_state)->toBe('active');
});

test('matrimony profile mass assignment persists lifecycle_state draft', function () {
    $user = User::factory()->create();
    $profile = MatrimonyProfile::create([
        'user_id' => $user->id,
        'full_name' => 'Lifecycle draft',
        'lifecycle_state' => 'draft',
    ]);

    expect($profile->fresh()->lifecycle_state)->toBe('draft');
});
