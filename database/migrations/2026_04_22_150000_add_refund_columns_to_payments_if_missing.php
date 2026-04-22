<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'refunded_at')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->timestamp('refunded_at')->nullable()->after('payload');
            });
        }

        if (! Schema::hasColumn('payments', 'refund_reason')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('refund_reason')->nullable()->after('refunded_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'refund_reason')) {
                $table->dropColumn('refund_reason');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'refunded_at')) {
                $table->dropColumn('refunded_at');
            }
        });
    }
};
