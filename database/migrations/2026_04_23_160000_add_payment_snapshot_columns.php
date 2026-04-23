<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'plan_term_id')) {
                $table->unsignedBigInteger('plan_term_id')->nullable()->after('plan_id');
                $table->index('plan_term_id');
            }
            if (! Schema::hasColumn('payments', 'currency')) {
                $table->string('currency', 8)->default('INR')->after('amount');
            }
            if (! Schema::hasColumn('payments', 'amount_paid')) {
                $table->decimal('amount_paid', 10, 2)->nullable()->after('currency');
            }
            if (! Schema::hasColumn('payments', 'meta')) {
                $table->json('meta')->nullable()->after('payload');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'meta')) {
                $table->dropColumn('meta');
            }
            if (Schema::hasColumn('payments', 'amount_paid')) {
                $table->dropColumn('amount_paid');
            }
            if (Schema::hasColumn('payments', 'currency')) {
                $table->dropColumn('currency');
            }
            if (Schema::hasColumn('payments', 'plan_term_id')) {
                $table->dropIndex(['plan_term_id']);
                $table->dropColumn('plan_term_id');
            }
        });
    }
};
