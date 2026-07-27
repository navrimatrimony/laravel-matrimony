<?php

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanQuotaPolicy;
use App\Services\PlanQuotaPolicyMirror;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-only data repair for the catalog corrupted by the old
 * {@see \Database\Seeders\SubscriptionPlansSeeder} "female = male with doubled quotas" helper.
 *
 * The helper doubled BOOLEAN flags along with numeric quotas, so an enabled flag `'1'` was stored
 * as `'2'`. {@see PlanQuotaPolicy::attributesFromCatalogFeatureMap()} accepts only `1/true/yes/on`
 * as "on", so `'2'` persisted the policy as DISABLED — every female paid tier silently lost
 * chat_can_read, photo_full_access, priority_listing, advanced_profile_search and
 * profile_whatsapp_direct. The seeder was fixed in e547033b; the already-written rows were not.
 *
 * Three idempotent passes:
 *   1. Normalise every boolean-typed {@see PlanFeature} value (config/plan_features.php is the type
 *      SSOT) to '0'/'1'. Any positive number was an enabled flag that got doubled.
 *   2. Restore `*_female` ↔ `*_male` parity on boolean flags for paid pairs (slug-driven, not by id).
 *   3. Enable chat_can_read on every PAID plan, both genders (product decision 2026-07-27 —
 *      paying must never remove chat read access). Free plans are left exactly as they are.
 *
 * NOT touched: `subscriptions.meta.checkout_snapshot.quota_policies`. {@see \App\Services\PlanQuotaUiSource}
 * prefers that frozen blob over the live plan rows, so members already subscribed keep the entitlements
 * they checked out with. Repairing existing snapshots is a separate, unapproved decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans')
            || ! Schema::hasTable('plan_features')
            || ! Schema::hasTable('plan_quota_policies')) {
            return;
        }

        DB::transaction(function (): void {
            $this->normalizeBooleanPlanFeatureValues();
            $this->restoreFemaleToMaleBooleanParity();
            $this->enableChatReadOnEveryPaidPlan();
        });

        Plan::flushDefaultFreeMemo();
    }

    public function down(): void
    {
        // Forward-only repair: a corrupt boolean ('2') must never be restored.
    }

    /**
     * Boolean-typed feature keys, per the config SSOT. A key added later is covered automatically.
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

        return $keys;
    }

    /**
     * Boolean flags whose SSOT is the {@see PlanQuotaPolicy} row; {@see PlanFeature} only mirrors them.
     *
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
     * '2' / '5' / 'yes' → '1'; anything else falsy → '0'. Already-clean rows are not rewritten.
     */
    private function normalizeBooleanPlanFeatureValues(): void
    {
        $booleanKeys = $this->booleanFeatureKeys();
        if ($booleanKeys === []) {
            return;
        }

        $rows = PlanFeature::query()->whereIn('key', $booleanKeys)->get();
        foreach ($rows as $row) {
            $canonical = self::canonicalBooleanValue($row->value);
            if ((string) $row->value === $canonical) {
                continue;
            }
            $row->value = $canonical;
            $row->save();
        }
    }

    /**
     * Paid `*_female` plans inherit their `*_male` twin's boolean flags — which is what the catalog
     * always meant ("female = male tier with doubled numeric quotas"). Free tiers are untouched, and
     * a female plan without a male twin (e.g. an admin-created one-off) is skipped.
     */
    private function restoreFemaleToMaleBooleanParity(): void
    {
        $suffix = '_female';
        $policyKeys = $this->booleanPolicyKeys();
        $plainKeys = array_values(array_filter(
            $this->booleanFeatureKeys(),
            static fn (string $key): bool => ! PlanQuotaPolicyKeys::isForbiddenPlanFeatureRowKey($key),
        ));

        foreach (Plan::query()->orderBy('id')->get() as $female) {
            $slug = strtolower(trim((string) $female->slug));
            if (! str_ends_with($slug, $suffix) || Plan::isFreeCatalogSlug($slug)) {
                continue;
            }

            $maleSlug = substr($slug, 0, -strlen($suffix)).'_male';
            $male = Plan::query()->where('slug', $maleSlug)->first();
            if (! $male instanceof Plan) {
                continue;
            }

            foreach ($policyKeys as $key) {
                $malePolicy = PlanQuotaPolicy::query()
                    ->where('plan_id', $male->id)
                    ->where('feature_key', $key)
                    ->first();
                if (! $malePolicy instanceof PlanQuotaPolicy) {
                    continue;
                }
                $this->applyBooleanPolicyFeature((int) $female->id, $key, (bool) $malePolicy->is_enabled);
            }

            foreach ($plainKeys as $key) {
                $maleRow = PlanFeature::query()
                    ->where('plan_id', $male->id)
                    ->where('key', $key)
                    ->first();
                if (! $maleRow instanceof PlanFeature) {
                    continue;
                }
                $this->writePlanFeatureValue((int) $female->id, $key, self::canonicalBooleanValue($maleRow->value));
            }
        }
    }

    /**
     * Product decision 2026-07-27: paying must never remove chat read access. "Paid" is derived from
     * the catalog slug via {@see Plan::isFreeCatalogSlug()}, never from hardcoded plan ids.
     */
    private function enableChatReadOnEveryPaidPlan(): void
    {
        foreach (Plan::query()->orderBy('id')->get() as $plan) {
            if (Plan::isFreeCatalogSlug((string) $plan->slug)) {
                continue;
            }
            $this->applyBooleanPolicyFeature((int) $plan->id, PlanFeatureKeys::CHAT_CAN_READ, true);
        }
    }

    /**
     * Writes the quota policy (SSOT) and its {@see PlanFeature} mirror through the existing
     * {@see PlanQuotaPolicyMirror} so the two tables can never disagree.
     */
    private function applyBooleanPolicyFeature(int $planId, string $featureKey, bool $enabled): void
    {
        $policy = PlanQuotaPolicy::query()
            ->where('plan_id', $planId)
            ->where('feature_key', $featureKey)
            ->first();

        if (! $policy instanceof PlanQuotaPolicy) {
            $policy = new PlanQuotaPolicy;
            $policy->fill(PlanQuotaPolicy::defaultsForNewPlan($featureKey));
            $policy->plan_id = $planId;
            $policy->feature_key = $featureKey;
            $policy->is_enabled = $enabled;
            $policy->save();
        } elseif ((bool) $policy->is_enabled !== $enabled) {
            $policy->is_enabled = $enabled;
            $policy->save();
        }

        $mirrored = PlanQuotaPolicyMirror::mirroredFeatureRowsFromPolicyPayload(
            $featureKey,
            PlanQuotaPolicyMirror::payloadFromModel($policy),
        );
        foreach ($mirrored as $row) {
            $this->writePlanFeatureValue($planId, (string) $row['key'], (string) $row['value']);
        }
    }

    private function writePlanFeatureValue(int $planId, string $key, string $value): void
    {
        $feature = PlanFeature::query()
            ->where('plan_id', $planId)
            ->where('key', $key)
            ->first();

        if (! $feature instanceof PlanFeature) {
            PlanFeature::query()->create([
                'plan_id' => $planId,
                'key' => $key,
                'value' => $value,
            ]);

            return;
        }

        if ((string) $feature->value === $value) {
            return;
        }

        $feature->value = $value;
        $feature->save();
    }

    private static function canonicalBooleanValue(mixed $raw): string
    {
        $s = strtolower(trim((string) ($raw ?? '')));
        if ($s === '') {
            return '0';
        }
        if (in_array($s, ['1', 'true', 'yes', 'on'], true)) {
            return '1';
        }

        // A doubled flag ('2') — or any other positive number — was an enabled flag before the seeder bug.
        return is_numeric($s) && (float) $s > 0 ? '1' : '0';
    }
};
