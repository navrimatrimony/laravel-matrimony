<?php

use App\Models\AdminSetting;
use App\Models\MatrimonyProfile;
use App\Models\SystemRule;
use App\Services\ProfileCompletenessService;
use App\Services\RuleEngineService;

test('resolveInterestMinimumPercent prefers system_rules over admin_settings', function () {
    AdminSetting::setValue(ProfileCompletenessService::ADMIN_KEY_INTEREST_MIN_CORE_PCT, '40');
    SystemRule::query()->create([
        'key' => RuleEngineService::KEY_PROFILE_COMPLETION_MIN,
        'value' => '72',
        'meta' => null,
    ]);

    expect(app(RuleEngineService::class)->resolveInterestMinimumPercent())->toBe(72);
});

test('resolveInterestMinimumPercent falls back to admin when system rule missing', function () {
    AdminSetting::setValue(ProfileCompletenessService::ADMIN_KEY_INTEREST_MIN_CORE_PCT, '55');

    expect(app(RuleEngineService::class)->resolveInterestMinimumPercent())->toBe(55);
});

test('interest mandatory core gate fails with friendly rule result when below threshold', function () {
    SystemRule::query()->create([
        'key' => RuleEngineService::KEY_PROFILE_COMPLETION_MIN,
        'value' => '100',
        'meta' => null,
    ]);

    $profile = MatrimonyProfile::factory()->create();
    $result = app(RuleEngineService::class)->checkInterestMandatoryCoreForSender($profile);

    expect($result->allowed)->toBeFalse()
        ->and($result->code)->toBe('PROFILE_INCOMPLETE')
        ->and($result->message)->not->toBe('')
        ->and($result->action)->toBeArray()
        ->and($result->action['type'] ?? null)->toBe('redirect');
});

test('interest mandatory core gate allows when threshold is zero', function () {
    SystemRule::query()->create([
        'key' => RuleEngineService::KEY_PROFILE_COMPLETION_MIN,
        'value' => '0',
        'meta' => null,
    ]);

    $profile = MatrimonyProfile::factory()->create();
    $result = app(RuleEngineService::class)->checkInterestMandatoryCoreForSender($profile);

    expect($result->allowed)->toBeTrue();
});
