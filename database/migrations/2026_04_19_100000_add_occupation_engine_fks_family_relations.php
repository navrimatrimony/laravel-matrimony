<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: centralized occupation engine FKs for parents, siblings, relatives, sibling spouses.
 * Legacy string columns (father_occupation, occupation, occupation_title) remain for display / exports.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('occupation_master') || ! Schema::hasTable('occupation_custom')) {
            return;
        }

        if (Schema::hasTable('matrimony_profiles')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                if (! Schema::hasColumn('matrimony_profiles', 'father_occupation_master_id')) {
                    $table->unsignedBigInteger('father_occupation_master_id')->nullable()->after('father_occupation');
                    $table->foreign('father_occupation_master_id')->references('id')->on('occupation_master')->nullOnDelete();
                }
                if (! Schema::hasColumn('matrimony_profiles', 'father_occupation_custom_id')) {
                    $table->unsignedBigInteger('father_occupation_custom_id')->nullable()->after('father_occupation_master_id');
                    $table->foreign('father_occupation_custom_id')->references('id')->on('occupation_custom')->nullOnDelete();
                }
                if (! Schema::hasColumn('matrimony_profiles', 'mother_occupation_master_id')) {
                    $table->unsignedBigInteger('mother_occupation_master_id')->nullable()->after('mother_occupation');
                    $table->foreign('mother_occupation_master_id')->references('id')->on('occupation_master')->nullOnDelete();
                }
                if (! Schema::hasColumn('matrimony_profiles', 'mother_occupation_custom_id')) {
                    $table->unsignedBigInteger('mother_occupation_custom_id')->nullable()->after('mother_occupation_master_id');
                    $table->foreign('mother_occupation_custom_id')->references('id')->on('occupation_custom')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('profile_siblings')) {
            Schema::table('profile_siblings', function (Blueprint $table) {
                if (! Schema::hasColumn('profile_siblings', 'occupation_master_id')) {
                    $table->unsignedBigInteger('occupation_master_id')->nullable()->after('occupation');
                    $table->foreign('occupation_master_id')->references('id')->on('occupation_master')->nullOnDelete();
                }
                if (! Schema::hasColumn('profile_siblings', 'occupation_custom_id')) {
                    $table->unsignedBigInteger('occupation_custom_id')->nullable()->after('occupation_master_id');
                    $table->foreign('occupation_custom_id')->references('id')->on('occupation_custom')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('profile_relatives')) {
            Schema::table('profile_relatives', function (Blueprint $table) {
                if (! Schema::hasColumn('profile_relatives', 'occupation_master_id')) {
                    $table->unsignedBigInteger('occupation_master_id')->nullable()->after('occupation');
                    $table->foreign('occupation_master_id')->references('id')->on('occupation_master')->nullOnDelete();
                }
                if (! Schema::hasColumn('profile_relatives', 'occupation_custom_id')) {
                    $table->unsignedBigInteger('occupation_custom_id')->nullable()->after('occupation_master_id');
                    $table->foreign('occupation_custom_id')->references('id')->on('occupation_custom')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('profile_sibling_spouses')) {
            Schema::table('profile_sibling_spouses', function (Blueprint $table) {
                if (! Schema::hasColumn('profile_sibling_spouses', 'occupation_master_id')) {
                    $table->unsignedBigInteger('occupation_master_id')->nullable()->after('occupation_title');
                    $table->foreign('occupation_master_id')->references('id')->on('occupation_master')->nullOnDelete();
                }
                if (! Schema::hasColumn('profile_sibling_spouses', 'occupation_custom_id')) {
                    $table->unsignedBigInteger('occupation_custom_id')->nullable()->after('occupation_master_id');
                    $table->foreign('occupation_custom_id')->references('id')->on('occupation_custom')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('profile_sibling_spouses')) {
            Schema::table('profile_sibling_spouses', function (Blueprint $table) {
                if (Schema::hasColumn('profile_sibling_spouses', 'occupation_custom_id')) {
                    $table->dropForeign(['occupation_custom_id']);
                    $table->dropColumn('occupation_custom_id');
                }
                if (Schema::hasColumn('profile_sibling_spouses', 'occupation_master_id')) {
                    $table->dropForeign(['occupation_master_id']);
                    $table->dropColumn('occupation_master_id');
                }
            });
        }

        if (Schema::hasTable('profile_relatives')) {
            Schema::table('profile_relatives', function (Blueprint $table) {
                if (Schema::hasColumn('profile_relatives', 'occupation_custom_id')) {
                    $table->dropForeign(['occupation_custom_id']);
                    $table->dropColumn('occupation_custom_id');
                }
                if (Schema::hasColumn('profile_relatives', 'occupation_master_id')) {
                    $table->dropForeign(['occupation_master_id']);
                    $table->dropColumn('occupation_master_id');
                }
            });
        }

        if (Schema::hasTable('profile_siblings')) {
            Schema::table('profile_siblings', function (Blueprint $table) {
                if (Schema::hasColumn('profile_siblings', 'occupation_custom_id')) {
                    $table->dropForeign(['occupation_custom_id']);
                    $table->dropColumn('occupation_custom_id');
                }
                if (Schema::hasColumn('profile_siblings', 'occupation_master_id')) {
                    $table->dropForeign(['occupation_master_id']);
                    $table->dropColumn('occupation_master_id');
                }
            });
        }

        if (Schema::hasTable('matrimony_profiles')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                foreach (['mother_occupation_custom_id', 'mother_occupation_master_id', 'father_occupation_custom_id', 'father_occupation_master_id'] as $col) {
                    if (Schema::hasColumn('matrimony_profiles', $col)) {
                        $table->dropForeign([$col]);
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
