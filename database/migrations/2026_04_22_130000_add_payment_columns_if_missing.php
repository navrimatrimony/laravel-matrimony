<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'source')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('source', 32)->nullable();
            });
        }

        if (! Schema::hasColumn('payments', 'is_processed')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->boolean('is_processed')->default(false);
            });
        }

        if (! Schema::hasColumn('payments', 'payment_status')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('payment_status', 32)->default('success');
            });
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'payment_status')) {
                $table->dropColumn('payment_status');
            }
        });
    }
};
