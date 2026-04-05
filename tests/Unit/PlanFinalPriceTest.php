<?php

namespace Tests\Unit;

use App\Models\Plan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlanFinalPriceTest extends TestCase
{
    #[Test]
    public function final_price_applies_discount_percent(): void
    {
        $plan = new Plan([
            'price' => 5000,
            'discount_percent' => 50,
        ]);

        $this->assertSame(2500.0, $plan->final_price);
    }

    #[Test]
    public function final_price_without_discount_equals_list_price(): void
    {
        $plan = new Plan([
            'price' => 5000,
            'discount_percent' => null,
        ]);

        $this->assertSame(5000.0, $plan->final_price);
    }

    #[Test]
    public function final_price_zero_discount_equals_list_price(): void
    {
        $plan = new Plan([
            'price' => 5000,
            'discount_percent' => 0,
        ]);

        $this->assertSame(5000.0, $plan->final_price);
    }
}
