<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlanTermFillMissingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function fill_missing_terms_only_creates_absent_billing_keys(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Test Gold',
            'slug' => 'test-gold-fill',
            'price' => 1000,
            'discount_percent' => null,
            'duration_days' => 30,
            'is_active' => true,
            'sort_order' => 1,
            'highlight' => false,
        ]);

        PlanTerm::query()->create([
            'plan_id' => $plan->id,
            'billing_key' => PlanTerm::BILLING_MONTHLY,
            'duration_days' => 30,
            'price' => 999,
            'discount_percent' => null,
            'is_visible' => true,
            'sort_order' => PlanTerm::defaultSortOrder(PlanTerm::BILLING_MONTHLY),
        ]);

        PlanTerm::fillMissingTermsForPlan($plan->fresh());

        $monthly = PlanTerm::query()
            ->where('plan_id', $plan->id)
            ->where('billing_key', PlanTerm::BILLING_MONTHLY)
            ->firstOrFail();
        $this->assertEqualsWithDelta(999.0, (float) $monthly->price, 0.01, 'Existing monthly term must not be overwritten');

        $keys = PlanTerm::query()->where('plan_id', $plan->id)->pluck('billing_key')->sort()->values()->all();
        $this->assertSame(
            ['half_yearly', 'monthly', 'quarterly', 'yearly'],
            $keys
        );
    }

    #[Test]
    public function fill_missing_terms_skips_free_plan(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Free',
            'slug' => 'free',
            'price' => 0,
            'discount_percent' => null,
            'duration_days' => 0,
            'is_active' => true,
            'sort_order' => 0,
            'highlight' => false,
        ]);

        PlanTerm::fillMissingTermsForPlan($plan);

        $this->assertSame(0, PlanTerm::query()->where('plan_id', $plan->id)->count());
    }
}
