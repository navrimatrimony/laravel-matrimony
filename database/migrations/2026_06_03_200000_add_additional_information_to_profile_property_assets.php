<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_property_assets', function (Blueprint $table) {
            if (! Schema::hasColumn('profile_property_assets', 'additional_information')) {
                $table->text('additional_information')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('profile_property_assets', function (Blueprint $table) {
            if (Schema::hasColumn('profile_property_assets', 'additional_information')) {
                $table->dropColumn('additional_information');
            }
        });
    }
};
