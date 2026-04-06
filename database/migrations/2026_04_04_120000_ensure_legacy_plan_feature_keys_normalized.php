<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_KEYS = [
        'daily_chat_send_limit',
        'monthly_interest_send_limit',
        'contact_number_access',
    ];

    /**
     * Idempotent repair: normalize legacy plan_features keys and matching user_entitlements.entitlement_key rows.
     * Safe to run after 2026_04_01_160000_normalize_plan_feature_keys (no-op when already clean).
     * Does not delete plans.
     */
    public function up(): void
    {
        if (! Schema::hasTable('plan_features')) {
            return;
        }

        $planFeaturesNeedWork = DB::table('plan_features')->whereIn('key', self::LEGACY_KEYS)->exists();
        $entitlementsNeedWork = Schema::hasTable('user_entitlements')
            && DB::table('user_entitlements')->whereIn('entitlement_key', self::LEGACY_KEYS)->exists();

        if (! $planFeaturesNeedWork && ! $entitlementsNeedWork) {
            return;
        }

        if ($planFeaturesNeedWork) {
            $this->mergePlanFeatureKey('daily_chat_send_limit', 'chat_send_limit');
            $this->mergePlanFeatureKey('monthly_interest_send_limit', 'interest_send_limit');
            $this->migrateContactNumberAccessToViewLimit();
        }

        if ($entitlementsNeedWork) {
            $this->renameEntitlementKey('daily_chat_send_limit', 'chat_send_limit');
            $this->renameEntitlementKey('monthly_interest_send_limit', 'interest_send_limit');
            $this->renameEntitlementKey('contact_number_access', 'contact_view_limit');
        }
    }

    public function down(): void
    {
        // Non-reversible repair migration (no-op).
    }

    private function mergePlanFeatureKey(string $oldKey, string $newKey): void
    {
        $planIds = DB::table('plan_features')->distinct()->pluck('plan_id');
        foreach ($planIds as $planId) {
            $oldRow = DB::table('plan_features')
                ->where('plan_id', $planId)
                ->where('key', $oldKey)
                ->first();
            if (! $oldRow) {
                continue;
            }
            $newRow = DB::table('plan_features')
                ->where('plan_id', $planId)
                ->where('key', $newKey)
                ->first();
            if ($newRow) {
                $newVal = trim((string) $newRow->value);
                $oldVal = trim((string) $oldRow->value);
                if ($newVal === '' && $oldVal !== '') {
                    DB::table('plan_features')->where('id', $newRow->id)->update([
                        'value' => $oldRow->value,
                        'updated_at' => now(),
                    ]);
                }
                DB::table('plan_features')->where('id', $oldRow->id)->delete();

                continue;
            }
            DB::table('plan_features')->where('id', $oldRow->id)->update([
                'key' => $newKey,
                'updated_at' => now(),
            ]);
        }
    }

    private function migrateContactNumberAccessToViewLimit(): void
    {
        $planIds = DB::table('plan_features')->distinct()->pluck('plan_id');
        foreach ($planIds as $planId) {
            $oldRow = DB::table('plan_features')
                ->where('plan_id', $planId)
                ->where('key', 'contact_number_access')
                ->first();
            if (! $oldRow) {
                continue;
            }
            $newRow = DB::table('plan_features')
                ->where('plan_id', $planId)
                ->where('key', 'contact_view_limit')
                ->first();
            if ($newRow) {
                $newVal = trim((string) $newRow->value);
                $oldVal = strtolower(trim((string) $oldRow->value));
                if ($newVal === '' && in_array($oldVal, ['1', 'true', 'yes', 'on'], true)) {
                    DB::table('plan_features')->where('id', $newRow->id)->update([
                        'value' => '-1',
                        'updated_at' => now(),
                    ]);
                }
                DB::table('plan_features')->where('id', $oldRow->id)->delete();

                continue;
            }
            $v = strtolower(trim((string) $oldRow->value));
            $newVal = in_array($v, ['1', 'true', 'yes', 'on'], true) ? '-1' : '0';
            DB::table('plan_features')->where('id', $oldRow->id)->update([
                'key' => 'contact_view_limit',
                'value' => $newVal,
                'updated_at' => now(),
            ]);
        }
    }

    private function renameEntitlementKey(string $from, string $to): void
    {
        if (! Schema::hasTable('user_entitlements')) {
            return;
        }

        $rows = DB::table('user_entitlements')->where('entitlement_key', $from)->orderBy('id')->get();
        foreach ($rows as $row) {
            $conflictQuery = DB::table('user_entitlements')
                ->where('user_id', $row->user_id)
                ->where('entitlement_key', $to)
                ->where('id', '!=', $row->id);
            if ($row->revoked_at === null) {
                $conflictQuery->whereNull('revoked_at');
            } else {
                $conflictQuery->where('revoked_at', $row->revoked_at);
            }
            if ($conflictQuery->exists()) {
                DB::table('user_entitlements')->where('id', $row->id)->delete();
            } else {
                DB::table('user_entitlements')->where('id', $row->id)->update([
                    'entitlement_key' => $to,
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
