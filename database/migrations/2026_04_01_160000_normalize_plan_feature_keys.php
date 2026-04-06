<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Align legacy plan_feature keys with {@see \App\Support\PlanFeatureKeys}.
     * Does not delete plans. contact_number_access values map to contact_view_limit:
     * truthy → -1 (unlimited monthly reveals when unlock applies), else 0.
     */
    public function up(): void
    {
        $this->mergePlanFeatureKey('daily_chat_send_limit', 'chat_send_limit');
        $this->mergePlanFeatureKey('monthly_interest_send_limit', 'interest_send_limit');
        $this->migrateContactNumberAccessToViewLimit();

        $this->renameEntitlementKey('daily_chat_send_limit', 'chat_send_limit');
        $this->renameEntitlementKey('monthly_interest_send_limit', 'interest_send_limit');
        $this->renameEntitlementKey('contact_number_access', 'contact_view_limit');
    }

    /**
     * Reverse key renames only; does not restore contact_number_access truthy values.
     */
    public function down(): void
    {
        $this->renameEntitlementKey('contact_view_limit', 'contact_number_access');
        $this->renameEntitlementKey('interest_send_limit', 'monthly_interest_send_limit');
        $this->renameEntitlementKey('chat_send_limit', 'daily_chat_send_limit');

        $this->mergePlanFeatureKey('chat_send_limit', 'daily_chat_send_limit');
        $this->mergePlanFeatureKey('interest_send_limit', 'monthly_interest_send_limit');

        $planIds = DB::table('plan_features')->distinct()->pluck('plan_id');
        foreach ($planIds as $planId) {
            $row = DB::table('plan_features')
                ->where('plan_id', $planId)
                ->where('key', 'contact_view_limit')
                ->first();
            if (! $row) {
                continue;
            }
            $legacy = DB::table('plan_features')
                ->where('plan_id', $planId)
                ->where('key', 'contact_number_access')
                ->first();
            if ($legacy) {
                continue;
            }
            $v = strtolower(trim((string) $row->value));
            $newVal = ($v === '-1' || $v === 'unlimited' || (is_numeric($v) && (int) $v > 0)) ? '1' : '0';
            DB::table('plan_features')->where('id', $row->id)->update([
                'key' => 'contact_number_access',
                'value' => $newVal,
                'updated_at' => now(),
            ]);
        }
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
