<?php

use App\Services\DuplicateMobileResolutionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1) Resolves duplicate mobiles in-app (no user deletes).
 * 2) Ensures a unique index on {@code users.mobile} (drops existing mobile unique if present, then re-adds).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        app(DuplicateMobileResolutionService::class)->dedupeAll();

        try {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['mobile']);
            });
        } catch (\Throwable) {
            // No prior unique index (e.g. migration 2026_03_22 never applied).
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('mobile');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        try {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['mobile']);
            });
        } catch (\Throwable) {
            //
        }
    }
};
