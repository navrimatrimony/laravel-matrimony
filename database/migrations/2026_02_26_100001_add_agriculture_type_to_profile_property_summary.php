<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_property_summary')) {
            return;
        }
        Schema::table('profile_property_summary', function (Blueprint $table) {
            if (! Schema::hasColumn('profile_property_summary', 'agriculture_type')) {
                $table->string('agriculture_type', 50)->nullable()->after('owns_agriculture');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_property_summary')) {
            return;
        }
        Schema::table('profile_property_summary', function (Blueprint $table) {
            if (Schema::hasColumn('profile_property_summary', 'agriculture_type')) {
                $table->dropColumn('agriculture_type');
            }
        });
    }
};
