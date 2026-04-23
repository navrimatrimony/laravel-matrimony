<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkout audit: canonical plan FK + billing_key alongside existing plan_key slug field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'plan_id')) {
                $table->unsignedBigInteger('plan_id')->nullable()->after('user_id');
                $table->index('plan_id');
            }
            if (! Schema::hasColumn('payments', 'billing_key')) {
                $after = Schema::hasColumn('payments', 'plan_key') ? 'plan_key' : 'txnid';
                $table->string('billing_key', 32)->nullable()->after($after);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'billing_key')) {
                $table->dropColumn('billing_key');
            }
            if (Schema::hasColumn('payments', 'plan_id')) {
                $table->dropIndex(['plan_id']);
                $table->dropColumn('plan_id');
            }
        });
    }
};
