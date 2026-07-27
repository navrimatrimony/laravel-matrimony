<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanQuotaPolicy;
use App\Services\SubscriptionService;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the class of bug that shipped with the old "female = male with doubled quotas" seeder:
 * a boolean flag doubled from '1' to '2' is read as DISABLED by
 * {@see PlanQuotaPolicy::attributesFromCatalogFeatureMap()}, so every female paid tier silently
 * lost chat_can_read, photo_full_access, priority_listing, advanced_profile_search and
 * profile_whatsapp_direct — paying literally removed the ability to read chat.
 *
 * Also covers the forward-only data repair that fixes catalogs already written with those values.
 */
class PlanBooleanFeatureIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const REPAIR_MIGRATION = 'migrations/2026_07_27_190000_repair_corrupted_plan_boolean_feature_values.php';

    /**
     * Boolean-typed feature keys per the config SSOT (config/plan_features.php).
     *
     * @return list<string>
     */
    private function booleanFeatureKeys(): array
    {
        $keys = [];
        foreach ((array) config('plan_features', []) as $key => $definition) {
            if (is_array($definition) && (string) ($definition['type'] ?? '') === 'boolean') {
                $keys[] = (string) $key;
            }
        }
        $this->assertNotEmpty($keys, 'config/plan_features.php must declare boolean feature keys');

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function booleanPolicyKeys(): array
    {
        return array_values(array_filter(
            PlanQuotaPolicyKeys::ordered(),
            static fn (string $key): bool => PlanQuotaPolicyKeys::mirrorsPlanFeatureAsBooleanOnly($key),
        ));
    }

    /**
     * @return list<string> "slug.key=value" for every boolean feature row outside {0,1}
     */
    private function corruptBooleanFeatureRows(): array
    {
        $slugById = Plan::query()->pluck('slug', 'id');

        return PlanFeature::query()
            ->whereIn('key', $this->booleanFeatureKeys())
            ->get()
            ->reject(fn (PlanFeature $row): bool => in_array((string) $row->value, ['0', '1'], true))
            ->map(fn (PlanFeature $row): string => ($slugById[$row->plan_id] ?? $row->plan_id).'.'.$row->key.'='.$row->value)
            ->values()
            ->all();
    }

    private function policyEnabled(int $planId, string $featureKey): bool
    {
        return (bool) PlanQuotaPolicy::query()
            ->where('plan_id', $planId)
            ->where('feature_key', $featureKey)
            ->value('is_enabled');
    }

    private function featureValue(int $planId, string $featureKey): ?string
    {
        $value = PlanFeature::query()
            ->where('plan_id', $planId)
            ->where('key', $featureKey)
            ->value('value');

        return $value === null ? null : (string) $value;
    }

    private function planId(string $slug): int
    {
        return (int) Plan::query()->where('slug', $slug)->value('id');
    }

    private function runRepairMigration(): void
    {
        $migration = require database_path(self::REPAIR_MIGRATION);
        $migration->up();
    }

    /**
     * Whole-catalog snapshot used to prove the repair is safe to run twice.
     *
     * @return array{features: array<string, string>, policies: array<string, bool>}
     */
    private function catalogSnapshot(): array
    {
        $features = [];
        foreach (PlanFeature::query()->orderBy('plan_id')->orderBy('key')->get() as $row) {
            $features[$row->plan_id.'.'.$row->key] = (string) $row->value;
        }

        $policies = [];
        foreach (PlanQuotaPolicy::query()->orderBy('plan_id')->orderBy('feature_key')->get() as $row) {
            $policies[$row->plan_id.'.'.$row->feature_key] = (bool) $row->is_enabled;
        }

        return ['features' => $features, 'policies' => $policies];
    }

    /**
     * Reproduces the exact production corruption: doubled boolean values plus the disabled policies
     * those values produced on every paid female tier.
     */
    private function corruptCatalogLikeProduction(): void
    {
        $silverFemale = $this->planId('silver_female');
        $goldFemale = $this->planId('gold_female');
        $basicFemale = $this->planId('basic_female');

        PlanFeature::query()
            ->where('plan_id', $silverFemale)
            ->whereIn('key', [
                PlanFeatureKeys::PHOTO_FULL_ACCESS,
                PlanFeatureKeys::ADVANCED_PROFILE_SEARCH,
                SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES,
            ])
            ->update(['value' => '2']);

        PlanFeature::query()
            ->where('plan_id', $goldFemale)
            ->whereIn('key', [
                PlanFeatureKeys::PHOTO_FULL_ACCESS,
                PlanFeatureKeys::ADVANCED_PROFILE_SEARCH,
                PlanFeatureKeys::PRIORITY_LISTING,
                PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT,
                SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES,
            ])
            ->update(['value' => '2']);

        PlanFeature::query()
            ->where('plan_id', $basicFemale)
            ->whereIn('key', $this->booleanPolicyKeys())
            ->update(['value' => '0']);

        PlanQuotaPolicy::query()
            ->whereIn('plan_id', [$basicFemale, $silverFemale, $goldFemale])
            ->whereIn('feature_key', $this->booleanPolicyKeys())
            ->update(['is_enabled' => false]);
    }

    // ---------------------------------------------------------------- guards

    public function test_no_boolean_plan_feature_value_is_outside_zero_or_one(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        // The original bug wrote '2' here. A boolean column holding '2' is corrupt data, whatever the flag.
        $this->assertSame(
            [],
            $this->corruptBooleanFeatureRows(),
            'Boolean plan_features values must be exactly "0" or "1"',
        );
    }

    public function test_chat_read_access_is_enabled_on_every_paid_plan(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        $paid = Plan::query()
            ->get()
            ->reject(fn (Plan $plan): bool => Plan::isFreeCatalogSlug((string) $plan->slug));
        $this->assertNotEmpty($paid, 'Catalog must contain paid plans');

        foreach ($paid as $plan) {
            $this->assertTrue(
                $this->policyEnabled((int) $plan->id, PlanFeatureKeys::CHAT_CAN_READ),
                "Paid plan {$plan->slug} must grant chat_can_read (paying must never remove chat read access)",
            );
            $this->assertSame(
                '1',
                $this->featureValue((int) $plan->id, PlanFeatureKeys::CHAT_CAN_READ),
                "Paid plan {$plan->slug} must mirror chat_can_read into plan_features",
            );
        }
    }

    // ---------------------------------------------------------------- repair

    public function test_repair_migration_normalizes_corrupt_booleans_and_restores_gender_parity(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->corruptCatalogLikeProduction();

        // Sanity: the corruption is actually present before the repair runs.
        $this->assertNotSame([], $this->corruptBooleanFeatureRows());
        $this->assertFalse($this->policyEnabled($this->planId('gold_female'), PlanFeatureKeys::CHAT_CAN_READ));

        $this->runRepairMigration();

        $this->assertSame([], $this->corruptBooleanFeatureRows(), 'No boolean row may survive outside {0,1}');

        foreach (['basic', 'silver', 'gold'] as $tier) {
            $male = $this->planId($tier.'_male');
            $female = $this->planId($tier.'_female');

            foreach ($this->booleanPolicyKeys() as $key) {
                $this->assertSame(
                    $this->policyEnabled($male, $key),
                    $this->policyEnabled($female, $key),
                    "Policy {$key} must match between {$tier}_male and {$tier}_female",
                );
                $this->assertSame(
                    $this->featureValue($male, $key),
                    $this->featureValue($female, $key),
                    "Mirrored plan_features {$key} must match between {$tier}_male and {$tier}_female",
                );
            }

            $this->assertTrue(
                $this->policyEnabled($female, PlanFeatureKeys::CHAT_CAN_READ),
                "{$tier}_female must be able to read chat after the repair",
            );
        }

        // Non-policy boolean flag ('2' → '1'), matching the male tier again.
        $this->assertSame(
            '1',
            $this->featureValue($this->planId('gold_female'), SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES),
        );
    }

    public function test_repair_migration_leaves_free_plans_untouched(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        $before = $this->catalogSnapshot();
        $this->runRepairMigration();

        foreach (['free_male', 'free_female'] as $slug) {
            $planId = $this->planId($slug);
            foreach ($this->booleanPolicyKeys() as $key) {
                $this->assertSame(
                    $before['policies'][$planId.'.'.$key] ?? null,
                    $this->policyEnabled($planId, $key),
                    "Free plan {$slug} must keep its own {$key} state — the repair only touches paid plans",
                );
            }
        }
    }

    public function test_repair_migration_is_safe_to_run_twice(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->corruptCatalogLikeProduction();

        $this->runRepairMigration();
        $afterFirstRun = $this->catalogSnapshot();

        $this->runRepairMigration();

        $this->assertEquals($afterFirstRun, $this->catalogSnapshot(), 'Second run must change nothing');
    }
}
