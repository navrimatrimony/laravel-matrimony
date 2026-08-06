<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Support\PlanFeatureKeys;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Biodata export keys live in plan_features (runtime entitlement) but are not admin quota cards.
 * Public /plans catalog must not list them.
 */
class PlanPricingCatalogHiddenBiodataTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_feature_rows_for_pricing_omit_biodata_export_keys(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $plan = Plan::query()->where('slug', 'silver_male')->firstOrFail();

        $this->assertSame(
            '20',
            PlanFeature::query()
                ->where('plan_id', $plan->id)
                ->where('key', PlanFeatureKeys::BIODATA_EXPORT_LIMIT)
                ->value('value')
        );
        $this->assertSame(
            '1',
            PlanFeature::query()
                ->where('plan_id', $plan->id)
                ->where('key', PlanFeatureKeys::BIODATA_PREMIUM_TEMPLATES)
                ->value('value')
        );

        $keys = $plan->catalogFeatureRowsForPricing()
            ->map(fn ($row) => (string) $row->key)
            ->all();

        $this->assertNotContains(PlanFeatureKeys::BIODATA_EXPORT_LIMIT, $keys);
        $this->assertNotContains(PlanFeatureKeys::BIODATA_PREMIUM_TEMPLATES, $keys);
    }
}
