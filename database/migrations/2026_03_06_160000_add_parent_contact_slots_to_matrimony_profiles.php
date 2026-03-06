<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5 additive: Up to 3 contact numbers each for Father and Mother (parent engine).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->string('father_contact_1', 20)->nullable()->after('father_occupation');
            $table->string('father_contact_2', 20)->nullable()->after('father_contact_1');
            $table->string('father_contact_3', 20)->nullable()->after('father_contact_2');
            $table->string('mother_contact_1', 20)->nullable()->after('mother_occupation');
            $table->string('mother_contact_2', 20)->nullable()->after('mother_contact_1');
            $table->string('mother_contact_3', 20)->nullable()->after('mother_contact_2');
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'father_contact_1', 'father_contact_2', 'father_contact_3',
                'mother_contact_1', 'mother_contact_2', 'mother_contact_3',
            ]);
        });
    }
};
