<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5 additive: Up to 3 contact numbers per sibling (contact_number_2, contact_number_3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_siblings', function (Blueprint $table) {
            if (! Schema::hasColumn('profile_siblings', 'contact_number_2')) {
                $table->string('contact_number_2', 30)->nullable()->after('contact_number');
            }
            if (! Schema::hasColumn('profile_siblings', 'contact_number_3')) {
                $table->string('contact_number_3', 30)->nullable()->after('contact_number_2');
            }
        });
    }

    public function down(): void
    {
        Schema::table('profile_siblings', function (Blueprint $table) {
            $table->dropColumn(['contact_number_2', 'contact_number_3']);
        });
    }
};
