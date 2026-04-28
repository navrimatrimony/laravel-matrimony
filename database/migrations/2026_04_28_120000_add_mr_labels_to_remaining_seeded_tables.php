<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds nullable Marathi display columns for seed data (additive; PHASE-5 safe).
 */
return new class extends Migration
{
    /** Tables using `key` + `label` (master lookups). */
    private const MASTER_KEY_LABEL_TABLES = [
        'master_genders',
        'master_marital_statuses',
        'master_complexions',
        'master_physical_builds',
        'master_blood_groups',
        'master_family_types',
        'master_rashis',
        'master_nakshatras',
        'master_gans',
        'master_nadis',
        'master_mangal_dosh_types',
        'master_yonis',
        'master_child_living_with',
        'master_contact_relations',
        'master_address_types',
        'master_asset_types',
        'master_ownership_types',
        'master_legal_case_types',
        'master_mother_tongues',
        'master_diets',
        'master_smoking_statuses',
        'master_drinking_statuses',
        'master_mangal_statuses',
        'master_marriage_type_preferences',
        'master_varnas',
        'master_vashyas',
        'master_rashi_lords',
    ];

    public function up(): void
    {
        foreach (self::MASTER_KEY_LABEL_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'label_mr')) {
                    $blueprint->string('label_mr', 255)->nullable()->after('label');
                }
            });
        }

        if (Schema::hasTable('master_income_currencies') && ! Schema::hasColumn('master_income_currencies', 'label_mr')) {
            Schema::table('master_income_currencies', function (Blueprint $table) {
                $table->string('label_mr', 128)->nullable()->after('symbol');
            });
        }

        foreach (['working_with_types', 'professions', 'income_ranges', 'colleges'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'name_mr')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('name_mr', 255)->nullable()->after('name');
            });
        }

        if (Schema::hasTable('plans') && ! Schema::hasColumn('plans', 'name_mr')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->string('name_mr', 160)->nullable()->after('name');
            });
        }

        if (Schema::hasTable('field_registry') && ! Schema::hasColumn('field_registry', 'display_label_mr')) {
            Schema::table('field_registry', function (Blueprint $table) {
                $table->string('display_label_mr', 160)->nullable()->after('display_label');
            });
        }
    }

    public function down(): void
    {
        foreach (self::MASTER_KEY_LABEL_TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'label_mr')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('label_mr');
                });
            }
        }

        if (Schema::hasTable('master_income_currencies') && Schema::hasColumn('master_income_currencies', 'label_mr')) {
            Schema::table('master_income_currencies', function (Blueprint $table) {
                $table->dropColumn('label_mr');
            });
        }

        foreach (['working_with_types', 'professions', 'income_ranges', 'colleges'] as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'name_mr')) {
                Schema::table($t, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('name_mr');
                });
            }
        }

        if (Schema::hasTable('plans') && Schema::hasColumn('plans', 'name_mr')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->dropColumn('name_mr');
            });
        }

        if (Schema::hasTable('field_registry') && Schema::hasColumn('field_registry', 'display_label_mr')) {
            Schema::table('field_registry', function (Blueprint $table) {
                $table->dropColumn('display_label_mr');
            });
        }
    }
};
