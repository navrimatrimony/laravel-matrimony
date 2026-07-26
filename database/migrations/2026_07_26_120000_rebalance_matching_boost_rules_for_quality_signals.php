<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trust/quality rebalance of the ranking boost layer (audit fix, 2026-07-26).
 *
 * Before: a paid tier bought up to +12 while photo, profile completeness, mobile verification and
 * KYC bought nothing at all — a paid-but-empty profile could sit at the top of a Suchak's list.
 *
 * After: five quality rows carry the weight (kyc 7, photo 7, completeness 6, mobile 5, recency 5),
 * the commercial tier is capped at +4 combined, and the aggregate ceiling moves 20 → 25 so the
 * quality signals are not squeezed out by it.
 *
 * Additive and idempotent. Existing rows are only rewritten when they still hold the historical
 * default value — an admin-tuned weight is left exactly as the admin set it.
 */
return new class extends Migration
{
    /**
     * New rows introduced by this migration.
     *
     * @var list<array{boost_type: string, value: int, max_cap: int, is_active: bool, meta: array<string, mixed>}>
     */
    private array $newRules = [
        ['boost_type' => 'verified_kyc', 'value' => 7, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
        ['boost_type' => 'photo', 'value' => 7, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
        ['boost_type' => 'completeness', 'value' => 6, 'max_cap' => 100, 'is_active' => true, 'meta' => ['min_percent' => 50]],
        ['boost_type' => 'verified_mobile', 'value' => 5, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
    ];

    /**
     * boost_type => [historical default value, new value]. Rewritten only on an exact match.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private array $rebalance = [
        'active' => [3, 5],
        'gold_extra' => [10, 2],
        'silver_extra' => [5, 1],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('matching_boost_rules')) {
            return;
        }

        // Fresh install: the table is seeded on first use by MatchingConfigService::ensureDefaults(),
        // which `create()`s every row from MatchBoostSettingDefaults (already rebalanced). Pre-inserting
        // here would collide on the unique boost_type index, so this migration only upgrades installs
        // whose rules were already seeded.
        if (DB::table('matching_boost_rules')->count() === 0) {
            return;
        }

        $now = now();

        foreach ($this->newRules as $rule) {
            $exists = DB::table('matching_boost_rules')->where('boost_type', $rule['boost_type'])->exists();
            if ($exists) {
                continue;
            }

            DB::table('matching_boost_rules')->insert([
                'boost_type' => $rule['boost_type'],
                'value' => $rule['value'],
                'max_cap' => $rule['max_cap'],
                'is_active' => $rule['is_active'],
                'meta' => json_encode($rule['meta']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($this->rebalance as $type => [$historicalDefault, $newValue]) {
            DB::table('matching_boost_rules')
                ->where('boost_type', $type)
                ->where('value', $historicalDefault)
                ->update(['value' => $newValue, 'updated_at' => $now]);
        }

        // Recency needs a decay horizon: full weight inside active_within_days, zero at stale_after_days.
        $active = DB::table('matching_boost_rules')->where('boost_type', 'active')->first();
        if ($active !== null) {
            $meta = json_decode((string) ($active->meta ?? '{}'), true);
            $meta = is_array($meta) ? $meta : [];
            if (! array_key_exists('stale_after_days', $meta)) {
                $meta['active_within_days'] = (int) ($meta['active_within_days'] ?? 7);
                $meta['stale_after_days'] = 180;
                DB::table('matching_boost_rules')
                    ->where('boost_type', 'active')
                    ->update(['meta' => json_encode($meta), 'updated_at' => $now]);
            }
        }

        // Global ceiling 20 → 25 so five quality signals can coexist with similarity + tier.
        DB::table('matching_boost_rules')
            ->where('boost_type', 'aggregate_cap')
            ->where('max_cap', 20)
            ->update(['max_cap' => 25, 'updated_at' => $now]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('matching_boost_rules')) {
            return;
        }

        $now = now();

        DB::table('matching_boost_rules')
            ->whereIn('boost_type', array_column($this->newRules, 'boost_type'))
            ->delete();

        foreach ($this->rebalance as $type => [$historicalDefault, $newValue]) {
            DB::table('matching_boost_rules')
                ->where('boost_type', $type)
                ->where('value', $newValue)
                ->update(['value' => $historicalDefault, 'updated_at' => $now]);
        }

        DB::table('matching_boost_rules')
            ->where('boost_type', 'aggregate_cap')
            ->where('max_cap', 25)
            ->update(['max_cap' => 20, 'updated_at' => $now]);
    }
};
