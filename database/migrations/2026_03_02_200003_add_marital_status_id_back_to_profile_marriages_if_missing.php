<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: Re-add marital_status_id to profile_marriages if it was dropped.
 * MutationService still maps this key; marital status is canonical on profile, this column is legacy/sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profile_marriages') && ! Schema::hasColumn('profile_marriages', 'marital_status_id')) {
            Schema::table('profile_marriages', function (Blueprint $table) {
                $table->unsignedBigInteger('marital_status_id')->nullable()->after('profile_id');
            });
            Schema::table('profile_marriages', function (Blueprint $table) {
                if (Schema::hasTable('master_marital_statuses')) {
                    $table->foreign('marital_status_id')->references('id')->on('master_marital_statuses')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('profile_marriages', 'marital_status_id')) {
            Schema::table('profile_marriages', function (Blueprint $table) {
                $table->dropForeign(['marital_status_id']);
                $table->dropColumn('marital_status_id');
            });
        }
    }
};
