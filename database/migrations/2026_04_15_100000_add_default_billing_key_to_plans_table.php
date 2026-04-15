<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }

        if (! Schema::hasColumn('plans', 'default_billing_key')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->string('default_billing_key', 64)->nullable()->after('duration_days');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('plans') && Schema::hasColumn('plans', 'default_billing_key')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->dropColumn('default_billing_key');
            });
        }
    }
};
