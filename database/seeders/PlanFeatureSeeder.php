<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Database\Seeder;

/**
 * Optional tweaks for gendered catalog tiers (slugs {@code *_male} / {@code *_female}).
 * Core limits are owned by {@see SubscriptionPlansSeeder}; this seeder only adjusts listed keys when plans exist.
 */
class PlanFeatureSeeder extends Seeder
{
    public function run(): void
    {
        $sets = [
            'silver_male' => [
                'chat_send_limit' => '100',
                'contact_view_limit' => '10',
                'chat_can_read' => '1',
            ],
            'silver_female' => [
                'chat_send_limit' => '200',
                'contact_view_limit' => '20',
                'chat_can_read' => '1',
            ],
            'gold_male' => [
                'chat_send_limit' => '-1',
                'contact_view_limit' => '-1',
                'chat_can_read' => '1',
            ],
            'gold_female' => [
                'chat_send_limit' => '-1',
                'contact_view_limit' => '-1',
                'chat_can_read' => '1',
            ],
        ];

        foreach ($sets as $slug => $features) {
            $plan = Plan::query()->where('slug', $slug)->first();
            if (! $plan) {
                continue;
            }

            foreach ($features as $key => $value) {
                PlanFeature::query()->updateOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'key' => $key,
                    ],
                    [
                        'value' => (string) $value,
                    ]
                );
            }

            $plan->forgetCachedPlanFeatures();
        }
    }
}
