<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Male and female catalog tiers share identical quotas and boolean flags (no 2× female scaling).
 */
class PlanGenderBooleanFeatureParityTest extends TestCase
{
    use RefreshDatabase;

    private function policyEnabled(string $slug, string $featureKey): bool
    {
        $plan = Plan::query()->where('slug', $slug)->firstOrFail();

        return (bool) PlanQuotaPolicy::query()
            ->where('plan_id', $plan->id)
            ->where('feature_key', $featureKey)
            ->value('is_enabled');
    }

    public function test_female_paid_plans_grant_chat_read_access(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        foreach (['basic_female', 'silver_female', 'gold_female'] as $slug) {
            $this->assertTrue(
                $this->policyEnabled($slug, PlanFeatureKeys::CHAT_CAN_READ),
                "Paid plan {$slug} must grant ".PlanFeatureKeys::CHAT_CAN_READ,
            );
        }
    }

    public function test_boolean_feature_flags_match_between_male_and_female_tiers(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        $booleanKeys = array_values(array_filter(
            PlanQuotaPolicyKeys::ordered(),
            fn (string $key): bool => PlanQuotaPolicyKeys::mirrorsPlanFeatureAsBooleanOnly($key),
        ));
        $this->assertNotEmpty($booleanKeys);

        foreach (['free', 'basic', 'silver', 'gold'] as $tier) {
            foreach ($booleanKeys as $key) {
                $this->assertSame(
                    $this->policyEnabled($tier.'_male', $key),
                    $this->policyEnabled($tier.'_female', $key),
                    "Boolean feature {$key} must match between {$tier}_male and {$tier}_female",
                );
            }
        }
    }

    public function test_numeric_quotas_match_between_male_and_female_tiers(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        $maleContact = PlanQuotaPolicy::query()
            ->where('plan_id', Plan::query()->where('slug', 'basic_male')->value('id'))
            ->where('feature_key', PlanFeatureKeys::CONTACT_VIEW_LIMIT)
            ->firstOrFail();
        $femaleContact = PlanQuotaPolicy::query()
            ->where('plan_id', Plan::query()->where('slug', 'basic_female')->value('id'))
            ->where('feature_key', PlanFeatureKeys::CONTACT_VIEW_LIMIT)
            ->firstOrFail();

        $this->assertSame((int) $maleContact->limit_value, (int) $femaleContact->limit_value);
        $this->assertSame((string) $maleContact->refresh_type, (string) $femaleContact->refresh_type);
        $this->assertSame(60, (int) $maleContact->limit_value);
        $this->assertSame(PlanQuotaPolicy::REFRESH_LIFETIME, (string) $maleContact->refresh_type);
    }
}
