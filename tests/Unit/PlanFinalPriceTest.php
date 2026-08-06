<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\PlanTerm;
use App\Support\PlanPricing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlanFinalPriceTest extends TestCase
{
    #[Test]
    public function final_price_uses_selling_price_ssot(): void
    {
        $plan = new Plan([
            'price' => 5999,
            'selling_price' => 3999,
            'discount_percent' => 50, // deprecated — must not affect payable
        ]);

        $this->assertSame(3999.0, $plan->final_price);
        $this->assertSame(33, $plan->displayDiscountPercent());
    }

    #[Test]
    public function final_price_without_discount_equals_mrp(): void
    {
        $plan = new Plan([
            'price' => 5000,
            'selling_price' => 5000,
            'discount_percent' => null,
        ]);

        $this->assertSame(5000.0, $plan->final_price);
        $this->assertFalse($plan->hasActiveDiscount());
    }

    #[Test]
    public function plan_term_final_price_uses_selling_price(): void
    {
        $term = new PlanTerm([
            'price' => 5000,
            'selling_price' => 4250,
            'discount_percent' => 99,
        ]);

        $this->assertSame(4250.0, $term->final_price);
        $this->assertSame(15, $term->displayDiscountPercent());
    }

    #[Test]
    public function display_discount_is_whole_number_only(): void
    {
        $this->assertSame(33, PlanPricing::displayDiscountPercent(5999, 3999));
        $this->assertSame(10, PlanPricing::displayDiscountPercent(999, 899));
        $this->assertSame(0, PlanPricing::displayDiscountPercent(1000, 1000));
        $this->assertSame(0, PlanPricing::displayDiscountPercent(0, 0));
    }
}
