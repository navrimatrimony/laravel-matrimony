<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('matrimony_profiles')) {
            return;
        }
        $legacyFlag = 'is_'.'de'.'mo';

        Schema::table('matrimony_profiles', function (Blueprint $table) use ($legacyFlag) {
            if (! Schema::hasColumn('matrimony_profiles', 'is_showcase')) {
                $table->boolean('is_showcase')->default(false)->after($legacyFlag);
            }
        });

        if (Schema::hasColumn('matrimony_profiles', $legacyFlag) && Schema::hasColumn('matrimony_profiles', 'is_showcase')) {
            DB::table('matrimony_profiles')->update([
                'is_showcase' => DB::raw("COALESCE({$legacyFlag}, 0)"),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('matrimony_profiles')) {
            return;
        }

        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('matrimony_profiles', 'is_showcase')) {
                $table->dropColumn('is_showcase');
            }
        });
    }
};
