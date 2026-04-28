<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_education', function (Blueprint $table) {
            if (! Schema::hasColumn('master_education', 'name_mr')) {
                $table->string('name_mr', 128)->nullable()->after('name');
            }
        });

        Schema::table('master_occupation_types', function (Blueprint $table) {
            if (! Schema::hasColumn('master_occupation_types', 'name_mr')) {
                $table->string('name_mr', 128)->nullable()->after('name');
            }
        });

        Schema::table('master_employment_statuses', function (Blueprint $table) {
            if (! Schema::hasColumn('master_employment_statuses', 'name_mr')) {
                $table->string('name_mr', 128)->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('master_education', function (Blueprint $table) {
            if (Schema::hasColumn('master_education', 'name_mr')) {
                $table->dropColumn('name_mr');
            }
        });

        Schema::table('master_occupation_types', function (Blueprint $table) {
            if (Schema::hasColumn('master_occupation_types', 'name_mr')) {
                $table->dropColumn('name_mr');
            }
        });

        Schema::table('master_employment_statuses', function (Blueprint $table) {
            if (Schema::hasColumn('master_employment_statuses', 'name_mr')) {
                $table->dropColumn('name_mr');
            }
        });
    }
};
