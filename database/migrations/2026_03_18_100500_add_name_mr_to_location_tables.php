<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('states', function (Blueprint $table) {
            $table->string('name_mr')->nullable()->after('name');
        });

        Schema::table('districts', function (Blueprint $table) {
            $table->string('name_mr')->nullable()->after('name');
        });

        Schema::table('talukas', function (Blueprint $table) {
            $table->string('name_mr')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('states', function (Blueprint $table) {
            $table->dropColumn('name_mr');
        });

        Schema::table('districts', function (Blueprint $table) {
            $table->dropColumn('name_mr');
        });

        Schema::table('talukas', function (Blueprint $table) {
            $table->dropColumn('name_mr');
        });
    }
};

