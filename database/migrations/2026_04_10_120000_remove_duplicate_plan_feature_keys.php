<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops legacy duplicate plan / entitlement keys superseded by canonical keys:
 * - chat_can_send → chat_send_limit
 * - contact_unlock → contact_view_limit
 * - profile_view_limit → daily_profile_view_limit
 */
return new class extends Migration
{
    private const KEYS = ['chat_can_send', 'contact_unlock', 'profile_view_limit'];

    public function up(): void
    {
        if (Schema::hasTable('plan_features')) {
            foreach (self::KEYS as $key) {
                DB::table('plan_features')->where('key', $key)->delete();
            }
        }

        if (Schema::hasTable('user_entitlements')) {
            foreach (self::KEYS as $key) {
                DB::table('user_entitlements')->where('entitlement_key', $key)->delete();
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty: do not reinsert ambiguous duplicate keys.
    }
};
