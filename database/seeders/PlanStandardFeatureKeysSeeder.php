<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanTerm;
use App\Services\SubscriptionService;
use App\Support\PlanFeatureKeys;
use Illuminate\Database\Seeder;

/**
 * Idempotent: merges standardized plan_feature rows via updateOrCreate.
 * Merges standardized plan_feature rows via updateOrCreate (canonical keys in {@see PlanFeatureKeys}).
 */
class PlanStandardFeatureKeysSeeder extends Seeder
{
    public function run(): void
    {
        $zeroBase = array_fill_keys(PlanFeatureKeys::all(), '0');

        $free = array_merge($zeroBase, [
            PlanFeatureKeys::CHAT_SEND_LIMIT => '5',
            PlanFeatureKeys::CHAT_CAN_READ => '0',
            PlanFeatureKeys::INTEREST_SEND_LIMIT => '5',
            PlanFeatureKeys::INTEREST_VIEW_LIMIT => '3',
            PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD => 'monthly',
            PlanFeatureKeys::WHO_VIEWED_ME_DAYS => '0',
            PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => '2',
            SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => '50',
        ]);

        $silver = array_merge($free, [
            PlanFeatureKeys::CHAT_CAN_READ => '1',
            PlanFeatureKeys::WHO_VIEWED_ME_DAYS => '1',
            PlanFeatureKeys::CONTACT_VIEW_LIMIT => '10',
            PlanFeatureKeys::INTEREST_VIEW_LIMIT => '15',
        ]);

        $gold = array_merge($silver, [
            PlanFeatureKeys::WHO_VIEWED_ME_DAYS => '7',
            PlanFeatureKeys::PROFILE_BOOST_PER_WEEK => '1',
            PlanFeatureKeys::CONTACT_VIEW_LIMIT => '30',
            PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => '5',
            PlanFeatureKeys::INTEREST_VIEW_LIMIT => '40',
        ]);

        $platinum = array_merge($gold, [
            PlanFeatureKeys::WHO_VIEWED_ME_DAYS => '999',
            PlanFeatureKeys::PROFILE_BOOST_PER_WEEK => '7',
            PlanFeatureKeys::PRIORITY_LISTING => '1',
            PlanFeatureKeys::CONTACT_VIEW_LIMIT => '-1',
            PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => '15',
            PlanFeatureKeys::INTEREST_VIEW_LIMIT => '-1',
        ]);

        $bySlug = [
            'free' => $free,
            'silver' => $silver,
            'gold' => $gold,
            'platinum' => $platinum,
        ];

        $this->ensurePlatinumPlan();

        foreach ($bySlug as $slug => $features) {
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
        }
    }

    private function ensurePlatinumPlan(): void
    {
        if (Plan::query()->where('slug', 'platinum')->exists()) {
            return;
        }

        $plan = Plan::query()->updateOrCreate(
            ['slug' => 'platinum'],
            [
                'name' => 'Platinum',
                'price' => 9999,
                'discount_percent' => null,
                'duration_days' => 30,
                'sort_order' => 50,
                'highlight' => false,
                'is_active' => true,
            ]
        );

        PlanTerm::syncDefaultsForPlan($plan->fresh());
    }
}
