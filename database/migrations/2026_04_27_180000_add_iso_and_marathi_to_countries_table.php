<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add stable ISO-3166-1 alpha-2 codes and Marathi labels for countries (additive; keeps existing id FKs).
     */
    public function up(): void
    {
        if (! Schema::hasTable('countries')) {
            return;
        }

        if (! Schema::hasColumn('countries', 'name_mr')) {
            Schema::table('countries', function (Blueprint $table) {
                $table->string('name_mr', 255)->nullable()->after('name');
            });
        }
        if (! Schema::hasColumn('countries', 'iso_alpha2')) {
            Schema::table('countries', function (Blueprint $table) {
                $table->char('iso_alpha2', 2)->nullable()->after('name_mr');
            });
        }

        Schema::table('countries', function (Blueprint $table) {
            $table->unique('iso_alpha2');
        });

        DB::table('countries')->where('name', 'India')->update([
            'name_mr' => 'भारत',
            'iso_alpha2' => 'IN',
        ]);
        DB::table('countries')->where('name', 'USA')->update([
            'name_mr' => 'अमेरिका',
            'iso_alpha2' => 'US',
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('countries')) {
            return;
        }

        Schema::table('countries', function (Blueprint $table) {
            $table->dropUnique(['iso_alpha2']);
        });

        Schema::table('countries', function (Blueprint $table) {
            if (Schema::hasColumn('countries', 'name_mr')) {
                $table->dropColumn('name_mr');
            }
            if (Schema::hasColumn('countries', 'iso_alpha2')) {
                $table->dropColumn('iso_alpha2');
            }
        });
    }
};
