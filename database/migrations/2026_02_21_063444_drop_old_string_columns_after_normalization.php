<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $conn = Schema::getConnection();
        $driver = $conn->getDriverName();
        $t = 'matrimony_profiles';

        if ($driver === 'sqlite') {
            Schema::table($t, function (Blueprint $table) {
                foreach (['matrimony_profiles_religion_index', 'matrimony_profiles_caste_index', 'matrimony_profiles_sub_caste_index'] as $idx) {
                    $r = Schema::getConnection()->select("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?", ['matrimony_profiles', $idx]);
                    if (count($r) > 0) {
                        $table->dropIndex($idx);
                    }
                }
            });
        }

        // Drop old identity string columns
        Schema::table($t, function (Blueprint $table) use ($t) {
            $cols = [];
            if (Schema::hasColumn($t, 'religion')) {
                $cols[] = 'religion';
            }
            if (Schema::hasColumn($t, 'caste')) {
                $cols[] = 'caste';
            }
            if (Schema::hasColumn($t, 'sub_caste')) {
                $cols[] = 'sub_caste';
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });

        // Drop old preference string columns
        Schema::table('profile_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'preferred_caste',
                'preferred_city'
            ]);
        });

        // Drop old village string column
        Schema::table('profile_addresses', function (Blueprint $table) {
            $table->dropColumn('village');
        });
    }

    public function down(): void
    {
        // Reverse only if absolutely required (simple rollback structure)
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->string('religion')->nullable();
            $table->string('caste')->nullable();
            $table->string('sub_caste')->nullable();
        });

        Schema::table('profile_preferences', function (Blueprint $table) {
            $table->string('preferred_caste')->nullable();
            $table->string('preferred_city')->nullable();
        });

        Schema::table('profile_addresses', function (Blueprint $table) {
            $table->string('village')->nullable();
        });
    }
};