<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'plan_status')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('plan_status', 32)->default('active')->after('plan_expires_at');
            });
        }

        if (! Schema::hasColumn('users', 'plan_started_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('plan_started_at')->nullable()->after('plan_status');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'plan_started_at')) {
                $table->dropColumn('plan_started_at');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'plan_status')) {
                $table->dropColumn('plan_status');
            }
        });
    }
};
