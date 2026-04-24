<?php

namespace Database\Seeders;

use App\Models\AdminSetting;
use App\Models\SystemRule;
use App\Services\MatchingEngine;
use App\Services\ProfileCompletenessService;
use App\Services\RuleEngineService;
use Illuminate\Database\Seeder;

class SystemRulesSeeder extends Seeder
{
    public function run(): void
    {
        $fromAdmin = max(0, min(100, (int) AdminSetting::getValue(
            ProfileCompletenessService::ADMIN_KEY_INTEREST_MIN_CORE_PCT,
            '0'
        )));

        if (! SystemRule::query()->where('key', RuleEngineService::KEY_PROFILE_COMPLETION_MIN)->exists()) {
            SystemRule::query()->create([
                'key' => RuleEngineService::KEY_PROFILE_COMPLETION_MIN,
                'value' => (string) $fromAdmin,
                'meta' => [
                    'action_url' => '/matrimony/profile/edit',
                ],
            ]);
        }

        SystemRule::query()->firstOrCreate(
            ['key' => RuleEngineService::KEY_SHOWCASE_AUTOFILL_LOG_MIN_CORE],
            [
                'value' => '80',
                'meta' => null,
            ]
        );

        SystemRule::query()->firstOrCreate(
            ['key' => MatchingEngine::RULE_MATCHING_MINIMUM_SCORE],
            [
                'value' => '60',
                'meta' => null,
            ]
        );

        $matchingDefaults = [
            MatchingEngine::RULE_MATCHING_AGE => ['value' => '20', 'meta' => ['max_age_diff_years' => 5]],
            MatchingEngine::RULE_MATCHING_LOCATION => ['value' => '20', 'meta' => []],
            MatchingEngine::RULE_MATCHING_EDUCATION => ['value' => '10', 'meta' => []],
            MatchingEngine::RULE_MATCHING_CASTE => ['value' => '30', 'meta' => []],
            MatchingEngine::RULE_MATCHING_PROFILE_COMPLETION => ['value' => '20', 'meta' => ['min_mandatory_pct' => 80]],
        ];

        foreach ($matchingDefaults as $key => $payload) {
            SystemRule::query()->firstOrCreate(
                ['key' => $key],
                [
                    'value' => $payload['value'],
                    'meta' => $payload['meta'],
                ]
            );
        }

        MatchingEngine::forgetRulesCache();
    }
}
