<?php

namespace Database\Seeders;

use App\Models\AdminSetting;
use App\Models\SystemRule;
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
    }
}
