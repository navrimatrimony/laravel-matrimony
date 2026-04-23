<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Support\PlanFeatureKeys;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanStandardFeatureKeysSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent_and_sets_free_tier_values(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $free = Plan::query()->where('slug', 'free_male')->firstOrFail();

        $this->assertSame('5', PlanFeature::query()->where('plan_id', $free->id)->where('key', PlanFeatureKeys::CHAT_SEND_LIMIT)->value('value'));
        $this->assertSame('0', PlanFeature::query()->where('plan_id', $free->id)->where('key', PlanFeatureKeys::CHAT_CAN_READ)->value('value'));
        $this->assertSame('3', PlanFeature::query()->where('plan_id', $free->id)->where('key', PlanFeatureKeys::INTEREST_SEND_LIMIT)->value('value'));
        $this->assertSame('2', PlanFeature::query()->where('plan_id', $free->id)->where('key', PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH)->value('value'));
        $this->assertSame('5', PlanFeature::query()->where('plan_id', $free->id)->where('key', PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT)->value('value'));
    }

    public function test_gold_tier_has_priority_listing_and_unlimited_who_viewed_preview(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $gold = Plan::query()->where('slug', 'gold_male')->firstOrFail();
        $this->assertSame('1', PlanFeature::query()->where('plan_id', $gold->id)->where('key', PlanFeatureKeys::PRIORITY_LISTING)->value('value'));
        $this->assertSame('-1', PlanFeature::query()->where('plan_id', $gold->id)->where('key', PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT)->value('value'));
    }
}
