<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Align {@code user_feature_usages.feature_key} with plan/entitlement keys:
     * {@code interest_send} → {@code interest_send_limit}, {@code contact_view} → {@code contact_view_limit}.
     */
    public function up(): void
    {
        if (! Schema::hasTable('user_feature_usages')) {
            return;
        }

        DB::table('user_feature_usages')
            ->where('feature_key', 'interest_send')
            ->update(['feature_key' => 'interest_send_limit']);

        DB::table('user_feature_usages')
            ->where('feature_key', 'contact_view')
            ->update(['feature_key' => 'contact_view_limit']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_feature_usages')) {
            return;
        }

        DB::table('user_feature_usages')
            ->where('feature_key', 'interest_send_limit')
            ->update(['feature_key' => 'interest_send']);

        DB::table('user_feature_usages')
            ->where('feature_key', 'contact_view_limit')
            ->update(['feature_key' => 'contact_view']);
    }
};
