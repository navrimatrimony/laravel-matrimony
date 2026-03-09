<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('matrimony_profiles', 'father_extra_info')) {
                $table->string('father_extra_info', 255)->nullable()->after('father_occupation');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'mother_extra_info')) {
                $table->string('mother_extra_info', 255)->nullable()->after('mother_occupation');
            }
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('matrimony_profiles', 'father_extra_info')) {
                $table->dropColumn('father_extra_info');
            }
            if (Schema::hasColumn('matrimony_profiles', 'mother_extra_info')) {
                $table->dropColumn('mother_extra_info');
            }
        });
    }
};

