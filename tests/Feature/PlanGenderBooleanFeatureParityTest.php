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
 * Female tiers are seeded as "male tier with doubled numeric quotas". Doubling must apply to
 * numeric quotas ONLY — a boolean flag doubled from '1' to '2' is persisted as DISABLED by
 * {@see PlanQuotaPolicy::attributesFromCatalogFeatureMap()}, which silently revoked paid
 * features (notably chat_can_read) from every female paid plan.
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

        // The exact production symptom: a paying female member could not read her chat messages.
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

    public function test_numeric_quotas_are_still_doubled_for_female_tiers(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        // Guards the fix against over-correcting: real quotas must keep their 2x scaling.
        $maleChatSend = PlanQuotaPolicy::query()
            ->where('plan_id', Plan::query()->where('slug', 'silver_male')->value('id'))
            ->where('feature_key', PlanFeatureKeys::CHAT_SEND_LIMIT)
            ->value('limit_value');
        $femaleChatSend = PlanQuotaPolicy::query()
            ->where('plan_id', Plan::query()->where('slug', 'silver_female')->value('id'))
            ->where('feature_key', PlanFeatureKeys::CHAT_SEND_LIMIT)
            ->value('limit_value');

        $this->assertSame(100, (int) $maleChatSend);
        $this->assertSame(200, (int) $femaleChatSend);
    }
}
