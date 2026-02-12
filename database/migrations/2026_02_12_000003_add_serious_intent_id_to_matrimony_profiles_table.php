<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->foreignId('serious_intent_id')
                ->nullable()
                ->after('lifecycle_state')
                ->constrained('serious_intents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->dropForeign(['serious_intent_id']);
        });
    }
};
