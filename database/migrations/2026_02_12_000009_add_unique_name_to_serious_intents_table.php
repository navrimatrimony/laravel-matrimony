<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serious_intents', function (Blueprint $table) {
            $table->unique('name', 'serious_intents_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('serious_intents', function (Blueprint $table) {
            $table->dropUnique('serious_intents_name_unique');
        });
    }
};
