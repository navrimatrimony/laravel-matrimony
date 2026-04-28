<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('occupation_categories')) {
            Schema::table('occupation_categories', function (Blueprint $table) {
                if (! Schema::hasColumn('occupation_categories', 'name_mr')) {
                    $table->string('name_mr', 128)->nullable()->after('name');
                }
            });
        }

        if (Schema::hasTable('occupation_master')) {
            Schema::table('occupation_master', function (Blueprint $table) {
                if (! Schema::hasColumn('occupation_master', 'name_mr')) {
                    $table->string('name_mr', 255)->nullable()->after('name');
                }
                if (! Schema::hasColumn('occupation_master', 'sort_order')) {
                    $table->unsignedInteger('sort_order')->default(0)->after('category_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('occupation_categories')) {
            Schema::table('occupation_categories', function (Blueprint $table) {
                if (Schema::hasColumn('occupation_categories', 'name_mr')) {
                    $table->dropColumn('name_mr');
                }
            });
        }

        if (Schema::hasTable('occupation_master')) {
            Schema::table('occupation_master', function (Blueprint $table) {
                if (Schema::hasColumn('occupation_master', 'name_mr')) {
                    $table->dropColumn('name_mr');
                }
                if (Schema::hasColumn('occupation_master', 'sort_order')) {
                    $table->dropColumn('sort_order');
                }
            });
        }
    }
};
