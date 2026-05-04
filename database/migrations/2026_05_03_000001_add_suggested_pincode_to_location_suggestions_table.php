<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('location_suggestions')) {
            return;
        }
        if (! Schema::hasColumn('location_suggestions', 'suggested_pincode')) {
            Schema::table('location_suggestions', function (Blueprint $table): void {
                $table->string('suggested_pincode', 10)->nullable()->after('taluka_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('location_suggestions')) {
            return;
        }
        if (Schema::hasColumn('location_suggestions', 'suggested_pincode')) {
            Schema::table('location_suggestions', function (Blueprint $table): void {
                $table->dropColumn('suggested_pincode');
            });
        }
    }
};
