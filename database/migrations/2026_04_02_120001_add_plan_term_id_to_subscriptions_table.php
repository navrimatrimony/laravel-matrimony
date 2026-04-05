<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'plan_term_id')) {
                return;
            }
            $table->foreignId('plan_term_id')->nullable()->after('plan_id')->constrained('plan_terms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'plan_term_id')) {
                return;
            }
            $table->dropConstrainedForeignId('plan_term_id');
        });
    }
};
