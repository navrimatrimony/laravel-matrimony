<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('matrimony_profiles')) {
            return;
        }
        if (! Schema::hasColumn('matrimony_profiles', 'pending_intake_suggestions_json')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                $table->json('pending_intake_suggestions_json')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('matrimony_profiles') && Schema::hasColumn('matrimony_profiles', 'pending_intake_suggestions_json')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                $table->dropColumn('pending_intake_suggestions_json');
            });
        }
    }
};
