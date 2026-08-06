<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanQuotaPolicy;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Biodata export keys live in plan_features (runtime entitlement) but are not admin quota cards.
 * Public /plans catalog must not list them. Admin-offered keys always appear with included flag.
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

    public function test_catalog_lists_all_admin_keys_with_included_and_excluded(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $plan = Plan::query()->where('slug', 'silver_male')->firstOrFail();

        PlanQuotaPolicy::query()
            ->where('plan_id', $plan->id)
            ->where('feature_key', PlanFeatureKeys::PRIORITY_LISTING)
            ->update(['is_enabled' => false]);

        PlanQuotaPolicy::query()
            ->where('plan_id', $plan->id)
            ->where('feature_key', PlanFeatureKeys::CONTACT_VIEW_LIMIT)
            ->update(['is_enabled' => true, 'limit_value' => 0, 'refresh_type' => PlanQuotaPolicy::REFRESH_LIFETIME]);

        $plan->unsetRelation('quotaPolicies');

        $rows = $plan->catalogFeatureRowsForPricing();
        $byKey = $rows->keyBy(fn ($row) => (string) $row->key);

        foreach (PlanQuotaPolicyKeys::ordered() as $fk) {
            $this->assertTrue($byKey->has($fk), "Missing admin catalog key: {$fk}");
        }

        $this->assertFalse((bool) $byKey->get(PlanFeatureKeys::PRIORITY_LISTING)->included);
        $this->assertFalse((bool) $byKey->get(PlanFeatureKeys::CONTACT_VIEW_LIMIT)->included);
        $this->assertTrue((bool) $byKey->get(PlanFeatureKeys::CHAT_SEND_LIMIT)->included);
        $this->assertTrue($byKey->has(PlanFeatureKeys::PHOTO_FULL_ACCESS));
        $this->assertTrue($byKey->has(PlanFeatureKeys::CHAT_CAN_READ));
        $this->assertTrue($byKey->has(PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT));

        $this->assertNotContains(PlanFeatureKeys::BIODATA_EXPORT_LIMIT, $rows->map(fn ($r) => (string) $r->key)->all());
        $this->assertNotContains(PlanFeatureKeys::BIODATA_PREMIUM_TEMPLATES, $rows->map(fn ($r) => (string) $r->key)->all());
    }
}
