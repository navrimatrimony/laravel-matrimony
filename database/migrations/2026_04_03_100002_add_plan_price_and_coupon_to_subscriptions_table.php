<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'plan_price_id')) {
                $table->foreignId('plan_price_id')->nullable()->after('plan_term_id')->constrained('plan_prices')->nullOnDelete();
            }
            if (! Schema::hasColumn('subscriptions', 'coupon_id')) {
                $table->foreignId('coupon_id')->nullable()->after('plan_price_id')->constrained('coupons')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'coupon_id')) {
                $table->dropConstrainedForeignId('coupon_id');
            }
            if (Schema::hasColumn('subscriptions', 'plan_price_id')) {
                $table->dropConstrainedForeignId('plan_price_id');
            }
        });
    }
};
