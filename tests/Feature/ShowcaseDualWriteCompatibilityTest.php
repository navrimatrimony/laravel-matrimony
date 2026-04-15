<?php

use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('setting is_showcase marks showcase profile', function () {
    $user = User::factory()->create();

    $profile = MatrimonyProfile::create([
        'user_id' => $user->id,
        'full_name' => 'Showcase Flag',
        'is_showcase' => true,
    ]);

    $fresh = $profile->fresh();

    expect((bool) $fresh->is_showcase)->toBeTrue()
        ->and($fresh->isShowcaseProfile())->toBeTrue();
});

test('where non showcase scope excludes showcase profiles', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $nonShowcase = MatrimonyProfile::create([
        'user_id' => $userA->id,
        'full_name' => 'Real Member',
        'is_showcase' => false,
    ]);

    $showcase = MatrimonyProfile::create([
        'user_id' => $userB->id,
        'full_name' => 'Showcase Member',
        'is_showcase' => true,
    ]);

    $ids = MatrimonyProfile::query()->whereNonShowcase()->pluck('id')->all();

    expect($ids)->toContain($nonShowcase->id)
        ->and($ids)->not->toContain($showcase->id);
});
