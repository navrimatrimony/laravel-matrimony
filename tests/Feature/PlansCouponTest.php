<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Plan;
use App\Models\PlanPrice;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlansCouponTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_coupon_returns_meta_json(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        Coupon::query()->create([
            'code' => 'SAVE10',
            'type' => Coupon::TYPE_PERCENT,
            'value' => 10,
            'max_redemptions' => null,
            'redemptions_count' => 0,
            'valid_from' => null,
            'valid_until' => null,
            'is_active' => true,
            'min_purchase_amount' => null,
            'applicable_plan_ids' => null,
            'applicable_duration_types' => null,
            'description' => null,
        ]);

        $this->postJson(route('plans.coupon.validate'), ['code' => 'save10'])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('type', 'percent')
            ->assertJsonPath('value', 10);
    }

    public function test_validate_coupon_with_plan_price_computes_final(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $gold = Plan::query()->where('slug', 'gold')->firstOrFail();
        $price = PlanPrice::query()
            ->where('plan_id', $gold->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->firstOrFail();

        Coupon::query()->create([
            'code' => 'FLAT100',
            'type' => Coupon::TYPE_FIXED,
            'value' => 100,
            'max_redemptions' => null,
            'redemptions_count' => 0,
            'valid_from' => null,
            'valid_until' => null,
            'is_active' => true,
            'min_purchase_amount' => null,
            'applicable_plan_ids' => null,
            'applicable_duration_types' => null,
            'description' => null,
        ]);

        $this->postJson(route('plans.coupon.validate'), [
            'code' => 'FLAT100',
            'plan_price_id' => $price->id,
        ])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonStructure(['base_amount', 'final_amount', 'savings']);
    }
}
