<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $name = 'profile_preference_criteria';
        if (! Schema::hasTable($name)) {
            return;
        }
        Schema::table($name, function (Blueprint $table) use ($name) {
            if (! Schema::hasColumn($name, 'preferred_marital_status_id')) {
                $table->foreignId('preferred_marital_status_id')
                    ->nullable()
                    ->constrained('master_marital_statuses')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        $name = 'profile_preference_criteria';
        if (! Schema::hasTable($name) || ! Schema::hasColumn($name, 'preferred_marital_status_id')) {
            return;
        }
        Schema::table($name, function (Blueprint $table) {
            $table->dropForeign(['preferred_marital_status_id']);
        });
    }
};
